<?php

namespace App\Notifications\Contracts;

/**
 * A notification whose email / push copies are tracked in
 * message_deliveries (App\Services\Retention\DeliveryTracker): it knows
 * the tracking token for each customer and channel, so "sent" and
 * "failed" can be written down when the provider answers.
 */
interface TracksDelivery
{
    /** @param  string  $channel  email | push */
    public function deliveryToken(string $channel, mixed $notifiable): ?string;
}
