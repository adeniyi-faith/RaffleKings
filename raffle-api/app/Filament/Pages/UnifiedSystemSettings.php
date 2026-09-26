<?php

namespace App\Filament\Pages;

use App\Models\Legacy\WpOption;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36's own closing note: every
 * "unified" flag this migration introduced (items 33, 35a-c, 36 itself)
 * is a real, working, instant-rollback on/off switch — but every one of
 * them was only ever given a checkbox on a DIFFERENT legacy admin page
 * (Financials, Draw Control, Settings, Support, Withdrawals), so an
 * admin using only the new console had no way to see, let alone flip,
 * any of them. This page is that "small Filament settings page" the
 * item's own writeup said still needed to exist before admin-panel.php
 * could be formally retired.
 *
 * Reads/writes the SAME wp_options rows every legacy checkbox and every
 * WpOption::flagEnabled() check already uses — flipping a switch here
 * has the exact same effect as flipping the equivalent legacy checkbox,
 * because it's the same row.
 */
class UnifiedSystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Unified System Flags';

    protected static string $view = 'filament.pages.unified-system-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    private const FLAGS = [
        'rk_wallets_unified_enabled' => [
            'label' => 'Wallets & payments (item 33)',
            'helper' => 'Ticket purchases, deposits, withdrawals, transfers, and winner-crediting settle against the new wallets/wallet_ledger_entries tables instead of wp_usermeta.',
        ],
        'rk_draw_engine_unified_enabled' => [
            'label' => 'Draw engine (item 35a)',
            'helper' => 'The admin "GENERATE WINNERS" button runs the provably-fair commit/reveal draw engine instead of the legacy non-cryptographic shuffle.',
        ],
        'rk_rewards_unified_enabled' => [
            'label' => 'Rewards — points, streak, tasks, spin (item 35c)',
            'helper' => 'Daily claim, tasks, Spin & Win, and point redemption settle against the native user_points/completed_tasks tables. Point redemption additionally requires Wallets & payments above (it credits real money).',
        ],
        'rk_support_unified_enabled' => [
            'label' => 'Support tickets (item 36)',
            'helper' => 'The legacy user-facing ticket API and admin reply page read/write ONLY the new support_tickets/support_ticket_messages tables.',
        ],
        'rk_withdrawals_unified_enabled' => [
            'label' => 'Withdrawal admin queue mirroring (item 36)',
            'helper' => 'Every legacy withdrawal approve/reject action also dual-writes into the new withdrawal_requests table, so the new admin queue stays current alongside the legacy one.',
        ],
    ];

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Form $form): Form
    {
        $fields = [];

        foreach (self::FLAGS as $name => $meta) {
            $fields[] = Forms\Components\Toggle::make($name)
                ->label($meta['label'])
                ->helperText($meta['helper'])
                ->onColor('success')
                ->offColor('gray');
        }

        return $form->schema([
            Forms\Components\Section::make('Instant-rollback flags')
                ->description('Each of these is read directly by the corresponding legacy PHP bridge on every request — flipping one here takes effect immediately, with zero deploy, exactly like flipping its legacy admin-page checkbox (they are the same underlying setting).')
                ->schema($fields),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (self::FLAGS as $name => $meta) {
            WpOption::query()->updateOrCreate(
                ['option_name' => $name],
                ['option_value' => ! empty($state[$name]) ? '1' : '0', 'autoload' => 'yes'],
            );
        }

        Notification::make()
            ->title('Saved')
            ->success()
            ->send();
    }

    /** @return array<string, bool> */
    private function currentValues(): array
    {
        $values = [];

        foreach (array_keys(self::FLAGS) as $name) {
            $values[$name] = WpOption::flagEnabled($name);
        }

        return $values;
    }
}
