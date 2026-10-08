<?php

namespace App\Models\Ads;

use App\Services\Ads\AdServer;
use App\Services\Images\ImageOptimizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** One version of an ad: the words, picture and colour people see. */
class AdVariant extends Model
{
    protected $fillable = ['ad_id', 'label', 'title', 'text', 'badge', 'button_label', 'image_path', 'icon', 'theme', 'weight', 'sort_order'];

    protected $attributes = ['label' => 'A', 'theme' => 'green', 'weight' => 1, 'sort_order' => 0];

    protected $casts = ['weight' => 'integer', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        // A newly uploaded picture is shrunk to WebP, the same as winner photos.
        static::saving(function (AdVariant $variant) {
            if ($variant->isDirty('image_path') && filled($variant->image_path)) {
                $variant->image_path = self::shrink($variant->image_path);
            }
        });

        $forget = fn () => AdServer::forgetCache();
        static::saved($forget);
        static::deleted($forget);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function imageUrl(): ?string
    {
        return filled($this->image_path) ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Swaps the uploaded picture for a smaller WebP copy. Keeps the original if that isn't possible. */
    private static function shrink(string $path): string
    {
        try {
            $disk = Storage::disk('public');

            if (str_ends_with(strtolower($path), '.webp') || ! $disk->exists($path)) {
                return $path;
            }

            $small = app(ImageOptimizer::class)->fit((string) $disk->get($path), 1200);

            if ($small === null) {
                return $path;
            }

            $newPath = 'ads/'.Str::uuid().'.webp';
            $disk->put($newPath, $small);
            $disk->delete($path);

            return $newPath;
        } catch (Throwable $e) {
            report($e);

            return $path;
        }
    }
}
