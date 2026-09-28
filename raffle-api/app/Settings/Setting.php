<?php

namespace App\Settings;

/**
 * One admin-editable setting. `key` is the config path it overrides
 * (e.g. services.paystack.secret_key), so the rest of the app keeps
 * reading plain config() and never needs to know about the Settings page.
 */
final class Setting
{
    /**
     * @param  string  $type  text|textarea|url|email|secret|int|money|percent|fraction_percent|bool|select|tags|timezone|daily_rewards|bundles|spin_prizes
     * @param  array<string, string>  $options  For select.
     * @param  array<int, string>  $rules  Extra validation rules.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly ?string $help = null,
        public readonly array $options = [],
        public readonly array $rules = [],
        public readonly ?string $placeholder = null,
    ) {}

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /** Config value → what the form shows. */
    public function toForm(mixed $value): mixed
    {
        return match ($this->type) {
            'secret' => null,
            'bool' => (bool) $value,
            'fraction_percent' => $value === null ? null : round((float) $value * 100, 4),
            'tags' => array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== '')),
            'daily_rewards' => array_values(array_map('intval', (array) $value)),
            'bundles', 'spin_prizes' => array_values((array) $value),
            default => $value,
        };
    }

    /** What the form sent → the config value to store. */
    public function fromForm(mixed $value): mixed
    {
        return match ($this->type) {
            'bool' => (bool) $value,
            'int' => $value === null || $value === '' ? null : (int) $value,
            'money', 'percent' => $value === null || $value === '' ? null : (float) $value,
            'fraction_percent' => $value === null || $value === '' ? null : round((float) $value / 100, 6),
            'tags' => array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== '')),
            'daily_rewards' => array_values(array_map('intval', (array) $value)),
            'bundles' => collect((array) $value)
                ->map(fn ($b) => ['quantity' => (int) $b['quantity'], 'percent_off' => (float) $b['percent_off']])
                ->sortBy('quantity')->values()->all(),
            'spin_prizes' => collect((array) $value)
                ->map(fn ($p) => ['payout' => (int) $p['payout'], 'weight' => (int) $p['weight'], 'outcome' => (string) $p['outcome']])
                ->values()->all(),
            default => $value === '' ? null : $value,
        };
    }
}
