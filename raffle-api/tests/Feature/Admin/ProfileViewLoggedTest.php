<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class ProfileViewLoggedTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_opening_a_customer_profile_is_logged_once_an_hour(): void
    {
        $this->actingAsAdministrator();
        $ada = WpUser::create(['user_login' => 'ada', 'user_pass' => 'x', 'user_email' => 'ada@example.com', 'display_name' => 'Ada']);

        Livewire::test(ViewWpUser::class, ['record' => $ada->ID]);
        Livewire::test(ViewWpUser::class, ['record' => $ada->ID]);

        $this->assertSame(1, AdminAuditLog::where('action', 'customer.viewed')->where('subject_id', $ada->ID)->count());
    }
}
