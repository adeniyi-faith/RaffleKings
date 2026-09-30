<?php

namespace App\Models;

use App\Models\Legacy\RaffleEntry;
use App\Services\Draw\DrawRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real, Laravel-owned raffle model — replaces the WordPress custom
 * post type + postmeta fields raffles are modelled as in the legacy site
 * (see App\Models\Legacy\WpPost / RaffleReadService for the read-only
 * bridge to that during the migration window). See
 * database/migrations/2024_06_03_000001_create_raffles_table.php for why
 * `status` is admin intent, not the same thing as "sold out."
 *
 * Since OVERHAUL_CHECKLIST.md item 43 this is the ONLY source of raffles
 * for the public site. `public_id` is the raffle's permanent number —
 * used in page addresses and by every ticket row — see the
 * 2026_09_28_000001 migration for why it isn't simply `id`.
 */
class Raffle extends Model
{
    /** Statuses customers can see. Drafts are admin-only. */
    public const PUBLIC_STATUSES = ['published', 'closed'];

    protected $fillable = [
        'legacy_post_id',
        'public_id',
        'prize_type',
        'prize_list',
        'title',
        'excerpt',
        'price',
        'max_tickets',
        'grand_prize',
        'expiry',
        'status',
        // Item 27 — Live Draw event controls (Filament RaffleResource).
        'is_live_draw_enabled',
        'live_draw_status',
        'live_draw_pace_ms',
        'live_draw_theme_color',
        'live_draw_scheduled_at',
        'live_draw_started_at',
        // Raffle Rules Engine: this raffle's published draw rules.
        'draw_rules',
        // Phase 11 flash raffles: sales stop at this exact moment.
        'is_flash',
        'sales_end_at',
        // Cancelled with every ticket refunded (App\Services\RaffleCancellationService).
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'refund_status',
        'refunded_customers',
        'refunded_total',
    ];

    protected $casts = [
        'draw_rules' => 'array',
        'is_flash' => 'boolean',
        'sales_end_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_total' => 'float',
        'price' => 'decimal:2',
        'public_id' => 'integer',
        'max_tickets' => 'integer',
        'expiry' => 'date',
        'is_live_draw_enabled' => 'boolean',
        'live_draw_pace_ms' => 'integer',
        'live_draw_scheduled_at' => 'datetime',
        'live_draw_started_at' => 'datetime',
    ];

    public function prizeTiers(): HasMany
    {
        return $this->hasMany(RafflePrizeTier::class)->orderBy('rank');
    }

    protected static function booted(): void
    {
        // Every raffle gets its permanent public number on creation: the
        // old WordPress post id when imported, otherwise a fresh number no
        // ticket has ever used.
        static::creating(function (Raffle $raffle) {
            $raffle->public_id ??= $raffle->legacy_post_id ?? static::nextPublicId();
        });
    }

    /**
     * One above every number already in use — raffle public ids, every
     * WordPress post id (raffles were posts, so their ids share that
     * sequence), and every raffle id any ticket row has ever pointed at
     * (covers raffles whose post was later deleted). Guarantees a new
     * raffle's tickets can never be counted as an old raffle's.
     */
    public static function nextPublicId(): int
    {
        $prefix = config('legacy.wp_prefix');

        return 1 + max(
            (int) static::query()->max('public_id'),
            Schema::hasTable($prefix.'posts') ? (int) DB::table($prefix.'posts')->max('ID') : 0,
            (int) RaffleEntry::query()->max('raffle_id'),
        );
    }

    /** Raffles customers may see (published or closed — never drafts). */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', self::PUBLIC_STATUSES);
    }

    /**
     * The moment sales stop: the end of the expiry day in the business's
     * own timezone (config/raffles.php) — the same "closes end of day"
     * rule the old site's countdown badges used. Null = no end date.
     */
    public function endsAt(): ?Carbon
    {
        // A flash raffle (or any raffle given an exact end) stops at that moment.
        if ($this->sales_end_at) {
            return $this->sales_end_at->copy();
        }

        if (! $this->expiry) {
            return null;
        }

        return Carbon::parse($this->expiry->toDateString(), config('raffles.timezone'))->endOfDay();
    }

    public function hasEnded(): bool
    {
        return $this->endsAt() !== null && now()->greaterThan($this->endsAt());
    }

    /**
     * Why a ticket can't be sold, or null if it can. The single rule every
     * reader and the purchase path share: closed by an admin, past its end
     * date, or genuinely sold out (counted from real tickets, never a
     * manual flag — audit TD-13). Pass a known sold count to avoid a query.
     */
    public function closedReason(?int $soldTickets = null): ?string
    {
        // A cancelled raffle stays closed even if someone sets it back to published.
        if ($this->cancelled_at) {
            return 'cancelled';
        }

        if ($this->status !== 'published') {
            return 'closed';
        }

        if ($this->hasEnded()) {
            return 'ended';
        }

        if ($this->max_tickets - ($soldTickets ?? $this->soldTickets()) <= 0) {
            return 'sold_out';
        }

        return null;
    }

    /**
     * Ticket sales live in the legacy wp_raffle_entries table, keyed by
     * this raffle's permanent `public_id`.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(RaffleEntry::class, 'raffle_id', 'public_id');
    }

    public function soldTickets(): int
    {
        return $this->entries()->count();
    }

    public function remainingTickets(): int
    {
        return max(0, $this->max_tickets - $this->soldTickets());
    }

    /** Its draw has run (winners generated) — see ProvablyFairDrawService. */
    public function isDrawn(): bool
    {
        return RaffleDraw::query()->where('raffle_id', $this->id)->whereNotNull('executed_at')->exists();
    }

    /**
     * Only a raffle nobody has bought into, and that was never drawn, can
     * be deleted (item 45): deleting one with tickets would orphan real
     * customers' tickets, payments and any winners.
     */
    public function canBeDeleted(): bool
    {
        return $this->soldTickets() === 0 && ! RaffleDraw::query()->where('raffle_id', $this->id)->exists();
    }

    /** True if a ticket genuinely cannot be sold right now — see closedReason(). */
    public function isClosed(): bool
    {
        return $this->closedReason() !== null;
    }

    /** This raffle's draw rules, with the site defaults filling any gaps (Raffle Rules Engine). */
    public function drawRules(): DrawRules
    {
        return DrawRules::fromArray($this->draw_rules);
    }

    /**
     * Draw rules can't change once anyone has bought a ticket or the draw
     * seed is locked — customers bought under the published rules.
     */
    public function drawRulesLocked(): bool
    {
        return $this->soldTickets() > 0 || RaffleDraw::query()->where('raffle_id', $this->id)->exists();
    }
}
