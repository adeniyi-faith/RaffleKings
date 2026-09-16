<?php

namespace Tests\Unit;

use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Services\BankAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BankAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private BankAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BankAccountService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_the_first_account_added_becomes_primary_automatically(): void
    {
        $user = $this->makeUser();

        $account = $this->service->add($user, 'GTBank', '0123456789', "O'Brien Smith");

        $this->assertTrue($account->is_primary);
    }

    public function test_a_second_account_is_not_automatically_primary(): void
    {
        $user = $this->makeUser();
        $this->service->add($user, 'GTBank', '0123456789', 'First Account');

        $second = $this->service->add($user, 'Kuda', '9876543210', 'Second Account');

        $this->assertFalse($second->is_primary);
    }

    public function test_a_third_account_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->service->add($user, 'GTBank', '0123456789', 'First Account');
        $this->service->add($user, 'Kuda', '9876543210', 'Second Account');

        $this->expectException(RuntimeException::class);
        $this->service->add($user, 'Zenith', '1111111111', 'Third Account');
    }

    public function test_it_rejects_an_account_number_that_is_not_exactly_10_digits(): void
    {
        $user = $this->makeUser();
        $this->expectException(InvalidArgumentException::class);
        $this->service->add($user, 'GTBank', '12345', 'Someone');
    }

    public function test_it_rejects_an_invalid_account_name(): void
    {
        $user = $this->makeUser();
        $this->expectException(InvalidArgumentException::class);
        $this->service->add($user, 'GTBank', '0123456789', 'X'); // too short
    }

    public function test_deleting_the_primary_account_promotes_the_remaining_one(): void
    {
        $user = $this->makeUser();
        $primary = $this->service->add($user, 'GTBank', '0123456789', 'First Account');
        $other = $this->service->add($user, 'Kuda', '9876543210', 'Second Account');

        $this->service->delete($user, $primary->id);

        $this->assertTrue($other->refresh()->is_primary);
        $this->assertSame(1, BankAccount::where('user_id', $user->ID)->count());
    }

    public function test_deleting_the_only_account_leaves_none(): void
    {
        $user = $this->makeUser();
        $account = $this->service->add($user, 'GTBank', '0123456789', 'First Account');

        $this->service->delete($user, $account->id);

        $this->assertSame(0, BankAccount::where('user_id', $user->ID)->count());
    }

    public function test_set_primary_switches_which_account_is_primary(): void
    {
        $user = $this->makeUser();
        $first = $this->service->add($user, 'GTBank', '0123456789', 'First Account');
        $second = $this->service->add($user, 'Kuda', '9876543210', 'Second Account');

        $this->service->setPrimary($user, $second->id);

        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->refresh()->is_primary);
    }

    public function test_a_user_cannot_delete_or_set_primary_on_another_users_account(): void
    {
        $owner = $this->makeUser();
        $attacker = $this->makeUser();
        $account = $this->service->add($owner, 'GTBank', '0123456789', 'Owner Account');

        $this->expectException(RuntimeException::class);
        $this->service->delete($attacker, $account->id);
    }
}
