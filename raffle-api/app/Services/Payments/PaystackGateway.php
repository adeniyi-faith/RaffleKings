<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\Legacy\WpUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Paystack — https://paystack.com/docs/payments/accept-payments/
 *
 * Paystack deals in kobo (₦1 = 100 kobo); this class converts at the
 * edge of every call so the rest of the app only ever sees naira.
 */
class PaystackGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(private readonly ?string $secretKey) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function initialize(WpUser $user, float $amount, string $reference, string $callbackUrl): PaymentInitializationResult
    {
        if (! $this->secretKey) {
            throw new PaymentGatewayException('Paystack is not configured (missing PAYSTACK_SECRET_KEY).');
        }

        $response = Http::withToken($this->secretKey)
            ->baseUrl(self::BASE_URL)
            ->timeout(15)
            ->post('/transaction/initialize', [
                'email' => $user->user_email,
                'amount' => (int) round($amount * 100),
                'reference' => $reference,
                'callback_url' => $callbackUrl,
            ]);

        if ($response->failed() || ! $response->json('status')) {
            throw new PaymentGatewayException('Paystack initialization failed: '.($response->json('message') ?? $response->body()));
        }

        $authorizationUrl = $response->json('data.authorization_url');

        if (! $authorizationUrl) {
            throw new PaymentGatewayException('Paystack initialization returned no authorization URL.');
        }

        return new PaymentInitializationResult(authorizationUrl: $authorizationUrl);
    }

    public function verify(string $reference): PaymentVerificationResult
    {
        if (! $this->secretKey) {
            throw new PaymentGatewayException('Paystack is not configured (missing PAYSTACK_SECRET_KEY).');
        }

        $response = Http::withToken($this->secretKey)
            ->baseUrl(self::BASE_URL)
            ->timeout(15)
            ->get('/transaction/verify/'.urlencode($reference));

        if ($response->failed()) {
            throw new PaymentGatewayException('Paystack verification failed: '.$response->body());
        }

        $data = $response->json('data', []);
        $status = $data['status'] ?? 'unknown';

        return new PaymentVerificationResult(
            successful: $status === 'success',
            amount: ((float) ($data['amount'] ?? 0)) / 100,
            currency: $data['currency'] ?? 'NGN',
            gatewayTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            rawStatus: $status,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        if (! $this->secretKey) {
            return false;
        }

        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha512', $request->getContent(), $this->secretKey);

        return hash_equals($expected, $signature);
    }

    public function referenceFromWebhookPayload(array $payload): ?string
    {
        return $payload['data']['reference'] ?? null;
    }
}
