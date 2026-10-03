<?php

namespace App\Jobs;

use App\Models\KnowledgeArticle;
use App\Models\SupportTicket;
use App\Services\Ai\SupportAi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * How the support agent learns: when a person on the team solves a ticket,
 * the AI looks for a general fact in their answer that the Knowledge base
 * doesn't have yet and saves it as a suggested entry, switched off. Staff
 * check it and switch it on (Support → Knowledge base); only then may the
 * AI answer from it. Tickets the AI solved on its own teach nothing new.
 */
class LearnFromTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $ticketId) {}

    public function handle(SupportAi $ai): void
    {
        if (! config('ai.enabled') || ! config('ai.learn_from_tickets')) {
            return;
        }

        $ticket = SupportTicket::query()->with('messages')->find($this->ticketId);
        if (! $ticket || ! $ticket->messages->contains(fn ($m) => $m->is_from_admin && ! $m->is_automated)) {
            return;
        }

        if (KnowledgeArticle::query()->where('suggested_from_ticket_id', $ticket->id)->exists()) {
            return;
        }

        try {
            $entry = $ai->suggestKnowledge($ticket);
        } catch (\Throwable $e) {
            Log::info('LearnFromTicket: skipped', ['ticket' => $ticket->id, 'why' => $e->getMessage()]);

            return;
        }

        if ($entry) {
            KnowledgeArticle::create($entry + ['is_active' => false, 'suggested_from_ticket_id' => $ticket->id]);
        }
    }
}
