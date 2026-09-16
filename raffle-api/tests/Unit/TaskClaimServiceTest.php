<?php

namespace Tests\Unit;

use App\Exceptions\TaskAlreadyCompletedException;
use App\Exceptions\UnknownTaskException;
use App\Models\Legacy\WpUser;
use App\Services\PointsService;
use App\Services\TaskClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskClaimService $service;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TaskClaimService::class);
        $this->points = app(PointsService::class);
    }

    private function makeUser(): WpUser
    {
        return WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_completing_a_task_pays_the_correct_reward(): void
    {
        $user = $this->makeUser();

        $result = $this->service->claim($user, 'push_notification');

        $this->assertSame(1500, $result['points_added']);
        $this->assertSame(1500, $this->points->balance($user));
    }

    public function test_an_unknown_task_is_refused(): void
    {
        $user = $this->makeUser();

        $this->expectException(UnknownTaskException::class);
        $this->service->claim($user, 'not_a_real_task');
    }

    public function test_a_one_time_task_cannot_be_claimed_twice(): void
    {
        $user = $this->makeUser();
        $this->service->claim($user, 'join_community');

        $this->expectException(TaskAlreadyCompletedException::class);
        $this->service->claim($user, 'join_community');
    }

    public function test_whatsapp_share_can_be_claimed_once_per_day_but_not_twice_the_same_day(): void
    {
        $user = $this->makeUser();
        $this->service->claim($user, 'whatsapp_share');

        $this->expectException(TaskAlreadyCompletedException::class);
        $this->service->claim($user, 'whatsapp_share');
    }
}
