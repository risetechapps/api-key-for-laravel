<?php


use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;

Route::middleware(['api', 'language'])->prefix('api/v1/')->group(function () {
    // Authentication routes
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Protected routes
    Route::middleware(['plan'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
    });
});
