<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\ProfileController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Modules\ModulesController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans\PlansController;

class RoutesApiKey
{
    public static function register(array $options = []): void
    {
        Route::group($options, function () use ($options) {

            Route::post('/register', [AuthController::class, 'register']);

            Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
            Route::post('login', [AuthController::class, 'login']);

            Route::middleware(['auth:sanctum'])->group(function () {
                Route::get('/auth/me', [AuthController::class, 'me']);

                Route::get('/dashboard/profile', [ProfileController::class, 'show']);
                Route::put('/dashboard/profile', [ProfileController::class, 'update']);

                Route::get('/dashboard/plans', [PlansController::class, 'index']);
                Route::post('/dashboard/plans', [PlansController::class, 'store']);
                Route::get('/dashboard/plans/{plan}', [PlansController::class, 'show']);
                Route::put('/dashboard/plans/{plan}', [PlansController::class, 'update']);
                Route::delete('/dashboard/plans/{plan}', [PlansController::class, 'delete']);
                Route::post('/dashboard/plans/associate', [PlansController::class, 'associate']);

                Route::get('/dashboard/modules', [ModulesController::class, 'index']);
                Route::post('/dashboard/modules', [ModulesController::class, 'store']);
                Route::get('/dashboard/modules/{module}', [ModulesController::class, 'show']);
                Route::put('/dashboard/modules/{module}', [ModulesController::class, 'update']);
                Route::delete('/dashboard/modules/{module}', [ModulesController::class, 'delete']);
            });
        });
    }
}
