<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings;
use App\Mail\Transport\BrevoTransport;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\BankAccount;
use App\Models\Wallet;
use App\Settings\ConnectionTester;
use App\Settings\SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * System → Settings: admins run the site from one page — keys, email,
 * pricing, rewards, limits and on/off switches — and changes apply at once.
 */
class SettingsTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private function page()
    {
        return Livewire::test(Settings::class);
    }

    public function test_the_settings_page_opens_for_an_admin(): void
    {
        $this->actingAsAdministrator();

        $this->get('/admin/settings')->assertOk()->assertSee('Paystack')->assertSee('Brevo')->assertSee('Spin &amp; Win', false);
    }

    public function test_a_secret_key_is_stored_encrypted_applied_at_once_and_kept_out_of_the_audit_log(): void
    {
        $this->actingAsAdministrator();

        $this->page()
            ->set('data.services__paystack__secret_key', 'sk_live_supersecret1234')
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = AppSetting::where('key', 'services.paystack.secret_key')->value('value');
        $this->assertStringNotContainsString('supersecret', $stored);
        $this->assertSame('"sk_live_supersecret1234"', Crypt::decryptString($stored));
        $this->assertSame('sk_live_supersecret1234', config('services.paystack.secret_key'));

        $log = AdminAuditLog::where('action', 'settings.updated')->firstOrFail();
        $this->assertStringNotContainsString('supersecret', json_encode($log->context));
    }

    public function test_a_saved_secret_is_never_sent_back_to_the_browser_and_empty_means_keep_it(): void
    {
        $this->actingAsAdministrator();
        $this->page()->set('data.services__paystack__secret_key', 'sk_live_keepme9999')->call('save');

        $page = $this->page()->assertSet('data.services__paystack__secret_key', null);
        $this->assertStringNotContainsString('sk_live_keepme9999', $page->html());
        $this->assertStringContainsString('ends in …9999', $page->html());

        $page->set('data.withdrawals__minimum_amount', 5000)->call('save');

        $this->assertSame('sk_live_keepme9999', config('services.paystack.secret_key'));
    }

    public function test_changing_a_limit_applies_immediately_and_changing_it_back_removes_the_override(): void
    {
        $this->actingAsAdministrator();
        $envValue = config('withdrawals.minimum_amount');

        $this->page()->set('data.withdrawals__minimum_amount', 5000)->call('save');
        $this->assertEquals(5000, config('withdrawals.minimum_amount'));
        $this->assertTrue(AppSetting::where('key', 'withdrawals.minimum_amount')->exists());

        $this->page()->set('data.withdrawals__minimum_amount', $envValue)->call('save');
        $this->assertEquals($envValue, config('withdrawals.minimum_amount'));
        $this->assertFalse(AppSetting::where('key', 'withdrawals.minimum_amount')->exists());
    }

    public function test_settings_survive_a_fresh_request(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save(['referrals.commission_rate' => 0.25, 'services.gemini.api_key' => 'AIzaFreshKey'], $admin);

        // What the next request does when it starts up.
        config(['referrals.commission_rate' => 0.5, 'services.gemini.api_key' => null]);
        SettingsStore::apply();

        $this->assertEquals(0.25, config('referrals.commission_rate'));
        $this->assertSame('AIzaFreshKey', config('services.gemini.api_key'));
    }

    public function test_the_referral_rate_is_entered_as_a_percentage(): void
    {
        $this->actingAsAdministrator();

        $this->page()->assertSet('data.referrals__commission_rate', 50.0)
            ->set('data.referrals__commission_rate', 20)
            ->call('save');

        $this->assertEquals(0.2, config('referrals.commission_rate'));
    }

    public function test_bulk_discounts_change_what_customers_pay(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->createRaffle(['price' => '1000']);

        $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=3")->assertJson(['discounted' => 1950]);

        $this->page()
            ->set('data.pricing__bundles', [['quantity' => 3, 'percent_off' => 20], ['quantity' => 4, 'percent_off' => 30]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=3")->assertJson(['discounted' => 2400]);
        $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=4")->assertJson(['discounted' => 2800]);
    }

    public function test_a_bundle_size_cannot_be_listed_twice(): void
    {
        $this->actingAsAdministrator();

        $this->page()
            ->set('data.pricing__bundles', [['quantity' => 3, 'percent_off' => 20], ['quantity' => 3, 'percent_off' => 30]])
            ->call('save')
            ->assertHasFormErrors(['pricing__bundles']);
    }

    public function test_spin_prizes_and_daily_rewards_are_editable(): void
    {
        $this->actingAsAdministrator();

        $this->page()
            ->set('data.rewards__spin_prizes', [['outcome' => 'loss', 'payout' => 0, 'weight' => 3], ['outcome' => 'win', 'payout' => 100, 'weight' => 1]])
            ->set('data.rewards__daily_claim__d6', 2000)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->getJson('/api/rewards/spin/odds')->assertJsonFragment(['outcome' => 'win', 'payout' => 100, 'probability' => 0.25]);
        $this->assertSame([50, 70, 100, 150, 200, 300, 2000], config('rewards.daily_claim'));
    }

    public function test_a_paused_feature_tells_customers_why(): void
    {
        $this->actingAsAdministrator();
        $this->page()
            ->set('data.site__switches__withdrawals', false)
            ->set('data.site__paused_message', 'Withdrawals are paused until 6pm.')
            ->call('save');

        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        $this->postJson('/api/withdrawals', ['amount' => 3000, 'bank_account_id' => $account->id])
            ->assertStatus(503)
            ->assertJson(['message' => 'Withdrawals are paused until 6pm.', 'paused' => 'withdrawals']);
    }

    public function test_sign_ups_can_be_paused(): void
    {
        config(['site.switches.registrations' => false]);

        $this->postJson('/api/auth/register', ['username' => 'newplayer', 'email' => 'n@example.com', 'password' => 'letmein1'])
            ->assertStatus(503);
    }

    public function test_undo_puts_a_setting_back_to_the_server_value(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save(['raffles.list_closed_for_days' => 3], $admin);
        $this->assertTrue(SettingsStore::isOverridden('raffles.list_closed_for_days'));

        SettingsStore::forget('raffles.list_closed_for_days', $admin);

        $this->assertSame(14, config('raffles.list_closed_for_days'));
        $this->assertFalse(AppSetting::where('key', 'raffles.list_closed_for_days')->exists());
    }

    public function test_a_secret_that_can_no_longer_be_decrypted_is_ignored(): void
    {
        AppSetting::create(['key' => 'services.paystack.secret_key', 'value' => 'not-encrypted-garbage']);
        config(['services.paystack.secret_key' => 'sk_env_value']);

        SettingsStore::refresh();

        $this->assertSame('sk_env_value', config('services.paystack.secret_key'));
    }

    public function test_the_check_buttons_tell_a_good_key_from_a_bad_one(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => true], 200),
            'api.flutterwave.com/*' => Http::response(['status' => 'error'], 401),
            'api.brevo.com/*' => Http::response(['email' => 'owner@example.com'], 200),
            'generativelanguage.googleapis.com/*' => Http::response(['displayName' => 'Gemini 2.5 Flash'], 200),
        ]);
        $tester = app(ConnectionTester::class);

        $this->assertTrue($tester->paystack('sk_test_abc')[0]);
        $this->assertStringContainsString('TEST mode', $tester->paystack('sk_test_abc')[1]);
        $this->assertFalse($tester->flutterwave('FLWSECK-bad')[0]);
        $this->assertStringContainsString('owner@example.com', $tester->brevo('xkeysib-abc')[1]);
        $this->assertTrue($tester->gemini('AIza', 'gemini-2.5-flash')[0]);
        $this->assertFalse($tester->paystack(null)[0]);
    }

    public function test_emails_can_be_sent_through_brevo_with_just_an_api_key(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        config(['mail.default' => 'brevo', 'services.brevo.key' => 'xkeysib-test', 'mail.from.address' => 'hello@rafflekings.test', 'mail.from.name' => 'RaffleKings']);
        app('mail.manager')->forgetMailers();

        Mail::raw('Your code is 123456', fn ($m) => $m->to('ada@example.com')->subject('Reset code'));

        Http::assertSent(fn (Request $r) => $r->url() === BrevoTransport::ENDPOINT
            && $r->header('api-key')[0] === 'xkeysib-test'
            && $r['to'][0]['email'] === 'ada@example.com'
            && $r['sender']['email'] === 'hello@rafflekings.test'
            && $r['subject'] === 'Reset code'
            && str_contains($r['textContent'], '123456'));
    }

    public function test_customer_pages_get_the_links_rates_bundles_and_switches(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save([
            'site.links.community' => 'https://t.me/rafflekings_community',
            'rewards.points_per_naira' => 20,
            'pricing.bundles' => [['quantity' => 4, 'percent_off' => 30]],
            'site.switches.spin' => false,
        ], $admin);

        $this->get('/rewards')->assertInertia(fn ($page) => $page
            ->where('site.links.community', 'https://t.me/rafflekings_community')
            ->where('site.points_per_naira', 20)
            ->where('site.ticket_bundles', [1, 4])
            ->where('site.switches.spin', false));
    }

    public function test_an_unlisted_server_value_does_not_block_saving(): void
    {
        config(['mail.default' => 'sendmail', 'services.gemini.model' => 'gemini-9-experimental']);
        $this->actingAsAdministrator();

        $this->page()
            ->assertSet('data.mail__default', 'sendmail')
            ->set('data.withdrawals__minimum_amount', 4000)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(4000, config('withdrawals.minimum_amount'));
        $this->assertSame('sendmail', config('mail.default'));
    }
}
