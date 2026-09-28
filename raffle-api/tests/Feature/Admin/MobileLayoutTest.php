<?php

namespace Tests\Feature\Admin;

use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * The admin on a phone: every table row is also a stacked card, pages
 * carry the phone stylesheet and the bottom tab bar, and the tab bar
 * shows the same "waiting" counts as the side menu.
 */
class MobileLayoutTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_every_admin_list_page_loads_with_the_phone_layout(): void
    {
        $this->actingAsAdministrator();

        foreach ([
            '/admin', '/admin/withdrawals', '/admin/bank-transfers', '/admin/payment-mismatches',
            '/admin/transactions', '/admin/raffles', '/admin/draws', '/admin/winners',
            '/admin/support-tickets', '/admin/announcements', '/admin/live-chat',
            '/admin/audit-log', '/admin/referrals', '/admin/points', '/admin/legacy/wp-users',
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('rk-tabbar', false)
                ->assertSee('_x_isOpen', false);
        }
    }

    public function test_a_withdrawal_row_shows_as_a_card_with_a_copyable_account_number(): void
    {
        $this->actingAsAdministrator();
        $customer = WpUser::create(['user_login' => 'ada', 'user_pass' => 'x', 'user_email' => 'ada@example.com', 'display_name' => 'Ada Obi']);
        $account = BankAccount::create(['user_id' => $customer->ID, 'bank_name' => 'GTBank', 'account_name' => 'Ada Obi', 'account_number' => '0123456789', 'is_primary' => true]);
        WithdrawalRequest::create(['user_id' => $customer->ID, 'bank_account_id' => $account->id, 'requested_amount' => 5000, 'fee_amount' => 1000, 'amount_to_send' => 4000, 'status' => 'pending']);

        $this->get('/admin/withdrawals')
            ->assertOk()
            ->assertSee('rk-card', false)
            ->assertSeeInOrder(['Ada Obi', '₦4,000', 'GTBank · Ada Obi', '0123456789', 'Waiting to be paid'])
            // Tab bar badge: one withdrawal waiting.
            ->assertSee('<span class="rk-tab-badge">1</span>', false);
    }
}
