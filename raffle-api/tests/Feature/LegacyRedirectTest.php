<?php

namespace Tests\Feature;

use App\Http\Controllers\LegacyRedirectController;
use App\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 42 — old-site links (bookmarks, WhatsApp
 * shares, search results, referral links) land on the right new page.
 */
class LegacyRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_links_keep_the_referral_code(): void
    {
        $this->get('/register.php?ref=musa_wins')->assertRedirect('/register?ref=musa_wins')->assertStatus(301);
        $this->get('/register-special.php?ref=musa_wins')->assertRedirect('/register?ref=musa_wins');
    }

    public function test_the_homepage_referral_form_goes_to_registration(): void
    {
        $this->get('/?ref=musa_wins')->assertRedirect('/register?ref=musa_wins');
    }

    public function test_the_plain_homepage_is_untouched(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_a_specific_raffle_link_goes_to_that_raffle(): void
    {
        $this->get('/raffle-details.php?id=42')->assertRedirect('/raffles/42');
        $this->get('/raffle-details?id=42')->assertRedirect('/raffles/42');
    }

    public function test_a_raffle_link_with_a_bad_id_goes_to_the_raffle_list(): void
    {
        $this->get('/raffle-details.php?id=abc')->assertRedirect('/raffles');
    }

    public function test_old_live_draw_links_are_translated_to_the_native_raffle_id(): void
    {
        $raffle = Raffle::create(['title' => 'Jackpot', 'price' => 100, 'max_tickets' => 10, 'legacy_post_id' => 555]);

        $this->get('/livedraw.php?id=555')->assertRedirect("/raffles/{$raffle->id}/live-draw");
        $this->get('/livedraw.php?id=999')->assertRedirect('/live-draws');
    }

    public function test_both_old_address_forms_redirect(): void
    {
        $this->get('/winners.php')->assertRedirect('/hall-of-fame');
        $this->get('/winners')->assertRedirect('/hall-of-fame');
        $this->get('/topup')->assertRedirect('/account/wallet');
        $this->get('/my-tickets.php')->assertRedirect('/account/tickets');
    }

    public function test_unknown_query_values_are_not_passed_along(): void
    {
        $this->get('/login.php?redirect=https://evil.example')->assertRedirect('/login');
    }

    public function test_new_pages_with_the_same_name_are_never_redirected(): void
    {
        foreach (LegacyRedirectController::ALREADY_NEW_PAGES as $page) {
            $this->assertFalse(
                $this->get("/{$page}")->isRedirect(url('/'.$page.'.php')),
                "/{$page} must stay the new page",
            );
        }

        $this->get('/raffles')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
    }

    public function test_every_mapped_old_page_redirects(): void
    {
        foreach (array_keys(LegacyRedirectController::MAP) as $page) {
            $this->assertSame(301, $this->get("/{$page}.php")->getStatusCode(), "{$page}.php should redirect");
        }
    }
}
