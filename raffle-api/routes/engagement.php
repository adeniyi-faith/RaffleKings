<?php

use App\Services\Engagement\ReferralLadder;
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
