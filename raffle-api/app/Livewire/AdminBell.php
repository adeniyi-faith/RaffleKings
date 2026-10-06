<?php

namespace App\Livewire;

use App\Filament\Pages\TeamTodo;
use App\Models\Admin\StaffTask;
use App\Models\Legacy\WpUser;
use App\Services\Admin\StaffTodo;
use Livewire\Component;

/**
 * The bell in the admin's top bar, like the customer site's bell: a red
 * number for open team to-dos, and a drop-down to tick the first few off
 * without leaving the page. The full list is Team to-do (App\Filament\Pages\TeamTodo).
 */
class AdminBell extends Component
{
    public function tick(int $id): void
    {
        $user = $this->staff();
        $task = StaffTask::find($id);

        if ($user && $task && app(StaffTodo::class)->canTouch($user, $task)) {
            app(StaffTodo::class)->markDone($task, $user);
        }
    }

    public function undo(int $id): void
    {
        $user = $this->staff();
        $task = StaffTask::find($id);

        if ($user && $task && app(StaffTodo::class)->canTouch($user, $task)) {
            app(StaffTodo::class)->reopen($task);
        }
    }

    private function staff(): ?WpUser
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser && $user->staffRole() !== null ? $user : null;
    }

    public function render()
    {
        $todo = app(StaffTodo::class);
        $user = $this->staff();

        if (! $user) {
            return view('livewire.admin-bell', ['count' => 0, 'open' => collect(), 'done' => collect(), 'allUrl' => null, 'me' => null]);
        }

        try {
            $todo->syncIfDue();
            $open = $todo->open($user);
            $done = $todo->recentlyDone($user);
        } catch (\Throwable $e) {
            // The list must never break the admin (e.g. mid-deploy, before the table exists).
            report($e);
            $open = $done = collect();
        }

        return view('livewire.admin-bell', [
            'count' => $todo->openCount($user),
            'open' => $open,
            'done' => $done,
            'allUrl' => TeamTodo::getUrl(),
            'me' => $user->ID,
        ]);
    }
}
