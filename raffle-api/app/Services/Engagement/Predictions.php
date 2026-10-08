<?php

namespace App\Services\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\Prediction;
use App\Models\PredictionAnswer;
use App\Notifications\EngagementAlert;
use App\Services\PointsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Daily predictions (Phase 11): staff post questions (football, quiz,
 * raffle trivia); customers answer free before the question closes;
 * staff settle it with the right answer and every right answer earns
 * points and Season Pass XP. Five right answers earns a badge.
 */
class Predictions
{
    public function __construct(
        private readonly PointsService $points,
        private readonly SeasonPass $season,
        private readonly BadgeService $badges,
    ) {}

    /** Open questions plus the customer's recent results, for the predictions page. */
    public function board(?int $userId): array
    {
        $open = Prediction::query()
            ->where('is_draft', false)
            ->whereNull('settled_at')
            ->where('closes_at', '>', now())
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->orderBy('closes_at')
            ->limit(10)
            ->get();

        $mine = $userId
            ? PredictionAnswer::query()->where('user_id', $userId)->whereIn('prediction_id', $open->pluck('id'))->pluck('option', 'prediction_id')
            : collect();

        $history = $userId
            ? PredictionAnswer::query()->with('prediction')->where('user_id', $userId)->whereNotNull('is_correct')->latest('id')->limit(10)->get()
            : collect();

        return [
            'open' => $open->map(fn (Prediction $p) => $this->present($p, $mine[$p->id] ?? null))->values()->all(),
            'history' => $history->map(fn (PredictionAnswer $a) => [
                'question' => $a->prediction?->question,
                'your_answer' => $a->prediction?->options[$a->option] ?? null,
                'right_answer' => $a->prediction?->options[$a->prediction->correct_option ?? -1] ?? null,
                'is_correct' => $a->is_correct,
                'points' => $a->points_awarded,
            ])->values()->all(),
            'correct_total' => $userId ? PredictionAnswer::query()->where('user_id', $userId)->where('is_correct', true)->count() : 0,
        ];
    }

    public function answer(WpUser $user, Prediction $prediction, int $option): array
    {
        if (! $prediction->isOpen()) {
            throw new InvalidArgumentException('This question has closed.');
        }

        if (! array_key_exists($option, $prediction->options)) {
            throw new InvalidArgumentException('Pick one of the answers.');
        }

        try {
            PredictionAnswer::create(['prediction_id' => $prediction->id, 'user_id' => $user->ID, 'option' => $option]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('You already answered this one.');
        }

        $this->season->addXp($user->ID, (int) config('engagement.season.xp.prediction', 5));

        return $this->present($prediction, $option);
    }

    /** Marks the right answer and pays everyone who got it. Returns how many were right. */
    public function settle(Prediction $prediction, int $correct): int
    {
        if ($prediction->settled_at) {
            throw new InvalidArgumentException('This question was already settled.');
        }

        if (! array_key_exists($correct, $prediction->options)) {
            throw new InvalidArgumentException('Pick one of the answers.');
        }

        DB::transaction(function () use ($prediction, $correct) {
            $prediction->update(['correct_option' => $correct, 'settled_at' => now()]);
            PredictionAnswer::query()->where('prediction_id', $prediction->id)->update(['is_correct' => false]);
            PredictionAnswer::query()->where('prediction_id', $prediction->id)->where('option', $correct)->update(['is_correct' => true, 'points_awarded' => $prediction->points]);
        });

        $winners = PredictionAnswer::query()->where('prediction_id', $prediction->id)->where('is_correct', true)->pluck('user_id');

        foreach ($winners as $userId) {
            $user = WpUser::find($userId);

            if (! $user) {
                continue;
            }

            if ($prediction->points > 0) {
                $this->points->credit($user, $prediction->points, 'prediction', 'prediction', $prediction->id, 'Right answer: '.$prediction->question);
            }

            $this->season->addXp($user->ID, (int) config('engagement.season.xp.prediction_correct', 20));

            if (PredictionAnswer::query()->where('user_id', $userId)->where('is_correct', true)->count() >= 5) {
                $this->badges->award($user->ID, 'predictor');
            }

            $user->notify(new EngagementAlert(
                'You called it! 🔮',
                "Right answer on \"{$prediction->question}\". +{$prediction->points} points.",
                '/rewards/predict',
                'Play today\'s questions',
            ));
        }

        return $winners->count();
    }

    /** One question for the page, with how everyone answered once the customer has answered too. */
    private function present(Prediction $p, ?int $mine): array
    {
        $counts = $mine !== null
            ? PredictionAnswer::query()->where('prediction_id', $p->id)->selectRaw('`option`, count(*) as n')->groupBy('option')->pluck('n', 'option')
            : collect();
        $total = max(1, $counts->sum());

        return [
            'id' => $p->id,
            'category' => $p->category,
            'category_label' => Prediction::CATEGORIES[$p->category] ?? $p->category,
            'question' => $p->question,
            'options' => $p->options,
            'points' => $p->points,
            'closes_at' => $p->closes_at->toIso8601String(),
            'your_answer' => $mine,
            // Shown only after answering, so nobody just follows the crowd.
            'crowd' => $mine !== null ? collect($p->options)->keys()->map(fn ($i) => (int) round(($counts[$i] ?? 0) / $total * 100))->all() : null,
        ];
    }
}
