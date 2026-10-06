<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\GamingTaxPeriod;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\WalletLedgerEntry;
use App\Services\GamingTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * The monthly gaming tax: a percentage of a month's ticket sales minus the
 * prizes won that month. These tests cover the money maths (including both
 * "prizes beat sales" rules), which sales and prizes count, month edges in
 * business time, and that a locked month never changes.
 */
class GamingTaxServiceTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['raffles.timezone' => 'Africa/Lagos', 'gaming_tax.rate' => 2.5, 'gaming_tax.shortfall' => 'zero', 'gaming_tax.due_day' => 21]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): GamingTaxService
    {
        return app(GamingTaxService::class);
    }

    /** A ticket purchase on a business-time date and hour (Lagos). */
    private function sale(float $amount, string $lagosTime, string $type = 'ticket_purchase_wallet', string $status = 'verified_final'): void
    {
        $txn = RaffleTransaction::create(['user_id' => 1, 'claimed_amount' => $amount, 'status' => $status, 'type' => $type]);
        $txn->forceFill(['created_at' => Carbon::parse($lagosTime, 'Africa/Lagos')->utc()])->save();
    }

    private function prize(float $value, string $lagosTime, bool $paid = true, int $raffleId = 7): void
    {
        $winner = RaffleWinner::create(['raffle_id' => $raffleId, 'user_id' => 1, 'ticket_number' => 1, 'prize_name' => 'Prize', 'prize_rank' => 1, 'prize_cash_value' => $value, 'is_credited' => $paid]);
        $winner->forceFill(['won_at' => Carbon::parse($lagosTime, 'Africa/Lagos')->utc()])->save();
    }

    private function refund(float $amount, string $lagosTime): void
    {
        // Written straight to the table with its date: ledger rows can't be edited afterwards.
        \Illuminate\Support\Facades\DB::table('wallet_ledger_entries')->insert(['user_id' => 1, 'balance_type' => 'wallet', 'direction' => 'credit', 'amount' => $amount, 'reason' => 'ticket_purchase_refunded', 'created_at' => Carbon::parse($lagosTime, 'Africa/Lagos')->utc()]);
    }

    private function admin(): \App\Models\Legacy\WpUser
    {
        return $this->actingAsWordPressUser();
    }

    // --- The maths ----------------------------------------------------------------

    public function test_tax_is_the_rate_times_sales_minus_prizes(): void
    {
        $r = GamingTaxService::work(1_000_000, 0, 800_000, 0, 2.5, 'zero');

        $this->assertSame(200_000.0, $r['taxable']);
        $this->assertSame(5_000.0, $r['tax_due']);
    }

    public function test_refunds_come_off_sales_before_the_prizes_do(): void
    {
        $r = GamingTaxService::work(1_000_000, 120_000, 600_000, 0, 2.5, 'zero');

        $this->assertSame(880_000.0, $r['net_sales']);
        $this->assertSame(280_000.0, $r['margin']);
        $this->assertSame(7_000.0, $r['tax_due']);
    }

    public function test_when_prizes_beat_sales_the_zero_rule_owes_nothing_and_forgets_it(): void
    {
        $r = GamingTaxService::work(500_000, 0, 800_000, 0, 2.5, 'zero');

        $this->assertSame(-300_000.0, $r['margin']);
        $this->assertSame(0.0, $r['taxable']);
        $this->assertSame(0.0, $r['tax_due']);
        $this->assertSame(0.0, $r['carried_out']);
    }

    public function test_when_prizes_beat_sales_the_carry_rule_passes_the_shortfall_on(): void
    {
        $r = GamingTaxService::work(500_000, 0, 800_000, 0, 2.5, 'carry_forward');

        $this->assertSame(0.0, $r['tax_due']);
        $this->assertSame(300_000.0, $r['carried_out']);
    }

    public function test_a_carried_shortfall_reduces_the_next_months_taxable_amount(): void
    {
        $r = GamingTaxService::work(600_000, 0, 100_000, 300_000, 2.5, 'carry_forward');

        $this->assertSame(200_000.0, $r['taxable']);
        $this->assertSame(5_000.0, $r['tax_due']);
        $this->assertSame(0.0, $r['carried_out']);
    }

    public function test_a_shortfall_bigger_than_the_next_margin_keeps_being_carried(): void
    {
        $r = GamingTaxService::work(200_000, 0, 100_000, 300_000, 2.5, 'carry_forward');

        $this->assertSame(0.0, $r['taxable']);
        $this->assertSame(200_000.0, $r['carried_out']);
    }

    public function test_tax_is_rounded_to_the_kobo(): void
    {
        $this->assertSame(1.67, GamingTaxService::work(1000, 0, 933.33, 0, 2.5, 'zero')['tax_due']);
    }

    // --- What counts -----------------------------------------------------------------

    public function test_only_ticket_purchases_that_went_through_count_as_sales(): void
    {
        $this->sale(1000, '2026-09-10 10:00');
        $this->sale(2000, '2026-09-11 10:00', 'ticket_purchase_earnings');
        $this->sale(4000, '2026-09-12 10:00', 'ticket_purchase', 'completed');
        $this->sale(9000, '2026-09-13 10:00', 'wallet_deposit');                  // a top-up, not a sale
        $this->sale(9000, '2026-09-14 10:00', 'ticket_purchase_wallet', 'pending'); // never went through

        $this->assertSame(7000.0, $this->service()->statement('2026-09')['sales']);
    }

    public function test_a_sale_belongs_to_the_month_in_business_time_not_utc(): void
    {
        $this->sale(100, '2026-08-31 23:30');   // still August in Lagos (22:30 UTC)
        $this->sale(200, '2026-09-01 00:30');   // already September in Lagos (23:30 UTC on 31 Aug)
        $this->sale(400, '2026-09-30 23:30');   // last hour of September
        $this->sale(800, '2026-10-01 00:30');   // October

        $this->assertSame(100.0, $this->service()->statement('2026-08')['sales']);
        $this->assertSame(600.0, $this->service()->statement('2026-09')['sales']);
        $this->assertSame(800.0, $this->service()->statement('2026-10')['sales']);
    }

    public function test_prizes_count_from_the_day_they_are_awarded_even_if_unpaid(): void
    {
        $this->prize(100_000, '2026-09-10 10:00', true);
        $this->prize(50_000, '2026-09-20 10:00', false);
        $this->prize(70_000, '2026-10-01 09:00', true);

        $this->assertSame(150_000.0, $this->service()->statement('2026-09')['prizes']);
    }

    public function test_refunds_are_taken_out_of_sales(): void
    {
        $this->sale(1_000_000, '2026-09-10 10:00');
        $this->refund(120_000, '2026-09-25 10:00');
        $this->prize(600_000, '2026-09-28 10:00');

        $s = $this->service()->statement('2026-09');

        $this->assertSame(120_000.0, $s['refunds']);
        $this->assertSame(880_000.0, $s['net_sales']);
        $this->assertSame(7_000.0, $s['tax_due']);
    }

    public function test_the_by_raffle_table_lists_sales_and_prizes_per_raffle(): void
    {
        $this->prize(300_000, '2026-09-10 10:00', true, 12);

        $rows = $this->service()->statement('2026-09')['by_raffle'];

        $this->assertSame(300_000.0, $rows[0]['prizes']);
    }

    // --- Locking ---------------------------------------------------------------------

    public function test_a_locked_month_keeps_its_figures_rate_and_rule_whatever_changes_later(): void
    {
        $admin = $this->admin();
        $this->sale(1_000_000, '2026-09-10 10:00');
        $this->prize(800_000, '2026-09-11 10:00');

        $this->service()->lock($admin, '2026-09');

        // Later: a late sale is recorded, a prize is added, and the settings change.
        $this->sale(500_000, '2026-09-12 10:00');
        $this->prize(100_000, '2026-09-13 10:00');
        config(['gaming_tax.rate' => 10.0, 'gaming_tax.shortfall' => 'carry_forward']);

        $s = $this->service()->statement('2026-09');

        $this->assertSame('locked', $s['status']);
        $this->assertSame(1_000_000.0, $s['sales']);
        $this->assertSame(800_000.0, $s['prizes']);
        $this->assertSame(2.5, $s['rate']);
        $this->assertSame('zero', $s['shortfall_rule']);
        $this->assertSame(5_000.0, $s['tax_due']);
    }

    public function test_locking_is_written_to_the_audit_log(): void
    {
        $admin = $this->admin();
        $this->sale(1_000_000, '2026-09-10 10:00');
        $this->prize(800_000, '2026-09-11 10:00');

        $this->service()->lock($admin, '2026-09');

        $entry = AdminAuditLog::where('action', 'gaming_tax.locked')->first();
        $this->assertSame('2026-09', $entry->context['period']);
        $this->assertSame(5_000.0, (float) $entry->context['tax_due']);
    }

    public function test_a_month_cannot_be_locked_before_it_has_ended(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('once it has ended');
        $this->service()->lock($this->admin(), '2026-10');
    }

    public function test_months_are_locked_in_order(): void
    {
        $admin = $this->admin();
        $this->service()->lock($admin, '2026-07');

        $this->assertStringContainsString('earlier months first', (string) $this->service()->whyCannotLock('2026-09'));
        $this->assertNull($this->service()->whyCannotLock('2026-08'));
    }

    public function test_a_month_before_an_already_locked_one_cannot_be_locked_late(): void
    {
        $admin = $this->admin();
        $this->service()->lock($admin, '2026-08');
        $this->service()->lock($admin, '2026-09');

        $this->assertStringContainsString('already locked', (string) $this->service()->whyCannotLock('2026-07'));
    }

    public function test_a_locked_month_cannot_be_locked_twice(): void
    {
        $admin = $this->admin();
        $this->service()->lock($admin, '2026-09');

        $this->expectException(RuntimeException::class);
        $this->service()->lock($admin, '2026-09');
    }

    // --- Carrying a shortfall across real months -------------------------------------------

    public function test_the_carry_rule_works_across_two_locked_months(): void
    {
        config(['gaming_tax.shortfall' => 'carry_forward']);
        $admin = $this->admin();
        $this->sale(100_000, '2026-08-10 10:00');
        $this->prize(400_000, '2026-08-11 10:00');
        $this->sale(600_000, '2026-09-10 10:00');
        $this->prize(100_000, '2026-09-11 10:00');

        $aug = $this->service()->lock($admin, '2026-08');
        $sep = $this->service()->lock($admin, '2026-09');

        $this->assertSame(0.0, $aug->tax_due);
        $this->assertSame(300_000.0, $aug->carried_out);
        $this->assertSame(300_000.0, $sep->carried_in);
        $this->assertSame(200_000.0, $sep->taxable);
        $this->assertSame(5_000.0, $sep->tax_due);
    }

    public function test_the_zero_rule_forgets_the_shortfall(): void
    {
        $admin = $this->admin();
        $this->sale(100_000, '2026-08-10 10:00');
        $this->prize(400_000, '2026-08-11 10:00');
        $this->sale(600_000, '2026-09-10 10:00');
        $this->prize(100_000, '2026-09-11 10:00');

        $this->service()->lock($admin, '2026-08');
        $sep = $this->service()->lock($admin, '2026-09');

        $this->assertSame(0.0, $sep->carried_in);
        $this->assertSame(500_000.0, $sep->taxable);
        $this->assertSame(12_500.0, $sep->tax_due);
    }

    // --- Reopen, file, pay ---------------------------------------------------------------------

    public function test_the_last_locked_month_can_be_reopened_with_a_reason(): void
    {
        $admin = $this->admin();
        $this->service()->lock($admin, '2026-09');

        $this->service()->reopen($admin, '2026-09', 'A prize was missing');

        $this->assertSame(0, GamingTaxPeriod::count());
        $this->assertSame('A prize was missing', AdminAuditLog::where('action', 'gaming_tax.reopened')->first()->context['reason']);
    }

    public function test_reopening_needs_a_reason_and_is_refused_once_filed_or_when_a_later_month_is_locked(): void
    {
        $admin = $this->admin();
        $this->service()->lock($admin, '2026-08');
        $this->service()->lock($admin, '2026-09');

        foreach ([['2026-09', ''], ['2026-08', 'later month is locked']] as [$period, $reason]) {
            try {
                $this->service()->reopen($admin, $period, $reason);
                $this->fail('Should have been refused.');
            } catch (RuntimeException) {
                $this->assertSame(2, GamingTaxPeriod::count());
            }
        }

        $this->service()->markFiled($admin, '2026-09', 'REF-1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('filed');
        $this->service()->reopen($admin, '2026-09', 'too late');
    }

    public function test_a_month_goes_locked_then_filed_then_paid_in_that_order_only(): void
    {
        $admin = $this->admin();
        $this->sale(1_000_000, '2026-09-10 10:00');
        $this->prize(800_000, '2026-09-11 10:00');

        try {
            $this->service()->markFiled($admin, '2026-09', 'REF');
            $this->fail('Filing an unlocked month must be refused.');
        } catch (RuntimeException) {
        }

        $this->service()->lock($admin, '2026-09');

        try {
            $this->service()->recordPayment($admin, '2026-09', 5000, 'PAY');
            $this->fail('Paying an unfiled month must be refused.');
        } catch (RuntimeException) {
        }

        $filed = $this->service()->markFiled($admin, '2026-09', 'FIRS-2026-09');
        $this->assertSame('filed', $filed->status);

        $paid = $this->service()->recordPayment($admin, '2026-09', 5000, 'BANK-991');
        $this->assertSame('paid', $paid->status);
        $this->assertSame(5000.0, $paid->paid_amount);
        $this->assertNotNull(AdminAuditLog::where('action', 'gaming_tax.paid')->first());
    }

    // --- Attention list, months, dates, export --------------------------------------------------

    public function test_the_attention_list_flags_unpaid_prizes_prizes_without_a_value_and_refunds(): void
    {
        $this->prize(45_000, '2026-09-10 10:00', false);
        $this->prize(0, '2026-09-12 10:00', true);
        $this->refund(120_000, '2026-09-20 10:00');

        $text = implode(' ', $this->service()->attention('2026-09'));

        $this->assertStringContainsString('awarded but not yet paid out', $text);
        $this->assertStringContainsString('no cash value', $text);
        $this->assertStringContainsString('refunded', $text);
    }

    public function test_months_run_from_the_first_sale_to_the_current_month(): void
    {
        $this->sale(1000, '2026-07-15 10:00');

        $this->assertSame(['2026-10', '2026-09', '2026-08', '2026-07'], $this->service()->months());
    }

    public function test_the_due_date_follows_the_setting(): void
    {
        $this->assertSame('2026-10-21', $this->service()->dueDate('2026-09')->format('Y-m-d'));
        config(['gaming_tax.due_day' => 10]);
        $this->assertSame('2026-10-10', $this->service()->dueDate('2026-09')->format('Y-m-d'));
        $this->assertSame('2027-01-10', $this->service()->dueDate('2026-12')->format('Y-m-d'));
    }

    public function test_the_spreadsheet_has_a_row_per_month_with_the_locked_details(): void
    {
        $admin = $this->admin();
        $this->sale(1_000_000, '2026-09-10 10:00');
        $this->prize(800_000, '2026-09-11 10:00');
        $this->service()->lock($admin, '2026-09');
        $this->service()->markFiled($admin, '2026-09', 'FIRS-1');

        $export = $this->service()->exportRows('2026-09');

        $this->assertCount(count($export['headings']), $export['rows'][0]);
        $this->assertSame('2026-09', $export['rows'][0][0]);
        $this->assertSame('Filed', $export['rows'][0][1]);
        $this->assertSame(5_000.0, $export['rows'][0][11]);
        $this->assertSame('FIRS-1', $export['rows'][0][15]);
    }

    public function test_a_badly_formed_month_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->statement('September');
    }

    public function test_the_settings_page_knows_the_three_tax_settings(): void
    {
        $keys = collect(\App\Settings\SettingsRegistry::tabs())->flatMap(fn ($tab) => collect($tab['sections'])->flatMap(fn ($s) => collect($s['settings'])->map->key))->all();

        $this->assertContains('gaming_tax.rate', $keys);
        $this->assertContains('gaming_tax.shortfall', $keys);
        $this->assertContains('gaming_tax.due_day', $keys);
    }
}
