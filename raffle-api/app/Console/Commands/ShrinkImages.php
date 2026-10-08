<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\HallOfFameController;
use App\Models\Legacy\WpUserMeta;
use App\Models\WinnerStory;
use App\Services\Images\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-off clean-up for pictures uploaded before uploads were shrunk
 * automatically: every profile picture and winner-story photo gets a small
 * WebP copy (the same as a new upload would, see ImageOptimizer) and the
 * site switches to showing that copy.
 *
 * Nothing is deleted or overwritten. The original files stay exactly where
 * they are, every change is written to a log file under
 * storage/app/image-shrink-logs/ (old address -> new address), and a
 * profile's previous address is also kept in the `profile_pic_url_before_shrink`
 * usermeta, so any picture can be switched back by hand. Safe to run again:
 * pictures already in WebP are skipped.
 */
class ShrinkImages extends Command
{
    protected $signature = 'images:shrink
        {--dry-run : Only show what would change; write nothing}
        {--skip-old-site : Leave profile pictures stored on the old WordPress site alone}';

    protected $description = 'Make existing profile pictures and winner-story photos smaller (originals are kept)';

    private const MAX_DOWNLOAD_BYTES = 15 * 1024 * 1024;

    /** @var list<array<string, mixed>> */
    private array $log = [];

    private int $count = 0;

    private int $before = 0;

    private int $after = 0;

    public function handle(ImageOptimizer $optimizer): int
    {
        if (! $optimizer->available()) {
            $this->error('This server\'s PHP has no GD image library with WebP support, so pictures cannot be shrunk here. Nothing was changed.');

            return self::FAILURE;
        }

        $this->log = [];
        $this->count = $this->before = $this->after = 0;
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'PREVIEW: nothing will be written.' : 'APPLY: small copies will be saved and used. Originals are kept.');

        $this->avatars($optimizer, $dry);
        $this->stories($optimizer, $dry);

        if (! $dry && $this->log !== []) {
            $file = 'image-shrink-logs/'.now()->format('Y-m-d_His').'.json';
            Storage::disk('local')->put($file, json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Cache::forget(HallOfFameController::CACHE_KEY);
            $this->line("Log of every change: storage/app/{$file}");
        }

        $this->info(sprintf(
            'Done. %d picture(s) %s: %s before, %s after.',
            $this->count,
            $dry ? 'would be shrunk' : 'shrunk',
            $this->size($this->before),
            $this->size($this->after),
        ));

        return self::SUCCESS;
    }

    private function avatars(ImageOptimizer $optimizer, bool $dry): void
    {
        $this->line('');
        $this->line('Profile pictures');

        $rows = WpUserMeta::query()->where('meta_key', 'profile_pic_url')->where('meta_value', '!=', '')->orderBy('user_id')->get(['user_id', 'meta_value']);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $url = (string) $row->meta_value;
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_ends_with(strtolower($path), '.webp')) {
                continue;
            }

            $bytes = $this->read($url, $path);

            if ($bytes === null) {
                $this->line("  user {$userId}: skipped, could not read {$url}");

                continue;
            }

            $small = $optimizer->square($bytes);

            if ($small === null) {
                $this->line("  user {$userId}: left as it is (already small, an animation, or not a picture)");

                continue;
            }

            $this->line(sprintf('  user %d: %s -> %s', $userId, $this->size(strlen($bytes)), $this->size(strlen($small))));
            $this->before += strlen($bytes);
            $this->after += strlen($small);

            $this->count++;

            if ($dry) {
                continue;
            }

            $newPath = 'avatars/'.$userId.'.webp';
            Storage::disk('public')->put($newPath, $small);
            $newUrl = Storage::disk('public')->url($newPath).'?v='.time();

            if (! WpUserMeta::query()->where('user_id', $userId)->where('meta_key', 'profile_pic_url_before_shrink')->exists()) {
                WpUserMeta::create(['user_id' => $userId, 'meta_key' => 'profile_pic_url_before_shrink', 'meta_value' => $url]);
            }

            WpUserMeta::query()->where('user_id', $userId)->where('meta_key', 'profile_pic_url')->update(['meta_value' => $newUrl]);

            $this->log[] = ['type' => 'avatar', 'user_id' => $userId, 'old' => $url, 'new' => $newUrl, 'old_bytes' => strlen($bytes), 'new_bytes' => strlen($small)];
        }
    }

    private function stories(ImageOptimizer $optimizer, bool $dry): void
    {
        $this->line('');
        $this->line('Winner story photos');

        $disk = Storage::disk('public');

        WinnerStory::query()->where('media_type', 'image')->orderBy('id')->each(function (WinnerStory $story) use ($optimizer, $dry, $disk) {
            if (str_ends_with(strtolower($story->media_path), '.webp') || ! $disk->exists($story->media_path)) {
                return;
            }

            $bytes = (string) $disk->get($story->media_path);
            $small = $optimizer->fit($bytes);

            if ($small === null) {
                $this->line("  story {$story->id}: left as it is");

                return;
            }

            $this->line(sprintf('  story %d: %s -> %s', $story->id, $this->size(strlen($bytes)), $this->size(strlen($small))));
            $this->before += strlen($bytes);
            $this->after += strlen($small);

            $this->count++;

            if ($dry) {
                return;
            }

            $newPath = 'stories/'.Str::uuid().'.webp';
            $disk->put($newPath, $small);
            $old = $story->media_path;
            $story->update(['media_path' => $newPath]);

            $this->log[] = ['type' => 'story', 'story_id' => $story->id, 'old' => $old, 'new' => $newPath, 'old_bytes' => strlen($bytes), 'new_bytes' => strlen($small)];
        });
    }

    /** The picture's bytes: from this site's own storage, or downloaded from the old site. */
    private function read(string $url, string $path): ?string
    {
        if (preg_match('#/storage/(avatars/[^/]+)$#', $path, $m)) {
            $disk = Storage::disk('public');

            return $disk->exists($m[1]) ? (string) $disk->get($m[1]) : null;
        }

        if ($this->option('skip-old-site') || ! preg_match('#^https?://#', $url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        $body = $response->body();

        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/') || strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            return null;
        }

        return $body;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
