<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\ReferralCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class ReferralControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_an_authenticated_user_can_see_their_referral_stats(): void
    {
        $referrer = $this->actingAsWordPressUser();

        $referee = WpUser::create(['user_login' => 'ref1', 'user_pass' => 'x', 'user_email' => 'ref1@example.com']);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        app(ReferralCommissionService::class)->payCommissionForFirstDeposit($referee, 1000);

        $response = $this->getJson('/api/referrals/stats');

        $response->assertOk();
        $response->assertJson(['referral_count' => 1, 'paid_count' => 1, 'pending_count' => 0, 'total_earned' => 500]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/referrals/stats')->assertUnauthorized();
    }
}
