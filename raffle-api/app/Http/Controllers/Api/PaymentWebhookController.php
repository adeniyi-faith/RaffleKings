<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\WebhookEvent;
use App\Services\DepositService;
use App\Services\Monitoring\StaffAlerts;
use App\Services\PayoutService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Receives Paystack/Flutterwave webhook events. Neither the payload's
 * signature-checked authenticity NOR its own claimed status/amount are
 * trusted blindly for crediting money — the signature only proves the
 * event came from the gateway; DepositService::confirm() then
 * independently re-verifies the transaction directly with that same
 * gateway's API before anything is settled.
 *
 * Answers 200 for a signed event we handled (even one that turns out not
 * to be a success), and 500 when we could NOT confirm it, so the gateway
 * sends it again instead of the payment being forgotten (money-safety
 * audit D5, E5). Every event is stored once by its own key.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly DepositService $deposits,
        private readonly PayoutService $payouts,
    ) {}

    public function paystack(Request $request): JsonResponse
    {
        return $this->handle('paystack', $request);
    }

    public function flutterwave(Request $request): JsonResponse
    {
        return $this->handle('flutterwave', $request);
    }

    private function handle(string $gatewayName, Request $request): JsonResponse
    {
        $gateway = $this->deposits->gatewayFor($gatewayName);

        if (! $gateway->verifyWebhookSignature($request)) {
            // Anyone can post to this URL; a pile of bad signatures is worth a look.
            StaffAlerts::send("Payment webhook with a wrong signature arrived at the {$gatewayName} address (from {$request->ip()}). If this keeps happening, check the webhook secret in Settings.", 'bad-signature:'.$gatewayName, 60);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $reference = $gateway->referenceFromWebhookPayload($payload);

        if (! $reference) {
            return response()->json(['message' => 'No reference in payload.'], 200);
        }

        // Stored once by the provider's own event key: the same event
        // arriving twice (they resend) is recognised, not acted on twice.
        $event = $this->storeEvent($gatewayName, $payload, $reference, $request->getContent());

        if ($event->status === 'processed') {
            return response()->json(['message' => 'ok']);
        }

        try {
            // Automatic payouts: Paystack's transfer.success / failed / reversed
            // arrive on the same URL as top-ups.
            if ($gatewayName === 'paystack' && str_starts_with((string) $request->json('event'), 'transfer.')) {
                $this->payouts->handleWebhook($reference);
            } elseif (! ($known = Deposit::query()->where('reference', $reference)->first()) || ($known->gateway && $known->gateway !== $gatewayName)) {
                // Not one of our top-ups (the account may be used for other
                // things), or one started with the other gateway: nothing to
                // do, and no point being sent it again.
                $event->update(['status' => 'processed', 'error' => 'Not a top-up we can settle from this gateway', 'processed_at' => now()]);

                return response()->json(['message' => 'ok']);
            } else {
                $this->deposits->confirm($gatewayName, $reference);
            }
        } catch (PaymentGatewayException|RuntimeException $e) {
            // We could not confirm it right now. Answering with an error makes
            // the provider send the event again, instead of the payment being
            // lost because we said "ok" and did nothing (audit D5, E5).
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);

            Log::error("Failed to handle a {$gatewayName} webhook.", ['reference' => $reference, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            if ($event->deliveries >= 3) {
                StaffAlerts::send("A {$gatewayName} payment ({$reference}) could not be confirmed after {$event->deliveries} tries: ".mb_substr($e->getMessage(), 0, 160), "unconfirmed:{$gatewayName}:{$reference}", 360);
            }

            return response()->json(['message' => 'Could not process this yet. Please send it again.'], 500);
        }

        $event->update(['status' => 'processed', 'error' => null, 'processed_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    private function storeEvent(string $gatewayName, array $payload, string $reference, string $rawBody): WebhookEvent
    {
        $type = (string) ($payload['event'] ?? $payload['event.type'] ?? 'unknown');
        $id = $payload['data']['id'] ?? null;
        $key = mb_substr($type.':'.($id !== null ? $id : $reference), 0, 150);
        $hash = hash('sha256', $rawBody);

        try {
            return WebhookEvent::create([
                'gateway' => $gatewayName,
                'event_key' => $key,
                'event_type' => mb_substr($type, 0, 60),
                'reference' => mb_substr($reference, 0, 100),
                'payload_hash' => $hash,
                'status' => 'received',
                'deliveries' => 1,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $event = WebhookEvent::query()->where('gateway', $gatewayName)->where('event_key', $key)->firstOrFail();
            $event->increment('deliveries');

            return $event->refresh();
        }
    }
}
