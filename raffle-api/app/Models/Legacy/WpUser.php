<?php

namespace App\Models\Legacy;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Maps to WordPress's own wp_users table. This is the ONLY source of truth
 * for accounts and passwords — do not create a parallel Laravel "users"
 * table for real accounts, and do not re-hash or replace WP's password
 * column here. See WpUserMeta for wallet balance, bans, etc.
 *
 * Implements Authenticatable so this model — not Laravel's stock `User`
 * model — is what `Auth::user()` returns once authenticated via the
 * `wordpress_session` guard (see App\Auth\WordPressSessionGuard).
 */
class WpUser extends LegacyModel implements Authenticatable
{
    protected static string $unprefixedTable = 'users';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $fillable = [
        'user_login',
        'user_pass',
        'user_email',
        'display_name',
    ];

    protected $hidden = [
        'user_pass',
    ];

    public function meta()
    {
        return $this->hasMany(WpUserMeta::class, 'user_id', 'ID');
    }

    public function transactions()
    {
        return $this->hasMany(RaffleTransaction::class, 'user_id', 'ID');
    }

    public function entries()
    {
        return $this->hasMany(RaffleEntry::class, 'user_id', 'ID');
    }

    public function wins()
    {
        return $this->hasMany(RaffleWinner::class, 'user_id', 'ID');
    }

    /** Convenience reader — usermeta is unstructured, so keep the awkward
     *  bits contained here rather than scattering get_user_meta-equivalent
     *  queries through controllers. Prefer the new Wallet/BankAccount
     *  models (see 22_backfill instructions) once they're backfilled. */
    public function metaValue(string $key): ?string
    {
        return $this->meta()->where('meta_key', $key)->value('meta_value');
    }

    /**
     * True if this is a real WordPress "administrator" — the same role
     * every admin-only action in the legacy site checks via WordPress's
     * own current_user_can('manage_options')/('administrator'). There is
     * no separate admin role system in this Laravel app yet (that's
     * OVERHAUL_CHECKLIST.md Phase 1 item 19's job); this reads the same
     * capabilities WordPress already assigns, so an admin-gated route
     * here respects exactly who's actually an admin today, not a new,
     * separate notion of one.
     */
    public function isAdministrator(): bool
    {
        $raw = $this->metaValue(config('legacy.wp_prefix').'capabilities');

        if (! $raw) {
            return false;
        }

        $capabilities = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($capabilities) && ! empty($capabilities['administrator']);
    }

    // -- Illuminate\Contracts\Auth\Authenticatable ---------------------
    // WordPress's own column names, not Laravel's usual id/password/
    // remember_token conventions. No remember-me support is implemented
    // (WordPress doesn't use Laravel's remember-token mechanism), so
    // those two methods are safe no-ops.

    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'user_pass';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->user_pass;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Intentional no-op — see class docblock.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
