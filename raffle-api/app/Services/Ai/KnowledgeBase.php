<?php

namespace App\Services\Ai;

use App\Models\KnowledgeArticle;
use App\Support\GuideTokens;
use Illuminate\Support\Str;

/**
 * Picks the Knowledge base articles most likely to answer a question, so
 * the AI is only ever shown platform facts staff wrote (never guesses).
 */
class KnowledgeBase
{
    private const CHAR_BUDGET = 24000;

    /** The relevant articles as one block of text ('' when there is nothing). */
    public function contextFor(string $question): string
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($question), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => mb_strlen($w) > 2)->unique()->values();

        $articles = KnowledgeArticle::query()->where('is_active', true)->get()
            ->map(function (KnowledgeArticle $a) use ($words) {
                $haystack = Str::lower($a->title.' '.$a->body);
                $score = $words->sum(fn ($w) => str_contains($haystack, $w) ? (str_contains(Str::lower($a->title), $w) ? 3 : 1) : 0);

                return ['article' => $a, 'score' => $score];
            })
            ->sortByDesc('score');

        $out = '';
        foreach ($articles as $row) {
            // With a big base, skip articles that share no words with the question.
            if ($row['score'] === 0 && $articles->count() > 12) {
                continue;
            }

            $chunk = "### {$row['article']->title}\n".Str::limit(strip_tags(GuideTokens::fill($row['article']->body)), 6000, '')."\n\n";
            if (mb_strlen($out) + mb_strlen($chunk) > self::CHAR_BUDGET) {
                break;
            }
            $out .= $chunk;
        }

        return trim($out);
    }
}
