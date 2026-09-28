<?php

namespace Tests\Feature;

use App\Services\Monitoring\ErrorAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 42 — server errors reach admins on Telegram,
 * without flooding them and without ever making an error worse.
 */
class ErrorAlerterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monitoring.telegram_error_alerts' => true,
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.admin_chat_ids' => ['111', '222'],
        ]);
    }

    private function telegramCalls(): int
    {
        return count(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.telegram.org')));
    }

    public function test_an_unhandled_server_error_alerts_every_admin_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Route::get('/boom-test', fn () => throw new RuntimeException('Database fell over'));

        $this->get('/boom-test?reset_code=123456')->assertStatus(500);

        $this->assertSame(2, $this->telegramCalls());
        Http::assertSent(function (HttpRequest $r) {
            return str_contains($r['text'], 'Database fell over')
                && str_contains($r['text'], 'GET boom-test')
                // Only the path is sent, never the query string, which can
                // carry private values like reset codes or tokens.
                && ! str_contains($r['text'], '123456');
        });
    }

    public function test_the_same_error_is_only_sent_once_per_window(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['services.telegram.admin_chat_ids' => ['111']]);
        $alerter = app(ErrorAlerter::class);
        $e = new RuntimeException('same thing again');

        $alerter->exception($e);
        $alerter->exception($e);
        $alerter->exception($e);

        $this->assertSame(1, $this->telegramCalls());
    }

    public function test_an_outage_cannot_flood_the_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['services.telegram.admin_chat_ids' => ['111'], 'monitoring.max_per_hour' => 3]);
        $alerter = app(ErrorAlerter::class);

        foreach (range(1, 10) as $i) {
            $alerter->problem("different problem {$i}");
        }

        $this->assertSame(3, $this->telegramCalls());
    }

    public function test_nothing_is_sent_when_telegram_is_not_set_up(): void
    {
        Http::fake();
        config(['services.telegram.bot_token' => null]);

        app(ErrorAlerter::class)->problem('anything');

        Http::assertNothingSent();
    }

    public function test_a_failing_telegram_call_never_throws(): void
    {
        Http::fake(fn () => throw new RuntimeException('Telegram is unreachable'));

        app(ErrorAlerter::class)->problem('this must not blow up');

        $this->assertTrue(true); // reaching here is the assertion
    }

    public function test_404s_are_not_reported(): void
    {
        Http::fake();

        $this->get('/this-page-does-not-exist')->assertStatus(404);

        Http::assertNothingSent();
    }
}
