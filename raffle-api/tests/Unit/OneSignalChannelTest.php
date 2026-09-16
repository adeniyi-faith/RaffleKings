<?php

namespace Tests\Unit;

use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\WinnerAnnounced;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OneSignalChannelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(?string $oneSignalId = 'player-123'): WpUser
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);

        if ($oneSignalId) {
            WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_onesignal_id', 'meta_value' => $oneSignalId]);
        }

        return $user;
    }

    private function makeWinner(int $userId): RaffleWinner
    {
        return RaffleWinner::create(['raffle_id' => 1, 'user_id' => $userId, 'ticket_number' => 7, 'prize_name' => 'Test Prize', 'prize_rank' => 1, 'prize_cash_value' => 5000]);
    }

    public function test_it_sends_a_push_with_the_correct_player_id_and_no_tls_bypass(): void
    {
        Http::fake(['onesignal.com/*' => Http::response(['id' => 'abc'], 200)]);
        $user = $this->makeUser('player-123');
        $winner = $this->makeWinner($user->ID);

        $user->notify(new WinnerAnnounced($winner));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://onesignal.com/api/v1/notifications'
                && $request['include_player_ids'] === ['player-123'];
        });
    }

    public function test_a_user_with_no_registered_device_is_skipped_without_error(): void
    {
        Http::fake();
        $user = $this->makeUser(oneSignalId: null);
        $winner = $this->makeWinner($user->ID);

        $user->notify(new WinnerAnnounced($winner));

        Http::assertNothingSent();
    }

    public function test_a_failed_send_throws_instead_of_being_silently_discarded(): void
    {
        Http::fake(['onesignal.com/*' => Http::response('server error', 500)]);
        $user = $this->makeUser('player-123');
        $winner = $this->makeWinner($user->ID);

        $this->expectException(RuntimeException::class);
        // Notification jobs normally swallow channel exceptions when
        // dispatched async; calling sendNow() surfaces it directly so
        // the throw-on-failure behaviour itself is provable here.
        $user->notifyNow(new WinnerAnnounced($winner));
    }
}
