<?php

namespace App\Console\Commands;

use App\Models\Legacy\LegacySupportTicket;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Console\Command;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — two real, independently-built
 * support ticket systems exist today: legacy's own real ticketing
 * system (Phase 0 item 3, `wp_raffle_support_tickets`/`_messages`) and
 * this app's separately-migrated `support_tickets`/`support_ticket_messages`
 * (item 20). Before support-bridge.php's unified flag can safely become
 * the live path, every real conversation legacy already has needs to
 * exist on the new tables too — otherwise turning the flag on would
 * make every existing ticket and its history disappear from both the
 * user's own ticket list and the admin queue.
 *
 * Maps legacy's `category` field into `subject` (legacy already sets
 * subject = category at creation time — see rk_create_support_ticket()
 * — so nothing is lost) and legacy's 'answered' status onto the new
 * table's 'pending' (the new schema has no 'answered' state; 'pending'
 * — "responded to, not yet closed" — is the closest real equivalent).
 * `sender_type` maps onto `is_from_admin`.
 *
 * Idempotent: every imported ticket/message carries the source row's id
 * in `legacy_ticket_id`/`legacy_message_id` (unique columns), so
 * re-running never creates a duplicate; a ticket that already has NEW
 * messages the legacy side never sent still gets any legacy messages it
 * doesn't have yet, so a ticket mid-migration is never left half-imported.
 *
 * Usage:
 *   php artisan legacy:import-support-tickets
 *   php artisan legacy:import-support-tickets --dry-run
 */
class ImportLegacySupportTickets extends Command
{
    protected $signature = 'legacy:import-support-tickets {--dry-run}';

    protected $description = 'One-time backfill of legacy support tickets/messages into the new support_tickets/support_ticket_messages tables';

    private const STATUS_MAP = [
        'answered' => 'pending',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $ticketsImported = 0;
        $messagesImported = 0;

        LegacySupportTicket::query()->with('messages')->orderBy('id')->chunk(100, function ($legacyTickets) use ($dryRun, &$ticketsImported, &$messagesImported) {
            foreach ($legacyTickets as $legacyTicket) {
                $status = self::STATUS_MAP[$legacyTicket->status] ?? $legacyTicket->status;

                $this->line(sprintf('%s legacy ticket #%d (user %d, %s)', $dryRun ? '[dry-run]' : '[import]', $legacyTicket->id, $legacyTicket->user_id, $status));

                if ($dryRun) {
                    continue;
                }

                $ticket = SupportTicket::query()->firstOrCreate(
                    ['legacy_ticket_id' => $legacyTicket->id],
                    [
                        'user_id' => $legacyTicket->user_id,
                        'subject' => $legacyTicket->subject,
                        'status' => $status,
                        'created_at' => $legacyTicket->created_at,
                        'updated_at' => $legacyTicket->updated_at,
                    ],
                );

                if ($ticket->wasRecentlyCreated) {
                    $ticketsImported++;
                }

                foreach ($legacyTicket->messages as $legacyMessage) {
                    $message = SupportTicketMessage::query()->firstOrCreate(
                        ['legacy_message_id' => $legacyMessage->id],
                        [
                            'support_ticket_id' => $ticket->id,
                            'author_id' => $legacyMessage->sender_id,
                            'is_from_admin' => $legacyMessage->sender_type === 'admin',
                            'message' => $legacyMessage->message,
                            'created_at' => $legacyMessage->created_at,
                        ],
                    );

                    if ($message->wasRecentlyCreated) {
                        $messagesImported++;
                    }
                }
            }
        });

        $this->info(sprintf(
            '%s %d ticket(s) and %d message(s) imported.',
            $dryRun ? 'Dry run complete —' : 'Done —',
            $ticketsImported,
            $messagesImported,
        ));

        return self::SUCCESS;
    }
}
