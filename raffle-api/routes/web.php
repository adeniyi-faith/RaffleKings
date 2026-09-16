<?php

use App\Services\RaffleReadService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home', [
        'message' => 'The new Inertia + React frontend build pipeline is live.',
    ]);
});

if (app()->environment(['local', 'testing'])) {
    // A living demo of the Phase 2 item 22 shared component library — not
    // a page real users see, just a way to visually verify every component
    // before the real pages (items 23-31) get built on top of them.
    Route::get('/dev/components', function (RaffleReadService $raffles) {
        $demoRaffle = collect($raffles->listActive())->first();

        return Inertia::render('ComponentLibrary', [
            'demoRaffleId' => $demoRaffle['id'] ?? null,
        ]);
    });
}
