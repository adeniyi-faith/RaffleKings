<?php

namespace Tests\Unit;

use App\Exceptions\InsufficientPointsException;
use App\Models\Legacy\WpUser;
use App\Models\PointLedgerEntry;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointsServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->points = app(PointsService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_a_new_user_has_zero_points(): void
    {
        $user = $this->makeUser();

        $this->assertSame(0, $this->points->balance($user));
    }

    public function test_credit_increases_the_balance_and_logs_a_ledger_entry(): void
    {
        $user = $this->makeUser();

        $newBalance = $this->points->credit($user, 100, 'daily_claim');

        $this->assertSame(100, $newBalance);
        $this->assertSame(100, $this->points->balance($user));
        $entry = PointLedgerEntry::where('user_id', $user->ID)->first();
        $this->assertSame('credit', $entry->direction);
        $this->assertSame(100, $entry->amount);
    }

    public function test_debit_decreases_the_balance(): void
    {
        $user = $this->makeUser();
        $this->points->credit($user, 100, 'daily_claim');

        $newBalance = $this->points->debit($user, 30, 'spin_cost');

        $this->assertSame(70, $newBalance);
    }

    public function test_debiting_more_than_the_balance_is_refused_and_changes_nothing(): void
    {
        $user = $this->makeUser();
        $this->points->credit($user, 20, 'daily_claim');

        try {
            $this->points->debit($user, 50, 'spin_cost');
            $this->fail('Expected InsufficientPointsException was not thrown.');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(30, $e->shortfall);
        }

        $this->assertSame(20, $this->points->balance($user));
    }
}
