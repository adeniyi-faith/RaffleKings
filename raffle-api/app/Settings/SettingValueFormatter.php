<?php

namespace App\Settings;

use Illuminate\Support\Str;

/** A setting's value in plain words, for the Settings history screen. Secrets are never shown. */
final class SettingValueFormatter
{
    public static function format(?Setting $setting, mixed $value): string
    {
        if ($setting?->isSecret()) {
            return $value === null ? 'not set' : 'a secret key (hidden)';
        }

        if ($value === null || $value === '' || $value === []) {
            return 'empty';
        }

        $number = fn (float $n, int $places = 2) => rtrim(rtrim(number_format($n, $places), '0'), '.');

        return match ($setting?->type) {
            'bool' => $value ? 'On' : 'Off',
            'money' => '₦'.$number((float) $value),
            'percent' => $number((float) $value).'%',
            'fraction_percent' => $number((float) $value * 100, 4).'%',
            'select' => $setting->options[(string) $value] ?? (string) $value,
            'tags' => implode(', ', (array) $value),
            'checklist' => implode(', ', array_map(fn ($v) => $setting->options[$v] ?? $v, (array) $value)),
            'daily_rewards' => 'Days 1-7: '.implode(' / ', (array) $value),
            'bundles', 'spin_prizes', 'loyalty_tiers' => count((array) $value).' entries: '.Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 90),
            default => Str::limit(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 110),
        };
    }
}
