<?php

namespace Tests\Unit;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WpUserIsAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_the_administrator_capability_is_recognised_as_an_administrator(): void
    {
        $user = WpUser::create(['user_login' => 'admin', 'user_pass' => 'x', 'user_email' => 'admin@example.com']);
        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        $this->assertTrue($user->fresh()->isAdministrator());
    }

    public function test_a_user_with_a_different_role_is_not_an_administrator(): void
    {
        $user = WpUser::create(['user_login' => 'subscriber', 'user_pass' => 'x', 'user_email' => 'sub@example.com']);
        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['subscriber' => true]),
        ]);

        $this->assertFalse($user->fresh()->isAdministrator());
    }

    public function test_a_user_with_no_capabilities_meta_at_all_is_not_an_administrator(): void
    {
        $user = WpUser::create(['user_login' => 'ghost', 'user_pass' => 'x', 'user_email' => 'ghost@example.com']);

        $this->assertFalse($user->fresh()->isAdministrator());
    }

    public function test_a_corrupted_capabilities_value_is_treated_as_not_an_administrator(): void
    {
        $user = WpUser::create(['user_login' => 'corrupt', 'user_pass' => 'x', 'user_email' => 'corrupt@example.com']);
        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => 'not-actually-serialized-data',
        ]);

        $this->assertFalse($user->fresh()->isAdministrator());
    }
}
