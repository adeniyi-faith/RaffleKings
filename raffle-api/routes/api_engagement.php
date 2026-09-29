<?php

use App\Http\Controllers\Api\Engagement\BadgeController;
use App\Http\Controllers\Api\Engagement\PredictionController;
use App\Http\Controllers\Api\Engagement\RedEnvelopeController;
use App\Http\Controllers\Api\Engagement\ReferralLadderController;
use App\Http\Controllers\Api\Engagement\SeasonPassController;
use App\Http\Controllers\Api\Engagement\SocialBoostController;
use Illuminate\Support\Facades\Route;

/*
| Phase 11 — community and engagement features (see config/engagement.php).
| Included from routes/api.php, so every path here starts with /api.
*/

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/badges', [BadgeController::class, 'index']);
    Route::post('/badges/showcase', [BadgeController::class, 'showcase']);

    Route::get('/referrals/overview', ReferralLadderController::class);

    Route::get('/predictions', [PredictionController::class, 'index']);
    Route::post('/predictions/{prediction}/answer', [PredictionController::class, 'answer'])->whereNumber('prediction')->middleware(['feature:predictions', 'throttle:30,1']);

    Route::get('/raffles/{raffle}/boosts', [SocialBoostController::class, 'show'])->whereNumber('raffle');
    Route::post('/raffles/{raffle}/unlock', [SocialBoostController::class, 'createUnlock'])->whereNumber('raffle')->middleware('feature:unlock_links');
    Route::post('/unlock/{code}/tap', [SocialBoostController::class, 'tap'])->middleware(['feature:unlock_links', 'throttle:20,1']);
    Route::post('/raffles/{raffle}/team', [SocialBoostController::class, 'createTeam'])->whereNumber('raffle')->middleware('feature:team_up');
    Route::post('/teams/{code}/join', [SocialBoostController::class, 'join'])->middleware(['feature:team_up', 'throttle:20,1']);

    Route::post('/raffles/{raffle}/live-draw/envelopes', [RedEnvelopeController::class, 'send'])->middleware(['feature:red_envelopes', 'feature:live_chat', 'throttle:6,1']);
    Route::post('/envelopes/{envelope}/claim', [RedEnvelopeController::class, 'claim'])->whereNumber('envelope')->middleware(['feature:red_envelopes', 'throttle:30,1']);

    Route::get('/season', [SeasonPassController::class, 'show']);
    Route::post('/season/claim', [SeasonPassController::class, 'claim'])->middleware('feature:season_pass');
});
