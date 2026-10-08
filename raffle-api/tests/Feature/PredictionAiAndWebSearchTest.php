<?php

namespace Tests\Feature;

use App\Filament\Resources\PredictionResource\Pages\ListPredictions;
use App\Filament\Resources\RaffleResource\Pages\CreateRaffle;
use App\Models\AdvisorReport;
use App\Models\Prediction;
use App\Services\Advisor\RaffleAdvisor;
use App\Services\Ai\ExaSearch;
use App\Services\Engagement\Predictions;
use App\Services\Engagement\PredictionWriter;
use App\Settings\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** AI-written daily predictions, and Exa web search for the admin's AI helpers. */
class PredictionAiAndWebSearchTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.daily_limit' => 50, 'ai.web_search_daily_limit' => 20, 'services.gemini.api_key' => 'g-key', 'services.exa.api_key' => 'exa-key']);
    }

    private function fakeExa(): array
    {
        return ['api.exa.ai/*' => Http::response(['results' => [
            ['title' => 'Arsenal v Chelsea preview', 'url' => 'https://news.example/arsenal-chelsea', 'publishedDate' => '2026-10-07T10:00:00Z', 'text' => 'Arsenal host Chelsea on Saturday 10 October, kick-off 17:30 WAT.'],
            ['title' => 'Not a page', 'url' => 'javascript:alert(1)', 'text' => 'x'],
        ]])];
    }

    private function gemini(array $json): array
    {
        return ['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]])];
    }

    public function test_exa_search_sends_the_key_in_a_header_and_drops_unsafe_links(): void
    {
        Http::fake($this->fakeExa());

        $results = app(ExaSearch::class)->search('test', 'Arsenal fixtures', 5, ['days' => 7, 'news' => true]);

        $this->assertCount(1, $results);
        $this->assertSame('https://news.example/arsenal-chelsea', $results[0]['url']);
        $this->assertSame('2026-10-07', $results[0]['published']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.exa.ai/search'
            && $r->header('x-api-key')[0] === 'exa-key'
            && ! str_contains($r->url(), 'exa-key')
            && $r['category'] === 'news'
            && isset($r['startPublishedDate']));
        $this->assertDatabaseHas('ai_requests', ['model' => 'exa', 'purpose' => 'web:test', 'succeeded' => true]);
    }

    public function test_web_search_is_hidden_without_a_key_and_has_its_own_daily_cap(): void
    {
        config(['services.exa.api_key' => null]);
        $this->assertFalse(app(ExaSearch::class)->available());

        config(['services.exa.api_key' => 'exa-key', 'ai.web_search_daily_limit' => 1]);
        Http::fake($this->fakeExa());
        app(ExaSearch::class)->search('test', 'one', 1);

        $this->expectExceptionMessage("Today's web search limit (1) has been reached");
        app(ExaSearch::class)->search('test', 'two', 1);
    }

    public function test_ai_questions_are_saved_as_drafts_customers_cannot_see(): void
    {
        $kickoff = now()->addDays(2)->setTimezone('Africa/Lagos')->setTime(17, 30);

        Http::fake($this->fakeExa() + $this->gemini(['questions' => [
            ['category' => 'football', 'question' => 'Arsenal v Chelsea: who wins?', 'options' => ['Arsenal win', 'Draw', 'Chelsea win'], 'closes_at' => $kickoff->toIso8601String(), 'source' => 1, 'answer_index' => -1, 'note' => 'Check kick-off time.'],
            ['category' => 'quiz', 'question' => 'What colour is the Nigerian flag?', 'options' => ['Green and white', 'Red and blue'], 'closes_at' => 'not a date', 'source' => 0, 'answer_index' => 0, 'note' => ''],
            ['category' => 'quiz', 'question' => 'Only one answer', 'options' => ['Yes'], 'closes_at' => '', 'source' => 0, 'answer_index' => 0, 'note' => ''],
        ]]));

        $drafts = app(PredictionWriter::class)->draft(PredictionWriter::ANY, 3);

        $this->assertCount(2, $drafts);
        [$match, $quiz] = $drafts;
        $this->assertTrue($match->is_draft);
        $this->assertSame('https://news.example/arsenal-chelsea', $match->source_url);
        $this->assertTrue($match->closes_at->equalTo($kickoff));
        $this->assertNull($match->suggested_option);
        $this->assertSame(0, $quiz->suggested_option);
        $this->assertStringContainsString('Check the closing time', $quiz->ai_note);

        // Drafts are hidden from customers and can't be answered.
        $this->assertSame([], app(Predictions::class)->board(null)['open']);
        $this->assertFalse($match->isOpen());

        $match->update(['is_draft' => false]);
        $this->assertCount(1, app(Predictions::class)->board(null)['open']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'generateContent') && str_contains($r['contents'][0]['parts'][0]['text'], 'Arsenal host Chelsea'));
    }

    public function test_checking_a_result_suggests_an_answer_but_pays_nothing(): void
    {
        $prediction = Prediction::create(['category' => 'football', 'question' => 'Arsenal v Chelsea: who wins?', 'options' => ['Arsenal win', 'Draw', 'Chelsea win'], 'points' => 50, 'closes_at' => now()->subHour()]);
        Http::fake($this->fakeExa() + $this->gemini(['answer_index' => 2, 'source' => 1, 'note' => 'Chelsea won 2-1.']));

        $found = app(PredictionWriter::class)->checkResult($prediction);

        $this->assertSame(2, $found['option']);
        $prediction->refresh();
        $this->assertSame(2, $prediction->suggested_option);
        $this->assertNull($prediction->settled_at);
        $this->assertSame('https://news.example/arsenal-chelsea', $prediction->source_url);
    }

    public function test_staff_write_questions_with_ai_and_publish_them(): void
    {
        $this->actingAsAdministrator();
        Http::fake($this->fakeExa() + $this->gemini(['questions' => [
            ['category' => 'quiz', 'question' => 'Capital of Nigeria?', 'options' => ['Abuja', 'Lagos'], 'closes_at' => now()->addDay()->toIso8601String(), 'source' => 0, 'answer_index' => 0, 'note' => ''],
        ]]));

        Livewire::test(ListPredictions::class)
            ->callAction('aiDraft', ['category' => 'quiz', 'count' => 1, 'focus' => '', 'use_web' => false])
            ->assertNotified('1 draft question(s) written');

        $draft = Prediction::query()->sole();
        $this->assertTrue($draft->is_draft);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'exa.ai'));

        Livewire::test(ListPredictions::class)->callTableAction('publish', $draft);
        $this->assertFalse($draft->fresh()->is_draft);
    }

    public function test_the_morning_job_only_runs_when_switched_on(): void
    {
        Http::fake($this->fakeExa() + $this->gemini(['questions' => []]));

        config(['engagement.predictions.ai_daily' => false]);
        $this->assertSame(0, app(PredictionWriter::class)->dailyDrafts());
        Http::assertNothingSent();
    }

    public function test_the_settings_check_button_tests_the_exa_key(): void
    {
        Http::fake(['api.exa.ai/*' => Http::sequence()->push(['results' => []])->push([], 401)]);
        $this->assertTrue(app(ConnectionTester::class)->exa('exa-key')[0]);

        $this->assertSame([false, 'Exa rejected the key. Check it was copied in full, with no spaces.'], app(ConnectionTester::class)->exa('bad'));
        $this->assertFalse(app(ConnectionTester::class)->exa(null)[0]);
    }

    public function test_the_raffle_advisor_reads_fresh_web_news_when_exa_is_set_up(): void
    {
        Http::fake($this->fakeExa() + $this->gemini(['summary' => 'ok', 'recommendations' => []]));

        app(RaffleAdvisor::class)->write(AdvisorReport::create(['status' => 'pending']));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'generateContent')
            && str_contains($r['contents'][0]['parts'][0]['text'], 'Fresh news from the web')
            && str_contains($r['contents'][0]['parts'][0]['text'], 'Arsenal host Chelsea'));
    }

    public function test_write_with_ai_can_look_things_up_on_the_web_first(): void
    {
        $this->actingAsAdministrator();
        Http::fake($this->fakeExa() + ['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Win an iPhone this Friday']]]]]])]);

        Livewire::test(CreateRaffle::class)
            ->callFormComponentAction('title', 'aiWrite', ['instruction' => 'Mention the newest iPhone', 'use_web' => true, 'search' => 'newest iPhone'])
            ->assertFormSet(['title' => 'Win an iPhone this Friday']);

        Http::assertSent(fn ($r) => $r->url() === 'https://api.exa.ai/search' && $r['query'] === 'newest iPhone');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generateContent') && str_contains($r['contents'][0]['parts'][0]['text'], 'Fresh facts from a web search'));
    }
}
