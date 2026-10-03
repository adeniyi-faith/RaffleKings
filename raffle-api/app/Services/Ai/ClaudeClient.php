<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\AiUnavailableException;
use App\Models\AiRequest;
use Psr\Http\Client\ClientInterface;

/**
 * The one place the app talks to Claude (Anthropic). Used by the Raffle
 * advisor. Like GeminiClient it obeys the same master switch and daily cap
 * in Settings → AI, and records each call (what it was for, never the text).
 */
class ClaudeClient
{
    /** @param  ClientInterface|null  $transporter  Tests pass a fake here instead of reaching the internet. */
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function available(): bool
    {
        return (bool) config('ai.enabled') && filled(config('services.anthropic.api_key'));
    }

    public function model(): string
    {
        return config('services.anthropic.advisor_model') ?: 'claude-opus-5-5';
    }

    /**
     * Ask for an answer that must match a JSON schema, and get it back as an array.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws AiUnavailableException
     */
    public function json(string $purpose, string $system, string $prompt, array $schema, int $maxTokens = 16000): array
    {
        if (! config('ai.enabled')) {
            throw new AiUnavailableException('AI helpers are switched off in Settings → AI.');
        }

        $apiKey = config('services.anthropic.api_key');
        if (blank($apiKey)) {
            throw new AiUnavailableException('No Claude (Anthropic) key is saved yet. Add one in Settings → AI.');
        }

        $limit = (int) config('ai.daily_limit');
        if (AiRequest::query()->where('created_at', '>=', now()->startOfDay())->count() >= $limit) {
            throw new AiUnavailableException("Today's AI limit ({$limit} calls) has been reached. It resets tomorrow, or raise it in Settings → AI.");
        }

        $model = $this->model();
        $client = new Client(apiKey: $apiKey, requestOptions: array_filter([
            'timeout' => 300.0,
            'maxRetries' => 2,
            'transporter' => $this->transporter,
        ], fn ($v) => $v !== null));

        try {
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                model: $model,
                system: $system,
                messages: [['role' => 'user', 'content' => $prompt]],
                thinking: ['type' => 'adaptive'],
                outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                // If the chosen model declines for a safety reason, Anthropic retries on its default substitute.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIConnectionException $e) {
            $this->log($purpose, $model, false, 'unreachable');

            throw new AiUnavailableException('Could not reach Claude. Try again in a moment.', previous: $e);
        } catch (APIStatusException $e) {
            $this->log($purpose, $model, false, 'HTTP '.$e->status);

            throw new AiUnavailableException(match (true) {
                $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => 'Claude rejected the key. Check it in Settings → AI.',
                $e instanceof NotFoundException, $e instanceof BadRequestException => "Claude did not accept the request for the model \"{$model}\". Choose another model in Settings → AI.",
                $e instanceof RateLimitException => 'Claude is busy or the account has run out of credit. Try again shortly.',
                default => 'Claude returned an error (HTTP '.$e->status.').',
            }, previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            $this->log($purpose, $model, false, 'refused');

            throw new AiUnavailableException('Claude declined to answer this one. Try rewording what you asked for.');
        }

        if ($message->stopReason === 'max_tokens') {
            $this->log($purpose, $model, false, 'cut off');

            throw new AiUnavailableException('Claude\'s answer was too long and got cut off. Try again, or ask about one thing at a time.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $data = json_decode(trim($text), true);
        if (! is_array($data)) {
            $this->log($purpose, $model, false, 'unreadable');

            throw new AiUnavailableException('Claude sent back an answer the site could not read. Try again.');
        }

        $this->log($purpose, $message->model ?: $model, true);

        return $data;
    }

    private function log(string $purpose, string $model, bool $ok, ?string $error = null): void
    {
        AiRequest::create([
            'purpose' => mb_substr($purpose, 0, 60),
            'model' => mb_substr($model, 0, 80),
            'succeeded' => $ok,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
