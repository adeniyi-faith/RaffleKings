<?php

namespace App\Services;

use App\Models\Tutorial;
use Illuminate\Support\Str;

/**
 * Reads the "Learning Hub" (item 29) from the tutorials table, which
 * admins manage under Site → Tutorials (item 45b). The WordPress posts it
 * used to read were copied in with their ids (App\Services\Legacy\TutorialImporter).
 *
 * Same behaviour as the legacy rk_get_tutorials(): the newest 20
 * published tutorials, the featured one pulled out as the hero,
 * everything else in a list.
 */
class TutorialReadService
{
    private const PER_PAGE = 20;

    /** @return array{featured: ?array, list: array<int, array>} */
    public function list(): array
    {
        $tutorials = Tutorial::query()->live()
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->get()
            ->map(fn (Tutorial $t) => $this->present($t));

        $featured = $tutorials->firstWhere('is_featured', true);
        $list = $featured ? $tutorials->reject(fn ($t) => $t['id'] === $featured['id'])->values() : $tutorials;

        return ['featured' => $featured, 'list' => $list->all()];
    }

    /**
     * Adds one to a published tutorial's "helpful" count.
     *
     * @return int|null the new count, or null if no such tutorial is published
     */
    public function markHelpful(int $id): ?int
    {
        $tutorial = Tutorial::query()->live()->whereKey($id)->first();

        if (! $tutorial) {
            return null;
        }

        $tutorial->increment('helpful_count');

        return $tutorial->helpful_count;
    }

    private function present(Tutorial $t): array
    {
        $content = $t->safeContent();

        return [
            'id' => $t->id,
            'title' => $t->title,
            'excerpt' => $t->excerpt ?: Str::limit(trim(strip_tags($content)), 160),
            'content' => $content,
            'date_ago' => ($t->published_at ?? $t->created_at)?->diffForHumans(),
            'is_featured' => $t->is_featured,
            'video_url' => $t->safeVideoUrl(),
            'category' => $t->category ?: 'Guide',
            'read_time' => $t->read_time ?: '3 min',
            'helpful_count' => $t->helpful_count,
        ];
    }
}
