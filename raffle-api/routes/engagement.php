<?php

use App\Services\Engagement\Predictions;
use App\Services\Engagement\ReferralLadder;
use App\Services\Engagement\SeasonPass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Phase 11 — community and engagement pages. Included from routes/web.php
| before the old-site redirects, so these always win.
*/

$signedIn = fn (Request $request) => Auth::guard('wordpress')->guest()
    ? redirect('/login?redirect='.urlencode($request->fullUrl()))
    : null;

Route::get('/account/badges', fn (Request $request) => $signedIn($request) ?? Inertia::render('Account/Badges'));

// The full referral page. Public: a guest sees how it works and is asked to sign in for their link.
Route::get('/referrals', fn () => Inertia::render('Referrals/Index', [
    'ladder' => app(ReferralLadder::class)->publicRungs(),
    'commissionPercent' => round((float) config('referrals.commission_rate') * 100),
]));

// The Season Pass track. Public like the Rewards page: a guest sees the levels and is asked to log in.
Route::get('/rewards/season', fn () => Inertia::render('Rewards/Season', [
    'preview' => app(SeasonPass::class)->state(0),
]));

// Daily predictions. Public: a guest sees today's questions and is asked to log in to answer.
Route::get('/rewards/predict', fn () => Inertia::render('Rewards/Predict', [
    'preview' => app(Predictions::class)->board(null),
]));
