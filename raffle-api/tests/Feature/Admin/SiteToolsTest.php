<?php

namespace Tests\Feature\Admin;

use App\Events\LiveDrawCommentHidden;
use App\Filament\Resources\AdminAuditLogResource\Pages\ListAdminAuditLogs;
use App\Filament\Resources\LiveChatResource\Pages\ListLiveChat;
use App\Filament\Resources\RaffleResource\Pages\EditRaffle;
use App\Filament\Resources\RaffleResource\Pages\ListRaffles;
use App\Filament\Resources\RaffleResource\RelationManagers\PrizeTiersRelationManager;
use App\Filament\Resources\SiteNoticeResource\Pages\CreateSiteNotice;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\TodayOverview;
use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleSiteNotice;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\LiveDrawComment;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Models\Wallet;
use App\Services\ChatModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 45 — announcements, live-chat moderation,
 * audit log, transactions, dashboard, referrals & points, and the raffle
 * safety locks. Admin screens are exercised by clicking their real
 * buttons as a signed-in administrator.
 */
class SiteToolsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(): WpUser
    {
        return WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Customer']);
    }

    // ---- Announcements ---------------------------------------------------

    public function test_only_switched_on_announcements_inside_their_window_are_shown(): void
    {
        RaffleSiteNotice::create(['title' => 'Live', 'message' => 'Showing', 'is_active' => true]);
        RaffleSiteNotice::create(['message' => 'Off', 'is_active' => false]);
        RaffleSiteNotice::create(['message' => 'Future', 'is_active' => true, 'starts_at' => now()->addDay()]);
        RaffleSiteNotice::create(['message' => 'Past', 'is_active' => true, 'ends_at' => now()->subHour()]);

        $messages = collect($this->getJson('/api/site-notices')->assertOk()->json('notices'))->pluck('message')->all();

        $this->assertSame(['Showing'], $messages);
    }

    public function test_an_unsafe_button_link_is_never_sent_to_the_site(): void
    {
        RaffleSiteNotice::create(['message' => 'x', 'is_active' => true, 'link_url' => 'javascript:alert(1)', 'link_label' => 'Click']);

        $this->assertNull($this->getJson('/api/site-notices')->json('notices.0.link_url'));
    }

    public function test_an_admin_can_publish_an_announcement_and_it_shows_immediately(): void
    {
        $this->actingAsAdministrator();
        $this->getJson('/api/site-notices'); // warm the cache with "nothing"

        Livewire::test(CreateSiteNotice::class)
            ->fillForm(['message' => 'Double tickets this weekend!', 'type' => 'promo', 'location' => 'banner', 'frequency' => 'once_day', 'dismiss_sec' => 0, 'is_active' => true, 'link_url' => '/raffles', 'link_label' => 'Play now'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->getJson('/api/site-notices')->assertJsonPath('notices.0.message', 'Double tickets this weekend!');
        $this->assertSame(1, AdminAuditLog::where('action', 'site_notice.created')->count());
    }

    public function test_the_admin_form_rejects_an_unsafe_link(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateSiteNotice::class)
            ->fillForm(['message' => 'x', 'type' => 'info', 'location' => 'banner', 'frequency' => 'always', 'link_url' => 'javascript:alert(1)'])
            ->call('create')
            ->assertHasFormErrors(['link_url']);
    }

    // ---- Live chat -------------------------------------------------------

    public function test_links_phone_numbers_and_blocked_words_are_removed_from_chat(): void
    {
        $clean = app(ChatModerationService::class)->clean('Winner! WhatsApp me on 0803 123 4567 or visit claim-prize.com you idiot');

        $this->assertStringNotContainsString('0803', $clean);
        $this->assertStringNotContainsString('claim-prize.com', $clean);
        $this->assertStringNotContainsString('idiot', $clean);
        $this->assertStringContainsString('Winner!', $clean);
    }

    public function test_a_muted_customer_is_told_they_cannot_post(): void
    {
        $raffle = Raffle::create(['title' => 'Live one', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $customer = $this->actingAsWordPressUser();
        app(ChatModerationService::class)->mute(WpUser::create(['user_login' => 'mod', 'user_pass' => 'x', 'user_email' => 'mod@example.com']), $customer->ID, 24);

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/comments", ['body' => 'hello'])
            ->assertStatus(422)
            ->assertJson(['message' => "You can't post in the chat right now. If you think this is a mistake, contact support."]);
        $this->assertSame(0, LiveDrawComment::count());
    }

    public function test_a_message_that_is_only_a_link_is_refused(): void
    {
        $raffle = Raffle::create(['title' => 'Live one', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $this->actingAsWordPressUser();

        $this->postJson("/api/raffles/{$raffle->id}/live-draw/comments", ['body' => 'https://scam.example/claim'])->assertStatus(422);
    }

    public function test_hiding_a_message_removes_it_for_everyone(): void
    {
        Event::fake([LiveDrawCommentHidden::class]);
        $this->actingAsAdministrator();
        $raffle = Raffle::create(['title' => 'Live one', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $comment = LiveDrawComment::create(['raffle_id' => $raffle->id, 'user_id' => $this->customer()->ID, 'body' => 'rude message']);

        Livewire::test(ListLiveChat::class)
            ->assertCanSeeTableRecords([$comment])
            ->callTableAction('hide', $comment);

        $this->assertNotNull($comment->fresh()->hidden_at);
        Event::assertDispatched(LiveDrawCommentHidden::class);
        $this->assertEmpty($this->getJson("/api/raffles/{$raffle->id}/live-draw")->json('comments'));
    }

    public function test_an_admin_can_mute_a_message_author(): void
    {
        $this->actingAsAdministrator();
        $raffle = Raffle::create(['title' => 'Live one', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $author = $this->customer();
        $comment = LiveDrawComment::create(['raffle_id' => $raffle->id, 'user_id' => $author->ID, 'body' => 'spam']);

        Livewire::test(ListLiveChat::class)->callTableAction('mute', $comment, data: ['hours' => '24']);

        $this->assertTrue(app(ChatModerationService::class)->isMuted($author->ID));
        $this->assertSame(1, AdminAuditLog::where('action', 'chat.user_muted')->count());
    }

    // ---- Audit log & transactions ---------------------------------------

    public function test_the_audit_log_shows_plain_descriptions(): void
    {
        $admin = $this->actingAsAdministrator();
        AdminAuditLog::create(['admin_user_id' => $admin->ID, 'action' => 'withdrawal.paid', 'subject_type' => 'App\\Models\\WithdrawalRequest', 'subject_id' => 5, 'context' => ['amount_sent' => 4000], 'created_at' => now()]);

        Livewire::test(ListAdminAuditLogs::class)->assertSee('Withdrawal marked paid');
    }

    public function test_reversing_a_purchase_from_the_transactions_screen_refunds_it(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        Wallet::create(['user_id' => $customer->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $txn = RaffleTransaction::create(['user_id' => $customer->ID, 'claimed_amount' => 500, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'created_at' => now()]);
        RaffleEntry::create(['user_id' => $customer->ID, 'raffle_id' => 1, 'ticket_number' => 9, 'txn_id' => $txn->id]);

        Livewire::test(ListTransactions::class)
            ->assertSee('1 ticket(s): 9')
            ->callTableAction('reverse', $txn, data: ['reason' => 'Customer asked to cancel']);

        $this->assertEquals(500, (float) Wallet::where('user_id', $customer->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::where('txn_id', $txn->id)->count());
    }

    // ---- Dashboard, referrals, points -----------------------------------

    public function test_the_dashboard_and_new_screens_load(): void
    {
        $this->actingAsAdministrator();
        RaffleTransaction::create(['user_id' => $this->customer()->ID, 'claimed_amount' => 1000, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'created_at' => now()]);

        $this->get('/admin')->assertOk();
        // Widgets load just after the page (Filament lazy-loads them).
        Livewire::test(NeedsAttention::class)->assertSee('Withdrawals to pay')->assertSee('All clear');
        Livewire::test(TodayOverview::class)->assertSee('Ticket sales')->assertSee('₦1,000');
        Livewire::test(SalesChart::class)->assertOk();

        foreach (['announcements', 'announcements/create', 'live-chat', 'audit-log', 'transactions', 'referrals', 'points'] as $page) {
            $this->get("/admin/{$page}")->assertOk();
        }
    }

    public function test_customers_cannot_open_the_new_screens(): void
    {
        $this->actingAsWordPressUser();

        foreach (['announcements', 'live-chat', 'audit-log', 'transactions', 'referrals', 'points'] as $page) {
            $this->get("/admin/{$page}")->assertForbidden();
        }
    }

    // ---- Raffle safety locks --------------------------------------------

    public function test_a_raffle_with_tickets_is_kept_when_bulk_deleting(): void
    {
        $this->actingAsAdministrator();
        $sold = Raffle::create(['legacy_post_id' => 800, 'title' => 'Has tickets', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        RaffleEntry::create(['user_id' => 1, 'raffle_id' => 800, 'ticket_number' => 1, 'txn_id' => 1]);
        $empty = Raffle::create(['title' => 'Empty draft', 'price' => 100, 'max_tickets' => 10, 'status' => 'draft']);

        Livewire::test(ListRaffles::class)->callTableBulkAction('delete', [$sold, $empty]);

        $this->assertNotNull(Raffle::find($sold->id));
        $this->assertNull(Raffle::find($empty->id));
    }

    public function test_prize_tiers_are_locked_once_the_raffle_is_drawn(): void
    {
        $this->actingAsAdministrator();
        $raffle = Raffle::create(['title' => 'Drawn', 'price' => 100, 'max_tickets' => 10, 'status' => 'closed']);
        RaffleDraw::create(['raffle_id' => $raffle->id, 'server_seed' => 's', 'server_seed_hash' => hash('sha256', 's'), 'committed_at' => now(), 'executed_at' => now()]);

        Livewire::test(PrizeTiersRelationManager::class, ['ownerRecord' => $raffle, 'pageClass' => EditRaffle::class])
            ->assertTableActionHidden('create');
    }
}
