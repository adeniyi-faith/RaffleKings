<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Item 30's real-time ticket counter: broadcast the instant a purchase
 * actually allocates ticket numbers (TicketPurchaseService), so every
 * open Raffle/Checkout page updates its sold/remaining count and
 * "closed" state live — replacing the audit's fabricated urgency
 * elements (index.php's fake activity ticker, raffles.php's dead
 * random-walk "viewing count", checkout.php's hardcoded "3 other
 * people are viewing this raffle") with a number that is always
 * exactly what RaffleReadService itself would compute right now, not a
 * client-side guess or a fake claim.
 *
 * Public channel, same reasoning as the live-draw events: raffle
 * details are public pages with no login wall, so a guest browsing a
 * raffle sees the same live counter a logged-in buyer does.
 */
class RaffleTicketsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $raffleId,
        public readonly int $soldTickets,
        public readonly int $remainingTickets,
        public readonly bool $isClosed,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("raffle.{$this->raffleId}")];
    }

    public function broadcastAs(): string
    {
        return 'tickets.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'sold_tickets' => $this->soldTickets,
            'remaining_tickets' => $this->remainingTickets,
            'is_closed' => $this->isClosed,
        ];
    }
}
