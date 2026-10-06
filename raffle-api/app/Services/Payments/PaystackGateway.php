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
            throw new PaymentGatewayException('Paystack initialization failed: '.mb_substr((string) ($response->json('message') ?? 'HTTP '.$response->status()), 0, 160));
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
            // Only the status code and Paystack's own short message: never the
            // whole reply, which can carry customer details (audit K1).
            throw new PaymentGatewayException('Paystack verification failed (HTTP '.$response->status().'): '.mb_substr((string) $response->json('message'), 0, 120), unclear: true);
        }

        $data = $response->json('data', []);
        $status = $data['status'] ?? 'unknown';

        // A "success" with no amount or currency can't be credited: treat it as
        // an error to look at again, never as ₦0 or as naira by default.
        if ($status === 'success' && (! isset($data['amount']) || ! is_numeric($data['amount']) || empty($data['currency']))) {
            throw new PaymentGatewayException('Paystack said success but left out the amount or currency.', unclear: true);
        }

        return new PaymentVerificationResult(
            successful: $status === 'success',
            amount: ((float) ($data['amount'] ?? 0)) / 100,
            currency: (string) ($data['currency'] ?? ''),
            gatewayTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            rawStatus: $status,
            // With "customer pays the fees" on in Paystack, `amount` includes
            // Paystack's fee and `requested_amount` is what we asked for.
            requestedAmount: isset($data['requested_amount']) ? ((float) $data['requested_amount']) / 100 : null,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        if (! $this->secretKey) {
            return false;
        }

        $signature = $request->header('x-paystack-signature');

        // With no secret key, anyone could produce a "valid" signature.
        if (! $signature || ! $this->secretKey) {
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
