<?php

use App\Http\Controllers\Api\Engagement\BadgeController;
use App\Http\Controllers\Api\Engagement\ReferralLadderController;
use Illuminate\Support\Facades\Route;

/*
| Phase 11 — community and engagement features (see config/engagement.php).
| Included from routes/api.php, so every path here starts with /api.
*/

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/badges', [BadgeController::class, 'index']);
    Route::post('/badges/showcase', [BadgeController::class, 'showcase']);

    Route::get('/referrals/overview', ReferralLadderController::class);
});
