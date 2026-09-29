<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings;
use App\Models\AppSetting;
use App\Settings\SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The Sentry DSN is entered in Settings → Alerts & push, not in the server's .env file. */
class SentrySettingTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_the_settings_page_has_a_sentry_field(): void
    {
        $this->actingAsAdministrator();

        $this->get('/admin/settings')->assertOk()->assertSee('Sentry');
    }

    public function test_a_saved_dsn_is_stored_encrypted_and_applied(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(Settings::class)
            ->set('data.sentry__dsn', 'https://abc123@o123456.ingest.sentry.io/1234567')
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = AppSetting::query()->where('key', 'sentry.dsn')->value('value');
        $this->assertStringNotContainsString('abc123', $stored);
        $this->assertSame('https://abc123@o123456.ingest.sentry.io/1234567', json_decode(Crypt::decryptString($stored)));
        $this->assertSame('https://abc123@o123456.ingest.sentry.io/1234567', config('sentry.dsn'));
    }

    public function test_something_that_is_not_a_web_address_is_refused(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(Settings::class)
            ->set('data.sentry__dsn', 'not-a-dsn')
            ->call('save')
            ->assertHasFormErrors(['sentry__dsn']);
    }

    /** Sentry reads its DSN when it boots, so it must boot after the admin settings are applied. */
    public function test_sentry_starts_after_the_admin_settings_are_applied(): void
    {
        $providers = require base_path('bootstrap/providers.php');

        $this->assertGreaterThan(
            array_search(\App\Providers\AppServiceProvider::class, $providers, true),
            array_search(\Sentry\Laravel\ServiceProvider::class, $providers, true),
        );
        $this->assertContains('sentry/sentry-laravel', json_decode(file_get_contents(base_path('composer.json')), true)['extra']['laravel']['dont-discover']);
    }
}
