<?php

namespace Tests\Feature;

use App\Filament\Resources\SitePageResource\Pages\EditSitePage;
use App\Filament\Resources\SitePageResource\Pages\ListSitePages;
use App\Models\AdminAuditLog;
use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\RaffleDraw;
use App\Models\SitePage;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 48 (Phase 8): Terms and About pages, sign-up
 * (18+/Terms, invited by, going back), link previews and icons, My Tickets
 * wins and links, live draws list, Hall of Fame pictures, and balances and
 * the Golden Box sent with the page (no flicker).
 */
class MissingPagesSignupSharingTest extends TestCase
{
    use ActsAsAdministrator, AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    // --- Terms and About -----------------------------------------------------

    public function test_the_terms_page_exists_with_the_company_and_age_rule(): void
    {
        config(['site.support_email' => 'help@rafflekings.com.ng']);

        $this->get('/terms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SitePage')
                ->where('title', 'Terms of Service')
                ->where('body', fn ($body) => str_contains($body, 'RKS DIGITAL INNOVATIONS')
                    && str_contains($body, 'at least 18 years old')
                    && str_contains($body, 'Federal Republic of Nigeria')
                    && str_contains($body, 'mailto:help@rafflekings.com.ng')
                    && ! str_contains($body, '{support_email_sentence}')));
    }

    public function test_without_a_support_email_the_placeholder_just_disappears(): void
    {
        config(['site.support_email' => null]);

        $this->get('/terms')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('body', fn ($body) => ! str_contains($body, '{support_email_sentence}') && ! str_contains($body, 'mailto:')));
    }

    public function test_the_about_page_exists(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SitePage')->where('title', 'About RaffleKings'));
    }

    public function test_old_addresses_go_to_the_new_pages(): void
    {
        $this->get('/toc.php')->assertRedirect('/terms');
        $this->get('/about.php')->assertRedirect('/about');
        $this->get('/games.php')->assertRedirect('/rewards/spin');
    }

    public function test_an_admin_can_edit_the_terms_and_unsafe_html_is_removed(): void
    {
        $this->actingAsAdministrator();
        $terms = SitePage::where('slug', 'terms')->first();

        Livewire::test(ListSitePages::class)->assertCanSeeTableRecords([$terms]);

        Livewire::test(EditSitePage::class, ['record' => $terms->getRouteKey()])
            ->fillForm(['title' => 'Terms of Service', 'body' => '<p>New terms</p><script>alert(1)</script>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringContainsString('New terms', $terms->fresh()->body);
        $this->assertStringNotContainsString('<script', $terms->fresh()->body);
        $this->assertTrue(AdminAuditLog::where('action', 'site_page.updated')->exists());
    }

    // --- Sign-up ----------------------------------------------------------------

    public function test_sign_up_requires_confirming_18_plus_and_the_terms(): void
    {
        $this->postJson('/api/auth/register', ['username' => 'young1', 'email' => 'y@example.com', 'password' => 'letmein1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accept_terms' => 'Please confirm you are 18 or older and accept the Terms of Service.']);

        $this->assertFalse(WpUser::where('user_login', 'young1')->exists());
    }

    public function test_sign_up_records_when_the_terms_were_accepted(): void
    {
        $this->postJson('/api/auth/register', ['username' => 'adult1', 'email' => 'a@example.com', 'password' => 'letmein1', 'accept_terms' => true])
            ->assertCreated();

        $user = WpUser::where('user_login', 'adult1')->first();
        $this->assertNotEmpty($user->metaValue('rk_terms_accepted_at'));
        $this->assertSame('18+', $user->metaValue('rk_age_confirmed'));
        $this->assertNotEmpty($user->metaValue('rk_terms_version'));
    }

    public function test_the_sign_up_page_says_who_invited_you_and_previews_the_link(): void
    {
        WpUser::create(['user_login' => 'chidi', 'user_pass' => 'x', 'user_email' => 'c@example.com', 'display_name' => 'Chidi O', 'user_registered' => now()]);

        $this->get('/register?ref=chidi')
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Chidi O invited you to', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('referrerName', 'Chidi O')->where('referralCode', 'chidi'));

        $this->get('/register?ref=nobody')->assertInertia(fn (AssertableInertia $page) => $page->where('referrerName', null));
    }

    public function test_the_sign_up_page_keeps_where_to_go_back_to(): void
    {
        $this->get('/register?redirect=%2Fraffles%2F9%2Fnumbers%3Fqty%3D2')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('redirect', '/raffles/9/numbers?qty=2'));
    }

    // --- Link previews and icons -------------------------------------------------

    public function test_every_page_has_link_preview_tags_and_a_real_icon(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSee('<meta property="og:image" content="'.url('/images/og-image.png').'"', false);
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        $response->assertSee('<link rel="icon" href="/favicon.ico"', false);

        $this->assertGreaterThan(500, filesize(public_path('favicon.ico')));
        $this->assertFileExists(public_path('images/og-image.png'));
        $this->assertFileExists(public_path('images/icon-192.png'));
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);
        $this->assertSame('/images/icon-192.png', $manifest['icons'][0]['src']);
    }

    public function test_a_shared_raffle_link_previews_with_its_own_name_and_prize(): void
    {
        $this->createRaffle(['public_id' => 9, 'title' => 'iPhone 16 Pro', 'grand_prize' => 'an iPhone 16 Pro', 'price' => '500']);

        $this->get('/raffles/9')
            ->assertSee('<meta property="og:title" content="iPhone 16 Pro | ', false)
            ->assertSee('Win an iPhone 16 Pro. Tickets from ₦500.', false);
    }

    // --- My Tickets ----------------------------------------------------------------

    public function test_my_tickets_shows_a_win_once_the_results_are_public(): void
    {
        $user = $this->actingAsWordPressUser();
        $raffle = $this->createRaffle(['public_id' => 9]);
        RaffleEntry::query()->insert(['user_id' => $user->ID, 'raffle_id' => 9, 'ticket_number' => 7, 'txn_id' => 1, 'created_at' => now()]);
        RaffleDraw::create(['raffle_id' => $raffle->id, 'server_seed' => 's', 'server_seed_hash' => 'h', 'client_seed' => 'c', 'committed_at' => now(), 'executed_at' => now()]);
        RaffleWinner::create(['raffle_id' => 9, 'user_id' => $user->ID, 'ticket_number' => 7, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 50000, 'is_credited' => true, 'is_visible' => false]);

        $group = $this->getJson('/api/account/tickets')->assertOk()->json('data.0');

        $this->assertSame($raffle->id, $group['native_id']);
        $this->assertTrue($group['draw']['results_public']);
        $this->assertSame('007', $group['wins'][0]['ticket_number']);
        $this->assertEquals(50000, $group['wins'][0]['prize_cash_value']);
        $this->assertTrue($group['wins'][0]['is_credited']);
    }

    public function test_a_win_stays_secret_until_the_live_reveal_has_finished(): void
    {
        $user = $this->actingAsWordPressUser();
        $raffle = $this->createRaffle(['public_id' => 9]);
        $raffle->update(['is_live_draw_enabled' => true, 'live_draw_status' => 'revealing']);
        RaffleEntry::query()->insert(['user_id' => $user->ID, 'raffle_id' => 9, 'ticket_number' => 7, 'txn_id' => 1, 'created_at' => now()]);
        RaffleDraw::create(['raffle_id' => $raffle->id, 'server_seed' => 's', 'server_seed_hash' => 'h', 'client_seed' => 'c', 'committed_at' => now(), 'executed_at' => now()]);
        RaffleWinner::create(['raffle_id' => 9, 'user_id' => $user->ID, 'ticket_number' => 7, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 50000, 'is_credited' => false, 'is_visible' => false]);

        $group = $this->getJson('/api/account/tickets')->json('data.0');
        $this->assertSame([], $group['wins']);
        $this->assertSame('revealing', $group['draw']['live_status']);

        $raffle->update(['live_draw_status' => 'completed']);
        $this->assertCount(1, $this->getJson('/api/account/tickets')->json('data.0.wins'));
    }

    // --- Live draws and Hall of Fame -------------------------------------------------

    public function test_the_live_draws_page_lists_live_upcoming_and_past_events(): void
    {
        $live = $this->createRaffle(['public_id' => 1, 'title' => 'Live one']);
        $live->update(['is_live_draw_enabled' => true, 'live_draw_status' => 'revealing']);
        $soon = $this->createRaffle(['public_id' => 2, 'title' => 'Soon one']);
        $soon->update(['is_live_draw_enabled' => true, 'live_draw_status' => 'idle']);
        $past = $this->createRaffle(['public_id' => 3, 'title' => 'Past one']);
        $past->update(['is_live_draw_enabled' => true, 'live_draw_status' => 'completed', 'live_draw_started_at' => now()->subDay()]);
        $this->createRaffle(['public_id' => 4, 'title' => 'Not live']);

        $this->get('/live-draws')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('LiveDraw/Index')
                ->where('live.0.title', 'Live one')
                ->where('upcoming.0.title', 'Soon one')
                ->where('past.0.title', 'Past one')
                ->has('past', 1));

        $this->get('/livedraw.php')->assertRedirect('/live-draws');
    }

    public function test_the_hall_of_fame_shows_each_winners_own_picture(): void
    {
        $withPicture = WpUser::create(['user_login' => 'ada', 'user_pass' => 'x', 'user_email' => 'ada@example.com', 'display_name' => 'Ada Lovelace', 'user_registered' => now()]);
        WpUserMeta::create(['user_id' => $withPicture->ID, 'meta_key' => 'profile_pic_url', 'meta_value' => '/storage/avatars/'.$withPicture->ID.'.jpg']);
        $without = WpUser::create(['user_login' => 'bola', 'user_pass' => 'x', 'user_email' => 'bola@example.com', 'display_name' => 'Bola', 'user_registered' => now()]);

        foreach ([$withPicture, $without] as $i => $user) {
            RaffleWinner::create(['raffle_id' => 900, 'user_id' => $user->ID, 'ticket_number' => $i + 1, 'prize_name' => 'Prize', 'prize_rank' => 1, 'prize_cash_value' => 1000, 'is_credited' => true, 'is_visible' => true]);
        }

        $avatars = collect($this->getJson('/api/hall-of-fame')->json('recent'))->pluck('avatar', 'name');

        $this->assertSame('/storage/avatars/'.$withPicture->ID.'.jpg', $avatars['Ada Lovelace']);
        $this->assertStringContainsString('dicebear.com/9.x/adventurer', $avatars['Bola']);
    }

    // --- No flicker ----------------------------------------------------------------------

    public function test_balances_are_sent_with_every_page(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1234.5, 'earnings_balance' => 50]);

        $this->get('/raffles')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.balances.wallet', 1234.5)
            ->where('auth.user.balances.earnings', 50)
            ->where('auth.user.balances.points', 0));
    }

    public function test_the_top_up_page_gets_the_minimum_and_recent_top_ups(): void
    {
        config(['payments.minimum_deposit' => 250]);
        $user = $this->actingAsWordPressUser();
        Deposit::create(['user_id' => $user->ID, 'reference' => 'dep_1', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful']);

        $this->get('/account/wallet')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('minimumDeposit', 250)
            ->where('recentTopups.0.amount', 5000)
            ->where('recentTopups.0.status', 'successful'));
    }

    public function test_the_golden_box_comes_with_the_page(): void
    {
        $this->createRaffle(['public_id' => 9, 'price' => '1000']);
        $this->actingAsWordPressUser();
        $this->get('/checkout?raffle_id=9&qty=1&numbers=7')->assertOk();

        $this->get('/raffles')->assertInertia(fn (AssertableInertia $page) => $page->where('goldenBox.raffle_id', 9));
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('goldenBox.raffle_id', 9));
    }
}
