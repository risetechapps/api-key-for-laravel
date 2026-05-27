<?php


use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;

Route::middleware(['api', 'language'])->prefix('api/v1/')->group(function () {
    // Public authentication routes
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Dashboard routes - authenticated via Sanctum Bearer token
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
    });
});
