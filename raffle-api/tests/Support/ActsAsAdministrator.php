<?php

namespace Tests\Support;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Livewire\Livewire;

/**
 * Signs in a WordPress administrator for admin-screen tests.
 *
 * Livewire's test harness sends button clicks as separate internal
 * requests that don't carry the test's own login cookie, so a plain
 * login made every click look signed-out (earlier admin tests could only
 * check which buttons were visible). Cookies handed to Livewire::withCookies()
 * ARE sent with every one of those requests, so the same real WordPress
 * login cookie is passed there too, and these tests click the real
 * buttons as a genuinely signed-in admin.
 */
trait ActsAsAdministrator
{
    use AuthenticatesWithWordPressCookie;

    protected function actingAsAdministrator(): WpUser
    {
        $admin = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $admin->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        Livewire::withCookies($this->unencryptedCookies);

        return $admin;
    }
}
