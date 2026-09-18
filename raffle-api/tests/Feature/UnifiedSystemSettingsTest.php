<?php

namespace Tests\Feature;

use App\Filament\Pages\UnifiedSystemSettings;
use App\Models\Legacy\WpOption;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36's own closing note: every
 * "unified" flag introduced across items 33/35a-c/36 previously had no
 * home on the new admin console at all. This page reads/writes the
 * SAME wp_options rows the legacy checkboxes and WpOption::flagEnabled()
 * already use.
 */
class UnifiedSystemSettingsTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function actingAsAdministrator(): WpUser
    {
        $admin = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $admin->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        return $admin;
    }

    public function test_toggling_a_flag_writes_the_same_wp_option_row_legacy_reads(): void
    {
        $this->actingAsAdministrator();
        $this->assertFalse(WpOption::flagEnabled('rk_wallets_unified_enabled'));

        Livewire::test(UnifiedSystemSettings::class)
            ->fillForm(['rk_wallets_unified_enabled' => true])
            ->call('save');

        $this->assertTrue(WpOption::flagEnabled('rk_wallets_unified_enabled'));
    }

    public function test_the_page_loads_the_current_flag_state_on_mount(): void
    {
        $this->actingAsAdministrator();
        WpOption::create(['option_name' => 'rk_support_unified_enabled', 'option_value' => '1', 'autoload' => 'yes']);

        Livewire::test(UnifiedSystemSettings::class)
            ->assertFormSet(['rk_support_unified_enabled' => true, 'rk_wallets_unified_enabled' => false]);
    }
}
