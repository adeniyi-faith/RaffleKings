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
            throw new PaymentGatewayException('Flutterwave initialization failed: '.mb_substr((string) ($response->json('message') ?? 'HTTP '.$response->status()), 0, 160));
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
            throw new PaymentGatewayException('Flutterwave verification failed (HTTP '.$response->status().'): '.mb_substr((string) $response->json('message'), 0, 120), unclear: true);
        }

        $data = $response->json('data', []);
        $status = $data['status'] ?? 'unknown';

        if ($status === 'successful' && (! isset($data['amount']) || ! is_numeric($data['amount']) || empty($data['currency']))) {
            throw new PaymentGatewayException('Flutterwave said successful but left out the amount or currency.', unclear: true);
        }

        return new PaymentVerificationResult(
            successful: $status === 'successful',
            amount: (float) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            gatewayTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            rawStatus: $status,
        );
    }

    /**
     * Every successful payment Flutterwave took between two dates (for the
     * nightly comparison with our own records).
     *
     * @return list<array{reference: string, amount: float, currency: string}>
     */
    public function successfulTransactions(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if (! $this->secretKey) {
            throw new PaymentGatewayException('Flutterwave is not configured (missing FLUTTERWAVE_SECRET_KEY).');
        }

        $rows = [];

        for ($page = 1; $page <= 100; $page++) {
            $response = Http::withToken($this->secretKey)->baseUrl(self::BASE_URL)->timeout(30)
                ->get('/transactions', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'status' => 'successful', 'page' => $page]);

            if ($response->failed()) {
                throw new PaymentGatewayException('Flutterwave listing failed (HTTP '.$response->status().').', unclear: true);
            }

            $data = $response->json('data', []);

            foreach ($data as $row) {
                $rows[] = ['reference' => (string) ($row['tx_ref'] ?? ''), 'amount' => (float) ($row['amount'] ?? 0), 'currency' => (string) ($row['currency'] ?? '')];
            }

            if (count($data) < 20 || $page >= (int) $response->json('meta.page_info.total_pages', $page)) {
                break;
            }
        }

        return $rows;
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
