<?php

namespace App\Models\Legacy;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Maps to WordPress's own wp_users table. This is the ONLY source of truth
 * for accounts and passwords — do not create a parallel Laravel "users"
 * table for real accounts, and do not re-hash or replace WP's password
 * column here. See WpUserMeta for wallet balance, bans, etc.
 *
 * Implements Authenticatable so this model — not Laravel's stock `User`
 * model — is what `Auth::user()` returns once authenticated via the
 * `wordpress_session` guard (see App\Auth\WordPressSessionGuard) or the
 * newer `App\Auth\WordPressOrSanctumGuard` it's now wrapped in (Phase 3
 * item 34) — both resolve to this same model, so nothing downstream of
 * `Auth::user()`/`$request->user()` needs to know or care which one
 * actually authenticated a given request.
 *
 * Also implements FilamentUser so the SAME model, resolved by the SAME
 * guard, is who Filament's admin panel (App\Providers\Filament\
 * AdminPanelProvider) sees as the current user — there is no separate
 * Filament login form or password; canAccessPanel() below is the one
 * gate, same isAdministrator() check every other admin-only endpoint
 * in this app already uses.
 *
 * HasApiTokens (Sanctum) lets this model issue/hold personal access
 * tokens — see App\Auth\WordPressOrSanctumGuard and
 * LoginController/RegisterController, which now issue one alongside the
 * existing WordPress cookie on every successful login.
 */
class WpUser extends LegacyModel implements Authenticatable, FilamentUser, HasName
{
    use HasApiTokens, Notifiable;

    protected static string $unprefixedTable = 'users';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $fillable = [
        'user_login',
        'user_pass',
        'user_email',
        'display_name',
        'user_registered',
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
    /** Reads the same rk_is_banned usermeta flag the legacy site sets. */
    public function isBanned(): bool
    {
        return $this->metaValue('rk_is_banned') === '1';
    }

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

    // -- Illuminate\Notifications\Notifiable routing -------------------
    // WordPress's own column/meta names, not Laravel's usual "email"
    // attribute or a Sanctum-style device-token column.

    public function routeNotificationForMail(): string
    {
        return $this->user_email;
    }

    /** Reads the same OneSignal player id the legacy site saves via rk_save_push_device(). */
    public function routeNotificationForOneSignal(): ?string
    {
        return $this->metaValue('rk_onesignal_id') ?: null;
    }

    // -- Filament\Models\Contracts\FilamentUser ------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdministrator();
    }

    // -- Filament\Models\Contracts\HasName ------------------------------

    public function getFilamentName(): string
    {
        return $this->display_name ?: $this->user_login;
    }
}
