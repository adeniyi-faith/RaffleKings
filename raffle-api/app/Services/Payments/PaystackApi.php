<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Paystack's bank and transfer APIs — the bank-name check and automatic
 * payouts (Settings → On / off → New features). Top-ups use
 * PaystackGateway; this is the money-going-out side.
 *
 * Amounts are naira here and converted to kobo (×100) at the edge, like
 * PaystackGateway. Every failure throws PaymentGatewayException with a
 * plain message that is safe to show staff and customers.
 *
 * https://paystack.com/docs/identity-verification/verify-account-number/
 * https://paystack.com/docs/transfers/single-transfers/
 */
class PaystackApi
{
    private const BASE_URL = 'https://api.paystack.co';

    public const BANKS_CACHE_KEY = 'paystack:banks:ng';

    public function configured(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    /**
     * Nigerian banks and wallets that can receive money, alphabetical.
     * Cached for a day: the list rarely changes.
     *
     * @return list<array{code: string, name: string}>
     */
    public function banks(): array
    {
        $cached = Cache::get(self::BANKS_CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->get('/bank', ['country' => 'nigeria', 'currency' => 'NGN', 'perPage' => 500]));

        $banks = collect($response->json('data', []))
            ->filter(fn ($b) => ($b['active'] ?? true) && ! ($b['is_deleted'] ?? false) && filled($b['code'] ?? null))
            ->map(fn ($b) => ['code' => (string) $b['code'], 'name' => trim((string) $b['name'])])
            ->unique('code')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        if ($banks !== []) {
            Cache::put(self::BANKS_CACHE_KEY, $banks, now()->addDay());
        }

        return $banks;
    }

    public function bankName(string $code): ?string
    {
        return collect($this->banks())->firstWhere('code', $code)['name'] ?? null;
    }

    /**
     * The name the bank holds for this account, or null when the bank says
     * there's no such account. Remembered for 30 minutes so the "check"
     * and the "save" a moment later cost one call, not two.
     *
     * @throws PaymentGatewayException when Paystack can't be reached or refuses the key
     */
    public function resolveAccountName(string $accountNumber, string $bankCode): ?string
    {
        $key = "paystack:resolve:{$bankCode}:{$accountNumber}";

        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        $response = $this->send(
            fn (PendingRequest $http) => $http->get('/bank/resolve', ['account_number' => $accountNumber, 'bank_code' => $bankCode]),
            allowClientErrors: true,
        );

        // Paystack answers 422/400 "Could not resolve account name" for a wrong number.
        if ($response->clientError() && ! in_array($response->status(), [401, 403, 429], true)) {
            Cache::put($key, '', now()->addMinutes(30));

            return null;
        }

        $this->throwIfFailed($response);

        $name = trim((string) $response->json('data.account_name'));
        Cache::put($key, $name, now()->addMinutes(30));

        return $name !== '' ? $name : null;
    }

    /** Paystack's id for "this person's bank account", needed before sending money. */
    public function createRecipient(string $name, string $accountNumber, string $bankCode): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'NGN',
        ]));

        $code = (string) $response->json('data.recipient_code');

        if ($code === '') {
            throw new PaymentGatewayException('Paystack did not return a recipient for this bank account.');
        }

        return $code;
    }

    /**
     * Starts a transfer. Paystack treats a repeated reference as the same
     * transfer, so retrying after a timeout can never pay twice.
     *
     * status is Paystack's: success, pending, processing, otp, failed, reversed…
     *
     * @return array{status: string, transfer_code: ?string, message: ?string}
     */
    public function transfer(float $amount, string $recipientCode, string $reference, string $reason): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transfer', [
            'source' => 'balance',
            'amount' => (int) round($amount * 100),
            'recipient' => $recipientCode,
            'reference' => $reference,
            'reason' => mb_substr($reason, 0, 100),
            'currency' => 'NGN',
        ]));

        return [
            'status' => (string) $response->json('data.status', 'pending'),
            'transfer_code' => $response->json('data.transfer_code'),
            'message' => $response->json('message'),
        ];
    }

    /**
     * Where a transfer is now, or null if Paystack has no transfer with
     * this reference (the request never reached them).
     *
     * @return array{status: string, reason: ?string}|null
     */
    public function verifyTransfer(string $reference): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/transfer/verify/'.rawurlencode($reference)), allowClientErrors: true);

        if ($response->status() === 404 || ($response->clientError() && str_contains(strtolower((string) $response->json('message')), 'not found'))) {
            return null;
        }

        $this->throwIfFailed($response);

        return [
            'status' => (string) $response->json('data.status'),
            'reason' => $response->json('data.failures') ? json_encode($response->json('data.failures')) : ($response->json('data.reason') ?: null),
        ];
    }

    /** The naira in the Paystack balance that payouts are sent from. Cached a minute. */
    public function balance(): ?float
    {
        return Cache::remember('paystack:balance', 60, function () {
            $response = $this->send(fn (PendingRequest $http) => $http->get('/balance'));

            $ngn = collect($response->json('data', []))->firstWhere('currency', 'NGN');

            return $ngn ? ((float) $ngn['balance']) / 100 : null;
        });
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, bool $allowClientErrors = false): Response
    {
        if (! $this->configured()) {
            throw new PaymentGatewayException('Paystack is not set up. Add the secret key in Settings → Payments → Paystack.');
        }

        try {
            $response = $call(Http::withToken((string) config('services.paystack.secret_key'))->baseUrl(self::BASE_URL)->acceptJson()->timeout(20));
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Could not reach Paystack. Try again in a minute.', previous: $e);
        }

        if (! ($allowClientErrors && $response->clientError())) {
            $this->throwIfFailed($response);
        }

        return $response;
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful() && $response->json('status') !== false) {
            return;
        }

        $message = (string) ($response->json('message') ?: 'HTTP '.$response->status());

        throw new PaymentGatewayException(match (true) {
            in_array($response->status(), [401, 403], true) => 'Paystack refused the secret key. Check Settings → Payments → Paystack.',
            $response->status() === 429 => 'Paystack is busy (too many requests). Try again in a minute.',
            $response->serverError() => 'Paystack is having problems right now. Try again in a few minutes.',
            default => "Paystack: {$message}",
        });
    }
}
