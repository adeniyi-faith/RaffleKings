<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\TeamTodo;
use App\Livewire\AdminBell;
use App\Models\Admin\StaffTask;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\SupportTicket;
use App\Models\WithdrawalRequest;
use App\Services\Admin\StaffTodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The admin bell and the shared team to-do list behind it. */
class TeamTodoTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function customer(string $name = 'Ada Obi'): WpUser
    {
        return WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => $name]);
    }

    private function withdrawal(float $amount = 3000): WithdrawalRequest
    {
        $customer = $this->customer();
        $account = BankAccount::create(['user_id' => $customer->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi', 'is_primary' => true]);

        return WithdrawalRequest::create(['user_id' => $customer->ID, 'bank_account_id' => $account->id, 'requested_amount' => $amount, 'fee_amount' => 0, 'amount_to_send' => $amount, 'status' => 'pending']);
    }

    private function ticket(string $subject = 'Where is my prize?'): SupportTicket
    {
        return SupportTicket::create(['user_id' => $this->customer('Bayo')->ID, 'subject' => $subject, 'status' => 'open', 'needs_human' => true]);
    }

    private function asRole(string $role, string $name = 'Staff'): WpUser
    {
        $user = $this->actingAsWordPressUser(['display_name' => $name]);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => $role]);
        Livewire::withCookies($this->unencryptedCookies);

        return $user;
    }

    private function todo(): StaffTodo
    {
        return app(StaffTodo::class);
    }

    private function taskFor(string $source, $record): ?StaffTask
    {
        return StaffTask::where('source', $source)->where('source_key', (string) $record->getKey())->first();
    }

    public function test_waiting_things_become_to_dos_once_each(): void
    {
        $w = $this->withdrawal(5000);
        $t = $this->ticket();

        $this->todo()->sync();
        $this->todo()->sync();

        $this->assertSame(2, StaffTask::count());
        $this->assertSame('Pay ₦5,000 withdrawal to Ada Obi', $this->taskFor('withdrawal', $w)->title);
        $this->assertSame('Answer Bayo: "Where is my prize?"', $this->taskFor('ticket', $t)->title);
        $this->assertSame('The support assistant handed this one to the team.', $this->taskFor('ticket', $t)->detail);
    }

    public function test_ticking_off_records_who_and_when_for_everyone(): void
    {
        $w = $this->withdrawal();
        $this->todo()->sync();

        $faith = $this->asRole('finance', 'Faith');
        Livewire::test(AdminBell::class)->call('tick', $this->taskFor('withdrawal', $w)->id);

        $task = $this->taskFor('withdrawal', $w);
        $this->assertTrue($task->isDone());
        $this->assertSame($faith->ID, $task->done_by);
        $this->assertSame('Faith', $task->doneByLabel());

        // Still waiting in its queue, but a person handled it: it stays done.
        $this->todo()->sync();
        $this->assertTrue($task->fresh()->isDone());

        // A teammate sees it on the list with Faith's name.
        $this->asRole('owner', 'Tunde');
        Livewire::test(TeamTodo::class)->set('show', 'done')
            ->assertCanSeeTableRecords([$task])
            ->assertSee('Done by Faith');
    }

    public function test_sorting_it_in_its_own_screen_closes_the_to_do_and_a_comeback_reopens_it(): void
    {
        $t = $this->ticket();
        $this->todo()->sync();

        $t->update(['status' => 'pending']); // staff replied
        $this->todo()->sync();
        $task = $this->taskFor('ticket', $t);
        $this->assertTrue($task->isDone());
        $this->assertNull($task->done_by);
        $this->assertSame('Sorted in its queue', $task->doneByLabel());

        $t->update(['status' => 'open']); // customer wrote back
        $this->todo()->sync();
        $this->assertFalse($task->fresh()->isDone());
        $this->assertSame(1, StaffTask::count());
    }

    public function test_the_bell_counts_open_to_dos_and_ticking_lowers_it(): void
    {
        $this->withdrawal();
        $this->withdrawal();
        $this->ticket();
        $this->asRole('owner');

        $bell = Livewire::test(AdminBell::class)->assertSee('3 waiting')->assertSee('Pay ₦3,000 withdrawal to Ada Obi');
        $bell->call('tick', StaffTask::where('source', 'ticket')->value('id'))
            ->assertSee('2 waiting')
            ->assertSee('Recently done')
            ->assertSee('Undo');

        $bell->call('undo', StaffTask::where('source', 'ticket')->value('id'))->assertSee('3 waiting');
    }

    public function test_each_role_only_sees_and_ticks_its_own_kind_of_to_do(): void
    {
        $w = $this->withdrawal();
        $this->ticket();
        $this->todo()->sync();

        $support = $this->asRole('support');
        $this->assertSame(1, $this->todo()->openCount($support));

        Livewire::test(AdminBell::class)
            ->assertSee('Where is my prize?')
            ->assertDontSee('withdrawal to Ada Obi')
            ->call('tick', $this->taskFor('withdrawal', $w)->id);

        $this->assertFalse($this->taskFor('withdrawal', $w)->isDone());
    }

    public function test_staff_can_add_their_own_to_do_and_the_page_and_bell_show_on_the_admin(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(TeamTodo::class)
            ->callAction('add', ['title' => 'Restock the prize cupboard', 'detail' => 'Before Friday'])
            ->assertHasNoActionErrors();

        $this->assertSame('Restock the prize cupboard', StaffTask::where('source', 'manual')->value('title'));

        $this->get('/admin/team-to-do')->assertOk()->assertSee('Team to-do')->assertSee('Restock the prize cupboard');
        $this->get('/admin')->assertOk()->assertSee('rk-bell-badge', false)->assertSee('See the full list');
    }

    public function test_marking_done_from_the_page_keeps_a_note(): void
    {
        $this->actingAsAdministrator();
        $w = $this->withdrawal();
        $this->todo()->sync();
        $task = $this->taskFor('withdrawal', $w);

        Livewire::test(TeamTodo::class)->callTableAction('done', $task, ['note' => 'Paid from the bank app']);

        $this->assertSame('Paid from the bank app', $task->fresh()->done_note);

        Livewire::test(TeamTodo::class)->set('show', 'done')->callTableAction('reopen', $task->fresh());
        $this->assertFalse($task->fresh()->isDone());

        // Tapping the box on the row ticks it off straight away.
        Livewire::test(TeamTodo::class)->callTableColumnAction('done_at', $task->fresh());
        $this->assertTrue($task->fresh()->isDone());
        $this->assertNull($task->fresh()->done_note);
    }

    public function test_a_long_queue_never_closes_to_dos_beyond_the_first_batch(): void
    {
        $w = $this->withdrawal();
        $this->todo()->sync();

        // A full batch of older withdrawals arrives: ours is no longer in the
        // first batch, but it is still waiting, so its to-do stays open.
        foreach (range(1, StaffTodo::PER_SOURCE) as $i) {
            $this->withdrawal()->forceFill(['created_at' => now()->subDays(2)])->save();
        }
        $this->todo()->sync();

        $this->assertFalse($this->taskFor('withdrawal', $w)->isDone());
    }

    public function test_a_customer_or_signed_out_visitor_gets_no_bell(): void
    {
        $this->actingAsWordPressUser();
        Livewire::withCookies($this->unencryptedCookies);

        Livewire::test(AdminBell::class)->assertDontSee('Team to-do');
        $this->get('/admin/team-to-do')->assertStatus(403);
    }
}
