<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Models\Admin\CustomerNote;
use App\Models\Admin\CustomerTag;
use App\Models\Admin\LoginEvent;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Admin\CustomerTimeline;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class CustomerTimelineAndNotesTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    private function customer(): WpUser
    {
        return WpUser::create(['user_login' => 'ada'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Ada', 'user_registered' => now()]);
    }

    public function test_the_timeline_puts_everything_in_date_order(): void
    {
        $user = $this->customer();
        $raffle = $this->createRaffle(['title' => 'iPhone 17']);

        $this->travelTo(now()->subDays(3));
        app(WalletLedgerService::class)->credit($user->ID, 'wallet', 5000, 'deposit', 'deposit:t'.$user->ID, 'gateway_clearing', description: 'Deposit via paystack');
        $this->travelBack();
        $this->travelTo(now()->subDays(2));
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 7, 'txn_id' => 0]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 9, 'txn_id' => 0]);
        $this->travelBack();
        $this->travelTo(now()->subDay());
        LoginEvent::record($user->ID, 'ada', false, 'site', 'wrong_password');
        $this->travelBack();
        Wallet::firstOrCreate(['user_id' => $user->ID], ['wallet_balance' => 0, 'earnings_balance' => 0]);
        $account = \App\Models\BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'UBA', 'account_number' => '2114907747', 'account_name' => 'ADA']);
        WithdrawalRequest::create(['user_id' => $user->ID, 'bank_account_id' => $account->id, 'requested_amount' => 3000, 'fee_amount' => 0, 'amount_to_send' => 3000, 'status' => 'pending']);

        $titles = array_column(app(CustomerTimeline::class)->for($user), 'title');

        $this->assertSame(['Asked to withdraw', 'Sign-in refused', '2 tickets in iPhone 17', 'Top-up'], $titles);
        $this->assertSame(['2 tickets in iPhone 17'], array_column(app(CustomerTimeline::class)->for($user, 'tickets'), 'title'));
    }

    public function test_sign_ins_are_recorded_for_the_site(): void
    {
        $user = WpUser::create(['user_login' => 'bola', 'user_pass' => app(\App\Services\Auth\WordPressPasswordHasher::class)->make('secret123'), 'user_email' => 'bola@example.com', 'display_name' => 'Bola']);

        $this->postJson('/api/auth/login', ['username' => 'bola', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['username' => 'bola', 'password' => 'secret123'])->assertOk();

        $this->assertSame([false, true], LoginEvent::query()->where('user_id', $user->ID)->orderBy('id')->pluck('success')->all());
    }

    public function test_staff_add_notes_and_tags_on_the_customer_page(): void
    {
        $this->actingAsAdministrator();
        $user = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $user->ID])
            ->callAction('addNote', ['body' => 'Called about a late payout.', 'pinned' => true])
            ->callAction('tags', ['tags' => ['VIP', ' watch  closely ', 'vip']])
            ->assertSee('Called about a late payout.')
            ->assertSee('watch closely')
            ->assertSee('Timeline');

        $this->assertTrue(CustomerNote::first()->pinned);
        $this->assertEqualsCanonicalizing(['VIP', 'watch closely'], CustomerTag::query()->pluck('tag')->all());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'customer.tags_changed', 'subject_id' => $user->ID]);
    }
}
