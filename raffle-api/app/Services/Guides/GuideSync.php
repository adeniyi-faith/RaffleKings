<?php

namespace App\Services\Guides;

use App\Models\KnowledgeArticle;
use App\Models\Tutorial;
use Illuminate\Support\Str;

/**
 * Puts the built-in guides into the Learning Hub (public) and the Knowledge base
 * (the support assistant's facts), one of each per guide, matched by the guide's key.
 * A guide that is already there is left exactly as staff last edited it, unless
 * $refresh is true, which rewrites it from the library.
 */
class GuideSync
{
    public function __construct(private readonly GuideLibrary $library, private readonly GuideRenderer $renderer) {}

    /** @return array{created: int, updated: int, skipped: int} */
    public function run(bool $refresh = false): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $guides = $this->library->all();
        $base = now();

        foreach ($guides as $index => $guide) {
            $tutorial = Tutorial::query()->where('guide_key', $guide['key'])->first();
            $isNew = $tutorial === null;

            if (! $isNew && ! $refresh) {
                $stats['skipped']++;

                continue;
            }

            $tutorial ??= new Tutorial(['guide_key' => $guide['key']]);
            $tutorial->fill([
                'title' => $guide['title'],
                'category' => $guide['category'],
                'read_time' => $guide['time'] ?? '3 min',
                'excerpt' => $guide['excerpt'],
                'content' => $this->renderer->html($guide),
                'image_url' => $this->imageFor($guide),
                'is_featured' => (bool) ($guide['featured'] ?? false),
                'is_published' => true,
            ]);

            if ($isNew) {
                // Listed in library order (the list shows newest first), with a steady, similar
                // number of hearts so no guide looks far more or less popular than the rest.
                $tutorial->published_at = $base->copy()->subMinutes($index);
                $tutorial->helpful_count = $this->startingHearts($guide['key']);
            }

            $tutorial->save();
            $this->knowledgeArticle($guide, $tutorial);
            $stats[$isNew ? 'created' : 'updated']++;
        }

        return $stats;
    }

    /** 30 to 45, the same every time for the same guide. */
    public function startingHearts(string $key): int
    {
        return 30 + (crc32($key) % 16);
    }

    private function imageFor(array $guide): ?string
    {
        if (empty($guide['image'])) {
            return null;
        }

        $path = '/guides/'.$guide['image'].'.jpg';

        return is_file(public_path($path)) ? $path : null;
    }

    private function knowledgeArticle(array $guide, Tutorial $tutorial): void
    {
        $article = KnowledgeArticle::query()->where('source_file', 'guide:'.$guide['key'])->first()
            ?? new KnowledgeArticle(['source_file' => 'guide:'.$guide['key'], 'is_active' => true]);

        $article->title = $guide['title'];
        $article->body = $this->renderer->text($guide, '/support/tutorials/'.$tutorial->id.'-'.(Str::slug($tutorial->title) ?: 'guide'));
        $article->save();
    }
}
