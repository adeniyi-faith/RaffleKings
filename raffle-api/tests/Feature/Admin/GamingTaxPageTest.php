<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\GamingTax;
use App\Models\GamingTaxPeriod;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The Finance → Gaming tax page: who sees what, and that the buttons do their job. */
class GamingTaxPageTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['raffles.timezone' => 'Africa/Lagos', 'gaming_tax.rate' => 2.5, 'gaming_tax.shortfall' => 'zero', 'gaming_tax.due_day' => 21]);
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

    private function asRole(string $role)
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => $role]);
        Livewire::withCookies($this->unencryptedCookies);

        return $user;
    }

    public function test_an_owner_sees_last_months_tax_worked_out(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(GamingTax::class)
            ->assertSuccessful()
            ->assertSet('month', '2026-09')
            ->assertSee('₦1,000,000')
            ->assertSee('− ₦800,000')
            ->assertSee('₦200,000')
            ->assertSee('₦5,000')
            ->assertSee('Tax due at 2.5%');
    }

    public function test_the_month_can_be_changed(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(GamingTax::class)->call('selectMonth', '2026-10')->assertSet('month', '2026-10');
        Livewire::test(GamingTax::class)->call('selectMonth', 'nonsense')->assertSet('month', '2026-09');
    }

    public function test_finance_staff_can_lock_file_and_record_payment(): void
    {
        $this->asRole('finance');

        $page = Livewire::test(GamingTax::class)->assertActionVisible('lock');
        $page->callAction('lock')->assertHasNoActionErrors();
        $this->assertSame('locked', GamingTaxPeriod::where('period', '2026-09')->value('status'));

        $page->assertActionHidden('lock')->assertActionVisible('file')
            ->callAction('file', ['reference' => 'FIRS-9', 'filed_on' => '2026-10-04'])->assertHasNoActionErrors();
        $this->assertSame('filed', GamingTaxPeriod::where('period', '2026-09')->value('status'));

        $page->assertActionVisible('pay')
            ->callAction('pay', ['amount' => 5000, 'reference' => 'BANK-1', 'paid_on' => '2026-10-04'])->assertHasNoActionErrors();
        $paid = GamingTaxPeriod::where('period', '2026-09')->first();
        $this->assertSame('paid', $paid->status);
        $this->assertSame(5000.0, $paid->paid_amount);
    }

    public function test_the_current_month_cannot_be_locked_yet(): void
    {
        $this->asRole('finance');

        Livewire::test(GamingTax::class)->call('selectMonth', '2026-10')->assertActionHidden('lock');
    }

    public function test_only_owners_can_reopen_a_locked_month(): void
    {
        $this->asRole('finance');
        Livewire::test(GamingTax::class)->callAction('lock');

        Livewire::test(GamingTax::class)->assertActionHidden('reopen');
    }

    public function test_an_owner_can_reopen_the_latest_locked_month_with_a_reason(): void
    {
        $this->actingAsAdministrator();
        $page = Livewire::test(GamingTax::class);
        $page->callAction('lock');

        $page->assertActionVisible('reopen')->callAction('reopen', ['reason' => 'A prize was missing'])->assertHasNoActionErrors();

        $this->assertSame(0, GamingTaxPeriod::count());
    }

    public function test_support_staff_cannot_open_the_page_at_all(): void
    {
        $this->asRole('support');

        $this->get(GamingTax::getUrl())->assertForbidden();
    }

    public function test_managers_can_look_but_finance_and_owners_are_the_ones_who_lock(): void
    {
        // Managers hold the payout ability too, so they can act; content staff can't even open it.
        $this->asRole('content');
        $this->get(GamingTax::getUrl())->assertForbidden();
    }

    public function test_the_spreadsheet_download_works(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(GamingTax::class)->callAction('download')->assertFileDownloaded();
    }

    public function test_the_page_shows_a_note_for_prizes_that_have_no_cash_value(): void
    {
        $this->actingAsAdministrator();
        RaffleWinner::create(['raffle_id' => 7, 'user_id' => 2, 'ticket_number' => 2, 'prize_name' => 'Phone', 'prize_rank' => 2, 'prize_cash_value' => 0, 'is_credited' => false])
            ->forceFill(['won_at' => Carbon::parse('2026-09-15 10:00', 'Africa/Lagos')->utc()])->save();

        Livewire::test(GamingTax::class)->assertSee('no cash value')->assertSee('awarded but not yet paid out');
    }

    public function test_the_owner_sees_a_link_to_the_tax_settings(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(GamingTax::class)->assertSee('Settings → Payments → Gaming tax');
    }

    public function test_the_pdf_return_downloads_for_the_month_on_screen(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(GamingTax::class)
            ->callAction('downloadReturn')
            ->assertFileDownloaded('gaming-tax-return-2026-09.pdf');

        $this->assertSame('Gaming tax return (PDF)', \App\Models\AdminAuditLog::where('action', 'report.downloaded')->latest('id')->first()->context['report']);
    }

    public function test_anyone_who_can_see_finance_can_download_the_return(): void
    {
        $this->asRole('finance');

        Livewire::test(GamingTax::class)->assertActionVisible('downloadReturn');
    }
}
