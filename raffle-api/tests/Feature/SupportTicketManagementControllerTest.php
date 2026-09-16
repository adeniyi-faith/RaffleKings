<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class SupportTicketManagementControllerTest extends TestCase
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

    public function test_a_regular_user_cannot_reach_the_admin_support_endpoints(): void
    {
        $this->actingAsWordPressUser();

        $this->getJson('/api/admin/support/tickets')->assertStatus(403);
    }

    public function test_an_admin_can_list_and_view_tickets(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $submitter = WpUser::create(['user_login' => 'submitter', 'user_pass' => 'x', 'user_email' => 'submitter@example.com']);
        $ticket = app(SupportTicketService::class)->open($submitter, 'Payment issue', 'Help please.');

        $list = $this->getJson('/api/admin/support/tickets');
        $list->assertOk();
        $list->assertJsonCount(1, 'tickets.data');

        $show = $this->getJson("/api/admin/support/tickets/{$ticket->id}");
        $show->assertOk();
        $show->assertJsonFragment(['message' => 'Help please.']);
    }

    public function test_an_admin_can_filter_tickets_by_status(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $submitter = WpUser::create(['user_login' => 'submitter2', 'user_pass' => 'x', 'user_email' => 'submitter2@example.com']);
        $open = SupportTicket::create(['user_id' => $submitter->ID, 'subject' => 'Open one', 'status' => 'open']);
        SupportTicket::create(['user_id' => $submitter->ID, 'subject' => 'Closed one', 'status' => 'closed']);

        $response = $this->getJson('/api/admin/support/tickets?status=open');

        $response->assertOk();
        $response->assertJsonCount(1, 'tickets.data');
        $response->assertJsonFragment(['id' => $open->id]);
    }

    public function test_an_admin_reply_is_recorded_and_audited(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $submitter = WpUser::create(['user_login' => 'submitter3', 'user_pass' => 'x', 'user_email' => 'submitter3@example.com']);
        $ticket = app(SupportTicketService::class)->open($submitter, 'Subject', 'Message');

        $response = $this->postJson("/api/admin/support/tickets/{$ticket->id}/reply", ['message' => 'We refunded you.']);

        $response->assertCreated();
        $response->assertJson(['message' => 'We refunded you.', 'is_from_admin' => true]);
        $this->assertSame('pending', $ticket->fresh()->status);

        $auditLog = $this->getJson('/api/admin/audit-logs');
        $auditLog->assertJsonFragment(['action' => 'support_ticket.replied']);
    }

    public function test_an_admin_can_change_ticket_status(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $submitter = WpUser::create(['user_login' => 'submitter4', 'user_pass' => 'x', 'user_email' => 'submitter4@example.com']);
        $ticket = app(SupportTicketService::class)->open($submitter, 'Subject', 'Message');

        $response = $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", ['status' => 'resolved']);

        $response->assertOk();
        $response->assertJson(['status' => 'resolved']);

        $auditLog = $this->getJson('/api/admin/audit-logs');
        $auditLog->assertJsonFragment(['action' => 'support_ticket.status_changed']);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $submitter = WpUser::create(['user_login' => 'submitter5', 'user_pass' => 'x', 'user_email' => 'submitter5@example.com']);
        $ticket = app(SupportTicketService::class)->open($submitter, 'Subject', 'Message');

        $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", ['status' => 'bogus'])->assertStatus(422);
    }
}
