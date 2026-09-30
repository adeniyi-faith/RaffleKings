<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Filament\Resources\BroadcastResource;
use App\Filament\Resources\Legacy\WpUserResource\Pages\ListWpUsers;
use App\Models\Admin\CustomerTag;
use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\Admin\CustomerBulkActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The Customers list: tick some (or select all of a filtered list) and act on them together. */
class CustomerBulkActionsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(string $login, array $extra = []): WpUser
    {
        $user = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com", 'display_name' => ucfirst($login), 'user_registered' => $extra['joined'] ?? now()->subMonths(2)]);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => $extra['wallet'] ?? 0, 'earnings_balance' => 0]);

        return $user;
    }

    private function tagsOf(WpUser $user): array
    {
        return CustomerTag::where('user_id', $user->ID)->orderBy('tag')->pluck('tag')->all();
    }

    private function asStaff(string $role): WpUser
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => $role]);
        Livewire::withCookies($this->unencryptedCookies);

        return $user;
    }

    // -- Tags ---------------------------------------------------------------

    public function test_tags_are_added_to_the_picked_customers_only_and_never_twice(): void
    {
        $admin = $this->actingAsAdministrator();
        [$ada, $bola, $cee] = [$this->customer('ada'), $this->customer('bola'), $this->customer('cee')];
        CustomerTag::create(['user_id' => $ada->ID, 'tag' => 'vip']);
        $service = app(CustomerBulkActions::class);

        $result = $service->addTags($admin, [$ada->ID, $bola->ID], ['VIP', '  Watch   closely ', 'vip']);

        $this->assertSame(['customers' => 2, 'added' => 3], $result, 'Ada already had vip (any capitals), so she gets only the new one');
        $this->assertSame(['Watch closely', 'vip'], $this->tagsOf($ada));
        $this->assertSame(['VIP', 'Watch closely'], $this->tagsOf($bola));
        $this->assertSame([], $this->tagsOf($cee));

        $this->assertSame(['customers' => 0, 'added' => 0], $service->addTags($admin, [$ada->ID, $bola->ID], ['vip']), 'doing it again changes nothing');
        $this->assertSame(2, AdminAuditLog::where('action', 'customer.tags_changed')->count(), 'one entry per customer who changed');
        $this->assertTrue(AdminAuditLog::where('subject_id', $bola->ID)->first()->context['bulk']);
    }

    public function test_tags_are_removed_from_the_picked_customers_only(): void
    {
        $admin = $this->actingAsAdministrator();
        [$ada, $bola] = [$this->customer('ada'), $this->customer('bola')];
        foreach ([$ada, $bola] as $u) {
            CustomerTag::create(['user_id' => $u->ID, 'tag' => 'promo']);
            CustomerTag::create(['user_id' => $u->ID, 'tag' => 'vip']);
        }

        $result = app(CustomerBulkActions::class)->removeTags($admin, [$ada->ID], ['PROMO']);

        $this->assertSame(['customers' => 1, 'removed' => 1], $result);
        $this->assertSame(['vip'], $this->tagsOf($ada));
        $this->assertSame(['promo', 'vip'], $this->tagsOf($bola));
    }

    // -- Ban and unban ------------------------------------------------------

    public function test_banning_skips_staff_and_yourself_and_counts_who_was_already_banned(): void
    {
        $admin = $this->actingAsAdministrator();
        $support = $this->customer('support');
        WpUserMeta::create(['user_id' => $support->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => 'support']);
        [$ada, $bola, $done] = [$this->customer('ada'), $this->customer('bola'), $this->customer('done')];
        WpUserMeta::create(['user_id' => $done->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $result = app(CustomerBulkActions::class)->ban($admin, [$ada->ID, $bola->ID, $done->ID, $support->ID, $admin->ID], 'Fake accounts');

        $this->assertSame(['banned' => 2, 'staff' => 2, 'already' => 1], $result);
        $this->assertTrue($ada->fresh()->isBanned());
        $this->assertTrue($bola->fresh()->isBanned());
        $this->assertFalse($support->fresh()->isBanned());
        $this->assertFalse($admin->fresh()->isBanned());
        $this->assertSame(2, AdminAuditLog::where('action', 'user.banned')->count());
        $this->assertSame('Fake accounts', AdminAuditLog::where('action', 'user.banned')->first()->context['reason']);
    }

    public function test_banning_more_than_the_limit_is_refused_before_anyone_is_touched(): void
    {
        $admin = $this->actingAsAdministrator();
        $ada = $this->customer('ada');

        try {
            app(CustomerBulkActions::class)->ban($admin, array_merge([$ada->ID], range(1000, 1000 + CustomerBulkActions::MAX_BAN)), 'x');
            $this->fail('should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('at most 500', $e->getMessage());
        }

        $this->assertFalse($ada->fresh()->isBanned());
    }

    public function test_unbanning_lifts_bans(): void
    {
        $admin = $this->actingAsAdministrator();
        [$ada, $bola] = [$this->customer('ada'), $this->customer('bola')];
        app(CustomerBulkActions::class)->ban($admin, [$ada->ID], 'oops');

        $result = app(CustomerBulkActions::class)->unban($admin, [$ada->ID, $bola->ID]);

        $this->assertSame(['banned' => 1, 'staff' => 0, 'already' => 1], $result);
        $this->assertFalse($ada->fresh()->isBanned());
    }

    // -- On the Customers list ---------------------------------------------

    public function test_the_list_actions_tag_message_export_and_ban(): void
    {
        $admin = $this->actingAsAdministrator();
        [$ada, $bola, $cee] = [$this->customer('ada'), $this->customer('bola'), $this->customer('cee')];
        $picked = WpUser::whereIn('ID', [$ada->ID, $bola->ID])->get();

        $page = Livewire::test(ListWpUsers::class);

        $page->callTableBulkAction('addTags', $picked, ['tags' => ['VIP']])->assertHasNoTableBulkActionErrors();
        $this->assertSame(['VIP'], $this->tagsOf($ada));
        $this->assertSame([], $this->tagsOf($cee));

        $page->callTableBulkAction('removeTags', $picked, ['tags' => ['VIP']]);
        $this->assertSame([], $this->tagsOf($ada));

        $page->callTableBulkAction('ban', $picked, ['reason' => 'Fake']);
        $this->assertTrue($ada->fresh()->isBanned());
        $this->assertFalse($cee->fresh()->isBanned());

        $page->callTableBulkAction('unban', $picked);
        $this->assertFalse($ada->fresh()->isBanned());
    }

    public function test_messaging_the_ticked_customers_hands_the_exact_list_to_the_message_form(): void
    {
        $this->actingAsAdministrator();
        [$ada, $bola] = [$this->customer('ada'), $this->customer('bola')];
        $this->customer('cee');

        $page = Livewire::test(ListWpUsers::class)
            ->callTableBulkAction('message', WpUser::whereIn('ID', [$ada->ID, $bola->ID])->get());

        $url = $page->effects['redirect'] ?? '';
        $this->assertStringStartsWith(BroadcastResource::getUrl('create'), $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEqualsCanonicalizing([$ada->ID, $bola->ID], Cache::get(BroadcastResource::TICKED_CACHE_PREFIX.$query['list']));
    }

    public function test_the_spreadsheet_has_only_the_ticked_customers_and_defuses_formulas(): void
    {
        $this->actingAsAdministrator();
        $evil = $this->customer('evil', ['wallet' => 1500]);
        $evil->update(['display_name' => '=HYPERLINK("http://x")']);
        CustomerTag::create(['user_id' => $evil->ID, 'tag' => 'vip']);
        $other = $this->customer('other');

        $csv = Livewire::test(ListWpUsers::class)
            ->callTableBulkAction('export', WpUser::whereKey($evil->ID)->get())
            ->effects['download']['content'] ?? '';
        $csv = base64_decode($csv, true) ?: $csv;

        $this->assertStringContainsString('evil@example.com', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('1500', $csv);
        $this->assertStringContainsString('vip', $csv);
        $this->assertStringNotContainsString($other->user_email, $csv);
        $this->assertTrue(AdminAuditLog::where('action', 'report.downloaded')->exists());
    }

    public function test_filters_narrow_the_list_so_select_all_means_what_you_meant(): void
    {
        $this->actingAsAdministrator();
        [$vip, $plain, $banned, $buyer] = [$this->customer('vip', ['wallet' => 900]), $this->customer('plain', ['joined' => now()->subDays(3)]), $this->customer('banned'), $this->customer('buyer')];
        CustomerTag::create(['user_id' => $vip->ID, 'tag' => 'VIP']);
        WpUserMeta::create(['user_id' => $banned->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 1, 'ticket_number' => 1, 'txn_id' => 1]);

        $names = fn ($page) => $page->instance()->getFilteredTableQuery()->pluck('user_login')->sort()->values()->all();

        $this->assertSame(['vip'], $names(Livewire::test(ListWpUsers::class)->filterTable('tag', ['VIP'])));
        $this->assertSame(['banned'], $names(Livewire::test(ListWpUsers::class)->filterTable('banned', true)));
        $this->assertSame(['banned', 'buyer', 'plain', 'vip'], array_values(array_diff($names(Livewire::test(ListWpUsers::class)->filterTable('banned', null)), [WpUser::first()->user_login])), 'no filter: everyone');
        $this->assertSame(['vip'], $names(Livewire::test(ListWpUsers::class)->filterTable('has_money', true)));
        $this->assertNotContains('buyer', $names(Livewire::test(ListWpUsers::class)->filterTable('never_bought', true)));
        $this->assertSame(['plain'], $names(Livewire::test(ListWpUsers::class)->filterTable('joined', ['from' => now()->subDays(7)->toDateString(), 'until' => now()->toDateString()])));
    }

    // -- Who sees what ------------------------------------------------------

    public function test_look_only_staff_get_no_bulk_actions(): void
    {
        $this->asStaff('support');
        $this->customer('ada');

        Livewire::test(ListWpUsers::class)->assertSuccessful()
            ->assertTableBulkActionHidden('addTags')->assertTableBulkActionHidden('ban')->assertTableBulkActionHidden('export')->assertTableBulkActionHidden('message');
    }

    public function test_finance_can_manage_customers_but_not_message_them(): void
    {
        $this->asStaff('finance');
        $this->customer('ada');

        Livewire::test(ListWpUsers::class)->assertSuccessful()
            ->assertTableBulkActionVisible('addTags')->assertTableBulkActionVisible('ban')->assertTableBulkActionHidden('message');
    }

    public function test_a_hidden_bulk_action_cannot_be_forced_by_a_look_only_role(): void
    {
        $this->asStaff('support');
        $ada = $this->customer('ada');

        // The button isn't there, so poke the page's own methods directly, the way a tampered browser would.
        Livewire::test(ListWpUsers::class)
            ->set('selectedTableRecords', [(string) $ada->ID])
            ->call('mountTableBulkAction', 'ban')
            ->set('mountedTableBulkActionData', ['reason' => 'x'])
            ->call('callMountedTableBulkAction');

        $this->assertFalse($ada->fresh()->isBanned());
    }

    public function test_the_same_direct_call_does_work_for_an_owner_so_the_block_above_is_real(): void
    {
        $this->actingAsAdministrator();
        $ada = $this->customer('ada');

        Livewire::test(ListWpUsers::class)
            ->set('selectedTableRecords', [(string) $ada->ID])
            ->call('mountTableBulkAction', 'ban')
            ->set('mountedTableBulkActionData', ['reason' => 'x'])
            ->call('callMountedTableBulkAction');

        $this->assertTrue($ada->fresh()->isBanned());
    }
}
