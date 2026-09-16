<?php

namespace App\Services;

use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Notifications\NewSupportTicketAdminAlert;
use App\Notifications\SupportTicketReply;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * A real support ticketing system, replacing the legacy site's
 * support.php — whose "Submit Ticket" handler has a comment reading
 * "// Simulate submission" and makes no network call at all. This is
 * the single most user-harmful gap the audit found: a user believes
 * their message was sent, and nothing is ever transmitted anywhere.
 */
class SupportTicketService
{
    public function open(WpUser $user, string $subject, string $message): SupportTicket
    {
        $ticket = DB::transaction(function () use ($user, $subject, $message) {
            $ticket = SupportTicket::create(['user_id' => $user->ID, 'subject' => $subject, 'status' => 'open']);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'author_id' => $user->ID,
                'is_from_admin' => false,
                'message' => $message,
                'created_at' => now(),
            ]);

            return $ticket;
        });

        Notification::send(new AnonymousNotifiable, new NewSupportTicketAdminAlert($ticket));

        return $ticket;
    }

    /**
     * Either party can reply. A user reply re-opens a resolved/closed
     * ticket automatically (they came back with something new to say);
     * an admin reply moves an open ticket to "pending" — same simple
     * status model most support systems use.
     */
    public function reply(SupportTicket $ticket, WpUser $author, string $message, bool $isFromAdmin): SupportTicketMessage
    {
        $reply = DB::transaction(function () use ($ticket, $author, $message, $isFromAdmin) {
            $reply = SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'author_id' => $author->ID,
                'is_from_admin' => $isFromAdmin,
                'message' => $message,
                'created_at' => now(),
            ]);

            $ticket->update(['status' => $isFromAdmin ? 'pending' : 'open']);

            return $reply;
        });

        if ($isFromAdmin) {
            $ticket->user?->notify(new SupportTicketReply($reply));
        }

        return $reply;
    }

    /**
     * @throws RuntimeException if the status isn't one of the known values
     */
    public function setStatus(SupportTicket $ticket, string $status): SupportTicket
    {
        if (! in_array($status, ['open', 'pending', 'resolved', 'closed'], true)) {
            throw new RuntimeException("Unknown ticket status: {$status}");
        }

        $ticket->update(['status' => $status]);

        return $ticket;
    }
}
