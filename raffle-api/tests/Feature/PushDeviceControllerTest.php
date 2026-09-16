<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class PushDeviceControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_it_saves_the_onesignal_player_id_for_the_current_user(): void
    {
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/push/device', ['player_id' => 'abc-123'])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(
            'abc-123',
            WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', 'rk_onesignal_id')->value('meta_value'),
        );
    }

    public function test_saving_again_updates_rather_than_duplicates_the_record(): void
    {
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/push/device', ['player_id' => 'first'])->assertOk();
        $this->postJson('/api/push/device', ['player_id' => 'second'])->assertOk();

        $this->assertSame(
            1,
            WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', 'rk_onesignal_id')->count(),
        );
        $this->assertSame(
            'second',
            WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', 'rk_onesignal_id')->value('meta_value'),
        );
    }

    public function test_a_missing_player_id_is_rejected(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/push/device', [])->assertStatus(422);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/push/device', ['player_id' => 'abc'])->assertUnauthorized();
    }
}
