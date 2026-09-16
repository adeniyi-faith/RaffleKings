<?php

namespace App\Models\Legacy;

/**
 * Maps to WordPress's own wp_posts table. Raffles are ordinary WordPress
 * posts of type "raffle" (see rk-core/database.php's rk_register_cpts())
 * — there is no dedicated raffles table in the legacy system. This model
 * is read-only from Laravel's side for now: raffles are still created and
 * edited through wp-admin (or the legacy metabox), never here. See
 * App\Models\Legacy\Raffle for the higher-level read model built on top
 * of this + WpPostMeta.
 */
class WpPost extends LegacyModel
{
    protected static string $unprefixedTable = 'posts';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $fillable = [
        'post_title',
        'post_content',
        'post_excerpt',
        'post_type',
        'post_status',
        'post_date',
    ];

    public function meta()
    {
        return $this->hasMany(WpPostMeta::class, 'post_id', 'ID');
    }

    public function scopeRaffles($query)
    {
        return $query->where('post_type', 'raffle')->where('post_status', 'publish');
    }

    /** The "tutorial" CPT (rk-core/database.php) — the Learning Hub's content (item 29). */
    public function scopeTutorials($query)
    {
        return $query->where('post_type', 'tutorial')->where('post_status', 'publish');
    }

    public function metaValue(string $key): ?string
    {
        return $this->meta()->where('meta_key', $key)->value('meta_value');
    }
}
