<?php

namespace App\Jobs;

use App\Models\SupportTicket;
use App\Services\Ai\SupportAi;
use App\Services\SupportTicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Lets the AI answer a support ticket when the Knowledge base has the
 * answer. Stands down (leaving the ticket for a person) if a person has
 * already replied, the customer asked for a human, the AI reached its
 * limit on this ticket, or it isn't sure. When the customer says their
 * problem is solved, it marks the ticket resolved. Never changes any account.
 */
class AutoReplyToTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $ticketId) {}

    public function handle(SupportAi $ai, SupportTicketService $tickets): void
    {
        if (! config('ai.enabled') || ! config('ai.auto_reply')) {
            return;
        }

        $ticket = SupportTicket::query()->with('messages')->find($this->ticketId);
        if (! $ticket || $ticket->needs_human || in_array($ticket->status, ['resolved', 'closed'], true)) {
            return;
        }

        $last = $ticket->messages->last();
        // Only reply to the customer's latest message, and never after a person has joined in.
        if (! $last || $last->is_from_admin || $ticket->messages->contains(fn ($m) => $m->is_from_admin && ! $m->is_automated)) {
            return;
        }

        if ($ticket->messages->where('is_automated', true)->count() >= (int) config('ai.max_auto_replies')) {
            return;
        }

        try {
            $result = $ai->answer($ticket);
        } catch (\Throwable $e) {
            Log::info('AutoReplyToTicket: left for a person', ['ticket' => $ticket->id, 'why' => $e->getMessage()]);

            return;
        }

        // The customer says it's sorted ("thanks, that worked"): say goodbye and mark it solved.
        // If they write again, the ticket opens again by itself.
        if ($result['solved'] && config('ai.auto_resolve')) {
            if ($result['reply'] !== '') {
                $tickets->replyAutomated($ticket, $result['reply']);
            }
            $tickets->setStatus($ticket, 'resolved');

            return;
        }

        if (! $result['answerable']) {
            return;
        }

        $tickets->replyAutomated($ticket, $result['reply']);
    }
}
