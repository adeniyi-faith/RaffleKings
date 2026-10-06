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

    /** Gemini gives these answers, one per call, in order. */
    private function geminiSaysInTurn(array ...$answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers as $json) {
            $sequence->push(['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]]);
        }
        Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);
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

        // Staff can see why it stayed quiet.
        $this->assertSame('not covered', \App\Models\AiRequest::query()->where('purpose', 'support:held-back')->where('support_ticket_id', $ticket->id)->value('error'));
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

    public function test_the_ai_marks_the_ticket_solved_when_the_customer_says_it_helped(): void
    {
        Notification::fake();
        $this->switchOn();
        $this->geminiSaysInTurn(
            ['answerable' => true, 'solved' => false, 'reply' => 'Pick a raffle and press Buy.', 'reason' => 'in KB'],
            ['answerable' => true, 'solved' => true, 'reply' => 'Glad it helped! Reply here if you need anything else.', 'reason' => 'customer is happy'],
            ['answerable' => false, 'solved' => false, 'reply' => '', 'reason' => 'new question'],
        );

        $user = $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Buying', 'message' => 'How do I buy a ticket?'])->assertCreated();
        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('pending', $ticket->status);

        app(SupportTicketService::class)->reply($ticket->refresh(), $user, 'Thank you. It helps.', isFromAdmin: false);

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame('Glad it helped! Reply here if you need anything else.', $ticket->messages()->latest('id')->first()->message);

        // Writing again opens it again.
        app(SupportTicketService::class)->reply($ticket, $user, 'Actually, one more thing', isFromAdmin: false);
        $this->assertSame('open', $ticket->refresh()->status);
    }

    public function test_tickets_stay_open_when_auto_resolve_is_off(): void
    {
        Notification::fake();
        $this->switchOn();
        config(['ai.auto_resolve' => false]);
        $this->geminiSays(['answerable' => true, 'solved' => true, 'reply' => 'Glad it helped!', 'reason' => '']);

        $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Thanks', 'message' => 'Thanks, all sorted'])->assertCreated();

        $this->assertSame('pending', SupportTicket::firstOrFail()->status);
    }

    public function test_a_ticket_the_team_solves_becomes_a_suggested_knowledge_entry(): void
    {
        Notification::fake();
        $this->switchOn();
        config(['ai.auto_reply' => false, 'ai.learn_from_tickets' => true]);

        $user = $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Prizes', 'message' => 'How do I claim a gadget prize?'])->assertCreated();
        $ticket = SupportTicket::firstOrFail();
        app(SupportTicketService::class)->reply($ticket, $user, 'Send us your address and we deliver it within 7 days.', isFromAdmin: true);

        $this->geminiSays(['useful' => true, 'title' => 'How do I claim a gadget prize?', 'body' => 'Reply to the winner message with your delivery address. Gadgets are delivered within 7 days.']);
        app(SupportTicketService::class)->setStatus($ticket->refresh(), 'resolved');

        $entry = KnowledgeArticle::query()->firstOrFail();
        $this->assertFalse($entry->is_active);
        $this->assertSame($ticket->id, $entry->suggested_from_ticket_id);
        $this->assertSame('How do I claim a gadget prize?', $entry->title);

        // Resolving again (after a reopen) doesn't add a second copy.
        $ticket->update(['status' => 'open']);
        app(SupportTicketService::class)->setStatus($ticket, 'closed');
        $this->assertSame(1, KnowledgeArticle::count());
    }

    public function test_nothing_is_learned_from_tickets_the_ai_solved_alone_or_when_nothing_is_new(): void
    {
        Notification::fake();
        $this->switchOn();
        config(['ai.auto_reply' => false]);
        $this->geminiSays(['useful' => false, 'title' => '', 'body' => '']);

        $user = $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Hi', 'message' => 'Hello'])->assertCreated();
        $ticket = SupportTicket::firstOrFail();
        app(SupportTicketService::class)->replyAutomated($ticket, 'Hello! How can I help?');
        app(SupportTicketService::class)->setStatus($ticket->refresh(), 'resolved');
        Http::assertNothingSent();

        $other = SupportTicket::create(['user_id' => $user->ID, 'subject' => 'x', 'status' => 'open']);
        app(SupportTicketService::class)->reply($other, $user, 'Done, check again.', isFromAdmin: true);
        app(SupportTicketService::class)->setStatus($other->refresh(), 'resolved');

        Http::assertSentCount(1);
        $this->assertSame(0, KnowledgeArticle::count());
    }

    public function test_a_short_gemini_hiccup_does_not_leave_the_customer_unanswered(): void
    {
        Notification::fake();
        $this->switchOn();
        KnowledgeArticle::create(['title' => 'Withdrawal times', 'body' => 'Withdrawals arrive within 24 hours.']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push('busy', 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['answerable' => true, 'reply' => 'Withdrawals arrive within 24 hours.', 'reason' => 'in KB'])]]]]]])]);

        $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Withdrawal', 'message' => 'How long do withdrawals take?'])->assertCreated();

        $ticket = SupportTicket::query()->with('messages')->firstOrFail();
        $this->assertNotNull($ticket->messages->firstWhere('is_automated', true));
        Http::assertSentCount(2);
    }

    public function test_it_switches_to_the_backup_model_when_the_first_keeps_failing(): void
    {
        $this->switchOn();
        Http::fake([
            'generativelanguage.googleapis.com/*gemini-3.5-flash-lite*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]),
            'generativelanguage.googleapis.com/*' => Http::response('busy', 503),
        ]);

        $text = app(\App\Services\Ai\GeminiClient::class)->generate('test', 'sys', 'hi', json: true);

        $this->assertSame('{"ok":true}', $text);
        Http::assertSentCount(3);
    }

    public function test_an_answer_wrapped_in_extra_words_or_a_code_fence_is_still_read(): void
    {
        Notification::fake();
        $this->switchOn();
        KnowledgeArticle::create(['title' => 'Withdrawal times', 'body' => 'Withdrawals arrive within 24 hours.']);
        $json = json_encode(['answerable' => true, 'solved' => false, 'reply' => 'Withdrawals arrive within 24 hours.', 'reason' => 'in KB']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => "Here you go:\n```json\n{$json}\n```"]]]]],
        ])]);

        $this->actingAsWordPressUser();
        $this->postJson('/api/support/tickets', ['subject' => 'Withdrawal', 'message' => 'How long do withdrawals take?'])->assertCreated();

        $this->assertNotNull(SupportTicket::query()->with('messages')->firstOrFail()->messages->firstWhere('is_automated', true));
        Http::assertSent(fn ($r) => data_get($r->data(), 'generationConfig.maxOutputTokens') === 4096 && data_get($r->data(), 'generationConfig.responseJsonSchema.required') !== null);
    }
}
