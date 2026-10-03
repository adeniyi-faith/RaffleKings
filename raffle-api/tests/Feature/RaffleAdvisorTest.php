<?php

namespace Tests\Feature;

use App\Filament\Pages\RaffleAdvisor as AdvisorPage;
use App\Filament\Resources\RaffleResource;
use App\Jobs\WriteAdvisorReport;
use App\Models\AdvisorReport;
use App\Models\AiRequest;
use App\Models\DailyDrop;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\Advisor\PlatformSnapshot;
use App\Services\Advisor\RaffleAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class RaffleAdvisorTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.enabled' => true, 'ai.daily_limit' => 100, 'services.gemini.api_key' => 'test-key', 'services.gemini.assistant_model' => 'gemini-3-flash-preview']);
    }

    /** Answer every Gemini call with this answer instead of reaching the internet. */
    private function fakeGemini(array $answer, int $status = 200): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $status === 200 ? ['candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]]] : ['error' => ['message' => 'nope']],
            $status,
        )]);
    }

    private function answer(): array
    {
        return [
            'summary' => 'Players come back on Fridays.',
            'recommendations' => [
                [
                    'title' => 'Friday flash raffle', 'kind' => 'new_raffle', 'ready_today' => true,
                    'what' => 'Run a 3-hour flash raffle on Friday evening.', 'why' => 'Most tickets are bought on Friday evenings.',
                    'steps' => ['Open the draft', 'Publish on Friday at 6pm'], 'risks' => 'Small pot if it sells badly.',
                    'fairness' => 'Show the countdown honestly.', 'measure' => 'Sell-through within 3 hours.',
                    'raffle_draft' => [
                        'title' => 'Friday Flash: ₦50,000', 'excerpt' => 'Three hours only.', 'ticket_price' => 200, 'tickets_available' => 500,
                        'max_per_order' => 20, 'grand_prize' => '₦50,000 cash', 'prize_type' => 'cash', 'is_flash' => true, 'flash_hours' => 3, 'sales_days' => null,
                        'prize_tiers' => [
                            ['tier_name' => 'Grand prize', 'prize_description' => '₦50,000', 'cash_value' => 50000, 'winner_count' => 1],
                            ['tier_name' => 'Runner-up', 'prize_description' => '₦2,000', 'cash_value' => 2000, 'winner_count' => 10],
                        ],
                        'draw_rules' => ['max_wins_per_person' => 1, 'new_players_only' => false, 'loyalty_bonus_entries' => true, 'consolation_min_tickets' => 5, 'consolation_points' => 50],
                    ],
                    'daily_drop' => null,
                ],
                [
                    'title' => 'Start a daily drop', 'kind' => 'incentive', 'ready_today' => true,
                    'what' => 'Credit a random ticket holder daily.', 'why' => 'A reason to come back every day.',
                    'steps' => [], 'risks' => 'Needs building.', 'fairness' => 'Publish the rules.', 'measure' => 'Daily return visits.',
                    'raffle_draft' => null,
                    'daily_drop' => ['pot_percent' => 10, 'winners_per_day' => 3, 'daily_cap' => 20000, 'drop_time' => '20:00'],
                ],
            ],
        ];
    }

    private function player(string $email): WpUser
    {
        return WpUser::create(['user_login' => uniqid('p'), 'user_pass' => 'x', 'user_email' => $email, 'display_name' => 'Ada Lovelace', 'user_registered' => now()]);
    }

    public function test_the_snapshot_has_totals_but_no_personal_details(): void
    {
        $raffle = $this->createRaffle(['title' => 'iPhone 17', 'price' => 500, 'max' => 10]);
        $ada = $this->player('ada@example.com');
        $bola = $this->player('bola@example.com');
        foreach ([[$ada, 1, 7], [$ada, 2, 7], [$bola, 3, 8]] as [$who, $ticket, $txn]) {
            RaffleEntry::create(['user_id' => $who->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => $ticket, 'txn_id' => $txn]);
        }

        $snap = app(PlatformSnapshot::class)->build();

        $this->assertSame(3, $snap['overview']['tickets_sold']);
        $this->assertEquals(1500, $snap['overview']['ticket_sales']);
        $this->assertSame(2, $snap['overview']['different_players']);
        $this->assertEquals(1.5, $snap['overview']['average_tickets_per_order']);
        $this->assertSame(2, $snap['players']['new_players_in_period']);
        $this->assertSame(30.0, (float) $snap['recent_raffles'][0]['sell_through_percent']);

        $json = json_encode($snap);
        $this->assertStringNotContainsString('example.com', $json);
        $this->assertStringNotContainsString('Ada', $json);
        $this->assertStringNotContainsString('"user_id"', $json);
    }

    public function test_a_report_is_written_from_geminis_answer(): void
    {
        $this->fakeGemini($this->answer());
        $report = AdvisorReport::create(['status' => 'pending', 'focus' => 'Plan next week']);

        app(RaffleAdvisor::class)->write($report);

        $report->refresh();
        $this->assertSame('ready', $report->status);
        $this->assertSame('Players come back on Fridays.', $report->summary);
        $this->assertCount(2, $report->recommendations);
        $this->assertNull($report->recommendations[0]['opened_raffle_id']);
        $this->assertSame('gemini-3-flash-preview', $report->model);

        Http::assertSent(function (Request $request) {
            $config = $request['generationConfig'];

            return str_contains($request->url(), 'gemini-3-flash-preview:generateContent')
                && $request->header('x-goog-api-key') === ['test-key']
                && $config['responseMimeType'] === 'application/json'
                && isset($config['responseJsonSchema']['properties']['recommendations'])
                && $config['maxOutputTokens'] === 16000
                && str_contains($request['contents'][0]['parts'][0]['text'], 'Plan next week')
                && str_contains($request['systemInstruction']['parts'][0]['text'], 'Daily Drops');
        });
        $this->assertTrue(AiRequest::query()->where('purpose', 'raffle-advisor')->where('succeeded', true)->exists());
    }

    public function test_an_ai_error_marks_the_report_failed_with_a_plain_reason(): void
    {
        $this->fakeGemini([], 403);
        $report = AdvisorReport::create(['status' => 'pending']);

        app(RaffleAdvisor::class)->write($report);

        $this->assertSame('failed', $report->fresh()->status);
        $this->assertStringContainsString('rejected the key', $report->fresh()->error);
    }

    public function test_an_unreadable_answer_marks_the_report_failed(): void
    {
        $this->fakeGemini(['summary' => 'no list here']);
        $report = AdvisorReport::create(['status' => 'pending']);

        app(RaffleAdvisor::class)->write($report);

        $this->assertSame('failed', $report->fresh()->status);
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->fakeGemini($this->answer());
        $report = AdvisorReport::create(['status' => 'pending']);

        app(RaffleAdvisor::class)->write($report);

        $this->assertSame('failed', $report->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_suggestion_opens_as_a_draft_raffle_once(): void
    {
        $admin = $this->actingAsAdministrator();
        $report = AdvisorReport::create(['status' => 'ready', 'summary' => 'x', 'recommendations' => $this->answer()['recommendations']]);

        $raffle = app(RaffleAdvisor::class)->openAsDraft($report, 0, $admin)['raffle'];

        $this->assertSame('draft', $raffle->status);
        $this->assertSame('Friday Flash: ₦50,000', $raffle->title);
        $this->assertTrue($raffle->is_flash);
        $this->assertNotNull($raffle->sales_end_at);
        $this->assertSame(500, $raffle->max_tickets);
        $this->assertSame(['Grand prize', 'Runner-up'], $raffle->prizeTiers->pluck('tier_name')->all());
        $this->assertSame(10, $raffle->prizeTiers[1]->winner_count);
        $this->assertTrue($raffle->drawRules()->loyaltyBonusEntries);
        $this->assertSame($raffle->id, $report->fresh()->recommendations[0]['opened_raffle_id']);
        $this->assertSame(0, DailyDrop::query()->count());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'advisor.suggestion_opened', 'subject_id' => $report->id]);

        $this->expectException(RuntimeException::class);
        app(RaffleAdvisor::class)->openAsDraft($report, 0, $admin);
    }

    public function test_a_daily_drop_suggestion_opens_as_a_draft_drop_that_pays_nothing_yet(): void
    {
        $admin = $this->actingAsAdministrator();
        $report = AdvisorReport::create(['status' => 'ready', 'recommendations' => $this->answer()['recommendations']]);

        $drop = app(RaffleAdvisor::class)->openAsDraft($report, 1, $admin)['drop'];

        $this->assertSame('draft', $drop->status);
        $this->assertNull($drop->raffle_id);
        $this->assertSame(10.0, $drop->pot_percent);
        $this->assertSame(3, $drop->winners_per_day);
        $this->assertSame(20000.0, $drop->daily_cap);
        $this->assertSame('20:00', $drop->drop_time);
        $this->assertSame($drop->id, $report->fresh()->recommendations[1]['opened_drop_id']);
    }

    public function test_an_idea_with_nothing_ready_made_cannot_be_opened(): void
    {
        $admin = $this->actingAsAdministrator();
        $recs = $this->answer()['recommendations'];
        $recs[1]['daily_drop'] = null;
        $report = AdvisorReport::create(['status' => 'ready', 'recommendations' => $recs]);

        $this->expectExceptionMessage('nothing ready-made');
        app(RaffleAdvisor::class)->openAsDraft($report, 1, $admin);
    }

    public function test_the_next_report_learns_how_an_opened_raffle_sold(): void
    {
        $admin = $this->actingAsAdministrator();
        $report = AdvisorReport::create(['status' => 'ready', 'recommendations' => $this->answer()['recommendations']]);
        $raffle = app(RaffleAdvisor::class)->openAsDraft($report, 0, $admin)['raffle'];
        $raffle->update(['status' => 'published']);
        RaffleEntry::create(['user_id' => $admin->ID, 'raffle_id' => $raffle->public_id, 'ticket_number' => 1, 'txn_id' => 1]);

        $past = app(PlatformSnapshot::class)->build()['past_advice'][0]['suggestions'];

        $this->assertTrue($past[0]['acted_on']);
        $this->assertSame(1, $past[0]['result']['tickets_sold']);
        $this->assertFalse($past[1]['acted_on']);
    }

    public function test_staff_ask_for_advice_and_open_a_draft_from_the_page(): void
    {
        Queue::fake();
        $this->actingAsAdministrator();

        Livewire::test(AdvisorPage::class)
            ->assertSee('What the advisor sees')
            ->set('focus', 'Should we try a daily drop?')
            ->call('askAdvisor')
            ->assertSee('The advisor is studying the numbers');

        $report = AdvisorReport::query()->sole();
        $this->assertSame('Should we try a daily drop?', $report->focus);
        Queue::assertPushed(WriteAdvisorReport::class, fn ($job) => $job->reportId === $report->id);

        $report->update(['status' => 'ready', 'summary' => 'Players come back on Fridays.', 'recommendations' => $this->answer()['recommendations']]);

        Livewire::test(AdvisorPage::class)
            ->assertSee('Friday flash raffle')
            ->assertSee('Can run today')
            ->assertSee('Set up as a draft Daily Drop')
            ->call('openDraft', 0)
            ->assertRedirect(RaffleResource::getUrl('edit', ['record' => Raffle::query()->sole()]));
    }

    public function test_the_advisor_page_is_for_raffle_staff_only(): void
    {
        $this->actingAsWordPressUser();

        $this->assertFalse(AdvisorPage::canAccess());
    }
}
