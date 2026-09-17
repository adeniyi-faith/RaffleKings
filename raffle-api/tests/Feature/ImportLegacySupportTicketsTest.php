<?php

namespace Tests\Feature;

use App\Models\Legacy\LegacySupportMessage;
use App\Models\Legacy\LegacySupportTicket;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — proves the one-time support
 * ticket backfill correctly imports legacy tickets and their message
 * threads, maps status/category fields correctly, and is idempotent.
 */
class ImportLegacySupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_ticket_and_its_messages_are_imported(): void
    {
        $legacyTicket = LegacySupportTicket::create([
            'user_id' => 5,
            'category' => 'Withdrawal Issue',
            'subject' => 'Withdrawal Issue',
            'status' => 'open',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ]);
        LegacySupportMessage::create(['ticket_id' => $legacyTicket->id, 'sender_type' => 'user', 'sender_id' => 5, 'message' => 'Where is my withdrawal?', 'created_at' => now()->subDays(2)]);
        LegacySupportMessage::create(['ticket_id' => $legacyTicket->id, 'sender_type' => 'admin', 'sender_id' => 1, 'message' => 'Looking into it.', 'created_at' => now()->subDay()]);

        Artisan::call('legacy:import-support-tickets');

        $ticket = SupportTicket::where('legacy_ticket_id', $legacyTicket->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame(5, $ticket->user_id);
        $this->assertSame('Withdrawal Issue', $ticket->subject);
        $this->assertSame('open', $ticket->status);
        $this->assertCount(2, $ticket->messages);

        $adminMessage = $ticket->messages()->where('is_from_admin', true)->first();
        $this->assertSame('Looking into it.', $adminMessage->message);
        $this->assertSame(1, $adminMessage->author_id);
    }

    public function test_legacy_answered_status_maps_to_pending(): void
    {
        $legacyTicket = LegacySupportTicket::create(['user_id' => 6, 'category' => 'General', 'subject' => 'General', 'status' => 'answered', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('legacy:import-support-tickets');

        $ticket = SupportTicket::where('legacy_ticket_id', $legacyTicket->id)->first();
        $this->assertSame('pending', $ticket->status);
    }

    public function test_it_is_idempotent(): void
    {
        $legacyTicket = LegacySupportTicket::create(['user_id' => 7, 'category' => 'General', 'subject' => 'General', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        LegacySupportMessage::create(['ticket_id' => $legacyTicket->id, 'sender_type' => 'user', 'sender_id' => 7, 'message' => 'Hi', 'created_at' => now()]);

        Artisan::call('legacy:import-support-tickets');
        Artisan::call('legacy:import-support-tickets');

        $this->assertSame(1, SupportTicket::where('legacy_ticket_id', $legacyTicket->id)->count());
        $this->assertSame(1, SupportTicketMessage::whereNotNull('legacy_message_id')->count());
    }

    public function test_a_new_legacy_message_on_an_already_imported_ticket_is_still_picked_up(): void
    {
        $legacyTicket = LegacySupportTicket::create(['user_id' => 8, 'category' => 'General', 'subject' => 'General', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        LegacySupportMessage::create(['ticket_id' => $legacyTicket->id, 'sender_type' => 'user', 'sender_id' => 8, 'message' => 'First message', 'created_at' => now()]);

        Artisan::call('legacy:import-support-tickets');

        // A second legacy message arrives before the command runs again (e.g. a user replied
        // through a legacy page that hasn't been cut over yet).
        LegacySupportMessage::create(['ticket_id' => $legacyTicket->id, 'sender_type' => 'user', 'sender_id' => 8, 'message' => 'Second message', 'created_at' => now()]);

        Artisan::call('legacy:import-support-tickets');

        $ticket = SupportTicket::where('legacy_ticket_id', $legacyTicket->id)->first();
        $this->assertCount(2, $ticket->messages);
    }

    public function test_dry_run_writes_nothing(): void
    {
        LegacySupportTicket::create(['user_id' => 9, 'category' => 'General', 'subject' => 'General', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('legacy:import-support-tickets', ['--dry-run' => true]);

        $this->assertSame(0, SupportTicket::count());
    }
}
