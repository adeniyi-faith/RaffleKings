<?php

namespace Tests\Feature\Growth;

use App\Filament\Pages\FraudWatch;
use App\Filament\Resources\AffiliateResource\Pages\ListAffiliates;
use App\Models\BankAccount;
use App\Models\Deposit;
use App\Models\Growth\Affiliate;
use App\Models\Growth\AffiliateEarning;
use App\Models\Growth\CustomerSource;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserDevice;
use App\Models\Wallet;
use App\Services\Growth\AffiliateService;
use App\Services\ReferralCommissionService;
use App\Services\Risk\AbuseDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class AffiliatesAndAbuseTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.affiliates' => true, 'features.abuse_detection' => true]);
    }

    private function person(string $name = 'p'): WpUser
    {
        return WpUser::create(['user_login' => $name.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => ucfirst($name), 'user_registered' => now()]);
    }

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::create($attributes + ['user_id' => $this->person('tobi')->ID, 'name' => 'Tobi', 'code' => 'Tobi', 'commission_percent' => 10, 'commission_days' => 90, 'hold_days' => 7]);
    }

    private function deposit(WpUser $user, float $amount = 5000): Deposit
    {
        return Deposit::create(['user_id' => $user->ID, 'amount' => $amount, 'reference' => 'ref-'.uniqid(), 'gateway' => 'paystack', 'status' => 'successful']);
    }

    public function test_the_link_counts_clicks_and_signs_the_visitor_up_as_theirs(): void
    {
        $affiliate = $this->affiliate();

        $this->get('/go/TOBI')->assertRedirect('/register')->assertCookie(AffiliateService::COOKIE, 'tobi', false);
        $this->get('/go/unknown')->assertRedirect('/');

        $this->withCredentials()->withUnencryptedCookie(AffiliateService::COOKIE, 'tobi')
            ->postJson('/api/auth/register', ['username' => 'fan'.random_int(100, 999), 'email' => uniqid().'@example.com', 'password' => 'secret123', 'accept_terms' => true])
            ->assertCreated();

        $customer = WpUser::query()->latest('ID')->first();
        $this->assertSame($affiliate->id, CustomerSource::find($customer->ID)->affiliate_id);
        $this->assertSame(1, app(AffiliateService::class)->dashboard($affiliate)['clicks_30_days']);
    }

    public function test_top_ups_earn_commission_which_is_held_then_paid_into_winnings(): void
    {
        $affiliate = $this->affiliate();
        $customer = $this->person('fan');
        CustomerSource::create(['user_id' => $customer->ID, 'affiliate_id' => $affiliate->id]);

        $earning = app(AffiliateService::class)->recordDeposit($this->deposit($customer));
        $this->assertSame(500.0, $earning->commission);
        $this->assertSame('held', $earning->status);

        // The same top-up is never counted twice.
        $this->assertNull(app(AffiliateService::class)->recordDeposit(Deposit::find($earning->source_id)));

        $this->assertSame(0, app(AffiliateService::class)->releaseDue());
        $this->travel(8)->days();
        $this->assertSame(1, app(AffiliateService::class)->releaseDue());

        $this->assertEquals(500, Wallet::where('user_id', $affiliate->user_id)->value('earnings_balance'));
        $this->assertDatabaseHas('wallet_ledger_entries', ['user_id' => $affiliate->user_id, 'reason' => 'affiliate_commission', 'amount' => 500]);
        $this->assertSame(500.0, app(AffiliateService::class)->dashboard($affiliate)['earned']['paid']);
    }

    public function test_nothing_is_earned_after_the_window_or_while_switched_off(): void
    {
        $affiliate = $this->affiliate(['commission_days' => 30]);
        $old = $this->person('old');
        $old->forceFill(['user_registered' => now()->subDays(31)])->save();
        CustomerSource::create(['user_id' => $old->ID, 'affiliate_id' => $affiliate->id]);
        $this->assertNull(app(AffiliateService::class)->recordDeposit($this->deposit($old)));

        config(['features.affiliates' => false]);
        $new = $this->person('new');
        CustomerSource::create(['user_id' => $new->ID, 'affiliate_id' => $affiliate->id]);
        $this->assertNull(app(AffiliateService::class)->recordDeposit($this->deposit($new)));
        $this->get('/go/tobi')->assertRedirect('/');
    }

    public function test_the_dashboard_is_only_for_affiliates_and_hides_customer_names(): void
    {
        $affiliate = $this->affiliate();
        $customer = $this->person('chinedu');
        CustomerSource::create(['user_id' => $customer->ID, 'affiliate_id' => $affiliate->id]);
        app(AffiliateService::class)->recordDeposit($this->deposit($customer));

        $this->actingAsWordPressUser();
        $this->get('/affiliate')->assertNotFound();

        $this->assertSame('Ch****u', AffiliateService::maskName('Chinedu'));
        $this->assertSame('Ch****u', app(AffiliateService::class)->dashboard($affiliate)['recent'][0]['customer']);
    }

    public function test_accounts_sharing_a_bank_phone_or_browser_are_linked(): void
    {
        $detector = app(AbuseDetector::class);
        [$a, $b, $c] = [$this->person('a'), $this->person('b'), $this->person('c')];

        $this->assertNull($detector->linkBetween($a->ID, $b->ID));

        BankAccount::create(['user_id' => $a->ID, 'bank_name' => 'GTB', 'account_number' => '0123456789', 'account_name' => 'A']);
        BankAccount::create(['user_id' => $b->ID, 'bank_name' => 'GTB', 'account_number' => '0123456789', 'account_name' => 'A']);
        $this->assertStringContainsString('bank account ••••••6789', $detector->linkBetween($a->ID, $b->ID));

        WpUserMeta::create(['user_id' => $a->ID, 'meta_key' => 'phone', 'meta_value' => '0801 234 5678']);
        WpUserMeta::create(['user_id' => $c->ID, 'meta_key' => 'phone', 'meta_value' => '+2348012345678']);
        $this->assertSame('Both accounts have the same phone number.', $detector->linkBetween($a->ID, $c->ID));
        $this->assertCount(1, $detector->sharedPhones());

        [$d, $e] = [$this->person('d'), $this->person('e')];
        foreach ([$d, $e] as $p) {
            UserDevice::create(['user_id' => $p->ID, 'device_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }
        $this->assertSame('Both accounts were used on the same phone or computer.', $detector->linkBetween($d->ID, $e->ID));
    }

    public function test_browsers_get_a_random_id_and_logged_in_visits_are_remembered(): void
    {
        $this->get('/raffles')->assertCookie(AbuseDetector::DEVICE_COOKIE, null, false);

        $user = $this->actingAsWordPressUser();
        $this->withUnencryptedCookie(AbuseDetector::DEVICE_COOKIE, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->get('/raffles')->assertOk();

        $this->assertDatabaseHas('user_devices', ['user_id' => $user->ID, 'device_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
    }

    public function test_a_self_referral_commission_is_held_for_staff_then_paid_or_cancelled(): void
    {
        config(['referrals.commission_rate' => 0.1]);
        $referrer = $this->person('ref');
        $friend = $this->person('friend');
        WpUserMeta::create(['user_id' => $friend->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        BankAccount::create(['user_id' => $referrer->ID, 'bank_name' => 'GTB', 'account_number' => '1111111111', 'account_name' => 'R']);
        BankAccount::create(['user_id' => $friend->ID, 'bank_name' => 'GTB', 'account_number' => '1111111111', 'account_name' => 'R']);

        $commission = app(ReferralCommissionService::class)->payCommissionForFirstDeposit($friend, 5000);

        $this->assertSame('held', $commission->status);
        $this->assertNull(Wallet::where('user_id', $referrer->ID)->value('earnings_balance'));
        $this->assertSame(0.0, app(ReferralCommissionService::class)->stats($referrer)['total_earned']);

        $this->actingAsAdministrator();
        Livewire::test(FraudWatch::class)->assertSee('Rewards held for a check')->call('releaseReferral', $commission->id);

        $this->assertSame('paid', $commission->refresh()->status);
        $this->assertEquals(500, Wallet::where('user_id', $referrer->ID)->value('earnings_balance'));
    }

    public function test_without_protection_the_commission_is_paid_as_before(): void
    {
        config(['features.abuse_detection' => false, 'referrals.commission_rate' => 0.1]);
        $referrer = $this->person('ref');
        $friend = $this->person('friend');
        WpUserMeta::create(['user_id' => $friend->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        BankAccount::create(['user_id' => $referrer->ID, 'bank_name' => 'GTB', 'account_number' => '1111111111', 'account_name' => 'R']);
        BankAccount::create(['user_id' => $friend->ID, 'bank_name' => 'GTB', 'account_number' => '1111111111', 'account_name' => 'R']);

        $this->assertSame('paid', app(ReferralCommissionService::class)->payCommissionForFirstDeposit($friend, 5000)->refresh()->status);
    }

    public function test_an_affiliate_bringing_themselves_is_put_on_hold(): void
    {
        $affiliate = $this->affiliate();
        $sock = $this->person('sock');
        CustomerSource::create(['user_id' => $sock->ID, 'affiliate_id' => $affiliate->id]);
        WpUserMeta::create(['user_id' => $affiliate->user_id, 'meta_key' => 'phone', 'meta_value' => '08099998888']);
        WpUserMeta::create(['user_id' => $sock->ID, 'meta_key' => 'phone', 'meta_value' => '08099998888']);

        $earning = app(AffiliateService::class)->recordDeposit($this->deposit($sock));
        $this->assertSame('on_hold', $earning->status);

        $this->travel(8)->days();
        $this->assertSame(0, app(AffiliateService::class)->releaseDue());

        $this->actingAsAdministrator();
        Livewire::test(FraudWatch::class)->call('cancelAffiliate', $earning->id);
        $this->assertSame('cancelled', AffiliateEarning::find($earning->id)->status);
    }

    public function test_staff_see_affiliates_with_an_off_label_when_switched_off(): void
    {
        $this->actingAsAdministrator();
        $this->affiliate();

        Livewire::test(ListAffiliates::class)->assertOk()->assertSee('Tobi')->assertSee(url('/go/tobi'));

        config(['features.affiliates' => false]);
        Livewire::test(ListAffiliates::class)->assertSee('Switched OFF');
    }
}
