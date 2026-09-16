<?php

namespace Tests\Unit;

use App\Models\AdminAuditLog;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\WinnerManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WinnerManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private WinnerManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WinnerManagementService::class);
    }

    private function makeAdmin(): WpUser
    {
        return WpUser::create(['user_login' => 'admin'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    private function makeWinner(float $prize = 5000): RaffleWinner
    {
        $winner = WpUser::create(['user_login' => 'winner'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);

        return RaffleWinner::create([
            'raffle_id' => 1, 'user_id' => $winner->ID, 'ticket_number' => 7,
            'prize_name' => 'Cash Prize', 'prize_rank' => 1, 'prize_cash_value' => $prize,
        ]);
    }

    public function test_crediting_a_winner_pays_their_earnings_balance(): void
    {
        $admin = $this->makeAdmin();
        $winner = $this->makeWinner(5000);

        $this->service->credit($admin, $winner);

        $this->assertTrue($winner->fresh()->is_credited);
        $this->assertEquals(5000, Wallet::where('user_id', $winner->user_id)->value('earnings_balance'));
    }

    public function test_crediting_writes_an_audit_log_entry(): void
    {
        $admin = $this->makeAdmin();
        $winner = $this->makeWinner(5000);

        $this->service->credit($admin, $winner);

        $log = AdminAuditLog::where('action', 'winner.credited')->first();
        $this->assertSame($admin->ID, $log->admin_user_id);
        $this->assertSame($winner->id, $log->subject_id);
        $this->assertEquals(5000, $log->context['amount']);
    }

    public function test_a_winner_cannot_be_credited_twice(): void
    {
        $admin = $this->makeAdmin();
        $winner = $this->makeWinner(5000);
        $this->service->credit($admin, $winner);

        $this->expectException(RuntimeException::class);
        $this->service->credit($admin, $winner->fresh());
    }

    public function test_a_zero_value_prize_does_not_create_a_wallet_row(): void
    {
        $admin = $this->makeAdmin();
        $winner = $this->makeWinner(0);

        $this->service->credit($admin, $winner);

        $this->assertTrue($winner->fresh()->is_credited);
        $this->assertSame(0, Wallet::where('user_id', $winner->user_id)->count());
    }

    public function test_visibility_can_be_toggled_and_is_logged(): void
    {
        $admin = $this->makeAdmin();
        $winner = $this->makeWinner();

        $this->service->setVisibility($admin, $winner, true);

        $this->assertTrue($winner->fresh()->is_visible);
        $this->assertSame(1, AdminAuditLog::where('action', 'winner.visibility_toggled')->count());
    }
}
