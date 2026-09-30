<?php

namespace App\Settings;

use App\Models\Admin\SettingChange;
use App\Models\AppSetting;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 * - Every change is written down (SettingChange: what it was, what it
 *   became, who did it, when) so it can be put back from System → Settings
 *   history. Secrets are kept there encrypted too, so a wrongly pasted key
 *   can be undone without anyone ever seeing it.
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
    public static function save(array $values, ?WpUser $admin, string $auditAction = 'settings.updated'): array
    {
        $changed = [];
        $auditChanges = [];
        $batch = (string) Str::uuid();

        DB::transaction(function () use ($values, $admin, $batch, &$changed, &$auditChanges) {
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

                $before = config($key);

                if (! $setting->isSecret() && self::same($setting, $value, self::baseline($key))) {
                    AppSetting::query()->where('key', $key)->delete();
                } else {
                    AppSetting::query()->updateOrCreate(['key' => $key], [
                        'value' => self::encode($setting, $value),
                        'updated_by' => $admin?->ID,
                    ]);
                }

                self::remember($batch, $setting, $before, $value, $admin);

                $changed[] = $setting->label;
                $auditChanges[$key] = $setting->isSecret()
                    ? 'secret replaced'
                    : ['from' => $before, 'to' => $value];
            }
        });

        if ($changed !== []) {
            self::refresh();

            if ($admin) {
                app(AdminAuditLogService::class)->record($admin, $auditAction, 'settings', 0, ['changes' => $auditChanges]);
            }
        }

        return $changed;
    }

    /** Remove the admin's value so the .env one applies again (used for secrets). */
    public static function forget(string $key, ?WpUser $admin, string $auditAction = 'settings.reset'): void
    {
        $setting = SettingsRegistry::find($key);

        if (! $setting || ! AppSetting::query()->where('key', $key)->exists()) {
            return;
        }

        $before = config($key);

        AppSetting::query()->where('key', $key)->delete();
        config([$key => self::baseline($key)]);
        self::remember((string) Str::uuid(), $setting, $before, self::baseline($key), $admin);
        self::refresh();

        if ($admin) {
            app(AdminAuditLogService::class)->record($admin, $auditAction, 'settings', 0, ['setting' => $setting->label]);
        }
    }

    /**
     * Put changes back (System → Settings history → Undo). Each setting
     * goes back to what it was before that change, whatever it is now.
     * A secret that was empty or came from the server file before goes
     * back to the server file's value.
     *
     * @param  iterable<SettingChange>  $changes
     * @return array{restored: list<string>, skipped: list<string>}
     */
    public static function undo(iterable $changes, WpUser $admin): array
    {
        $restored = [];
        $skipped = [];

        foreach ($changes as $change) {
            $change = $change->fresh();
            $setting = $change ? SettingsRegistry::find($change->key) : null;

            if (! $change || $change->reverted_at !== null) {
                $skipped[] = ($change->label ?? 'A setting').' (already put back)';

                continue;
            }

            if (! $setting) {
                $skipped[] = "{$change->label} (no longer a setting)";

                continue;
            }

            $old = self::historyRead($change, 'old_value');

            if ($setting->isSecret() && ($old === null || $old === '' || $old === self::baseline($change->key))) {
                self::forget($change->key, $admin, 'settings.undone');
            } else {
                self::save([$change->key => $old], $admin, 'settings.undone');
            }

            $change->forceFill(['reverted_by' => $admin->ID, 'reverted_at' => now()])->save();
            $restored[] = $change->label;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /** Is the setting still what this change made it? (False once someone changed it again.) */
    public static function isCurrent(SettingChange $change): bool
    {
        $setting = SettingsRegistry::find($change->key);

        return $setting !== null && self::same($setting, self::historyRead($change, 'new_value'), config($change->key));
    }

    /** What a setting's history row holds (secrets are decrypted here, and only here). */
    public static function historyRead(SettingChange $change, string $column): mixed
    {
        $raw = $change->{$column};

        if (! $change->is_secret || $raw === null) {
            return $raw;
        }

        try {
            return json_decode(Crypt::decryptString($raw), true);
        } catch (DecryptException) {
            return null;
        }
    }

    private static function remember(string $batch, Setting $setting, mixed $old, mixed $new, ?WpUser $admin): void
    {
        $keep = fn (mixed $v) => $setting->isSecret() && $v !== null
            ? Crypt::encryptString(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            : $v;

        SettingChange::query()->create([
            'batch' => $batch,
            'key' => $setting->key,
            'label' => $setting->label,
            'is_secret' => $setting->isSecret(),
            'old_value' => $keep($old),
            'new_value' => $keep($new),
            'changed_by' => $admin?->ID,
        ]);
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
