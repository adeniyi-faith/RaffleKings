<?php

namespace Tests\Unit;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralClick;
use App\Services\ReferralTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReferralTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReferralTrackingService::class);
    }

    public function test_it_resolves_a_referrer_by_username(): void
    {
        $referrer = WpUser::create(['user_login' => 'jane', 'user_pass' => 'x', 'user_email' => 'jane@example.com']);

        $resolved = $this->service->resolveReferrer('jane');

        $this->assertSame($referrer->ID, $resolved->ID);
    }

    public function test_it_resolves_a_referrer_by_rk_referral_code_meta(): void
    {
        $referrer = WpUser::create(['user_login' => 'original-login', 'user_pass' => 'x', 'user_email' => 'jane2@example.com']);
        WpUserMeta::create(['user_id' => $referrer->ID, 'meta_key' => 'rk_referral_code', 'meta_value' => 'jane-code']);

        $resolved = $this->service->resolveReferrer('jane-code');

        $this->assertSame($referrer->ID, $resolved->ID);
    }

    public function test_an_unknown_code_resolves_to_nothing(): void
    {
        $this->assertNull($this->service->resolveReferrer('nobody-uses-this-code'));
    }

    public function test_recording_the_same_visitor_twice_only_creates_one_click(): void
    {
        $referrer = WpUser::create(['user_login' => 'jane3', 'user_pass' => 'x', 'user_email' => 'jane3@example.com']);

        $this->service->recordClick($referrer, 'visitor-token-1');
        $this->service->recordClick($referrer, 'visitor-token-1');

        $this->assertSame(1, ReferralClick::where('referrer_user_id', $referrer->ID)->count());
    }
}
