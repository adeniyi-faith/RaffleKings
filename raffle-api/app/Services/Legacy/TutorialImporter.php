<?php

namespace App\Services\Legacy;

use App\Models\Tutorial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the old WordPress "tutorial" posts (and their postmeta) into the
 * tutorials table, keeping each post's id. Safe to run again: posts
 * already copied are skipped.
 */
final class TutorialImporter
{
    private const META_KEYS = ['is_featured', 'video_url', 'category_badge', 'read_time', 'helpful_count'];

    public function import(): int
    {
        $prefix = config('legacy.wp_prefix');

        if (! Schema::hasTable($prefix.'posts') || ! Schema::hasTable('tutorials')) {
            return 0;
        }

        $posts = DB::table($prefix.'posts')
            ->where('post_type', 'tutorial')
            ->whereIn('post_status', ['publish', 'draft', 'pending', 'private', 'future'])
            ->whereNotIn('ID', Tutorial::query()->whereNotNull('legacy_post_id')->select('legacy_post_id'))
            ->get();

        if ($posts->isEmpty()) {
            return 0;
        }

        $meta = Schema::hasTable($prefix.'postmeta')
            ? DB::table($prefix.'postmeta')->whereIn('post_id', $posts->pluck('ID'))->whereIn('meta_key', self::META_KEYS)->get()->groupBy('post_id')
            : collect();

        foreach ($posts as $post) {
            $m = collect($meta->get($post->ID, []))->pluck('meta_value', 'meta_key');

            $tutorial = new Tutorial([
                'title' => $post->post_title ?: 'Untitled',
                'category' => $m->get('category_badge') ?: 'Guide',
                'read_time' => $m->get('read_time') ?: '3 min',
                'video_url' => $m->get('video_url') ?: null,
                'excerpt' => $post->post_excerpt ?: null,
                'content' => (string) $post->post_content,
                'is_featured' => $m->get('is_featured') === '1',
                'is_published' => $post->post_status === 'publish',
                'helpful_count' => (int) ($m->get('helpful_count') ?: 0),
                'published_at' => $post->post_date,
                'legacy_post_id' => $post->ID,
            ]);
            $tutorial->id = Tutorial::query()->whereKey($post->ID)->exists() ? null : $post->ID;
            $tutorial->save();
        }

        return $posts->count();
    }
}
