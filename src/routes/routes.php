<?php


use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;

Route::middleware(['api'])->prefix('api/v1/')->group(function () {
    // Authentication routes
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Protected routes
    Route::middleware(['plan'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('test-endpoint', function () {
            return response()->json(['message' => 'success']);
        });
    });
});
