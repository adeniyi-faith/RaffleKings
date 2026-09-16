<?php

namespace App\Services;

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads the "Learning Hub" (item 29) — real content authored through the
 * existing `tutorial` WordPress CPT (rk-core/database.php), replacing
 * the legacy tutorials.php's direct call to the WordPress REST API. Same
 * read-only-from-Laravel approach as RaffleReadService: tutorials are
 * still authored through wp-admin, not here.
 *
 * Mirrors the legacy rk_get_tutorials()'s exact behaviour: the newest 20
 * published tutorials, the first one flagged `is_featured` pulled out as
 * the hero, everything else in a list.
 */
class TutorialReadService
{
    private const META_KEYS = ['is_featured', 'video_url', 'category_badge', 'read_time', 'helpful_count'];

    private const PER_PAGE = 20;

    /** @return array{featured: ?array, list: array<int, array>} */
    public function list(): array
    {
        $posts = WpPost::query()->tutorials()->orderByDesc('post_date')->limit(self::PER_PAGE)->get();

        $hydrated = $this->hydrate($posts);

        $featured = $hydrated->firstWhere('is_featured', true);
        $list = $featured
            ? $hydrated->reject(fn ($t) => $t['id'] === $featured['id'])->values()
            : $hydrated;

        return ['featured' => $featured, 'list' => $list->all()];
    }

    /**
     * Increments the "helpful" counter — same effect as the legacy
     * rk_tutorial_mark_helpful(), with one real fix: that endpoint took
     * any post id at all with no check it was even a tutorial, so
     * anyone could bump `helpful_count` postmeta onto an unrelated post
     * (a raffle, a page) just by guessing its id. This only ever
     * touches a real, published tutorial.
     *
     * @return int|null the new count, or null if no such tutorial exists
     */
    public function markHelpful(int $postId): ?int
    {
        $post = WpPost::query()->tutorials()->whereKey($postId)->first();

        if (! $post) {
            return null;
        }

        $meta = WpPostMeta::query()->where('post_id', $postId)->where('meta_key', 'helpful_count')->first();
        $newCount = ((int) ($meta->meta_value ?? 0)) + 1;

        if ($meta) {
            $meta->update(['meta_value' => $newCount]);
        } else {
            WpPostMeta::create(['post_id' => $postId, 'meta_key' => 'helpful_count', 'meta_value' => $newCount]);
        }

        return $newCount;
    }

    /** @param  Collection<int, WpPost>  $posts */
    private function hydrate(Collection $posts): Collection
    {
        if ($posts->isEmpty()) {
            return collect();
        }

        $metaByPost = WpPostMeta::query()
            ->whereIn('post_id', $posts->pluck('ID'))
            ->whereIn('meta_key', self::META_KEYS)
            ->get()
            ->groupBy('post_id')
            ->map(fn ($rows) => $rows->pluck('meta_value', 'meta_key'));

        return $posts->map(function (WpPost $post) use ($metaByPost) {
            $meta = $metaByPost->get($post->ID, collect());

            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'excerpt' => $post->post_excerpt ?: Str::limit(strip_tags((string) $post->post_content), 160),
                'content' => (string) $post->post_content,
                'date_ago' => Carbon::parse($post->post_date)->diffForHumans(),
                'is_featured' => $meta->get('is_featured') === '1',
                'video_url' => $meta->get('video_url') ?: null,
                'category' => $meta->get('category_badge') ?: 'Guide',
                'read_time' => $meta->get('read_time') ?: '3 min',
                'helpful_count' => (int) ($meta->get('helpful_count') ?: 0),
            ];
        });
    }
}
