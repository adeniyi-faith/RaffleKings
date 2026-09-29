<?php

namespace Tests\Feature\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\Prediction;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\Predictions;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class PredictionsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function question(array $overrides = []): Prediction
    {
        return Prediction::create($overrides + [
            'category' => 'football',
            'question' => 'Who wins?',
            'options' => ['Home', 'Draw', 'Away'],
            'points' => 40,
            'closes_at' => now()->addHour(),
        ]);
    }

    public function test_answering_is_free_once_and_shows_the_crowd_afterwards(): void
    {
        $q = $this->question();
        $this->actingAsWordPressUser();

        $this->getJson('/api/predictions')->assertOk()->assertJsonPath('open.0.crowd', null);
        $this->postJson("/api/predictions/{$q->id}/answer", ['option' => 2])->assertOk()
            ->assertJsonPath('your_answer', 2)
            ->assertJsonPath('crowd', [0, 0, 100]);
        $this->postJson("/api/predictions/{$q->id}/answer", ['option' => 1])->assertStatus(422);
        $this->postJson("/api/predictions/{$this->question(['closes_at' => now()->subMinute()])->id}/answer", ['option' => 0])->assertStatus(422);
    }

    public function test_settling_pays_right_answers_and_five_right_earns_a_badge(): void
    {
        $service = app(Predictions::class);
        $right = WpUser::create(['user_login' => 'seer', 'user_pass' => 'x', 'user_email' => 'seer@example.com']);
        $wrong = WpUser::create(['user_login' => 'miss', 'user_pass' => 'x', 'user_email' => 'miss@example.com']);

        foreach (range(1, 5) as $i) {
            $q = $this->question(['question' => "Q{$i}"]);
            $service->answer($right, $q, 0);
            $service->answer($wrong, $q, 1);
            $q->update(['closes_at' => now()->subMinute()]);
            $this->assertSame(1, $service->settle($q->fresh(), 0));
        }

        $this->assertSame(200, app(PointsService::class)->balance($right));
        $this->assertSame(0, app(PointsService::class)->balance($wrong));
        $this->assertTrue(app(BadgeService::class)->has($right->ID, 'predictor'));
        $this->expectException(\InvalidArgumentException::class);
        $service->settle($q->fresh(), 1);
    }

    public function test_the_pages_open(): void
    {
        $this->question();
        $this->get('/rewards/predict')->assertOk();
        $this->actingAsAdministrator();
        $this->get('/admin/predictions')->assertOk();
        $this->get('/admin/predictions/create')->assertOk();
    }
}
