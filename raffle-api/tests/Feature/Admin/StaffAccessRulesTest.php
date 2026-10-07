<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Filament\Resources\BalanceAdjustmentResource\Pages\ListBalanceAdjustments;
use App\Models\BalanceAdjustment;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\BalanceAdjustments;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class StaffAccessRulesTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function person(string $name, ?string $role = null): WpUser
    {
        $user = WpUser::create(['user_login' => $name, 'user_pass' => 'x', 'user_email' => "{$name}@example.com", 'display_name' => $name]);

        if ($role) {
            WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => $role]);
        }

        return $user;
    }

    public function test_a_wordpress_administrator_with_no_staff_role_gets_no_access(): void
    {
        $admin = $this->person('wp_admin');
        WpUserMeta::create(['user_id' => $admin->ID, 'meta_key' => config('legacy.wp_prefix').'capabilities', 'meta_value' => serialize(['administrator' => true])]);

        $this->assertNull(WpUser::find($admin->ID)->staffRole());
        $this->assertFalse(WpUser::find($admin->ID)->staffCan('money.pay'));
    }

    public function test_only_staff_who_can_pay_out_may_change_a_balance(): void
    {
        $support = $this->person('help', 'support');
        $customer = $this->person('ada');

        $this->expectException(InvalidArgumentException::class);
        app(UserManagementService::class)->adjustBalance($support, $customer, 'wallet', 100, 'add', 'goodwill');
    }

    public function test_a_big_change_waits_and_only_a_different_payout_staff_member_can_approve_it(): void
    {
        $finance = $this->person('fin', 'finance');
        $other = $this->person('fin2', 'finance');
        $support = $this->person('help', 'support');
        $customer = $this->person('ada');

        $adjustment = app(BalanceAdjustments::class)->propose($finance, $customer, 'wallet', 'add', 20000, 'Fixing a lost top-up, ref 123');
        $this->assertSame('pending', $adjustment->status);

        foreach ([$finance, $support] as $notAllowed) {
            try {
                app(BalanceAdjustments::class)->approve($notAllowed, $adjustment);
                $this->fail('approval should be refused');
            } catch (RuntimeException $e) {
                $this->assertSame('pending', $adjustment->fresh()->status);
            }
        }

        $this->assertSame('applied', app(BalanceAdjustments::class)->approve($other, $adjustment)->status);
    }

    public function test_the_waiting_list_shows_a_pending_change_and_the_other_person_can_approve_it(): void
    {
        $asker = $this->person('fin', 'finance');
        $customer = $this->person('ada');
        $adjustment = app(BalanceAdjustments::class)->propose($asker, $customer, 'wallet', 'add', 20000, 'Fixing a lost top-up, ref 123');

        $this->actingAsAdministrator();

        Livewire::test(ListBalanceAdjustments::class)
            ->assertCanSeeTableRecords([$adjustment])
            ->callTableAction('approve', $adjustment);

        $this->assertSame('applied', BalanceAdjustment::find($adjustment->id)->status);
    }
}
