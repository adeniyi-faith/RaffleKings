<?php

use App\Http\Controllers\Api\AuthBridgeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:wordpress')->group(function () {
    Route::get('/me', [AuthBridgeController::class, 'me']);
});
