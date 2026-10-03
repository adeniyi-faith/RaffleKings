<?php

use App\Services\Ai\GeminiModels;
use App\Settings\SettingsStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → AI now offers only Gemini 3 series Flash models. A model
 * picked earlier that is no longer on the list (2.5 models, old previews
 * Google has switched off) is cleared, so the new default is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $outdated = DB::table('app_settings')
            ->whereIn('key', ['services.gemini.model', 'services.gemini.assistant_model'])
            ->get()
            ->reject(fn ($row) => array_key_exists((string) json_decode((string) $row->value, true), GeminiModels::OPTIONS))
            ->pluck('id');

        if ($outdated->isNotEmpty()) {
            DB::table('app_settings')->whereIn('id', $outdated)->delete();
            Cache::forget(SettingsStore::CACHE_KEY);
        }
    }

    public function down(): void
    {
        // Nothing to put back: the old models are no longer offered.
    }
};
