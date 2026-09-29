<?php

namespace App\Services;

use App\Models\Tutorial;
use App\Models\TutorialLike;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  string|null  $voter  who is asking ("u:12" / "d:abc…"), to say which hearts are theirs
     * @return array{featured: ?array, list: array<int, array>}
     */
    public function list(?string $voter = null): array
    {
        $tutorials = Tutorial::query()->live()
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->get();

        $liked = $this->likedIds($voter, $tutorials->modelKeys());
        $tutorials = $tutorials->map(fn (Tutorial $t) => $this->present($t, in_array($t->id, $liked, true)));

        $featured = $tutorials->firstWhere('is_featured', true);
        $list = $featured ? $tutorials->reject(fn ($t) => $t['id'] === $featured['id'])->values() : $tutorials;

        return ['featured' => $featured, 'list' => $list->all()];
    }

    /**
     * One tutorial for its own page, plus a few others to read next.
     *
     * @return array{tutorial: array, more: array<int, array>}|null null when not published
     */
    public function find(int $id, ?string $voter = null): ?array
    {
        $tutorial = Tutorial::query()->live()->whereKey($id)->first();

        if (! $tutorial) {
            return null;
        }

        $more = Tutorial::query()->live()->whereKeyNot($id)
            ->orderByRaw('category = ? desc', [$tutorial->category])
            ->orderByDesc('helpful_count')
            ->limit(3)
            ->get()
            ->map(fn (Tutorial $t) => $this->present($t, false, withContent: false))
            ->all();

        return [
            'tutorial' => $this->present($tutorial, $this->likedIds($voter, [$id]) !== []),
            'more' => $more,
        ];
    }

    /**
     * Heart (or un-heart) a published tutorial. Each person counts once:
     * hearting twice does nothing, and taking it back lowers the count.
     *
     * @return int|null the new count, or null if no such tutorial is published
     */
    public function like(int $id, string $voter, bool $liked = true): ?int
    {
        $tutorial = Tutorial::query()->live()->whereKey($id)->first();

        if (! $tutorial) {
            return null;
        }

        DB::transaction(function () use ($tutorial, $voter, $liked) {
            if ($liked) {
                $added = TutorialLike::query()->insertOrIgnore(['tutorial_id' => $tutorial->id, 'voter' => $voter, 'created_at' => now()]);
                if ($added) {
                    $tutorial->increment('helpful_count');
                }
            } elseif (TutorialLike::query()->where('tutorial_id', $tutorial->id)->where('voter', $voter)->delete()) {
                Tutorial::query()->whereKey($tutorial->id)->where('helpful_count', '>', 0)->decrement('helpful_count');
            }
        });

        return (int) $tutorial->fresh()->helpful_count;
    }

    /** @return list<int> */
    private function likedIds(?string $voter, array $ids): array
    {
        if (! $voter || $ids === []) {
            return [];
        }

        return TutorialLike::query()->where('voter', $voter)->whereIn('tutorial_id', $ids)->pluck('tutorial_id')->map(fn ($id) => (int) $id)->all();
    }

    private function present(Tutorial $t, bool $liked = false, bool $withContent = true): array
    {
        $content = $t->safeContent();

        return [
            'id' => $t->id,
            'url' => '/support/tutorials/'.$t->id.'-'.(Str::slug($t->title) ?: 'guide'),
            'title' => $t->title,
            'excerpt' => $t->excerpt ?: Str::limit(trim(strip_tags($content)), 160),
            'content' => $withContent ? $content : null,
            'date_ago' => ($t->published_at ?? $t->created_at)?->diffForHumans(),
            'is_featured' => $t->is_featured,
            'video_url' => $t->safeVideoUrl(),
            'category' => $t->category ?: 'Guide',
            'read_time' => $t->read_time ?: '3 min',
            'helpful_count' => $t->helpful_count,
            'liked' => $liked,
        ];
    }
}
