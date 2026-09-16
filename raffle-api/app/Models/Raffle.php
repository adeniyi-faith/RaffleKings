<?php

namespace App\Models;

use App\Models\Legacy\RaffleEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The real, Laravel-owned raffle model — replaces the WordPress custom
 * post type + postmeta fields raffles are modelled as in the legacy site
 * (see App\Models\Legacy\WpPost / RaffleReadService for the read-only
 * bridge to that during the migration window). See
 * database/migrations/2024_06_03_000001_create_raffles_table.php for why
 * `status` is admin intent, not the same thing as "sold out."
 */
class Raffle extends Model
{
    protected $fillable = [
        'legacy_post_id',
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
    ];

    protected $casts = [
        'price' => 'decimal:2',
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

    /**
     * Ticket sales still live in the legacy wp_raffle_entries table
     * (raffle_id there refers to this raffle's legacy_post_id during the
     * migration window, or its own id for a raffle created natively).
     */
    public function entries(): HasMany
    {
        return $this->hasMany(RaffleEntry::class, 'raffle_id', 'legacy_post_id');
    }

    public function soldTickets(): int
    {
        return $this->entries()->count();
    }

    public function remainingTickets(): int
    {
        return max(0, $this->max_tickets - $this->soldTickets());
    }

    /**
     * True if a ticket genuinely cannot be sold right now — either an
     * admin closed it on purpose, or it has actually sold out. Never
     * trust `status` alone for the sold-out case (audit TD-13).
     */
    public function isClosed(): bool
    {
        return $this->status !== 'published' || $this->remainingTickets() <= 0;
    }
}
