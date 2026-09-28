<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RunsAdminActions;
use App\Services\TicketPricingService;
use App\Settings\ConnectionTester;
use App\Settings\Setting;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * System → Settings: everything about how the site runs, editable without
 * a developer or a deploy — payment keys, email, AI, alerts, pricing,
 * rewards, limits and on/off switches. Definitions live in
 * App\Settings\SettingsRegistry; storage and the "applies instantly"
 * part in App\Settings\SettingsStore.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms, RunsAdminActions;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    protected static string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function getSubheading(): ?string
    {
        return 'Changes apply to the whole site the moment you save. Secret keys are stored encrypted and never shown again.';
    }

    /** Dots nest in form state, so config paths use __ in the form. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $state = [];

        foreach (SettingsRegistry::all() as $key => $setting) {
            $value = $setting->toForm(config($key));

            if ($setting->type === 'daily_rewards') {
                // One box per day (field__d0 … field__d6).
                foreach (range(0, 6) as $i) {
                    $state[self::field($key)."__d{$i}"] = $value[$i] ?? 0;
                }

                continue;
            }

            $state[self::field($key)] = $value;
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        $tabs = [];

        foreach (SettingsRegistry::tabs() as $name => $tab) {
            $sections = [];

            foreach ($tab['sections'] as $title => $section) {
                $sections[] = Forms\Components\Section::make($title)
                    ->description(isset($section['description']) ? $this->withWebhookUrls($section['description']) : null)
                    ->headerActions($this->sectionActions($title))
                    ->schema([
                        ...array_map(fn (Setting $s) => $this->component($s), $section['settings']),
                        ...$this->sectionExtras($title),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible()
                    ->persistCollapsed(false);
            }

            $tabs[] = Forms\Components\Tabs\Tab::make($name)->icon($tab['icon'])->schema($sections);
        }

        return $form
            ->schema([
                Forms\Components\Tabs::make('settings')->tabs($tabs)->persistTabInQueryString('tab')->contained(false),
            ])
            ->statePath('data');
    }

    private function component(Setting $setting): Component
    {
        $name = self::field($setting->key);
        $wide = in_array($setting->type, ['textarea', 'bundles', 'spin_prizes', 'daily_rewards', 'tags'], true);

        $field = match ($setting->type) {
            'text' => Forms\Components\TextInput::make($name)->maxLength(255),
            'textarea' => Forms\Components\Textarea::make($name)->rows(2),
            'url' => Forms\Components\TextInput::make($name)->url()->maxLength(255),
            'email' => Forms\Components\TextInput::make($name)->email()->maxLength(150),
            'secret' => $this->secretField($setting),
            'int' => Forms\Components\TextInput::make($name)->integer(),
            'money' => Forms\Components\TextInput::make($name)->numeric()->prefix('₦'),
            'percent', 'fraction_percent' => Forms\Components\TextInput::make($name)->numeric()->suffix('%'),
            'bool' => Forms\Components\Toggle::make($name)->onColor('success')->offColor('danger')->inline(false),
            'select' => Forms\Components\Select::make($name)->options($this->selectOptions($setting))->selectablePlaceholder(false),
            'timezone' => Forms\Components\Select::make($name)->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->required(),
            'tags' => Forms\Components\TagsInput::make($name)->splitKeys(['Tab', ',']),
            'daily_rewards' => Forms\Components\Fieldset::make($setting->label)->columns(['default' => 3, 'sm' => 4, 'xl' => 7])->schema(
                array_map(fn (int $i) => Forms\Components\TextInput::make("{$name}__d{$i}")->label('Day '.($i + 1))->integer()->minValue(0)->required()->live(onBlur: true), range(0, 6)),
            ),
            'bundles' => Forms\Components\Repeater::make($name)
                ->schema([
                    Forms\Components\TextInput::make('quantity')->label('Tickets')->integer()->minValue(2)->required(),
                    Forms\Components\TextInput::make('percent_off')->label('% off')->numeric()->minValue(0)->maxValue(90)->suffix('%')->required(),
                ])
                ->columns(['default' => 2])->addActionLabel('Add a bundle')->reorderable(false)->minItems(0)->maxItems(8)->grid(['md' => 2])
                ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                    $sizes = collect($value)->pluck('quantity')->map(fn ($q) => (int) $q);
                    if ($sizes->count() !== $sizes->unique()->count()) {
                        $fail('Each bundle size can only be listed once.');
                    }
                }])
                ->live(onBlur: true),
            'spin_prizes' => Forms\Components\Repeater::make($name)
                ->schema([
                    Forms\Components\Select::make('outcome')->options(['loss' => 'Loss', 'tie' => 'Money back', 'win' => 'Win', 'jackpot' => 'Jackpot'])->required(),
                    Forms\Components\TextInput::make('payout')->label('Pays (points)')->integer()->minValue(0)->required(),
                    Forms\Components\TextInput::make('weight')->label('Weight')->integer()->minValue(0)->required(),
                ])
                ->columns(['default' => 3])->addActionLabel('Add a prize')->minItems(1)->maxItems(12)
                ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                    if (collect($value)->sum(fn ($p) => (int) ($p['weight'] ?? 0)) <= 0) {
                        $fail('At least one prize needs a weight above 0.');
                    }
                }])
                ->live(onBlur: true),
        };

        $field->label($setting->label);

        if ($setting->help && $field instanceof Forms\Components\Fieldset) {
            $field->schema([
                ...$field->getChildComponents(),
                Forms\Components\Placeholder::make($name.'_help')->hiddenLabel()->content($setting->help)->columnSpanFull(),
            ]);
        }

        if ($setting->help && $setting->type !== 'secret' && method_exists($field, 'helperText')) {
            $field->helperText($setting->help);
        }
        if ($setting->placeholder && method_exists($field, 'placeholder')) {
            $field->placeholder($setting->placeholder);
        }
        if ($setting->rules !== [] && method_exists($field, 'rules')) {
            $field->rules($setting->rules);
            if (in_array('required', $setting->rules, true) && method_exists($field, 'required')) {
                $field->required();
            }
        }
        if (str_starts_with($setting->key, 'pricing.') && method_exists($field, 'live')) {
            $field->live(onBlur: true);
        }

        // Where the value comes from, and a one-click way back to the server's own value.
        if (! $setting->isSecret() && $field instanceof Forms\Components\Field) {
            $field
                ->hint(fn () => SettingsStore::isOverridden($setting->key) ? 'Changed here' : null)
                ->hintColor('primary')
                ->hintAction(
                    FormAction::make('reset_'.$name)
                        ->label('Undo')
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->tooltip('Go back to the server\'s own value (.env)')
                        ->visible(fn () => SettingsStore::isOverridden($setting->key))
                        ->requiresConfirmation()
                        ->modalHeading('Use the server\'s own value?')
                        ->modalDescription('This removes the value set here. The value from the server\'s .env file applies again straight away.')
                        ->action(function () use ($setting) {
                            SettingsStore::forget($setting->key, static::admin());
                            $this->fillForm();
                            Notification::make()->title("\"{$setting->label}\" is back to the server value.")->success()->send();
                        }),
                );
        }

        return $wide ? Forms\Components\Group::make([$field])->columnSpanFull() : $field;
    }

    /**
     * The listed choices, plus whatever the server is using now if it's
     * something else (e.g. MAIL_MAILER=sendmail) — otherwise the page
     * couldn't be saved until someone changed a setting they never touched.
     */
    private function selectOptions(Setting $setting): array
    {
        $options = $setting->options;
        $current = (string) config($setting->key);

        if (! array_key_exists($current, $options)) {
            $options[$current] = ($current === '' ? 'None' : $current).' (current server setting)';
        }

        return $options;
    }

    private function secretField(Setting $setting): Forms\Components\TextInput
    {
        $current = (string) config($setting->key);
        $status = match (true) {
            $current === '' => 'Not set yet.',
            SettingsStore::isOverridden($setting->key) => 'Saved here · ends in …'.substr($current, -4).'. Leave empty to keep it.',
            default => 'From the server file (.env) · ends in …'.substr($current, -4).'. Type a new one here to replace it.',
        };

        return Forms\Components\TextInput::make(self::field($setting->key))
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->helperText(trim(($setting->help ? $setting->help.' ' : '').$status))
            ->hint($current === '' ? 'Missing' : 'Set')
            ->hintColor($current === '' ? 'danger' : 'success')
            ->hintIcon($current === '' ? 'heroicon-m-exclamation-circle' : 'heroicon-m-lock-closed')
            ->suffixAction(
                FormAction::make('forget_'.self::field($setting->key))
                    ->icon('heroicon-m-trash')
                    ->tooltip('Remove the key saved here')
                    ->visible(fn () => SettingsStore::isOverridden($setting->key))
                    ->requiresConfirmation()
                    ->modalHeading("Remove the saved {$setting->label}?")
                    ->modalDescription('The server file\'s value (if any) will be used instead. Anything that needs this key stops working if there isn\'t one.')
                    ->color('danger')
                    ->action(function () use ($setting) {
                        SettingsStore::forget($setting->key, static::admin());
                        $this->fillForm();
                        Notification::make()->title("{$setting->label} removed.")->success()->send();
                    }),
            );
    }

    /** "Check" buttons on the sections that talk to an outside service. */
    private function sectionActions(string $section): array
    {
        $tester = fn () => app(ConnectionTester::class);
        // A key typed but not saved yet is checked as typed; otherwise the saved one.
        $typed = fn (string $key) => filled($this->data[self::field($key)] ?? null) ? $this->data[self::field($key)] : config($key);

        $check = fn (string $name, callable $run) => FormAction::make($name)
            ->label('Check')
            ->icon('heroicon-m-signal')
            ->color('gray')
            ->action(function () use ($run) {
                [$ok, $message] = $run();
                Notification::make()->title($ok ? 'It works' : 'Didn\'t work')->body($message)->{$ok ? 'success' : 'danger'}()->persistent(! $ok)->send();
            });

        return match ($section) {
            'Paystack' => [$check('checkPaystack', fn () => $tester()->paystack($typed('services.paystack.secret_key')))],
            'Flutterwave' => [$check('checkFlutterwave', fn () => $tester()->flutterwave($typed('services.flutterwave.secret_key')))],
            'Google Gemini' => [$check('checkGemini', fn () => $tester()->gemini($typed('services.gemini.api_key'), $typed('services.gemini.model')))],
            'Brevo' => [$check('checkBrevo', fn () => $tester()->brevo($typed('services.brevo.key')))],
            'Telegram (staff alerts)' => [$check('checkTelegram', fn () => $tester()->telegram(
                $typed('services.telegram.bot_token'),
                array_values((array) ($this->data[self::field('services.telegram.admin_chat_ids')] ?? config('services.telegram.admin_chat_ids'))),
            ))->label('Send test alert')],
            'Sending' => [
                FormAction::make('testEmail')
                    ->label('Send test email')
                    ->icon('heroicon-m-paper-airplane')
                    ->color('gray')
                    ->form([Forms\Components\TextInput::make('to')->label('Send to')->email()->required()->default(fn () => static::admin()->user_email)])
                    ->modalDescription('Uses the SAVED email settings — save any changes first.')
                    ->modalSubmitActionLabel('Send')
                    ->action(function (array $data) use ($tester) {
                        [$ok, $message] = $tester()->email($data['to']);
                        Notification::make()->title($ok ? 'Sent' : 'Didn\'t send')->body($message)->{$ok ? 'success' : 'danger'}()->persistent(! $ok)->send();
                    }),
            ],
            default => [],
        };
    }

    /** Read-only helpers shown under some sections. */
    private function sectionExtras(string $section): array
    {
        return match ($section) {
            'Email server (SMTP)' => [
                Forms\Components\Actions::make([
                    $this->smtpPreset('Brevo', 'smtp-relay.brevo.com', 587, 'smtp'),
                    $this->smtpPreset('Zoho', 'smtp.zoho.com', 465, 'smtps'),
                    $this->smtpPreset('Gmail', 'smtp.gmail.com', 587, 'smtp'),
                    $this->smtpPreset('Outlook', 'smtp.office365.com', 587, 'smtp'),
                ])->label('Quick fill')->columnSpanFull(),
            ],
            'Bulk discounts' => [
                Forms\Components\Placeholder::make('price_preview')
                    ->label('What customers will pay')
                    ->content(fn (Get $get) => $this->pricePreview($get))
                    ->columnSpanFull(),
            ],
            'Spin & Win' => [
                Forms\Components\Placeholder::make('spin_summary')
                    ->label('Odds customers will see')
                    ->content(fn (Get $get) => $this->spinSummary($get))
                    ->columnSpanFull(),
            ],
            default => [],
        };
    }

    private function smtpPreset(string $name, string $host, int $port, string $scheme): FormAction
    {
        return FormAction::make('preset'.$name)
            ->label($name)
            ->color('gray')
            ->size('sm')
            ->action(function (Set $set) use ($host, $port, $scheme) {
                $set(self::field('mail.mailers.smtp.host'), $host);
                $set(self::field('mail.mailers.smtp.port'), $port);
                $set(self::field('mail.mailers.smtp.scheme'), $scheme);
                $set(self::field('mail.default'), 'smtp');
            });
    }

    private function pricePreview(Get $get): HtmlString
    {
        $original = config('pricing');
        $preview = [];

        foreach (array_keys($original) as $key) {
            $setting = SettingsRegistry::find("pricing.{$key}");
            $preview[$key] = $setting ? $setting->fromForm($get(self::field("pricing.{$key}"))) : $original[$key];
        }

        config(['pricing' => $preview]);

        try {
            $pricing = app(TicketPricingService::class);
            $quantities = collect($pricing->bundleQuantities())->push(max(15, ((int) ($preview['above_quantity'] ?? 0)) + 5))->unique()->values();
            $prices = collect([(float) $preview['cheap_ticket_max_price'], 500, 1000])->unique()->sort()->values();

            $head = '<th class="px-2 py-1 text-start">Tickets</th>'.$prices->map(fn ($p) => '<th class="px-2 py-1 text-end">₦'.number_format($p).' each</th>')->implode('');
            $rows = $quantities->map(function ($q) use ($prices, $pricing) {
                $cells = $prices->map(function ($p) use ($q, $pricing) {
                    $total = $pricing->calculate($q, $p);
                    $off = $pricing->percentOff($q, $p);

                    return '<td class="px-2 py-1 text-end tabular-nums">₦'.number_format($total).($off > 0 ? ' <span style="color:rgb(var(--success-600))">−'.rtrim(rtrim(number_format($off, 1), '0'), '.').'%</span>' : '').'</td>';
                })->implode('');

                return "<tr class=\"border-t border-gray-100 dark:border-white/5\"><td class=\"px-2 py-1 font-medium\">{$q}</td>{$cells}</tr>";
            })->implode('');

            return new HtmlString('<div style="overflow-x:auto;margin-inline:-4px"><table class="w-full text-sm" style="white-space:nowrap"><thead class="text-gray-500"><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table></div>');
        } finally {
            config(['pricing' => $original]);
        }
    }

    private function spinSummary(Get $get): HtmlString
    {
        $prizes = collect($get(self::field('rewards.spin_prizes')) ?? [])->map(fn ($p) => ['payout' => (int) ($p['payout'] ?? 0), 'weight' => (int) ($p['weight'] ?? 0), 'outcome' => $p['outcome'] ?? '']);
        $total = $prizes->sum('weight');
        $cost = (int) $get(self::field('rewards.spin_cost'));

        if ($total <= 0 || $cost <= 0) {
            return new HtmlString('<span class="text-danger-600">Add a spin cost and at least one prize with a weight.</span>');
        }

        $expected = $prizes->sum(fn ($p) => $p['payout'] * $p['weight'] / $total);
        $lines = $prizes->map(fn ($p) => e(ucfirst($p['outcome'])).': '.number_format($p['payout']).' pts — '.rtrim(rtrim(number_format($p['weight'] / $total * 100, 2), '0'), '.').'%')->implode('<br>');
        $keep = (1 - $expected / $cost) * 100;
        $verdict = $keep >= 0
            ? 'On average a spin pays back <b>'.number_format($expected, 1).'</b> of its '.$cost.' points — the site keeps <b>'.number_format($keep, 1).'%</b>.'
            : '<span style="color:rgb(var(--danger-600))">Careful: on average a spin pays back <b>'.number_format($expected, 1).'</b> points, MORE than its '.$cost.'-point cost. Customers will farm points.</span>';

        return new HtmlString("<div class=\"text-sm\">{$lines}<p class=\"mt-2\">{$verdict}</p></div>");
    }

    private function withWebhookUrls(string $text): HtmlString|string
    {
        if (! str_contains($text, '{webhook:')) {
            return $text;
        }

        return new HtmlString(preg_replace_callback('/\{webhook:(\w+)\}/', fn ($m) => '<code class="rounded bg-gray-100 px-1 text-xs dark:bg-white/10" style="word-break:break-all">'.e(url("/api/webhooks/{$m[1]}")).'</code>', e($text)));
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $values = [];

        foreach (SettingsRegistry::all() as $key => $setting) {
            $field = self::field($key);
            if ($setting->type === 'daily_rewards') {
                $values[$key] = $setting->fromForm(array_map(fn (int $i) => $state["{$field}__d{$i}"] ?? 0, range(0, 6)));

                continue;
            }
            if (array_key_exists($field, $state)) {
                $values[$key] = $setting->fromForm($state[$field]);
            }
        }

        $changed = SettingsStore::save($values, static::admin());
        $this->fillForm();

        if ($changed === []) {
            Notification::make()->title('Nothing changed')->info()->send();

            return;
        }

        Notification::make()
            ->title('Saved — live on the site now')
            ->body(implode(', ', array_slice($changed, 0, 6)).(count($changed) > 6 ? ' and '.(count($changed) - 6).' more' : ''))
            ->success()
            ->send();

        foreach ($this->warnings() as $warning) {
            Notification::make()->title('Heads up')->body($warning)->warning()->persistent()->send();
        }
    }

    /** Settings that were saved fine but won't work together. @return list<string> */
    private function warnings(): array
    {
        $warnings = [];

        foreach (['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave'] as $gateway => $label) {
            if (in_array($gateway, [config('payments.default_gateway'), config('payments.backup_gateway')], true) && blank(config("services.{$gateway}.secret_key"))) {
                $warnings[] = "{$label} is chosen for top-ups but has no secret key, so it can't take payments.";
            }
        }
        if (config('mail.default') === 'brevo' && blank(config('services.brevo.key'))) {
            $warnings[] = 'Email is set to Brevo but there\'s no Brevo API key, so no emails will send.';
        }
        if (config('mail.default') === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            $warnings[] = 'Email is set to an email server but no server is filled in.';
        }
        if (config('mail.default') === 'log') {
            $warnings[] = 'Emails are not being sent (log only). Customers won\'t get password-reset codes.';
        }

        return $warnings;
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save changes')->submit('save')->keyBindings(['mod+s']),
        ];
    }

    public function areFormActionsSticky(): bool
    {
        return true;
    }
}
