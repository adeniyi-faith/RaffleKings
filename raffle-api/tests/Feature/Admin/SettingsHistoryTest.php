<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Filament\Pages\Settings;
use App\Filament\Resources\SettingChangeResource;
use App\Filament\Resources\SettingChangeResource\Pages\ListSettingChanges;
use App\Models\Admin\SettingChange;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\Legacy\WpUserMeta;
use App\Settings\SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** System → Settings history: every change is written down and can be put back. */
class SettingsHistoryTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private const LIMIT = 'withdrawals.minimum_amount';

    private function limit(): float|int
    {
        return config(self::LIMIT);
    }

    public function test_a_save_writes_down_what_changed_who_did_it_and_skips_what_did_not(): void
    {
        $admin = $this->actingAsAdministrator();
        $before = $this->limit();

        $changed = SettingsStore::save([self::LIMIT => 7000, 'withdrawals.verification_fee' => config('withdrawals.verification_fee')], $admin);

        $this->assertCount(1, $changed);
        $row = SettingChange::firstOrFail();
        $this->assertEquals([self::LIMIT, $before, 7000, $admin->ID, false], [$row->key, $row->old_value, $row->new_value, $row->changed_by, $row->is_secret]);
        $this->assertNotEmpty($row->batch);
        $this->assertNull($row->reverted_at);

        SettingsStore::save([self::LIMIT => 7000], $admin);
        $this->assertSame(1, SettingChange::count(), 'saving the same value again records nothing');
    }

    public function test_changes_saved_together_share_a_batch(): void
    {
        $admin = $this->actingAsAdministrator();

        SettingsStore::save([self::LIMIT => 7100, 'withdrawals.verification_fee' => 4321], $admin);
        SettingsStore::save([self::LIMIT => 7200], $admin);

        $batches = SettingChange::orderBy('id')->pluck('batch');
        $this->assertSame($batches[0], $batches[1]);
        $this->assertNotSame($batches[0], $batches[2]);
    }

    public function test_undo_puts_the_old_value_back_at_once_and_drops_the_override_when_it_matches_the_server(): void
    {
        $admin = $this->actingAsAdministrator();
        $server = $this->limit();
        SettingsStore::save([self::LIMIT => 9000], $admin);
        $this->assertSame(9000, $this->limit());

        $result = SettingsStore::undo(SettingChange::all(), $admin);

        $this->assertSame(['Smallest withdrawal'], $result['restored']);
        $this->assertEquals($server, $this->limit());
        $this->assertFalse(AppSetting::where('key', self::LIMIT)->exists(), 'back to the server value, so no override is kept');
        $this->assertNotNull(SettingChange::first()->reverted_at);
        $this->assertSame($admin->ID, SettingChange::first()->reverted_by);
        $this->assertTrue(AdminAuditLog::where('action', 'settings.undone')->exists());
    }

    public function test_an_undo_is_itself_recorded_and_can_be_undone_again(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save([self::LIMIT => 9000], $admin);
        SettingsStore::undo(SettingChange::all(), $admin);

        $this->assertSame(2, SettingChange::count());
        $undoRow = SettingChange::orderByDesc('id')->first();
        $this->assertSame(9000, $undoRow->old_value);

        SettingsStore::undo([$undoRow], $admin);
        $this->assertSame(9000, $this->limit(), 'undoing the undo brings the change back');
    }

    public function test_a_change_cannot_be_put_back_twice(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save([self::LIMIT => 9000], $admin);
        $change = SettingChange::first();
        SettingsStore::undo([$change], $admin);
        SettingsStore::save([self::LIMIT => 4321], $admin);

        $result = SettingsStore::undo([$change], $admin);

        $this->assertSame([], $result['restored']);
        $this->assertStringContainsString('already put back', $result['skipped'][0]);
        $this->assertSame(4321, $this->limit(), 'the newer value is untouched');
    }

    public function test_undoing_an_older_change_after_a_newer_one_goes_back_to_before_the_older_one(): void
    {
        $admin = $this->actingAsAdministrator();
        $server = $this->limit();
        SettingsStore::save([self::LIMIT => 1111], $admin);
        SettingsStore::save([self::LIMIT => 2222], $admin);
        [$first, $second] = SettingChange::orderBy('id')->get()->all();

        $this->assertFalse(SettingsStore::isCurrent($first), 'changed again since');
        $this->assertTrue(SettingsStore::isCurrent($second));

        SettingsStore::undo([$first], $admin);

        $this->assertEquals($server, $this->limit());
    }

    public function test_a_whole_save_can_be_undone_together(): void
    {
        $admin = $this->actingAsAdministrator();
        $min = $this->limit();
        $max = config('withdrawals.verification_fee');
        SettingsStore::save([self::LIMIT => 5000, 'withdrawals.verification_fee' => 4321], $admin);

        $batch = SettingChange::first()->batch;
        $result = SettingsStore::undo(SettingChange::where('batch', $batch)->get(), $admin);

        $this->assertCount(2, $result['restored']);
        $this->assertEquals([$min, $max], [$this->limit(), config('withdrawals.verification_fee')]);
    }

    // -- Secrets ------------------------------------------------------------

    public function test_secret_keys_are_kept_encrypted_in_the_history_and_never_shown(): void
    {
        $admin = $this->actingAsAdministrator();
        config(['services.paystack.secret_key' => 'sk_live_OLDKEY1111']);

        SettingsStore::save(['services.paystack.secret_key' => 'sk_live_NEWKEY2222'], $admin);

        $row = SettingChange::firstOrFail();
        $raw = json_encode($row->getRawOriginal());
        $this->assertStringNotContainsString('OLDKEY', $raw);
        $this->assertStringNotContainsString('NEWKEY', $raw);
        $this->assertTrue($row->is_secret);
        $this->assertSame('sk_live_OLDKEY1111', SettingsStore::historyRead($row, 'old_value'));

        $summary = SettingChangeResource::summary($row);
        $this->assertStringNotContainsString('KEY', $summary);
        $this->assertStringContainsString('hidden', $summary);
        $this->assertStringNotContainsString('OLDKEY', json_encode(AdminAuditLog::where('action', 'settings.updated')->first()->context));
    }

    public function test_a_wrongly_pasted_secret_can_be_undone(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save(['services.paystack.secret_key' => 'sk_live_GOOD1111'], $admin);
        SettingsStore::save(['services.paystack.secret_key' => 'sk_live_TYPO2222'], $admin);
        $this->assertSame('sk_live_TYPO2222', config('services.paystack.secret_key'));

        SettingsStore::undo([SettingChange::orderByDesc('id')->first()], $admin);

        $this->assertSame('sk_live_GOOD1111', config('services.paystack.secret_key'));
    }

    public function test_undoing_the_first_time_a_secret_was_set_goes_back_to_the_server_file(): void
    {
        $admin = $this->actingAsAdministrator();
        $server = config('services.paystack.secret_key');
        SettingsStore::save(['services.paystack.secret_key' => 'sk_live_FIRST3333'], $admin);

        SettingsStore::undo(SettingChange::all(), $admin);

        $this->assertSame($server, config('services.paystack.secret_key'));
        $this->assertFalse(AppSetting::where('key', 'services.paystack.secret_key')->exists());
    }

    public function test_removing_a_saved_value_is_recorded_and_can_be_undone(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save([self::LIMIT => 6000], $admin);
        SettingsStore::forget(self::LIMIT, $admin);
        $this->assertNotSame(6000, $this->limit());

        SettingsStore::undo([SettingChange::orderByDesc('id')->first()], $admin);

        $this->assertSame(6000, $this->limit());
    }

    public function test_a_change_from_the_server_command_line_is_recorded_without_a_person(): void
    {
        SettingsStore::save(['security.staff_two_step' => true], null);

        $row = SettingChange::firstOrFail();
        $this->assertNull($row->changed_by);
        $this->assertSame('The server (command line)', SettingChangeResource::changedBy($row));
    }

    // -- The screen ---------------------------------------------------------

    public function test_the_settings_page_records_its_saves_and_the_history_screen_can_undo_them(): void
    {
        $admin = $this->actingAsAdministrator();
        $server = $this->limit();

        Livewire::test(Settings::class)->set('data.withdrawals__minimum_amount', 8888)->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, SettingChange::count());
        $this->assertSame($admin->ID, SettingChange::first()->changed_by);

        $change = SettingChange::first();
        Livewire::test(ListSettingChanges::class)
            ->assertCanSeeTableRecords([$change])
            ->assertSee('8,888')
            ->callTableAction('undo', $change);

        $this->assertEquals($server, $this->limit());
        $this->assertNotNull($change->fresh()->reverted_at);
    }

    public function test_the_screen_says_where_each_change_stands(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save([self::LIMIT => 1111], $admin);
        SettingsStore::save([self::LIMIT => 2222], $admin);
        [$first, $second] = SettingChange::orderBy('id')->get()->all();

        $this->assertSame('Changed again since', SettingChangeResource::status($first)[0]);
        $this->assertSame('In effect now', SettingChangeResource::status($second)[0]);

        SettingsStore::undo([$second], $admin);
        $this->assertStringStartsWith('Put back by', SettingChangeResource::status($second->fresh())[0]);
    }

    public function test_a_whole_save_undo_button_only_appears_when_several_settings_were_saved_together(): void
    {
        $admin = $this->actingAsAdministrator();
        SettingsStore::save([self::LIMIT => 5000], $admin);
        SettingsStore::save([self::LIMIT => 5100, 'withdrawals.verification_fee' => 4321], $admin);
        $alone = SettingChange::orderBy('id')->first();
        $together = SettingChange::orderBy('id')->skip(1)->first();

        Livewire::test(ListSettingChanges::class)
            ->assertTableActionHidden('undoSave', $alone)
            ->assertTableActionVisible('undoSave', $together)
            ->callTableAction('undoSave', $together);

        $this->assertSame(2, SettingChange::whereNotNull('reverted_at')->count());
    }

    public function test_only_owners_can_see_the_history(): void
    {
        $this->assertTrue(StaffRoles::canOpen('owner', SettingChangeResource::class));
        foreach (['manager', 'finance', 'support', 'content'] as $role) {
            $this->assertFalse(StaffRoles::canOpen($role, SettingChangeResource::class), $role);
        }

        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => 'manager']);
        $this->get('/admin/settings-history')->assertForbidden();
    }
}
