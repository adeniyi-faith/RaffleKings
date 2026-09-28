<?php

namespace App\Settings;

use App\Models\AppSetting;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin-changed settings, applied on top of the server's .env/config on
 * every request (AppServiceProvider::boot → apply()).
 *
 * - Only settings the admin actually changed are stored; everything else
 *   keeps coming from the .env file ("baseline"). Setting a value back to
 *   the .env one deletes the row again.
 * - Secrets are encrypted with APP_KEY before they reach the database or
 *   the cache, are never sent back to the browser, and never appear in
 *   the audit log.
 * - One cached read per request; any change clears the cache at once.
 */
final class SettingsStore
{
    public const CACHE_KEY = 'app-settings:v1';

    /** @var array<string, mixed> config values before any override */
    private static array $baseline = [];

    /** @var array<string, true> keys currently overridden */
    private static array $overridden = [];

    /** Load the stored overrides into config. Safe before the table exists. */
    public static function apply(): void
    {
        $definitions = SettingsRegistry::all();

        foreach ($definitions as $key => $setting) {
            self::$baseline[$key] ??= config($key);
        }

        self::$overridden = [];

        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            return; // not migrated yet, or no database — run on .env alone
        }

        foreach ($rows as $key => $raw) {
            $setting = $definitions[$key] ?? null;

            if (! $setting) {
                continue;
            }

            try {
                $value = self::decode($setting, $raw);
            } catch (DecryptException) {
                Log::warning("Setting {$key} could not be decrypted (was APP_KEY changed?). Using the .env value.");

                continue;
            }

            config([$key => $value]);
            self::$overridden[$key] = true;
        }
    }

    public static function isOverridden(string $key): bool
    {
        return isset(self::$overridden[$key]);
    }

    /** The .env/config value underneath any admin change. */
    public static function baseline(string $key): mixed
    {
        return array_key_exists($key, self::$baseline) ? self::$baseline[$key] : config($key);
    }

    /**
     * Save the admin's changes. Only values that differ from what's in
     * effect are written. A secret left empty keeps its saved value.
     *
     * @param  array<string, mixed>  $values  config value per key (already converted with Setting::fromForm)
     * @return list<string> labels of the settings that changed
     */
    public static function save(array $values, WpUser $admin): array
    {
        $changed = [];
        $auditChanges = [];

        DB::transaction(function () use ($values, $admin, &$changed, &$auditChanges) {
            foreach ($values as $key => $value) {
                $setting = SettingsRegistry::find($key);

                if (! $setting) {
                    continue;
                }

                if ($setting->isSecret()) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if ($value === config($key)) {
                        continue;
                    }
                } elseif (self::same($setting, $value, config($key))) {
                    continue;
                }

                if (! $setting->isSecret() && self::same($setting, $value, self::baseline($key))) {
                    AppSetting::query()->where('key', $key)->delete();
                } else {
                    AppSetting::query()->updateOrCreate(['key' => $key], [
                        'value' => self::encode($setting, $value),
                        'updated_by' => $admin->ID,
                    ]);
                }

                $changed[] = $setting->label;
                $auditChanges[$key] = $setting->isSecret()
                    ? 'secret replaced'
                    : ['from' => config($key), 'to' => $value];
            }
        });

        if ($changed !== []) {
            self::refresh();
            app(AdminAuditLogService::class)->record($admin, 'settings.updated', 'settings', 0, ['changes' => $auditChanges]);
        }

        return $changed;
    }

    /** Remove the admin's value so the .env one applies again (used for secrets). */
    public static function forget(string $key, WpUser $admin): void
    {
        $setting = SettingsRegistry::find($key);

        if (! $setting || ! AppSetting::query()->where('key', $key)->delete()) {
            return;
        }

        config([$key => self::baseline($key)]);
        self::refresh();
        app(AdminAuditLogService::class)->record($admin, 'settings.reset', 'settings', 0, ['setting' => $setting->label]);
    }

    /** Clear the cache and re-apply, so this same request sees the new values too. */
    public static function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);

        foreach (array_keys(self::$overridden) as $key) {
            config([$key => self::baseline($key)]);
        }

        self::apply();

        // Mailers are built once and remembered — rebuild with the new settings.
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }
    }

    /** Equal once both are in the form's canonical shape (so 100 == 100.0, '' == null). */
    private static function same(Setting $setting, mixed $a, mixed $b): bool
    {
        $canonical = fn ($v) => json_encode($setting->fromForm($setting->toForm($v)));

        return $canonical($a) === $canonical($b);
    }

    private static function encode(Setting $setting, mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $setting->isSecret() ? Crypt::encryptString($json) : $json;
    }

    private static function decode(Setting $setting, ?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return json_decode($setting->isSecret() ? Crypt::decryptString($raw) : $raw, true);
    }
}
