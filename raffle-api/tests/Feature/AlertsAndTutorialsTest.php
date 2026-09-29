<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CustomerMessage;
use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tutorial;
use App\Models\WithdrawalRequest;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\SupportTicketReply;
use App\Notifications\WithdrawalProcessed;
use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * Personal alerts in the bell, tutorials on their own pages with a heart
 * that counts once per person, and the admin's own error page.
 */
class AlertsAndTutorialsTest extends TestCase
{
    use ActsAsAdministrator, AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function customer(): WpUser
    {
        return WpUser::create(['user_login' => 'ada', 'user_pass' => 'x', 'user_email' => 'ada@example.com', 'display_name' => 'Ada Obi']);
    }

    private function tutorial(array $attributes = []): Tutorial
    {
        return Tutorial::create(array_merge([
            'title' => 'How to Play & Win',
            'category' => 'How to play',
            'content' => '<h2>Step one</h2><p>Pick a raffle.</p>',
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    public function test_a_support_reply_lands_in_the_customers_bell_and_links_to_the_ticket(): void
    {
        Http::fake();
        $customer = $this->actingAsWordPressUser();
        $ticket = SupportTicket::create(['user_id' => $customer->ID, 'subject' => 'Top-up missing', 'status' => 'open']);
        $message = SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'author_id' => 1, 'is_from_admin' => true, 'message' => 'We found it, credited now.']);

        $customer->notify(new SupportTicketReply($message));

        $alert = CustomerMessage::query()->where('user_id', $customer->ID)->sole();
        $this->assertSame('support', $alert->kind);
        $this->assertSame('Support replied: Top-up missing', $alert->title);
        $this->assertSame('/support?ticket='.$ticket->id, $alert->link_url);
        $this->assertNull($alert->read_at);

        $this->getJson('/api/messages')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('messages.0.kind', 'support');
    }

    public function test_the_bell_alert_is_written_at_once_even_when_email_waits_for_the_queue(): void
    {
        $customer = $this->customer();
        $account = BankAccount::create(['user_id' => $customer->ID, 'bank_name' => 'GTBank', 'account_name' => 'Ada Obi', 'account_number' => '0123456789', 'is_primary' => true]);
        $withdrawal = WithdrawalRequest::create(['user_id' => $customer->ID, 'bank_account_id' => $account->id, 'requested_amount' => 5000, 'fee_amount' => 1000, 'amount_to_send' => 4000, 'status' => 'paid']);

        $notification = new WithdrawalProcessed($withdrawal, 'paid');
        // Email and push can wait for the background worker; the bell can't.
        $this->assertSame('sync', $notification->viaConnections()[InboxChannel::class]);

        $customer->notify($notification);

        $alert = CustomerMessage::query()->where('user_id', $customer->ID)->sole();
        $this->assertSame('withdrawal', $alert->kind);
        $this->assertStringContainsString('₦4,000', $alert->body);
    }

    public function test_a_tutorial_has_its_own_page(): void
    {
        $tutorial = $this->tutorial();
        $other = $this->tutorial(['title' => 'Topping up']);

        $this->get("/support/tutorials/{$tutorial->id}-how-to-play-win")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Support/Tutorial')
                ->where('tutorial.id', $tutorial->id)
                ->where('tutorial.url', "/support/tutorials/{$tutorial->id}-how-to-play-win")
                ->where('more.0.id', $other->id));

        // Only the number matters, so an old link still works after a rename.
        $this->get("/support/tutorials/{$tutorial->id}-old-title")->assertOk();

        $hidden = $this->tutorial(['is_published' => false]);
        $this->get("/support/tutorials/{$hidden->id}")->assertNotFound();
    }

    public function test_a_heart_counts_once_per_person_and_can_be_taken_back(): void
    {
        $tutorial = $this->tutorial(['helpful_count' => 400]);
        $device = ['device' => 'device-abc-123'];

        $this->postJson("/api/tutorials/{$tutorial->id}/helpful", $device)->assertOk()->assertJson(['new_count' => 401, 'liked' => true]);
        $this->postJson("/api/tutorials/{$tutorial->id}/helpful", $device)->assertOk()->assertJson(['new_count' => 401]);

        $this->getJson('/api/tutorials?device=device-abc-123')->assertJsonPath('list.0.liked', true);
        $this->getJson('/api/tutorials?device=someone-else-1')->assertJsonPath('list.0.liked', false);

        $this->postJson("/api/tutorials/{$tutorial->id}/helpful", ['device' => 'someone-else-1'])->assertJson(['new_count' => 402]);
        $this->deleteJson("/api/tutorials/{$tutorial->id}/helpful", $device)->assertOk()->assertJson(['new_count' => 401, 'liked' => false]);
        $this->deleteJson("/api/tutorials/{$tutorial->id}/helpful", $device)->assertJson(['new_count' => 401]);
    }

    public function test_a_signed_in_customer_hearts_as_themselves(): void
    {
        $tutorial = $this->tutorial();
        $this->actingAsWordPressUser();

        $this->postJson("/api/tutorials/{$tutorial->id}/helpful", ['device' => 'phone-one-11'])->assertJson(['new_count' => 1]);
        $this->postJson("/api/tutorials/{$tutorial->id}/helpful", ['device' => 'laptop-two-22'])->assertJson(['new_count' => 1]);
    }

    public function test_admin_tutorials_list_shows_the_hearts(): void
    {
        $this->actingAsAdministrator();
        $this->tutorial(['title' => 'Loved guide', 'helpful_count' => 1234]);

        $this->get('/admin/tutorials')->assertOk()->assertSee('1,234 hearts in total', false)->assertSee('most loved', false);
    }

    public function test_admin_errors_use_the_admins_own_page(): void
    {
        config(['app.debug' => false]);
        $this->actingAsAdministrator();

        $this->get('/admin/no-such-page')
            ->assertNotFound()
            ->assertSee('Admin dashboard')
            ->assertSee('Try again')
            ->assertDontSee('data-page=', false);
    }

    public function test_table_totals_are_formatted_without_intl(): void
    {
        $sum = Sum::make();

        $this->assertNotNull((fn () => $this->formatStateUsing)->call($sum));
        $this->assertSame('12,345', $sum->formatState(12345));
    }
}
