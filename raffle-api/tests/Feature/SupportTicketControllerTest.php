<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class SupportTicketControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/support/tickets')->assertUnauthorized();
    }

    public function test_a_user_can_open_a_ticket(): void
    {
        Notification::fake();
        $this->actingAsWordPressUser();

        $response = $this->postJson('/api/support/tickets', [
            'subject' => 'Payment issue',
            'message' => 'My withdrawal never arrived.',
        ]);

        $response->assertCreated();
        $response->assertJson(['subject' => 'Payment issue', 'status' => 'open']);
    }

    public function test_opening_a_ticket_requires_a_subject_and_message(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/support/tickets', [])->assertStatus(422);
    }

    public function test_a_user_can_list_only_their_own_tickets(): void
    {
        Notification::fake();
        $otherUser = WpUser::create(['user_login' => 'other'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        SupportTicket::create(['user_id' => $otherUser->ID, 'subject' => 'Theirs', 'status' => 'open']);

        $user = $this->actingAsWordPressUser();
        SupportTicket::create(['user_id' => $user->ID, 'subject' => 'Mine', 'status' => 'open']);

        $response = $this->getJson('/api/support/tickets');

        $response->assertOk();
        $response->assertJsonCount(1, 'tickets');
        $response->assertJsonFragment(['subject' => 'Mine']);
    }

    public function test_a_user_cannot_view_another_users_ticket(): void
    {
        Notification::fake();
        $owner = $this->actingAsWordPressUser();
        $ticket = SupportTicket::create(['user_id' => $owner->ID, 'subject' => 'Mine', 'status' => 'open']);
        $this->actingAsWordPressUser();

        $this->getJson("/api/support/tickets/{$ticket->id}")->assertStatus(404);
    }

    public function test_a_user_can_reply_to_their_own_ticket(): void
    {
        Notification::fake();
        $user = $this->actingAsWordPressUser();
        $ticket = app(SupportTicketService::class)->open($user, 'Subject', 'Message');

        $response = $this->postJson("/api/support/tickets/{$ticket->id}/reply", ['message' => 'Any update?']);

        $response->assertCreated();
        $response->assertJson(['message' => 'Any update?', 'is_from_admin' => false]);
    }

    public function test_a_user_cannot_reply_to_another_users_ticket(): void
    {
        Notification::fake();
        $owner = $this->actingAsWordPressUser();
        $ticket = app(SupportTicketService::class)->open($owner, 'Subject', 'Message');
        $this->actingAsWordPressUser();

        $this->postJson("/api/support/tickets/{$ticket->id}/reply", ['message' => 'Sneaky'])->assertStatus(404);
    }
}
