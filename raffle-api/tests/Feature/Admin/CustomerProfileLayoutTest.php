<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * The customer profile page: three header buttons and one Actions menu, grouped
 * by purpose and by who may use them, one tab strip, and professional icons
 * (no emoji anywhere on the page).
 */
class CustomerProfileLayoutTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(): WpUser
    {
        return WpUser::create(['user_login' => 'adesanya'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Adesanya']);
    }

    private function asRole(string $role): void
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => $role]);
        Livewire::withCookies($this->unencryptedCookies);
    }

    private function menu(WpUser $customer): array
    {
        $page = Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()]);

        return collect($page->instance()->menuGroups())->mapWithKeys(fn ($g) => [$g['heading'] => array_column($g['items'], 'label')])->all();
    }

    public function test_the_header_has_adjust_balance_message_and_actions_and_hides_the_rest(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->assertActionVisible('adjustBalance')
            ->assertActionVisible('messageCustomer')
            ->assertActionVisible('actionsMenu')
            ->assertSeeHtml('rk-action-registry')
            ->assertSeeHtml('display:none');
    }

    public function test_an_owner_sees_every_group_in_the_menu(): void
    {
        $this->actingAsAdministrator();

        $menu = $this->menu($this->customer());

        $this->assertSame(['Help the customer', 'Money and rewards', 'Notes', 'Care', 'Owner only'], array_keys($menu));
        $this->assertContains('Reset password', $menu['Help the customer']);
        $this->assertContains('Free spins and tokens', $menu['Money and rewards']);
        $this->assertContains('Ban customer', $menu['Care']);
        $this->assertContains('View as customer', $menu['Owner only']);
    }

    public function test_support_staff_only_see_the_help_and_notes_groups(): void
    {
        $this->asRole('support');

        $menu = $this->menu($this->customer());

        $this->assertSame(['Help the customer', 'Notes'], array_keys($menu));
        $this->assertContains('Reset password', $menu['Help the customer']);
        $this->assertNotContains('Edit name, email, phone', $menu['Help the customer']);
    }

    public function test_managers_get_money_and_care_but_not_the_owner_only_item(): void
    {
        $this->asRole('manager');

        $menu = $this->menu($this->customer());

        $this->assertArrayHasKey('Money and rewards', $menu);
        $this->assertArrayHasKey('Care', $menu);
        $this->assertArrayNotHasKey('Owner only', $menu);
    }

    public function test_a_banned_customer_offers_unban_not_ban(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        WpUserMeta::create(['user_id' => $customer->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $care = $this->menu($customer)['Care'];

        $this->assertContains('Unban customer', $care);
        $this->assertNotContains('Ban customer', $care);
    }

    public function test_the_menu_opens_and_lists_the_actions_with_help_text(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->mountAction('actionsMenu')
            ->assertSee('Customer actions')
            ->assertSee('Reset password')
            ->assertSee('Email a code, or set a temporary one')
            ->assertSee('Help the customer')
            ->assertSee('Care');
    }

    public function test_choosing_an_item_in_the_menu_opens_that_action(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->mountAction('actionsMenu')
            ->call('replaceMountedAction', 'resetPassword')
            ->assertSet('mountedActions', ['resetPassword'])
            ->assertSee('Reset this customer');
    }

    public function test_a_hidden_action_cannot_be_opened_even_by_name(): void
    {
        $this->asRole('support');
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->call('replaceMountedAction', 'adjustBalance')
            ->assertSet('mountedActions', [])
            ->call('replaceMountedAction', 'ban')
            ->assertSet('mountedActions', [])
            ->call('replaceMountedAction', 'viewAsCustomer')
            ->assertSet('mountedActions', []);
    }

    public function test_the_profile_shows_one_tab_strip_with_overview_and_the_record_tabs(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->assertSee('Overview')
            ->assertSee('Money')
            ->assertSee('Tickets')
            ->assertSee('Withdrawals')
            ->assertSee('Support')
            ->assertSee('Admin actions');
    }

    public function test_the_heading_shows_the_account_status(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])->assertSeeHtml('>Active<');

        WpUserMeta::create(['user_id' => $customer->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);
        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])->assertSeeHtml('>Banned<');
    }

    public function test_no_emoji_appears_on_the_profile_or_in_the_menu(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();
        UserBadge::create(['user_id' => $customer->ID, 'badge' => 'first_ticket', 'earned_at' => now()]);

        $html = Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])->mountAction('actionsMenu')->html();

        $this->assertStringContainsString('First Ticket', $html);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $html), 'the page must use icons, not emoji');
    }
}
