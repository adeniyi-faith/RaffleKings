<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Filament\Pages\MoneyReports as MoneyReportsPage;
use App\Models\BankAccount;
use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Reports\MoneyReports;
use App\Services\Reports\ReportExporter;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/** Finance → Money reports. Every figure below is worked out by hand from the rows created in each test. */
class MoneyReportsTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private function customer(string $login = 'ada'): WpUser
    {
        $user = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com", 'display_name' => ucfirst($login)]);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

        return $user;
    }

    /** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} the last 30 business days */
    private function days(): array
    {
        return ReportExporter::range(now(config('raffles.timezone'))->subDays(29)->toDateString(), now(config('raffles.timezone'))->toDateString());
    }

    private function sale(WpUser $user, $raffle, array $tickets, float $paid, ?string $when = null): void
    {
        $txn = RaffleTransaction::create(['user_id' => $user->ID, 'claimed_amount' => $paid, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        if ($when) {
            $txn->forceFill(['created_at' => $when])->save();
        }
        foreach ($tickets as $n) {
            RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => $n, 'txn_id' => $txn->id]);
        }
    }

    private function credit(WpUser $user, float $amount, string $reason): void
    {
        app(WalletLedgerService::class)->credit($user->ID, 'wallet', $amount, $reason, $reason.':'.uniqid(), 'gateway_clearing');
    }

    private function withdrawal(WpUser $user, string $status, float $asked, float $fee): WithdrawalRequest
    {
        $account = BankAccount::firstOrCreate(['user_id' => $user->ID], ['bank_name' => 'UBA', 'account_number' => '2114907747', 'account_name' => 'ADA']);

        return WithdrawalRequest::create(['user_id' => $user->ID, 'bank_account_id' => $account->id, 'requested_amount' => $asked, 'fee_amount' => $fee, 'amount_to_send' => $asked - $fee, 'status' => $status]);
    }

    public function test_the_summary_adds_up_and_the_site_kept_figure_is_sales_minus_what_was_given_away(): void
    {
        $ada = $this->customer();
        $raffle = $this->createRaffle();
        $this->sale($ada, $raffle, [1, 2], 1000);
        $this->sale($ada, $raffle, [3], 500);
        $this->credit($ada, 5000, 'deposit');
        $this->credit($ada, 300, 'prize_payout');
        $this->credit($ada, 200, 'ticket_purchase_refunded');
        $this->credit($ada, 100, 'signup_bonus');
        $this->credit($ada, 50, 'promo_bonus');
        $this->credit($ada, 75, 'affiliate_commission');
        $this->withdrawal($ada, 'paid', 2000, 100);
        $this->withdrawal($ada, 'pending', 900, 0);

        $s = app(MoneyReports::class)->summary(...$this->days());

        $this->assertSame(1500.0, $s['sales']);
        $this->assertSame(5000.0, $s['topups']);
        $this->assertSame(1900.0, $s['withdrawals'], 'only paid withdrawals, and what was actually sent');
        $this->assertSame(100.0, $s['withdrawal_fees']);
        $this->assertSame(300.0, $s['prizes']);
        $this->assertSame(200.0, $s['refunds']);
        $this->assertSame(150.0, $s['bonuses']);
        $this->assertSame(75.0, $s['commissions']);
        $this->assertSame(225.0, $s['given']);
        $this->assertSame(775.0, $s['kept'], '1500 sales - 300 prizes - 200 refunds - 150 bonuses - 75 commissions');
    }

    public function test_only_the_chosen_days_count_and_the_days_before_are_the_same_length(): void
    {
        $ada = $this->customer();
        $raffle = $this->createRaffle();
        $this->sale($ada, $raffle, [1], 400, now()->subDays(2)->toDateTimeString());
        $this->sale($ada, $raffle, [2], 700, now()->subDays(20)->toDateTimeString());

        $from = now()->subDays(6)->startOfDay();
        $to = now();
        [$prevFrom, $prevTo] = MoneyReports::previous($from, $to);

        $this->assertSame(400.0, app(MoneyReports::class)->summary($from, $to)['sales']);
        $this->assertEqualsWithDelta($from->diffInSeconds($to), $prevFrom->diffInSeconds($prevTo), 1);
        $this->assertTrue($prevTo->lt($from));
        $this->assertSame(0.0, app(MoneyReports::class)->summary($prevFrom, $prevTo)['sales']);
    }

    public function test_the_overview_splits_by_day_week_and_month_in_business_time(): void
    {
        $ada = $this->customer();
        $raffle = $this->createRaffle();
        $this->travelTo(now(config('raffles.timezone'))->startOfMonth()->addDays(3)->setTime(12, 0)->utc());
        $this->sale($ada, $raffle, [1], 100);
        $this->sale($ada, $raffle, [2], 250);
        $this->travel(1)->days();
        $this->sale($ada, $raffle, [3], 50);
        $this->credit($ada, 1000, 'deposit');

        $from = now()->subDays(10);
        $to = now()->addDay();
        $daily = app(MoneyReports::class)->overview($from, $to, 'day');

        $this->assertCount(2, $daily['rows']);
        $this->assertSame(350.0, $daily['rows'][0][1]);
        $this->assertSame(50.0, $daily['rows'][1][1]);
        $this->assertSame(1000.0, $daily['rows'][1][2]);
        $this->assertSame(['Total', 400.0, 1000.0, 0.0, 0.0, 0.0, 0.0, 400.0], $daily['totals']);

        $monthly = app(MoneyReports::class)->overview($from, $to, 'month');
        $this->assertCount(1, $monthly['rows']);
        $this->assertSame(400.0, $monthly['rows'][0][1]);
    }

    public function test_a_late_evening_sale_belongs_to_the_business_day_not_the_server_day(): void
    {
        $ada = $this->customer();
        $raffle = $this->createRaffle();
        // 23:30 UTC is 00:30 the next day in Lagos.
        $this->sale($ada, $raffle, [1], 100, '2026-03-10 23:30:00');

        $rows = app(MoneyReports::class)->overview(now()->setDate(2026, 3, 9)->startOfDay(), now()->setDate(2026, 3, 12)->endOfDay(), 'day')['rows'];

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Wed 11 Mar 2026', $rows[0][0]);
    }

    public function test_a_too_long_range_is_refused_for_the_split(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MoneyReports::class)->overview(now()->subDays(500), now(), 'day');
    }

    public function test_each_raffle_shows_sales_prizes_refunds_and_what_it_earned(): void
    {
        $ada = $this->customer();
        $big = $this->createRaffle(['title' => 'Car', 'public_id' => 501]);
        $small = $this->createRaffle(['title' => 'Phone', 'public_id' => 502]);
        $this->sale($ada, $big, [1, 2, 3], 3000);
        $this->sale($ada, $small, [1], 500);
        RaffleWinner::create(['raffle_id' => $big->public_id, 'user_id' => $ada->ID, 'ticket_number' => 2, 'prize_name' => 'Car', 'prize_cash_value' => 1800]);
        $small->forceFill(['cancelled_at' => now(), 'refunded_total' => 500])->save();

        $report = app(MoneyReports::class)->raffles(...$this->days());

        $this->assertSame(['Car', 'Published', 3, 3000.0, 1800.0, 0.0, 1200.0], $report['rows'][0]);
        $this->assertSame(['Phone', 'Cancelled', 1, 500.0, 0.0, 500.0, 0.0], $report['rows'][1]);
        $this->assertSame(['Total', '', 4, 3500.0, 1800.0, 500.0, 1200.0], $report['totals']);
    }

    public function test_topups_show_each_provider_and_the_rest_as_other(): void
    {
        $ada = $this->customer();
        foreach ([['paystack', 'successful', 2000], ['paystack', 'successful', 1000], ['paystack', 'pending', 500], ['paystack', 'amount_mismatch', 700], ['flutterwave', 'successful', 400]] as $i => [$gateway, $status, $amount]) {
            Deposit::create(['user_id' => $ada->ID, 'reference' => "ref{$i}", 'gateway' => $gateway, 'amount' => $amount, 'currency' => 'NGN', 'status' => $status]);
        }
        $this->credit($ada, 3400, 'deposit'); // 3000 + 400 from providers, so 0 other
        $this->credit($ada, 600, 'deposit');  // an approved bank transfer

        $report = app(MoneyReports::class)->topups(...$this->days());

        $this->assertSame(['Paystack', 4, 2, 1, 50.0, 3000.0], $report['rows'][0]);
        $this->assertSame(['Flutterwave', 1, 1, 0, 100.0, 400.0], $report['rows'][1]);
        $this->assertSame(['Other (bank transfers, manual)', '', '', '', '', 600.0], $report['rows'][2]);
        $this->assertSame(['Total', 5, 3, 1, 60.0, 4000.0], $report['totals']);
    }

    public function test_withdrawals_are_counted_by_where_they_stand(): void
    {
        $ada = $this->customer();
        $this->withdrawal($ada, 'pending', 1000, 0);
        $paid = $this->withdrawal($ada, 'paid', 2000, 100);
        $paid->forceFill(['created_at' => now()->subHours(3), 'updated_at' => now()->subHour()])->save();
        $this->withdrawal($ada, 'rejected', 500, 0);

        $report = app(MoneyReports::class)->withdrawals(...$this->days());

        $this->assertSame(['Waiting to be paid', 1, 1000.0, 0.0, 1000.0, ''], $report['rows'][0]);
        $this->assertSame(['Paid', 1, 2000.0, 100.0, 1900.0, 2.0], $report['rows'][1]);
        $this->assertSame(['Refused (money returned)', 1, 500.0, 0.0, 500.0, ''], $report['rows'][2]);
        $this->assertSame(['Total', 3, 3500.0, 100.0, 3400.0, ''], $report['totals']);
    }

    public function test_what_customers_hold_now(): void
    {
        $ada = $this->customer('ada');
        $bola = $this->customer('bola');
        Wallet::where('user_id', $ada->ID)->update(['wallet_balance' => 4000, 'earnings_balance' => 1000]);
        Wallet::where('user_id', $bola->ID)->update(['wallet_balance' => 500]);
        $this->withdrawal($ada, 'pending', 800, 0);

        $report = app(MoneyReports::class)->holding();

        $this->assertSame(['Spending wallets', 2, 4500.0], $report['rows'][0]);
        $this->assertSame(['Winnings (can be withdrawn)', 1, 1000.0], $report['rows'][1]);
        $this->assertSame(['Withdrawals asked for, not paid yet', 1, 800.0], $report['rows'][2]);
        $this->assertStringStartsWith('Top holder: Ada', $report['rows'][3][0]);
        $this->assertSame(5500.0, $report['totals'][2]);
    }

    public function test_the_page_shows_the_numbers_and_downloads_them_as_a_spreadsheet(): void
    {
        $this->actingAsAdministrator();
        $ada = $this->customer();
        $raffle = $this->createRaffle();
        $this->sale($ada, $raffle, [1], 1234);
        $tz = config('raffles.timezone');

        $page = Livewire::test(MoneyReportsPage::class)
            ->set('data.from', now($tz)->subDay()->toDateString())
            ->set('data.to', now($tz)->toDateString())
            ->assertSee('1,234')
            ->assertSee('What the site kept');

        $csv = $page->call('download')->effects['download']['content'] ?? '';
        $csv = base64_decode($csv, true) ?: $csv;
        $this->assertStringContainsString('Ticket sales (₦)', $csv);
        $this->assertStringContainsString('1234', $csv);

        $page->set('data.report', 'raffles')->assertSee('Test Raffle');
        $page->set('data.report', 'holding')->assertSee('Spending wallets');
        $page->set('data.report', 'overview')->set('data.from', now($tz)->toDateString())->set('data.to', now($tz)->subDays(3)->toDateString())
            ->assertSee('not after the end day');
    }

    public function test_support_staff_cannot_open_money_reports_but_finance_can(): void
    {
        $this->assertFalse(StaffRoles::canOpen('support', MoneyReportsPage::class));
        $this->assertFalse(StaffRoles::canOpen('content', MoneyReportsPage::class));
        $this->assertTrue(StaffRoles::canOpen('finance', MoneyReportsPage::class));
        $this->assertTrue(StaffRoles::canOpen('manager', MoneyReportsPage::class));
        $this->assertTrue(StaffRoles::canOpen('owner', MoneyReportsPage::class));
    }
}
