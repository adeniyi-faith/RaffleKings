<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Services\GamingTaxReturnPdf;
use App\Services\GamingTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/** The printable gaming tax return (PDF): right figures, draft marking, and a reference for locked months. */
class GamingTaxReturnPdfTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'raffles.timezone' => 'Africa/Lagos', 'gaming_tax.rate' => 2.5, 'gaming_tax.shortfall' => 'zero', 'gaming_tax.due_day' => 21,
            'gaming_tax.business_name' => 'RaffleKings Limited', 'gaming_tax.tax_id' => '1234567-0001',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Africa/Lagos'));

        $sale = RaffleTransaction::create(['user_id' => 1, 'claimed_amount' => 1_000_000, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        $sale->forceFill(['created_at' => Carbon::parse('2026-09-10 10:00', 'Africa/Lagos')->utc()])->save();
        $win = RaffleWinner::create(['raffle_id' => 7, 'user_id' => 1, 'ticket_number' => 1, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 800_000, 'is_credited' => true]);
        $win->forceFill(['won_at' => Carbon::parse('2026-09-11 10:00', 'Africa/Lagos')->utc()])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pdf(): GamingTaxReturnPdf
    {
        return app(GamingTaxReturnPdf::class);
    }

    public function test_the_return_is_a_real_pdf_with_a_sensible_name(): void
    {
        $bytes = $this->pdf()->render('2026-09');

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(2000, strlen($bytes));
        $this->assertSame('gaming-tax-return-2026-09.pdf', $this->pdf()->filename('2026-09'));

        if ($path = getenv('PDF_SAMPLE_PATH')) {
            file_put_contents($path, $bytes);
        }
    }

    public function test_the_page_shows_the_business_the_working_and_the_tax(): void
    {
        $html = $this->pdf()->html('2026-09');

        $this->assertStringContainsString('RaffleKings Limited', $html);
        $this->assertStringContainsString('Tax ID: 1234567-0001', $html);
        $this->assertStringContainsString('September 2026', $html);
        $this->assertStringContainsString('₦1,000,000.00', $html);
        $this->assertStringContainsString('-₦800,000.00', $html);
        $this->assertStringContainsString('₦200,000.00', $html);
        $this->assertStringContainsString('2.5%', $html);
        $this->assertStringContainsString('₦5,000.00', $html);
        $this->assertStringContainsString('21 October 2026', $html);
    }

    public function test_an_unlocked_month_is_marked_draft_and_has_no_reference(): void
    {
        $html = $this->pdf()->html('2026-09');

        $this->assertStringContainsString('DRAFT. This month is not locked', $html);
        $this->assertStringContainsString('DRAFT (NOT LOCKED)', $html);
        $this->assertStringNotContainsString('Reference ', explode('Filing and payment', $html)[0]);
    }

    public function test_a_locked_month_is_not_draft_and_carries_a_stable_reference(): void
    {
        $admin = $this->actingAsWordPressUser();
        app(GamingTaxService::class)->lock($admin, '2026-09');

        $html = $this->pdf()->html('2026-09');
        $record = \App\Models\GamingTaxPeriod::where('period', '2026-09')->first();

        $this->assertStringNotContainsString('DRAFT. This month', $html);
        $this->assertStringContainsString('LOCKED', $html);
        $this->assertMatchesRegularExpression('/Reference [A-F0-9]{12}/', $html);
        $this->assertSame($this->pdf()->fingerprint($record), $this->pdf()->fingerprint($record));

        // Change one locked figure and the reference changes.
        $before = $this->pdf()->fingerprint($record);
        $record->tax_due = 9999;
        $this->assertNotSame($before, $this->pdf()->fingerprint($record));
    }

    public function test_filing_and_payment_details_appear_once_recorded(): void
    {
        $admin = $this->actingAsWordPressUser();
        $service = app(GamingTaxService::class);
        $service->lock($admin, '2026-09');
        $service->markFiled($admin, '2026-09', 'FIRS-2026-09', Carbon::parse('2026-10-04'));
        $service->recordPayment($admin, '2026-09', 5000, 'BANK-991', Carbon::parse('2026-10-04'));

        $html = $this->pdf()->html('2026-09');

        $this->assertStringContainsString('FIRS-2026-09', $html);
        $this->assertStringContainsString('BANK-991', $html);
        $this->assertStringContainsString('PAID', $html);
    }

    public function test_it_falls_back_to_the_site_name_without_a_business_name(): void
    {
        config(['gaming_tax.business_name' => '', 'gaming_tax.tax_id' => '', 'app.name' => 'RaffleKings']);

        $html = $this->pdf()->html('2026-09');

        $this->assertStringContainsString('RaffleKings', $html);
        $this->assertStringNotContainsString('Tax ID:', $html);
    }

    public function test_a_carried_in_shortfall_and_a_carried_out_shortfall_show_as_lines(): void
    {
        config(['gaming_tax.shortfall' => 'carry_forward']);
        $admin = $this->actingAsWordPressUser();
        $aug = RaffleTransaction::create(['user_id' => 1, 'claimed_amount' => 100_000, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        $aug->forceFill(['created_at' => Carbon::parse('2026-08-10 10:00', 'Africa/Lagos')->utc()])->save();
        $w = RaffleWinner::create(['raffle_id' => 7, 'user_id' => 1, 'ticket_number' => 2, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 400_000, 'is_credited' => true]);
        $w->forceFill(['won_at' => Carbon::parse('2026-08-11 10:00', 'Africa/Lagos')->utc()])->save();

        app(GamingTaxService::class)->lock($admin, '2026-08');

        $this->assertStringContainsString('Shortfall carried into the next month', $this->pdf()->html('2026-08'));
        $this->assertStringContainsString('shortfall brought in from earlier months', $this->pdf()->html('2026-09'));
    }
}
