<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\Legacy\WpUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave — https://developer.flutterwave.com/docs/collecting-payments/standard
 * The automatic backup gateway when Paystack can't be reached — see
 * DepositService and config/payments.php.
 *
 * Unlike Paystack, webhook signature verification is a plain string
 * comparison against a "secret hash" YOU set in Flutterwave's dashboard
 * (Settings > Webhooks) — a separate value from the API secret key.
 */
class FlutterwaveGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $webhookSecretHash,
    ) {}

    public function name(): string
    {
        return 'flutterwave';
    }

    public function initialize(WpUser $user, float $amount, string $reference, string $callbackUrl): PaymentInitializationResult
    {
        if (! $this->secretKey) {
            throw new PaymentGatewayException('Flutterwave is not configured (missing FLUTTERWAVE_SECRET_KEY).');
        }

        $response = Http::withToken($this->secretKey)
            ->baseUrl(self::BASE_URL)
            ->timeout(15)
            ->post('/payments', [
                'tx_ref' => $reference,
                'amount' => $amount,
                'currency' => 'NGN',
                'redirect_url' => $callbackUrl,
                'customer' => [
                    'email' => $user->user_email,
                    'name' => $user->display_name ?: $user->user_login,
                ],
            ]);

        if ($response->failed() || $response->json('status') !== 'success') {
            throw new PaymentGatewayException('Flutterwave initialization failed: '.($response->json('message') ?? $response->body()));
        }

        $authorizationUrl = $response->json('data.link');

        if (! $authorizationUrl) {
            throw new PaymentGatewayException('Flutterwave initialization returned no payment link.');
        }

        return new PaymentInitializationResult(authorizationUrl: $authorizationUrl);
    }

    public function verify(string $reference): PaymentVerificationResult
    {
        if (! $this->secretKey) {
            throw new PaymentGatewayException('Flutterwave is not configured (missing FLUTTERWAVE_SECRET_KEY).');
        }

        $response = Http::withToken($this->secretKey)
            ->baseUrl(self::BASE_URL)
            ->timeout(15)
            ->get('/transactions/verify_by_reference', ['tx_ref' => $reference]);

        if ($response->failed()) {
            throw new PaymentGatewayException('Flutterwave verification failed: '.$response->body());
        }

        $data = $response->json('data', []);
        $status = $data['status'] ?? 'unknown';

        return new PaymentVerificationResult(
            successful: $status === 'successful',
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'NGN',
            gatewayTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            rawStatus: $status,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        if (! $this->webhookSecretHash) {
            return false;
        }

        $provided = (string) $request->header('verif-hash');

        return $provided !== '' && hash_equals($this->webhookSecretHash, $provided);
    }

    public function referenceFromWebhookPayload(array $payload): ?string
    {
        return $payload['data']['tx_ref'] ?? null;
    }
}
