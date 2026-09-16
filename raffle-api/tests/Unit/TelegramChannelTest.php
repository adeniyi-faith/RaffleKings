<?php

namespace Tests\Unit;

use App\Notifications\DrawCompletedAdminAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.admin_chat_ids' => ['111', '222']]);
    }

    public function test_it_sends_to_every_configured_admin_chat_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        (new AnonymousNotifiable)->notifyNow(new DrawCompletedAdminAlert(5, 3));

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['chat_id'] === '111' && str_contains($r['text'], 'raffle #5'));
        Http::assertSent(fn ($r) => $r['chat_id'] === '222');
    }

    public function test_it_sends_nothing_when_not_configured(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        (new AnonymousNotifiable)->notifyNow(new DrawCompletedAdminAlert(5, 3));

        Http::assertNothingSent();
    }

    public function test_a_failed_send_throws(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('bad request', 400)]);

        $this->expectException(RuntimeException::class);
        (new AnonymousNotifiable)->notifyNow(new DrawCompletedAdminAlert(5, 3));
    }
}
