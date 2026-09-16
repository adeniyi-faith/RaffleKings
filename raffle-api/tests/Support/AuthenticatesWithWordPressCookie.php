<?php

namespace Tests\Support;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;

/**
 * Shared helper for feature tests that need a real, valid WordPress
 * session cookie on the test HTTP client — see
 * App\Auth\WordPressAuthCookieValidator for the algorithm this mirrors.
 */
trait AuthenticatesWithWordPressCookie
{
    protected function actingAsWordPressUser(array $attributes = []): WpUser
    {
        $user = WpUser::create(array_merge([
            'user_login' => 'tester_'.uniqid(),
            'user_pass' => 'irrelevant-hash-1234567890',
            'user_email' => 'tester_'.uniqid().'@example.com',
            'display_name' => 'Tester',
        ], $attributes));

        $token = 'raw-session-token-'.uniqid();

        WpUserMeta::create([
            'user_id' => $user->getKey(),
            'meta_key' => 'session_tokens',
            'meta_value' => serialize([
                hash('sha256', $token) => ['expiration' => time() + 3600],
            ]),
        ]);

        $expiration = time() + 3600;
        $passFrag = substr($user->user_pass, 8, 4);
        $key = hash_hmac(
            'md5',
            "{$user->user_login}|{$passFrag}|{$expiration}|{$token}",
            config('legacy.wp_logged_in_key').config('legacy.wp_logged_in_salt'),
        );
        $hmac = hash_hmac('sha256', "{$user->user_login}|{$expiration}|{$token}", $key);
        $cookie = "{$user->user_login}|{$expiration}|{$token}|{$hmac}";

        $cookieName = 'wordpress_logged_in_'.config('legacy.wp_cookiehash');

        $this->withCredentials()->withUnencryptedCookies([$cookieName => $cookie]);

        return $user;
    }
}
