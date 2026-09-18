<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Public, static content, same as the legacy privacy-policy.php. */
class PrivacyPolicyRouteTest extends TestCase
{
    public function test_the_privacy_policy_is_public(): void
    {
        $response = $this->get('/privacy-policy');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('PrivacyPolicy'));
    }
}
