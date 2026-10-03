<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use App\Models\AiRequest;
use Illuminate\Support\Facades\Http;

/**
 * The one place the app talks to Gemini for writing and replying. Checks
 * the master switch and the daily cap, sends the key in a header (never in
 * the web address, where it could end up in logs), and records each call.
 */
class GeminiClient
{
    public function available(): bool
    {
        return (bool) config('ai.enabled') && filled(config('services.gemini.api_key'));
    }

    /**
     * @param  list<array{mime_type: string, data: string}>  $files  Optional files (base64) to read, e.g. a PDF.
     * @param  array<string, mixed>|null  $schema  Optional JSON schema the answer must follow (implies $json).
     * @param  int  $maxTokens  Longest answer allowed; long structured answers (the Raffle advisor) need more.
     *
     * @throws AiUnavailableException
     */
    public function generate(string $purpose, string $system, string $prompt, bool $json = false, ?int $ticketId = null, array $files = [], ?array $schema = null, int $maxTokens = 2048): string
    {
        if (! config('ai.enabled')) {
            throw new AiUnavailableException('AI helpers are switched off in Settings → AI.');
        }

        $apiKey = config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new AiUnavailableException('No Gemini key is saved yet. Add one in Settings → AI.');
        }

        $limit = (int) config('ai.daily_limit');
        if (AiRequest::query()->where('created_at', '>=', now()->startOfDay())->count() >= $limit) {
            throw new AiUnavailableException("Today's AI limit ({$limit} calls) has been reached. It resets tomorrow, or raise it in Settings → AI.");
        }

        $model = config('services.gemini.assistant_model') ?: 'gemini-3-flash-preview';

        $parts = [['text' => $prompt]];
        foreach ($files as $file) {
            $parts[] = ['inline_data' => ['mime_type' => $file['mime_type'], 'data' => $file['data']]];
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens]
                + ($json || $schema ? ['responseMimeType' => 'application/json'] : [])
                + ($schema ? ['responseJsonSchema' => $schema] : []),
        ];

        try {
            // Long answers (the Raffle advisor, written in the background) get longer to arrive.
            $response = Http::timeout($maxTokens > 4096 ? 240 : 45)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', $body);
        } catch (\Throwable $e) {
            $this->log($purpose, $model, $ticketId, false, 'unreachable');

            throw new AiUnavailableException('Could not reach Gemini. Try again in a moment.', previous: $e);
        }

        if ($response->failed()) {
            $this->log($purpose, $model, $ticketId, false, 'HTTP '.$response->status());

            throw new AiUnavailableException(match ($response->status()) {
                400, 404 => "Gemini did not accept the model \"{$model}\". Choose another model in Settings → AI.",
                401, 403 => 'Gemini rejected the key. Check it in Settings → AI.',
                429 => 'Gemini is busy or the quota is used up. Try again shortly.',
                default => 'Gemini returned an error (HTTP '.$response->status().').',
            });
        }

        // Join the answer parts, skipping any "thinking" parts a newer model may include.
        $text = collect($response->json('candidates.0.content.parts', []))
            ->reject(fn ($p) => ! empty($p['thought']))
            ->pluck('text')
            ->implode('');

        $text = trim($text);
        if ($text === '') {
            $this->log($purpose, $model, $ticketId, false, 'empty');

            throw new AiUnavailableException('Gemini sent back nothing. Try again.');
        }

        $this->log($purpose, $model, $ticketId, true);

        return $text;
    }

    private function log(string $purpose, string $model, ?int $ticketId, bool $ok, ?string $error = null): void
    {
        AiRequest::create([
            'purpose' => mb_substr($purpose, 0, 60),
            'model' => mb_substr($model, 0, 80),
            'support_ticket_id' => $ticketId,
            'succeeded' => $ok,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
