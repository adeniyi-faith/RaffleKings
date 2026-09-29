<?php

namespace Tests\Feature;

use App\Models\SiteError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Monitoring (Phase 10, item 37): error codes and browser errors. */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_an_error_code(): void
    {
        $id = $this->get('/')->assertHeader('X-Request-Id')->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $id);

        // A sane id from a proxy in front of the site is kept; junk is replaced.
        $this->withHeader('X-Request-Id', 'proxy-abc-12345')->get('/')->assertHeader('X-Request-Id', 'proxy-abc-12345');
        $this->assertNotSame('<script>', $this->withHeader('X-Request-Id', '<script>')->get('/')->headers->get('X-Request-Id'));
    }

    public function test_a_browser_error_is_saved_for_system_health_with_its_error_code(): void
    {
        config(['monitoring.browser_error_alerts' => false]);

        $response = $this->postJson('/api/client-errors', [
            'message' => "TypeError: Cannot read properties of undefined (reading 'map')",
            'source' => 'http://localhost/build/assets/Checkout-abc.js:1:200',
            'page' => '/checkout',
        ])->assertNoContent();

        $error = SiteError::query()->where('title', 'Browser error')->sole();
        $this->assertStringContainsString("reading 'map'", $error->message);
        $this->assertStringContainsString('page /checkout', $error->details);
        $this->assertStringContainsString('error code '.$response->headers->get('X-Request-Id'), $error->details);

        // The same error again is counted, not duplicated.
        $this->postJson('/api/client-errors', [
            'message' => "TypeError: Cannot read properties of undefined (reading 'map')",
            'source' => 'http://localhost/build/assets/Checkout-abc.js:1:200',
        ])->assertNoContent();
        $this->assertSame(2, SiteError::query()->where('title', 'Browser error')->sole()->occurrences);
    }

    public function test_browser_error_reports_are_checked_and_rate_limited(): void
    {
        $this->postJson('/api/client-errors', [])->assertStatus(422);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/client-errors', ['message' => "Error {$i}"]);
        }

        $this->postJson('/api/client-errors', ['message' => 'One too many'])->assertStatus(429);
    }

    public function test_the_error_page_shows_the_error_code_for_server_errors(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/boom-test', fn () => throw new \RuntimeException('boom'))->middleware('web');

        $response = $this->get('/boom-test')->assertStatus(500);

        $response->assertInertia(fn ($page) => $page->component('Error')->where('reference', $response->headers->get('X-Request-Id')));
    }
}
