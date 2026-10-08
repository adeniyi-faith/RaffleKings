<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use App\Models\AiRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Web search for the admin's AI helpers, through Exa (exa.ai). Used by
 * Daily predictions (real fixtures and results), "Write with AI" (fresh
 * facts) and the Raffle advisor (prize trends). The key is sent in a
 * header, never in the web address. Each search is logged with the AI
 * calls and has its own daily cap. Only search words go to Exa, never
 * customer details.
 */
class ExaSearch
{
    public const NO_KEY_MESSAGE = 'No Exa key is saved yet. Add one in Settings → AI → Exa web search.';

    /** Whether the web search options should show at all. */
    public function available(): bool
    {
        return (bool) config('ai.enabled') && filled(config('services.exa.api_key'));
    }

    /**
     * Options: days = only pages from the last N days; news = news articles
     * only; domains = only these sites; chars = how much text to read from each page.
     *
     * @param  array{days?: int, news?: bool, domains?: list<string>, chars?: int}  $options
     * @return list<array{title: string, url: string, published: ?string, text: string}>
     *
     * @throws AiUnavailableException
     */
    public function search(string $purpose, string $query, int $results = 5, array $options = []): array
    {
        if (! config('ai.enabled')) {
            throw new AiUnavailableException('AI helpers are switched off in Settings → AI.');
        }

        $apiKey = config('services.exa.api_key');
        if (blank($apiKey)) {
            throw new AiUnavailableException(self::NO_KEY_MESSAGE);
        }

        $limit = (int) config('ai.web_search_daily_limit', 100);
        if (AiRequest::query()->where('model', 'exa')->where('created_at', '>=', now()->startOfDay())->count() >= $limit) {
            throw new AiUnavailableException("Today's web search limit ({$limit}) has been reached. It resets tomorrow, or raise it in Settings → AI → Exa web search.");
        }

        $body = array_filter([
            'query' => mb_substr(trim($query), 0, 500),
            'type' => 'auto',
            'numResults' => max(1, min(10, $results)),
            'category' => ! empty($options['news']) ? 'news' : null,
            'startPublishedDate' => ! empty($options['days']) ? now()->subDays((int) $options['days'])->startOfDay()->toIso8601ZuluString() : null,
            'includeDomains' => $options['domains'] ?? null,
            'contents' => ['text' => ['maxCharacters' => (int) ($options['chars'] ?? 1500)]],
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $response = Http::timeout(30)
                ->withHeaders(['x-api-key' => $apiKey])
                ->acceptJson()
                ->post('https://api.exa.ai/search', $body);
        } catch (Throwable $e) {
            $this->log($purpose, false, 'unreachable');

            throw new AiUnavailableException('Could not reach Exa web search. Try again in a moment.', previous: $e);
        }

        if ($response->failed()) {
            $this->log($purpose, false, 'HTTP '.$response->status());

            throw new AiUnavailableException(match ($response->status()) {
                401, 403 => 'Exa rejected the key. Check it in Settings → AI → Exa web search.',
                402 => 'The Exa account is out of credit. Top it up at dashboard.exa.ai.',
                429 => 'Exa is busy or the quota is used up. Try again shortly.',
                default => 'Exa web search returned an error (HTTP '.$response->status().').',
            });
        }

        $this->log($purpose, true);

        return collect($response->json('results', []))
            ->filter(fn ($r) => is_array($r) && filled($r['url'] ?? null) && str_starts_with((string) $r['url'], 'http'))
            ->map(fn (array $r) => [
                'title' => mb_substr(trim((string) ($r['title'] ?? '')), 0, 200),
                'url' => (string) $r['url'],
                'published' => isset($r['publishedDate']) ? mb_substr((string) $r['publishedDate'], 0, 10) : null,
                'text' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($r['text'] ?? implode(' ', (array) ($r['highlights'] ?? []))))), 0, (int) ($options['chars'] ?? 1500)),
            ])
            ->values()
            ->all();
    }

    /**
     * Search results written out for a Gemini prompt, numbered so the AI
     * can say which one it used.
     *
     * @param  list<array{title: string, url: string, published: ?string, text: string}>  $results
     */
    public static function asContext(array $results): string
    {
        return collect($results)->values()->map(fn (array $r, int $i) => '['.($i + 1).'] '.$r['title']
            .($r['published'] ? " ({$r['published']})" : '')."\n".$r['url']."\n".$r['text'])
            ->implode("\n\n");
    }

    private function log(string $purpose, bool $ok, ?string $error = null): void
    {
        AiRequest::create([
            'purpose' => mb_substr('web:'.$purpose, 0, 60),
            'model' => 'exa',
            'succeeded' => $ok,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
