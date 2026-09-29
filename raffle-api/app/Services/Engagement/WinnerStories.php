<?php

namespace App\Services\Engagement;

use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\WinnerStory;
use App\Models\WinnerStoryReaction;
use App\Notifications\EngagementAlert;
use App\Services\PointsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The winner stories wall (Phase 11): a winner posts a photo or video
 * with their prize for one of their published wins; staff approve it
 * before it shows; everyone can react. Real proof from real winners.
 */
class WinnerStories
{
    public const REACTIONS = ['❤️', '🔥', '👏', '🎉'];

    public function __construct(private readonly PointsService $points, private readonly BadgeService $badges) {}

    /** Published wins this customer hasn't posted a story for yet. */
    public function postableWins(int $userId): array
    {
        $posted = WinnerStory::query()->where('user_id', $userId)->pluck('raffle_winner_id');
        $wins = RaffleWinner::query()->where('user_id', $userId)->where('is_visible', true)->whereNotIn('id', $posted)->latest('id')->limit(20)->get();
        $titles = Raffle::query()->whereIn('public_id', $wins->pluck('raffle_id'))->pluck('title', 'public_id');

        return $wins->map(fn (RaffleWinner $w) => [
            'id' => $w->id,
            'label' => $w->prize_name.' · '.($titles[$w->raffle_id] ?? 'Raffle #'.$w->raffle_id),
        ])->values()->all();
    }

    public function post(WpUser $user, int $winnerId, ?string $caption, UploadedFile $file): WinnerStory
    {
        $win = RaffleWinner::query()->whereKey($winnerId)->where('user_id', $user->ID)->where('is_visible', true)->first();

        if (! $win) {
            throw new InvalidArgumentException('You can share a story for one of your own published wins.');
        }

        if (WinnerStory::query()->where('raffle_winner_id', $win->id)->exists()) {
            throw new InvalidArgumentException('You already shared a story for this win.');
        }

        $type = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
        $path = $file->storeAs('stories', Str::uuid().'.'.$file->extension(), 'public');

        return WinnerStory::create([
            'user_id' => $user->ID,
            'raffle_winner_id' => $win->id,
            'raffle_id' => $win->raffle_id,
            'caption' => $caption !== null ? mb_substr(trim(strip_tags($caption)), 0, 500) : null,
            'media_path' => $path,
            'media_type' => $type,
            'status' => 'pending',
        ]);
    }

    public function approve(WinnerStory $story): void
    {
        if ($story->status === 'approved') {
            return;
        }

        $story->update(['status' => 'approved', 'approved_at' => now(), 'review_note' => null]);
        $user = WpUser::find($story->user_id);

        if (! $user) {
            return;
        }

        $points = max(0, (int) config('engagement.stories.points', 100));

        if ($points > 0) {
            $this->points->credit($user, $points, 'winner_story', 'winner_story', $story->id, 'Thanks for sharing your winner story');
        }

        $this->badges->award($user->ID, 'storyteller');
        $user->notify(new EngagementAlert('Your winner story is live! 📸', ($points ? "+{$points} points for sharing. " : '').'Everyone can see it on the winners wall now.', '/winners/stories', 'See it'));
    }

    public function reject(WinnerStory $story, string $reason): void
    {
        $story->update(['status' => 'rejected', 'review_note' => mb_substr($reason, 0, 255)]);
        Storage::disk('public')->delete($story->media_path);

        WpUser::find($story->user_id)?->notify(new EngagementAlert(
            'About your winner story',
            'We couldn\'t publish it: '.$reason.' You can share another one.',
            '/winners/stories',
            'Try again',
        ));
    }

    /** Sets (or changes, or removes with the same emoji) a reaction. Returns the story's counts. */
    public function react(WpUser $user, WinnerStory $story, string $emoji): array
    {
        if (! in_array($emoji, self::REACTIONS, true) || $story->status !== 'approved') {
            throw new InvalidArgumentException('That reaction isn\'t available.');
        }

        $existing = WinnerStoryReaction::query()->where('winner_story_id', $story->id)->where('user_id', $user->ID)->first();

        if ($existing && $existing->emoji === $emoji) {
            $existing->delete();
        } elseif ($existing) {
            $existing->update(['emoji' => $emoji]);
        } else {
            WinnerStoryReaction::create(['winner_story_id' => $story->id, 'user_id' => $user->ID, 'emoji' => $emoji]);
        }

        return ['counts' => $this->counts($story->id), 'yours' => $existing && $existing->emoji === $emoji ? null : $emoji];
    }

    /** The public wall, newest first. */
    public function wall(?int $viewerId, int $page = 1): array
    {
        $stories = WinnerStory::query()->where('status', 'approved')->latest('approved_at')->forPage($page, 12)->get();
        $users = WpUser::query()->whereIn('ID', $stories->pluck('user_id'))->get()->keyBy('ID');
        $winners = RaffleWinner::query()->whereIn('id', $stories->pluck('raffle_winner_id'))->get()->keyBy('id');
        $titles = Raffle::query()->whereIn('public_id', $stories->pluck('raffle_id'))->pluck('title', 'public_id');
        $mine = $viewerId ? WinnerStoryReaction::query()->where('user_id', $viewerId)->whereIn('winner_story_id', $stories->pluck('id'))->pluck('emoji', 'winner_story_id') : collect();

        return [
            'stories' => $stories->map(function (WinnerStory $s) use ($users, $winners, $titles, $mine) {
                $u = $users->get($s->user_id);
                $name = trim((string) ($u?->user_login ?: $u?->display_name));

                return [
                    'id' => $s->id,
                    'name' => $name === '' ? 'A winner' : $name,
                    // Set only when that winner has a public profile card to open.
                    'profile' => $u && app(PlayerProfiles::class)->isPublic($u) ? '/player/'.rawurlencode($u->user_login) : null,
                    'avatar' => $u?->metaValue('profile_pic_url') ?: null,
                    'showcase' => $u ? $this->badges->showcase($u->ID) : [],
                    'prize' => $winners->get($s->raffle_winner_id)?->prize_name,
                    'raffle' => $titles[$s->raffle_id] ?? null,
                    'caption' => $s->caption,
                    'media_url' => $s->mediaUrl(),
                    'media_type' => $s->media_type,
                    'posted_ago' => $s->approved_at?->diffForHumans(),
                    'counts' => $this->counts($s->id),
                    'yours' => $mine[$s->id] ?? null,
                ];
            })->values()->all(),
            'reactions' => self::REACTIONS,
            'has_more' => WinnerStory::query()->where('status', 'approved')->count() > $page * 12,
        ];
    }

    private function counts(int $storyId): array
    {
        $counts = WinnerStoryReaction::query()->where('winner_story_id', $storyId)->selectRaw('emoji, count(*) as n')->groupBy('emoji')->pluck('n', 'emoji');

        return collect(self::REACTIONS)->mapWithKeys(fn ($e) => [$e => (int) ($counts[$e] ?? 0)])->all();
    }
}
