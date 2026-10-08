<?php

namespace App\Services\Engagement;

use App\Exceptions\AiUnavailableException;
use App\Models\Prediction;
use App\Services\Ai\ExaSearch;
use App\Services\Ai\GeminiClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Daily predictions written by AI.
 *
 * draft(): when Exa web search is set up, it first looks up real upcoming
 * matches and news, then Gemini writes questions from what it found. Every
 * question is saved as a DRAFT that customers can't see; staff check it
 * and press "Publish". Nothing is published by itself.
 *
 * checkResult(): after a question closes, it searches the web for what
 * actually happened and suggests the right answer with the page it read.
 * Staff still press "Settle" themselves, because settling pays points.
 */
class PredictionWriter
{
    public const ANY = 'any';

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ExaSearch $exa,
    ) {}

    /**
     * @param  string  $category  A Prediction::CATEGORIES key, or "any" to let the AI choose.
     * @return list<Prediction> The drafts saved.
     *
     * @throws AiUnavailableException
     */
    public function draft(string $category, int $count, ?string $focus = null, bool $useWeb = true): array
    {
        $count = max(1, min(10, $count));
        $category = $category === self::ANY || array_key_exists($category, Prediction::CATEGORIES) ? $category : self::ANY;
        $focus = filled($focus) ? mb_substr(trim($focus), 0, 300) : null;
        $tz = (string) config('raffles.timezone');
        $now = now()->setTimezone($tz);

        $sources = [];
        if ($useWeb && $this->exa->available() && $category !== 'raffle') {
            $sources = $this->exa->search('predictions', $this->searchQuery($category, $focus, $now), 8, ['days' => 7, 'chars' => 2000]);
        }

        $categories = $category === self::ANY ? array_keys(Prediction::CATEGORIES) : [$category];
        $site = config('app.name');
        $rules = trim((string) config('ai.instructions'));

        $system = "You write free daily prediction questions for {$site}, a Nigerian raffle site. Customers pick an answer; a right answer earns points.\n"
            ."Write clear, simple English that ordinary Nigerian customers understand.\n"
            ."Every question must have one answer that will be clearly known after it closes (a match result, a score, a yes/no event), or for a quiz, one correct fact.\n"
            ."Use only facts from the web results you are given for real events (teams, dates, kick-off times). Never invent fixtures, dates or results. If the results don't give a kick-off time, don't write a question about that match.\n"
            ."No questions about politics, religion, tragedies, crime or anything upsetting. No gambling odds.\n"
            .($rules !== '' ? "House rules from the team: {$rules}\n" : '');

        $prompt = 'Today is '.$now->format('l j F Y, H:i')." ({$tz} time).\n"
            ."Write {$count} question(s). Allowed categories: ".implode(', ', $categories).".\n"
            .($focus ? "The team wants questions about: {$focus}\n" : '')
            ."Each question has 2 to 4 short answers (under 40 characters each); for a match use \"Home team win\", \"Draw\", \"Away team win\" style answers with the real team names.\n"
            ."closes_at: when answers must stop, as ISO 8601 with the {$tz} offset. For a match, the kick-off time. For a quiz, the end of tomorrow. It must be in the future and within 14 days.\n"
            ."source: the number of the web result the question is based on, or 0 if none.\n"
            ."answer_index: for a quiz, the index (from 0) of the right answer; otherwise -1.\n"
            ."note: one short line for the staff (what to check before publishing).\n"
            .($sources !== [] ? "\nWeb results:\n".ExaSearch::asContext($sources) : "\nNo web results: write only quiz or raffle trivia questions that don't depend on dates.");

        $answer = $this->gemini->generate('predictions:draft', $system, $prompt, schema: $this->schema($categories), maxTokens: 4096);
        $items = (array) (json_decode($answer, true)['questions'] ?? []);

        $saved = [];

        foreach (array_slice($items, 0, $count) as $item) {
            if ($prediction = $this->saveDraft((array) $item, $categories, $sources, $now)) {
                $saved[] = $prediction;
            }
        }

        return $saved;
    }

    /**
     * Looks up the real result and stores the suggestion on the question.
     *
     * @return array{option: ?int, note: string, source: ?string}
     *
     * @throws AiUnavailableException
     */
    public function checkResult(Prediction $prediction): array
    {
        $sources = [];
        if ($this->exa->available()) {
            $days = max(2, (int) ceil(($prediction->opens_at ?? $prediction->created_at ?? now()->subDays(3))->diffInDays(now(), true)) + 2);
            $sources = $this->exa->search('predictions:result', 'Result: '.$prediction->question, 6, ['days' => min(30, $days), 'chars' => 1500]);
        }

        $options = collect($prediction->options)->map(fn ($o, $i) => "{$i}: {$o}")->implode("\n");

        $system = "You check the result of a prediction question for a raffle site's staff. Only answer from the web results given; never guess.\n"
            ."If the results don't clearly show the outcome (for example the match hasn't been played or the pages disagree), answer_index must be -1.";

        $prompt = "Question: {$prediction->question}\nIt closed at ".$prediction->closes_at->setTimezone(config('raffles.timezone'))->format('j M Y H:i')."\n"
            ."Answers:\n{$options}\n"
            ."answer_index: the right answer's number, or -1 if not certain.\n"
            ."source: the number of the web result that shows it, or 0.\n"
            ."note: one or two short sentences for the staff, e.g. the final score.\n"
            .($sources !== [] ? "\nWeb results:\n".ExaSearch::asContext($sources) : "\nNo web results were found (web search may be off). Unless this is a general-knowledge quiz you are certain about, answer -1.");

        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['answer_index', 'source', 'note'], 'properties' => [
            'answer_index' => ['type' => 'integer'],
            'source' => ['type' => 'integer'],
            'note' => ['type' => 'string'],
        ]];

        $data = (array) json_decode($this->gemini->generate('predictions:result', $system, $prompt, schema: $schema, maxTokens: 1024), true);

        $option = isset($data['answer_index']) && array_key_exists((int) $data['answer_index'], $prediction->options) ? (int) $data['answer_index'] : null;
        $source = $sources[((int) ($data['source'] ?? 0)) - 1]['url'] ?? null;
        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 500) ?: ($option === null ? 'The AI could not find a clear result.' : '');

        $prediction->update([
            'suggested_option' => $option,
            'ai_note' => 'Result check: '.$note,
            'source_url' => $source ?? $prediction->source_url,
        ]);

        return ['option' => $option, 'note' => $note, 'source' => $source];
    }

    /** The morning job: a few drafts, when switched on (Settings → Community → Daily predictions). */
    public function dailyDrafts(): int
    {
        if (! config('engagement.predictions.ai_daily') || ! $this->gemini->available()) {
            return 0;
        }

        try {
            return count($this->draft(self::ANY, (int) config('engagement.predictions.ai_daily_count', 3), config('engagement.predictions.ai_focus')));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function searchQuery(string $category, ?string $focus, Carbon $now): string
    {
        $week = $now->format('j F Y');

        return match (true) {
            $focus !== null => "{$focus} upcoming fixtures and news week of {$week}",
            $category === 'football', $category === self::ANY => "football fixtures kick-off times this week {$week} Premier League Champions League Nigeria Super Eagles NPFL",
            default => "Nigeria trending news this week {$week}",
        };
    }

    /**
     * @param  list<string>  $categories
     * @param  list<array{url: string}>  $sources
     */
    private function saveDraft(array $item, array $categories, array $sources, Carbon $now): ?Prediction
    {
        $question = mb_substr(trim(strip_tags((string) ($item['question'] ?? ''))), 0, 255);
        $options = collect((array) ($item['options'] ?? []))
            ->map(fn ($o) => mb_substr(trim(strip_tags((string) $o)), 0, 60))
            ->filter()->unique()->values()->take(4)->all();

        if ($question === '' || count($options) < 2) {
            return null;
        }

        $category = in_array($item['category'] ?? null, $categories, true) ? $item['category'] : $categories[0];
        $note = mb_substr(trim((string) ($item['note'] ?? '')), 0, 400);

        try {
            $closes = Carbon::parse((string) ($item['closes_at'] ?? ''));
        } catch (Throwable) {
            $closes = null;
        }

        if (! $closes || $closes->lte(now()->addMinutes(30)) || $closes->gt(now()->addDays(14))) {
            $closes = $now->copy()->addDay()->endOfDay()->setSecond(0);
            $note = trim('Check the closing time: the AI did not give a usable one. '.$note);
        }

        $answer = (int) ($item['answer_index'] ?? -1);

        return Prediction::create([
            'category' => $category,
            'question' => $question,
            'options' => $options,
            'points' => (int) config('engagement.predictions.default_points', 50),
            // Saved in the app's own time zone (the database stores no offset).
            'closes_at' => $closes->copy()->setTimezone(config('app.timezone')),
            'is_draft' => true,
            'source_url' => $sources[((int) ($item['source'] ?? 0)) - 1]['url'] ?? null,
            'ai_note' => $note !== '' ? $note : null,
            'suggested_option' => array_key_exists($answer, $options) ? $answer : null,
        ]);
    }

    /** @param  list<string>  $categories */
    private function schema(array $categories): array
    {
        $question = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['category', 'question', 'options', 'closes_at', 'source', 'answer_index', 'note'],
            'properties' => [
                'category' => ['type' => 'string', 'enum' => $categories],
                'question' => ['type' => 'string'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 4],
                'closes_at' => ['type' => 'string'],
                'source' => ['type' => 'integer'],
                'answer_index' => ['type' => 'integer'],
                'note' => ['type' => 'string'],
            ]];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['questions'], 'properties' => [
            'questions' => ['type' => 'array', 'items' => $question],
        ]];
    }
}
