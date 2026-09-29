<?php

namespace App\Settings;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Throwable;

/**
 * The Settings page's "Check" buttons: one small, harmless request to each
 * provider to prove a key works before customers depend on it. Nothing is
 * charged or changed at the provider. Each returns [worked?, plain message].
 */
final class ConnectionTester
{
    /** @return array{0: bool, 1: string} */
    public function paystack(?string $secretKey): array
    {
        if (blank($secretKey)) {
            return [false, 'No Paystack secret key is saved yet.'];
        }

        return $this->check(
            fn () => Http::withToken($secretKey)->timeout(15)->get('https://api.paystack.co/balance'),
            fn (Response $r) => 'Paystack accepted the key'.(str_starts_with($secretKey, 'sk_test_') ? ' (TEST mode: customers can\'t really pay).' : ' (live mode).'),
            'Paystack',
        );
    }

    /** @return array{0: bool, 1: string} */
    public function flutterwave(?string $secretKey): array
    {
        if (blank($secretKey)) {
            return [false, 'No Flutterwave secret key is saved yet.'];
        }

        return $this->check(
            fn () => Http::withToken($secretKey)->timeout(15)->get('https://api.flutterwave.com/v3/banks/NG'),
            fn (Response $r) => 'Flutterwave accepted the key'.(str_contains($secretKey, '_TEST') ? ' (TEST mode: customers can\'t really pay).' : '.'),
            'Flutterwave',
        );
    }

    /** @return array{0: bool, 1: string} */
    public function gemini(?string $apiKey, ?string $model): array
    {
        if (blank($apiKey)) {
            return [false, 'No Gemini API key is saved yet.'];
        }

        return $this->check(
            fn () => Http::timeout(15)->get('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode((string) $model), ['key' => $apiKey]),
            fn (Response $r) => 'Gemini accepted the key and the model "'.($r->json('displayName') ?? $model).'" is available.',
            'Gemini',
        );
    }

    /** @return array{0: bool, 1: string} */
    public function brevo(?string $apiKey): array
    {
        if (blank($apiKey)) {
            return [false, 'No Brevo API key is saved yet.'];
        }

        return $this->check(
            fn () => Http::withHeaders(['api-key' => $apiKey, 'accept' => 'application/json'])->timeout(15)->get('https://api.brevo.com/v3/account'),
            fn (Response $r) => 'Brevo accepted the key (account: '.($r->json('email') ?? 'unknown').').',
            'Brevo',
        );
    }

    /**
     * @param  list<string>  $chatIds
     * @return array{0: bool, 1: string}
     */
    public function telegram(?string $botToken, array $chatIds): array
    {
        if (blank($botToken)) {
            return [false, 'No Telegram bot token is saved yet.'];
        }
        if ($chatIds === []) {
            return [false, 'Add at least one chat ID to send the test alert to.'];
        }

        $failed = [];

        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout(15)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => '✅ '.config('app.name').' test alert. Staff alerts will arrive here.',
                ]);
                if ($response->failed()) {
                    $failed[] = "{$chatId} (".($response->json('description') ?? $response->status()).')';
                }
            } catch (Throwable $e) {
                return [false, 'Could not reach Telegram: '.$e->getMessage()];
            }
        }

        return $failed === []
            ? [true, 'Test alert sent to '.count($chatIds).' chat(s). Check Telegram.']
            : [false, 'Telegram refused: '.implode(', ', $failed).'. Has each person/group sent the bot a message first?'];
    }

    /**
     * Cloudflare answers a made-up check with "invalid-input-secret" when
     * the secret key is wrong, and "invalid-input-response" when the key
     * is fine (only the made-up check is wrong).
     *
     * @return array{0: bool, 1: string}
     */
    public function turnstile(?string $secretKey, ?string $siteKey): array
    {
        if (blank($secretKey) || blank($siteKey)) {
            return [false, 'Fill in BOTH the site key and the secret key. The check stays off until both are set.'];
        }

        try {
            $codes = (array) Http::asForm()->timeout(15)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secretKey, 'response' => 'settings-page-check'])
                ->json('error-codes', []);
        } catch (Throwable $e) {
            return [false, 'Could not reach Cloudflare: '.$e->getMessage()];
        }

        return in_array('invalid-input-secret', $codes, true)
            ? [false, 'Cloudflare rejected the secret key. Copy it again from the Turnstile page.']
            : [true, 'Cloudflare accepted the secret key. Open the sign-up page to see the check.'];
    }

    /** Sends through whatever is saved in Settings → Email right now. @return array{0: bool, 1: string} */
    public function email(string $to): array
    {
        if (config('mail.default') === 'log') {
            return [false, 'Email is set to "Don\'t send (log only)". Choose Brevo or an email server and save first.'];
        }

        try {
            Mail::raw(
                'This is a test email from '.config('app.name')."'s admin Settings page.\n\nIf you're reading this, customer emails (password resets, receipts, alerts) will be delivered.",
                fn ($message) => $message->to($to)->subject(config('app.name').': test email'),
            );
        } catch (Throwable $e) {
            return [false, 'Sending failed: '.$e->getMessage()];
        }

        return [true, "Test email sent to {$to}. Check the inbox (and spam folder)."];
    }

    /**
     * Pusher (live updates, Phase 9): a signed request for the app's
     * channels proves the app id, key, secret and cluster all match.
     *
     * @return array{0: bool, 1: string}
     */
    public function pusher(?string $appId, ?string $key, ?string $secret, ?string $cluster): array
    {
        if (blank($appId) || blank($key) || blank($secret)) {
            return [false, 'Enter the app_id, key and secret first (all three are on the Pusher app\'s "App Keys" page).'];
        }

        try {
            (new Pusher($key, $secret, $appId, ['cluster' => $cluster ?: 'mt1', 'useTLS' => true]))->getChannels();
        } catch (ApiErrorException $e) {
            return [false, in_array($e->getCode(), [401, 403], true)
                ? 'Pusher rejected the keys. Check the app_id, key and secret were copied in full, with no spaces.'
                : 'Pusher replied with an error ('.$e->getCode().'). Check the cluster (e.g. eu or mt1).'];
        } catch (Throwable $e) {
            return [false, 'Could not reach Pusher. Check the cluster (e.g. eu or mt1).'];
        }

        return [true, 'Pusher accepted the keys. Set "Live updates" to On and save.'];
    }

    /** @return array{0: bool, 1: string} */
    private function check(callable $request, callable $success, string $provider): array
    {
        try {
            $response = $request();
        } catch (Throwable $e) {
            return [false, "Could not reach {$provider}: ".$e->getMessage()];
        }

        if ($response->successful()) {
            return [true, $success($response)];
        }

        return [false, match ($response->status()) {
            401, 403 => "{$provider} rejected the key. Check it was copied in full, with no spaces.",
            400, 404 => "{$provider} did not recognise the request. Check the key (and the model name).",
            default => "{$provider} replied with an error ({$response->status()}). Try again in a minute.",
        }];
    }
}
