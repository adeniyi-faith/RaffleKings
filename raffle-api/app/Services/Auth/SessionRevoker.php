<?php

namespace App\Services\Auth;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;

/**
 * Signs a person out everywhere: every browser session (the WordPress
 * session_tokens usermeta the cookie is checked against) AND every app
 * token. Used for bans, password and email changes, support's "sign out
 * everywhere", temporary passwords and staff removal, so none of them can
 * leave a stolen token working (money-safety audit H5, I6).
 */
class SessionRevoker
{
    /**
     * @param  string|null  $keepSessionToken  the raw session token of the
     *                                         device making the change, to keep it signed in
     */
    public function everywhere(WpUser $user, ?string $keepSessionToken = null): void
    {
        $meta = WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', 'session_tokens')->first();

        if ($meta && $keepSessionToken !== null) {
            $sessions = @unserialize((string) $meta->meta_value, ['allowed_classes' => false]);
            $keepHash = hash('sha256', $keepSessionToken);

            if (is_array($sessions) && isset($sessions[$keepHash])) {
                $meta->update(['meta_value' => serialize([$keepHash => $sessions[$keepHash]])]);
            } else {
                $meta->delete();
            }
        } elseif ($meta) {
            WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', 'session_tokens')->delete();
        }

        $user->tokens()->delete();
    }
}
