<?php

use App\Http\Controllers\Api\Engagement\BadgeController;
use App\Http\Controllers\Api\Engagement\PredictionController;
use App\Http\Controllers\Api\Engagement\ReferralLadderController;
use App\Http\Controllers\Api\Engagement\SeasonPassController;
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

    Route::get('/season', [SeasonPassController::class, 'show']);
    Route::post('/season/claim', [SeasonPassController::class, 'claim'])->middleware('feature:season_pass');
});
