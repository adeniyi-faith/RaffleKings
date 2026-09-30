<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Services\DepositService;
use App\Services\PayoutService;
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
 * Always responds 200 for a recognized, signed event (even one that
 * turns out not to be a success) so the gateway doesn't keep retrying
 * indefinitely — a genuine problem is logged, not surfaced as an error
 * status the gateway would interpret as "please resend."
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
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $reference = $gateway->referenceFromWebhookPayload($request->json()->all());

        if (! $reference) {
            return response()->json(['message' => 'No reference in payload.'], 200);
        }

        // Automatic payouts: Paystack's transfer.success / failed / reversed
        // arrive on the same URL as top-ups.
        if ($gatewayName === 'paystack' && str_starts_with((string) $request->json('event'), 'transfer.')) {
            try {
                $this->payouts->handleWebhook($reference);
            } catch (PaymentGatewayException|RuntimeException $e) {
                Log::error('Failed to check a Paystack payout from webhook.', ['reference' => $reference, 'error' => $e->getMessage()]);
            }

            return response()->json(['message' => 'ok']);
        }

        try {
            $this->deposits->confirm($gatewayName, $reference);
        } catch (PaymentGatewayException|RuntimeException $e) {
            Log::error("Failed to confirm {$gatewayName} deposit from webhook.", [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'ok']);
    }
}
