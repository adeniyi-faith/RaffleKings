<?php

namespace App\Contracts;

use App\Exceptions\PaymentGatewayException;
use App\Models\Legacy\WpUser;
use App\Services\Payments\PaymentInitializationResult;
use App\Services\Payments\PaymentVerificationResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /**
     * @throws PaymentGatewayException
     */
    public function initialize(WpUser $user, float $amount, string $reference, string $callbackUrl): PaymentInitializationResult;

    /**
     * Always re-checks directly with the gateway — a webhook payload's
     * own claimed status/amount is never trusted on its own.
     *
     * @throws PaymentGatewayException
     */
    public function verify(string $reference): PaymentVerificationResult;

    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Pulls OUR OWN `reference` back out of a webhook payload, so the
     * caller knows which Deposit row an event is about before doing
     * anything else with it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function referenceFromWebhookPayload(array $payload): ?string;
}
