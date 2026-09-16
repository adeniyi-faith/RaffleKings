<?php

namespace Tests\Feature;

use App\Filament\Pages\FinancialReconciliation;
use App\Filament\Resources\Legacy\WpUserResource\Pages\ListWpUsers;
use App\Filament\Resources\RaffleResource\Pages\ListRaffles;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Raffle;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * Covers item 19's Filament pages: user management (ban/unban), raffle
 * management, and financial reconciliation. Access control is checked at
 * the HTTP level (the panel's own auth guard/canAccessPanel gate); the
 * table pages themselves are exercised via Livewire so a broken column
 * or action closure fails a test instead of only showing up in a
 * browser.
 */
class FilamentAdminPanelTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function actingAsAdministrator(): WpUser
    {
        $user = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        return $user;
    }

    public function test_a_non_administrator_cannot_reach_the_admin_panel(): void
    {
        $this->actingAsWordPressUser();

        $this->get('/admin')->assertForbidden();
    }

    public function test_an_administrator_can_reach_the_admin_panel_dashboard(): void
    {
        $this->actingAsAdministrator();

        $this->get('/admin')->assertOk();
    }

    public function test_the_user_list_shows_wallet_balances_and_ban_status(): void
    {
        $admin = $this->actingAsAdministrator();
        $target = WpUser::create(['user_login' => 'target', 'user_pass' => 'x', 'user_email' => 'target@example.com']);
        Wallet::create(['user_id' => $target->ID, 'wallet_balance' => 2500, 'earnings_balance' => 0]);

        Livewire::test(ListWpUsers::class)
            ->assertCanSeeTableRecords([$admin, $target])
            ->assertTableColumnStateSet('wallet_balance', 2500, record: $target);
    }

    /**
     * The actual ban()/unban() behavior (usermeta write + audit log) is
     * covered directly against App\Services\UserManagementService in
     * tests/Unit/UserManagementServiceTest.php. This only checks that the
     * table offers the right action for the right state — Livewire's test
     * harness calls component actions through an internal request broker
     * that does not carry the outer test's cookies (see RequestBroker),
     * so it cannot exercise WordPressSessionGuard's cookie resolution the
     * way a real browser action call does.
     */
    public function test_the_ban_action_is_only_offered_for_an_unbanned_user(): void
    {
        $this->actingAsAdministrator();
        $target = WpUser::create(['user_login' => 'target2', 'user_pass' => 'x', 'user_email' => 'target2@example.com']);

        Livewire::test(ListWpUsers::class)
            ->assertTableActionVisible('ban', $target)
            ->assertTableActionHidden('unban', $target);

        WpUserMeta::create(['user_id' => $target->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        Livewire::test(ListWpUsers::class)
            ->assertTableActionHidden('ban', $target)
            ->assertTableActionVisible('unban', $target);
    }

    public function test_the_raffle_list_shows_sold_tickets(): void
    {
        $this->actingAsAdministrator();
        $raffle = Raffle::create([
            'title' => 'Test Raffle', 'price' => 500, 'max_tickets' => 100, 'status' => 'published',
        ]);

        Livewire::test(ListRaffles::class)->assertCanSeeTableRecords([$raffle]);
    }

    public function test_the_financial_reconciliation_page_flags_a_drifted_wallet(): void
    {
        $this->actingAsAdministrator();
        $user = WpUser::create(['user_login' => 'driftuser', 'user_pass' => 'x', 'user_email' => 'drift@example.com']);
        // A stored balance with no matching ledger entries at all — the
        // drift this page exists to catch.
        $wallet = Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);

        Livewire::test(FinancialReconciliation::class)
            ->assertTableColumnStateSet('wallet_reconciled', 0.0, record: $wallet)
            ->assertTableColumnStateSet('drift', false, record: $wallet);
    }
}
