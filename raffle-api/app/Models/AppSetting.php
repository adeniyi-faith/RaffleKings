<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One admin-changed setting (see App\Settings\SettingsStore). `value` is
 * JSON, encrypted for secrets. Only ever read and written via the store.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];
}
