<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AdminLogin;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\Auth\WordPressPasswordHasher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin sign-in used to "just reload": clearing leftover copies of the
 * login cookie was queued under the same name, which replaced the new
 * login cookie itself, so the browser was never signed in.
 */
class AdminSignInCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_sends_the_real_login_cookie(): void
    {
        $staff = WpUser::create(['user_login' => 'boss', 'user_pass' => app(WordPressPasswordHasher::class)->make('secret123'), 'user_email' => 'boss@example.com', 'display_name' => 'Boss']);
        WpUserMeta::create(['user_id' => $staff->ID, 'meta_key' => config('legacy.wp_prefix').'capabilities', 'meta_value' => serialize(['administrator' => true])]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminLogin::class)
            ->set('data.login', 'boss')
            ->set('data.password', 'secret123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $cookie = Cookie::queued(app('wordpress.auth_cookie_name'));

        $this->assertNotNull($cookie);
        $this->assertStringStartsWith('boss|', urldecode((string) $cookie->getValue()));
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
    }
}
