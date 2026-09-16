<?php

namespace Tests\Unit;

use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Notifications\NewSupportTicketAdminAlert;
use App\Notifications\SupportTicketReply;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class SupportTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportTicketService $tickets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tickets = app(SupportTicketService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_opening_a_ticket_creates_the_ticket_and_its_first_message(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $ticket = $this->tickets->open($user, 'Payment issue', 'My withdrawal never arrived.');

        $this->assertSame('open', $ticket->status);
        $this->assertSame($user->ID, $ticket->user_id);
        $this->assertCount(1, $ticket->messages);
        $this->assertSame('My withdrawal never arrived.', $ticket->messages->first()->message);
        $this->assertFalse($ticket->messages->first()->is_from_admin);
    }

    public function test_opening_a_ticket_notifies_an_admin(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->tickets->open($user, 'Subject', 'Message');

        Notification::assertSentTo(new AnonymousNotifiable, NewSupportTicketAdminAlert::class);
    }

    public function test_an_admin_reply_moves_the_ticket_to_pending_and_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $admin = $this->makeUser();
        $ticket = $this->tickets->open($user, 'Subject', 'Message');

        $reply = $this->tickets->reply($ticket, $admin, 'We are looking into it.', isFromAdmin: true);

        $this->assertTrue($reply->is_from_admin);
        $this->assertSame('pending', $ticket->fresh()->status);
        Notification::assertSentTo($user, SupportTicketReply::class);
    }

    public function test_a_user_reply_reopens_the_ticket_and_notifies_no_one(): void
    {
        $user = $this->makeUser();
        $ticket = $this->tickets->open($user, 'Subject', 'Message');
        $ticket->update(['status' => 'resolved']);

        Notification::fake();
        $reply = $this->tickets->reply($ticket, $user, 'Still broken.', isFromAdmin: false);

        $this->assertFalse($reply->is_from_admin);
        $this->assertSame('open', $ticket->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_status_can_be_set_to_a_known_value(): void
    {
        $user = $this->makeUser();
        $ticket = SupportTicket::create(['user_id' => $user->ID, 'subject' => 'S', 'status' => 'open']);

        $updated = $this->tickets->setStatus($ticket, 'resolved');

        $this->assertSame('resolved', $updated->status);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $user = $this->makeUser();
        $ticket = SupportTicket::create(['user_id' => $user->ID, 'subject' => 'S', 'status' => 'open']);

        $this->expectException(RuntimeException::class);
        $this->tickets->setStatus($ticket, 'bogus');
    }
}
