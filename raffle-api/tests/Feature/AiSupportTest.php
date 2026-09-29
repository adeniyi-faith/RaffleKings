<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class AiSupportTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function geminiSays(array $json): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]],
        ])]);
    }

    private function switchOn(): void
    {
        config([
            'ai.enabled' => true, 'ai.auto_reply' => true, 'ai.daily_limit' => 50, 'ai.max_auto_replies' => 3,
            'services.gemini.api_key' => 'test-key', 'services.gemini.assistant_model' => 'gemini-3-flash-preview',
        ]);
    }

    public function test_the_ai_answers_from_the_knowledge_base_and_labels_it_automated(): void
    {
        Notification::fake();
        $this->switchOn();
        KnowledgeArticle::create(['title' => 'Withdrawal times', 'body' => 'Withdrawals arrive within 24 hours.']);
        $this->geminiSays(['answerable' => true, 'reply' => 'Withdrawals arrive within 24 hours.', 'reason' => 'in KB']);

        $user = $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Withdrawal', 'message' => 'How long do withdrawals take?'])->assertCreated();

        $ticket = SupportTicket::query()->with('messages')->firstOrFail();
        $auto = $ticket->messages->firstWhere('is_automated', true);

        $this->assertNotNull($auto);
        $this->assertTrue($auto->is_from_admin);
        $this->assertSame('pending', $ticket->status);

        Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', 'test-key') && str_contains($r->url(), 'gemini-3-flash-preview') && ! str_contains($r->url(), 'test-key'));
        $this->assertSame($user->ID, $ticket->user_id);
    }

    public function test_it_stays_quiet_when_it_is_not_sure(): void
    {
        Notification::fake();
        $this->switchOn();
        $this->geminiSays(['answerable' => false, 'reply' => '', 'reason' => 'not covered']);

        $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Odd', 'message' => 'Something strange.'])->assertCreated();

        $ticket = SupportTicket::query()->with('messages')->firstOrFail();
        $this->assertSame('open', $ticket->status);
        $this->assertCount(1, $ticket->messages);
    }

    public function test_it_does_nothing_when_auto_reply_is_off(): void
    {
        Notification::fake();
        $this->switchOn();
        config(['ai.auto_reply' => false]);
        Http::fake();

        $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Hi', 'message' => 'Hello'])->assertCreated();

        Http::assertNothingSent();
    }

    public function test_the_customer_can_ask_for_a_person_and_the_ai_stops(): void
    {
        Notification::fake();
        $this->switchOn();
        $this->geminiSays(['answerable' => true, 'reply' => 'Try this.', 'reason' => '']);

        $user = $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Help', 'message' => 'Help me'])->assertCreated();
        $ticket = SupportTicket::firstOrFail();

        $this->postJson("/api/support/tickets/{$ticket->id}/human")->assertOk()->assertJson(['needs_human' => true, 'status' => 'open']);

        Http::fake();
        app(SupportTicketService::class)->reply($ticket->refresh(), $user, 'Still stuck', isFromAdmin: false);
        Http::assertNothingSent();
    }

    public function test_one_customer_cannot_ask_for_a_person_on_anothers_ticket(): void
    {
        $this->actingAsWordPressUser();
        $other = \App\Models\Legacy\WpUser::create(['user_login' => 'o'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $ticket = SupportTicket::create(['user_id' => $other->ID, 'subject' => 'x', 'status' => 'open']);

        $this->postJson("/api/support/tickets/{$ticket->id}/human")->assertNotFound();
    }

    public function test_the_daily_limit_stops_the_ai(): void
    {
        Notification::fake();
        $this->switchOn();
        config(['ai.daily_limit' => 0]);
        Http::fake();

        $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Hi', 'message' => 'Hello'])->assertCreated();

        Http::assertNothingSent();
        $this->assertSame(1, SupportTicket::firstOrFail()->messages()->count());
    }
}
