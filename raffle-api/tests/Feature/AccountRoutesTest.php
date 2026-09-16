<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * The account section's pages (item 26) — My Tickets, Transactions,
 * Wallet, Withdraw, Bank Accounts — all currently do an
 * `is_user_logged_in()` + redirect in the legacy PHP
 * (my-tickets.php/transactions.php/topup.php/withdraw.php/bank-details.php).
 * Same fix as item 25's checkout-flow routes: the real `wordpress` guard
 * is checked server-side, and a guest is redirected to /login with a
 * same-origin `redirect` back to where they were, rather than the page
 * ever rendering for someone who isn't actually logged in.
 */
class AccountRoutesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public static function accountRoutes(): array
    {
        return [
            ['/account/tickets', 'Account/Tickets'],
            ['/account/transactions', 'Account/Transactions'],
            ['/account/wallet', 'Account/Wallet'],
            ['/account/withdraw', 'Account/Withdraw'],
            ['/account/bank-accounts', 'Account/BankAccounts'],
        ];
    }

    #[DataProvider('accountRoutes')]
    public function test_a_guest_is_redirected_to_login_with_a_way_back(string $path): void
    {
        $response = $this->get($path);

        $response->assertRedirect();
        $this->assertStringContainsString('/login?redirect=', $response->headers->get('Location'));
        $this->assertStringContainsString(urlencode($path), $response->headers->get('Location'));
    }

    #[DataProvider('accountRoutes')]
    public function test_a_logged_in_user_sees_the_real_page(string $path, string $component): void
    {
        $this->actingAsWordPressUser();

        $response = $this->get($path);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component($component));
    }
}
