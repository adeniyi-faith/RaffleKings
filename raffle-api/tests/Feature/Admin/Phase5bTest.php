<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Filament\Pages\Downloads;
use App\Filament\Resources\Legacy\WpUserResource\Pages\ListWpUsers;
use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Filament\Resources\Legacy\WpUserResource\RelationManagers\AdminActionsRelationManager;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\StaffResource;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TutorialResource\Pages\CreateTutorial;
use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\AdminAuditLog;
use App\Models\BankAccount;
use App\Models\CustomerMessage;
use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\SiteError;
use App\Models\Tutorial;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Notifications\BroadcastMessage;
use App\Services\DailyClaimService;
use App\Services\Messaging\Audience;
use App\Services\Messaging\BroadcastService;
use App\Services\Monitoring\ErrorAlerter;
use App\Services\Risk\FraudWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * Phase 5b (item 45b): customer profile, ticket lookup, online payments,
 * tutorials, points boost, messaging, staff roles, downloads, health and
 * fraud watch.
 */
class Phase5bTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private function customer(string $login = 'ada', array $wallet = []): WpUser
    {
        $user = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com", 'display_name' => ucfirst($login), 'user_registered' => now()->subMonth()]);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => $wallet['wallet'] ?? 0, 'earnings_balance' => $wallet['earnings'] ?? 0]);

        return $user;
    }

    private function actingAsStaff(string $role): WpUser
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => $role]);
        Livewire::withCookies($this->unencryptedCookies);

        return $user;
    }

    private function withdrawal(WpUser $user, array $attributes = []): WithdrawalRequest
    {
        $account = BankAccount::firstOrCreate(['user_id' => $user->ID, 'account_number' => $attributes['account'] ?? '0123456789'], ['bank_name' => 'GTBank', 'account_name' => $user->display_name, 'is_primary' => true]);
        unset($attributes['account']);

        return WithdrawalRequest::create([...['user_id' => $user->ID, 'bank_account_id' => $account->id, 'requested_amount' => 5000, 'fee_amount' => 0, 'amount_to_send' => 5000, 'status' => 'pending'], ...$attributes]);
    }

    // -- Customer profile --------------------------------------------------

    public function test_a_customer_profile_shows_balances_totals_and_their_history(): void
    {
        $admin = $this->actingAsAdministrator();
        $ada = $this->customer('ada', ['wallet' => 2500, 'earnings' => 700]);
        WalletLedgerEntry::create(['user_id' => $ada->ID, 'balance_type' => 'wallet', 'direction' => 'credit', 'amount' => 5000, 'reason' => 'deposit', 'created_at' => now()]);
        $withdrawal = $this->withdrawal($ada);
        AdminAuditLog::create(['admin_user_id' => $admin->ID, 'action' => 'withdrawal.paid', 'subject_type' => WithdrawalRequest::class, 'subject_id' => $withdrawal->id, 'created_at' => now()]);

        $this->get("/admin/legacy/wp-users/{$ada->ID}")
            ->assertOk()
            ->assertSee('₦2,500')
            ->assertSee('₦700')
            ->assertSee('₦5,000')
            ->assertSee('0123456789');

        Livewire::test(AdminActionsRelationManager::class, ['ownerRecord' => $ada, 'pageClass' => ViewWpUser::class])
            ->assertSee('Withdrawal marked paid');
    }

    public function test_customers_can_be_found_and_opened_from_the_list(): void
    {
        $this->actingAsAdministrator();
        $ada = $this->customer('ada');

        Livewire::test(ListWpUsers::class)->searchTable('ada@example')->assertCanSeeTableRecords([$ada]);
    }

    // -- Ticket lookup -----------------------------------------------------

    public function test_ticket_lookup_finds_the_exact_ticket_in_a_raffle(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->createRaffle();
        $ada = $this->customer('ada');
        $ticket45 = RaffleEntry::create(['user_id' => $ada->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 45, 'txn_id' => 1]);
        $ticket145 = RaffleEntry::create(['user_id' => $ada->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 145, 'txn_id' => 1]);

        Livewire::test(ListTickets::class)
            ->filterTable('raffle_id', $raffle->public_id)
            ->filterTable('ticket_number', ['number' => 45])
            ->assertCanSeeTableRecords([$ticket45])
            ->assertCanNotSeeTableRecords([$ticket145]);
    }

    // -- Online payments ---------------------------------------------------

    public function test_check_again_credits_a_payment_the_gateway_confirms(): void
    {
        $this->actingAsAdministrator();
        config(['services.paystack.secret_key' => 'sk_test_x']);
        $ada = $this->customer('ada');
        $deposit = Deposit::create(['user_id' => $ada->ID, 'reference' => 'dep_1', 'gateway' => 'paystack', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'pending']);
        Http::fake(['api.paystack.co/*' => Http::response(['data' => ['status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'id' => 9]])]);

        Livewire::test(ListPayments::class)->callTableAction('checkAgain', $deposit);

        $this->assertSame('successful', $deposit->fresh()->status);
        $this->assertEquals(5000, Wallet::where('user_id', $ada->ID)->value('wallet_balance'));
        $this->assertTrue(AdminAuditLog::where('action', 'deposit.rechecked')->exists());
    }

    public function test_the_payments_list_includes_paid_failed_and_unfinished_payments(): void
    {
        $this->actingAsAdministrator();
        $ada = $this->customer('ada');
        $rows = collect(['successful', 'failed', 'pending'])->map(fn ($s, $i) => Deposit::create(['user_id' => $ada->ID, 'reference' => "dep_{$i}", 'gateway' => 'paystack', 'amount' => 1000, 'currency' => 'NGN', 'status' => $s]));

        Livewire::test(ListPayments::class)->assertCanSeeTableRecords($rows);
    }

    // -- Tutorials ---------------------------------------------------------

    public function test_staff_can_write_a_tutorial_and_unsafe_html_is_removed(): void
    {
        $this->actingAsAdministrator();
        $old = Tutorial::create(['title' => 'Old featured', 'content' => '<p>x</p>', 'is_featured' => true]);

        Livewire::test(CreateTutorial::class)
            ->fillForm([
                'title' => 'How to withdraw',
                'content' => '<p>Step one</p><script>alert(1)</script>',
                'category' => 'Withdrawals',
                'is_published' => true,
                'is_featured' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tutorial = Tutorial::where('title', 'How to withdraw')->firstOrFail();
        $this->assertStringNotContainsString('script', $tutorial->content);
        $this->assertTrue($tutorial->is_featured);
        $this->assertFalse($old->fresh()->is_featured, 'Only one tutorial is featured at a time.');
        $this->getJson('/api/tutorials')->assertJson(['featured' => ['title' => 'How to withdraw']]);
    }

    // -- Points boost ------------------------------------------------------

    public function test_a_points_boost_multiplies_daily_claim_points_only_while_it_runs(): void
    {
        // A fixed midday in Lagos: the test moves the clock forward 2 hours, which
        // would otherwise cross Lagos midnight (and break the streak) whenever it ran late in the evening.
        $this->travelTo(now('Africa/Lagos')->setDate(2026, 9, 15)->setTime(12, 0));

        $ada = $this->customer('ada');
        config(['rewards.boost' => ['multiplier' => 2, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addHour()->toDateTimeString(), 'label' => 'Double']]);

        $this->assertSame(100, app(DailyClaimService::class)->claim($ada)['points_added']);

        $this->travel(2)->hours();
        $this->travel(1)->days();
        $this->assertSame(70, app(DailyClaimService::class)->claim($ada)['points_added'], 'Day 2, boost over.');
    }

    // -- Messaging ---------------------------------------------------------

    public function test_a_message_reaches_the_chosen_customers_inbox_and_email_but_not_banned_or_staff(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $ada = $this->customer('ada');
        WpUserMeta::create(['user_id' => $ada->ID, 'meta_key' => 'first_name', 'meta_value' => 'Ada']);
        WpUserMeta::create(['user_id' => $ada->ID, 'meta_key' => 'last_name', 'meta_value' => 'Obi']);
        $bola = $this->customer('bola');
        $banned = $this->customer('banned');
        WpUserMeta::create(['user_id' => $banned->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $broadcast = app(BroadcastService::class)->send([
            'title' => 'Hello {name}', 'body' => 'New raffle is live!', 'link_url' => '/raffles',
            'channels' => ['inbox', 'email'], 'audience' => 'everyone',
        ], $admin);

        $this->assertSame('sent', $broadcast->fresh()->status);
        $this->assertSame(2, $broadcast->fresh()->recipients_count);
        // {name} is the full name, or the username when no name is saved.
        $this->assertSame('Hello Ada Obi', CustomerMessage::where('user_id', $ada->ID)->value('title'));
        $this->assertSame('Hello bola', CustomerMessage::where('user_id', $bola->ID)->value('title'));
        $this->assertFalse(CustomerMessage::where('user_id', $banned->ID)->exists());
        $this->assertFalse(CustomerMessage::where('user_id', $admin->ID)->exists());
        Notification::assertSentTo([$ada, $bola], BroadcastMessage::class);
        Notification::assertNotSentTo($banned, BroadcastMessage::class);
    }

    public function test_audiences_pick_the_right_customers(): void
    {
        $raffle = $this->createRaffle();
        $buyer = $this->customer('buyer');
        $lapsed = $this->customer('lapsed', ['wallet' => 800, 'earnings' => 3000]);
        $never = $this->customer('never');
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 1, 'txn_id' => 1]);
        $old = RaffleEntry::create(['user_id' => $lapsed->ID, 'raffle_id' => 999, 'ticket_number' => 1, 'txn_id' => 2]);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();
        $ids = fn ($type, $opts = []) => app(Audience::class)->query($type, $opts)->pluck('ID')->all();

        $this->assertSame([$buyer->ID], $ids('raffle_buyers', ['raffle_id' => $raffle->public_id]));
        $this->assertSame([$lapsed->ID], $ids('inactive', ['days' => 30]));
        $this->assertSame([$never->ID], $ids('never_bought'));
        $this->assertSame([$lapsed->ID], $ids('unwithdrawn_winnings', ['min_amount' => 1000]));
    }

    public function test_customers_read_only_their_own_messages(): void
    {
        $ada = $this->actingAsWordPressUser();
        $other = $this->customer('other');
        $mine = CustomerMessage::create(['user_id' => $ada->ID, 'title' => 'Hi', 'body' => 'Yours', 'created_at' => now()]);
        $theirs = CustomerMessage::create(['user_id' => $other->ID, 'title' => 'Hi', 'body' => 'Not yours', 'created_at' => now()]);

        $this->getJson('/api/messages')->assertJson(['unread' => 1])->assertJsonCount(1, 'messages');
        $this->postJson("/api/messages/{$theirs->id}/read")->assertOk();
        $this->assertNull($theirs->fresh()->read_at);
        $this->postJson("/api/messages/{$mine->id}/read")->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
    }

    // -- Staff roles -------------------------------------------------------

    public function test_support_staff_see_support_but_not_payouts_or_settings(): void
    {
        $this->actingAsStaff('support');

        $this->get('/admin/support-tickets')->assertOk();
        $this->get('/admin/legacy/wp-users')->assertOk();
        $this->get('/admin/withdrawals')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_look_only_staff_do_not_get_balance_buttons(): void
    {
        $this->actingAsStaff('support');
        $ada = $this->customer('ada');

        Livewire::test(ListWpUsers::class)->assertTableActionHidden('adjustBalance', $ada);
    }

    public function test_finance_staff_can_pay_withdrawals(): void
    {
        $this->actingAsStaff('finance');
        $withdrawal = $this->withdrawal($this->customer('ada'));

        Livewire::test(ListWithdrawalRequests::class)->assertTableActionVisible('markPaid', $withdrawal);
        $this->get('/admin/tutorials')->assertForbidden();
    }

    public function test_a_customer_with_no_role_cannot_open_the_admin(): void
    {
        $this->actingAsWordPressUser();

        $this->get('/admin')->assertForbidden();
    }

    public function test_an_owner_can_give_roles_but_not_change_their_own(): void
    {
        $owner = $this->actingAsAdministrator();
        $ada = $this->customer('ada');

        StaffResource::setRole($ada, 'support', $owner);
        $this->assertSame('support', WpUser::find($ada->ID)->staffRole());
        $this->assertTrue(AdminAuditLog::where('action', 'staff.role_changed')->exists());

        $this->expectExceptionMessage('own role');
        StaffResource::setRole($owner, 'support', $owner);
    }

    public function test_the_last_owner_cannot_be_downgraded(): void
    {
        $onlyOwner = $this->actingAsAdministrator();
        $manager = $this->customer('manager');
        WpUserMeta::create(['user_id' => $manager->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => 'manager']);

        $this->expectExceptionMessage('only owner');
        StaffResource::setRole($onlyOwner, 'support', $manager);
    }

    public function test_setting_a_role_to_no_access_removes_admin_access(): void
    {
        $owner = $this->actingAsAdministrator();
        $other = $this->customer('other');
        WpUserMeta::create(['user_id' => $other->ID, 'meta_key' => config('legacy.wp_prefix').'capabilities', 'meta_value' => serialize(['administrator' => true])]);
        $this->assertSame('owner', WpUser::find($other->ID)->staffRole());

        StaffResource::setRole($other, StaffRoles::NO_ACCESS, $owner);

        $this->assertNull(WpUser::find($other->ID)->staffRole());
    }

    // -- Downloads ---------------------------------------------------------

    public function test_the_sales_spreadsheet_lists_purchases_and_defuses_formulas(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->createRaffle(['title' => 'Cash Jackpot']);
        $evil = $this->customer('evil');
        $evil->update(['display_name' => '=HYPERLINK("http://x")']);
        $txn = RaffleTransaction::create(['user_id' => $evil->ID, 'claimed_amount' => 300, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'order_id' => 'RK-9']);
        RaffleEntry::create(['user_id' => $evil->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 7, 'txn_id' => $txn->id]);

        $response = Livewire::test(Downloads::class)
            ->set('data.report', 'sales')
            // Dates are the business's (Lagos) days, which are a day ahead of UTC late in the evening.
            ->set('data.from', now(config('raffles.timezone'))->subDay()->toDateString())
            ->set('data.to', now(config('raffles.timezone'))->toDateString())
            ->call('download');

        $csv = $response->effects['download']['content'] ?? '';
        $csv = base64_decode($csv, true) ?: $csv;
        $this->assertStringContainsString('Cash Jackpot', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // -- Health ------------------------------------------------------------

    public function test_server_errors_are_counted_for_the_health_page(): void
    {
        $alerter = app(ErrorAlerter::class);
        foreach ([1, 2] as $time) {
            $alerter->exception(new RuntimeException('Payout report broke')); // same place twice
        }

        $this->assertSame(1, SiteError::count());
        $this->assertSame(2, SiteError::first()->occurrences);

        $this->actingAsAdministrator();
        $this->get('/admin/health')->assertOk()->assertSee('Payout report broke');
    }

    // -- Fraud watch -------------------------------------------------------

    public function test_fraud_watch_flags_shared_bank_accounts_rapid_topups_and_quick_cashouts(): void
    {
        $ada = $this->customer('ada');
        $twin = $this->customer('twin');
        BankAccount::create(['user_id' => $ada->ID, 'bank_name' => 'GTBank', 'account_number' => '5555555555', 'account_name' => 'A', 'is_primary' => true]);
        BankAccount::create(['user_id' => $twin->ID, 'bank_name' => 'GTBank', 'account_number' => '5555555555', 'account_name' => 'A', 'is_primary' => true]);
        foreach ([0, 5, 10] as $minutes) {
            Deposit::create(['user_id' => $ada->ID, 'reference' => "r{$minutes}", 'gateway' => 'paystack', 'amount' => 2000, 'currency' => 'NGN', 'status' => 'successful'])
                ->forceFill(['created_at' => now()->subHour()->addMinutes($minutes)])->save();
        }
        WalletLedgerEntry::create(['user_id' => $ada->ID, 'balance_type' => 'wallet', 'direction' => 'credit', 'amount' => 6000, 'reason' => 'deposit', 'created_at' => now()->subMinutes(40)]);
        $withdrawal = $this->withdrawal($ada, ['account' => '5555555555']);

        $types = collect(app(FraudWatchService::class)->flagsFor($ada))->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['shared_bank', 'rapid_topups', 'quick_cashout'], $types);
        $this->assertArrayHasKey($withdrawal->id, app(FraudWatchService::class)->pendingWithdrawalWarnings());

        $this->actingAsAdministrator();
        $this->get('/admin/fraud-watch')->assertOk()->assertSee('5555555555');
    }
}
