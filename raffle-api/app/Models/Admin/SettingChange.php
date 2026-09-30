<?php

namespace App\Models\Admin;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** One setting changed on the Settings page (System → Settings history). */
class SettingChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['batch', 'key', 'label', 'is_secret', 'old_value', 'new_value', 'changed_by', 'reverted_by', 'reverted_at'];

    protected $casts = [
        'is_secret' => 'boolean',
        'old_value' => 'json',
        'new_value' => 'json',
        'reverted_at' => 'datetime',
    ];

    public function changer()
    {
        return $this->belongsTo(WpUser::class, 'changed_by', 'ID');
    }

    public function reverter()
    {
        return $this->belongsTo(WpUser::class, 'reverted_by', 'ID');
    }
}
