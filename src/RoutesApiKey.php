<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\ProfileController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Coupons\CouponsController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Modules\ModulesController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans\PlansController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature\SignatureController;

class RoutesApiKey
{
    public static function register(array $options = []): void
    {
        $options['middleware'][] = 'language';

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

                Route::get('/dashboard/modules', [ModulesController::class, 'index']);

                Route::get('/dashboard/coupons', [CouponsController::class, 'index']);
                Route::post('/dashboard/coupons', [CouponsController::class, 'store']);
                Route::get('/dashboard/coupons/{coupon}', [CouponsController::class, 'show']);
                Route::put('/dashboard/coupons/{coupon}', [CouponsController::class, 'update']);
                Route::delete('/dashboard/coupons/{coupon}', [CouponsController::class, 'delete']);

                Route::post('/dashboard/signature', [SignatureController::class, 'signature']);
                Route::get('/dashboard/history', [SignatureController::class, 'history']);
                Route::get('/dashboard/log', [SignatureController::class, 'log']);
            });
        });
    }
}
