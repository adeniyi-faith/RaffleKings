<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 42 — customers see a branded, helpful error
 * page instead of Laravel's bare default, while developers (debug mode)
 * and API callers keep the normal response.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'monitoring.telegram_error_alerts' => false]);
    }

    public function test_a_missing_page_shows_the_branded_404(): void
    {
        $this->get('/no-such-page-here')
            ->assertStatus(404)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_a_server_error_shows_the_branded_500_without_technical_details(): void
    {
        Route::get('/boom-page', fn () => throw new RuntimeException('SQLSTATE secret internals'));

        $response = $this->get('/boom-page')->assertStatus(500);

        $response->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_api_errors_stay_json(): void
    {
        $this->getJson('/api/no-such-endpoint')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_debug_mode_keeps_the_developer_error_screen(): void
    {
        config(['app.debug' => true]);
        Route::get('/boom-debug', fn () => throw new RuntimeException('visible to developers'));

        $response = $this->get('/boom-debug')->assertStatus(500);

        $this->assertStringNotContainsString('"component":"Error"', $response->getContent());
    }
}
