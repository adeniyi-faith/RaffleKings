<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

/** A staff label on a customer, e.g. VIP or "watch closely". Staff only. */
class CustomerTag extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tag', 'added_by'];

    /** Tags are stored tidy: trimmed, single spaces, at most 40 characters. */
    public static function clean(string $tag): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $tag)), 0, 40);
    }

    /** Every tag in use, most used first. @return list<string> */
    public static function inUse(): array
    {
        return static::query()->selectRaw('tag, COUNT(*) as n')->groupBy('tag')->orderByDesc('n')->limit(200)->pluck('tag')->all();
    }
}
