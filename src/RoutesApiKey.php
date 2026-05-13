<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\ProfileController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\AdminController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards\CardController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout\CheckoutController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Coupons\CouponsController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans\PlansController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature\SignatureController;

class RoutesApiKey
{
    public static function register(array $options = []): void
    {
        $options['middleware'][] = 'language';

        $prefix = config('api-key.routes.prefix', '');
        if (!empty($prefix)) {
            $options['prefix'] = ($options['prefix'] ?? '') . '/' . trim($prefix, '/');
        }

        Route::group($options, function () use ($options) {

            $throttleAttempts = config('api-key.auth_throttle.attempts', 5);
            $throttleDecay = config('api-key.auth_throttle.decay_minutes', 1);
            $throttleMiddleware = config('api-key.auth_throttle.enabled', true)
                ? ["throttle:{$throttleAttempts},{$throttleDecay}"]
                : [];

            Route::middleware(array_filter($throttleMiddleware))->group(function () {
                Route::post('/register', [AuthController::class, 'register']);
                Route::post('login', [AuthController::class, 'login']);
            });

            Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');

            Route::get('/dashboard/plans', [PlansController::class, 'index']);

            Route::post('/dashboard/checkout/webhook', [CheckoutController::class, 'webhook']);

            Route::middleware(['auth:sanctum'])->group(function () {
                Route::post('/logout', [AuthController::class, 'logout']);
                Route::get('/auth/me', [AuthController::class, 'me']);

                Route::post('/dashboard/checkout/process', [CheckoutController::class, 'process']);
                Route::post('/dashboard/checkout/coupon', [CheckoutController::class, 'validateCoupon']);

                Route::get('/dashboard/profile', [ProfileController::class, 'show']);
                Route::put('/dashboard/profile', [ProfileController::class, 'update']);

                Route::get('/dashboard/profile/allowed', [ProfileController::class, 'getAllowedOrigins']);
                Route::post('/dashboard/profile/allowed', [ProfileController::class, 'updateAllowedOrigins']);
                Route::post('/dashboard/profile/regenerate-key', [ProfileController::class, 'regenerateKey']);

                Route::get('/dashboard/plans/{plan}', [PlansController::class, 'show']);
                Route::get('/dashboard/coupons', [CouponsController::class, 'index']);
                Route::get('/dashboard/coupons/{coupon}', [CouponsController::class, 'show']);

                Route::middleware(['admin'])->group(function () {
                    Route::post('/dashboard/plans', [PlansController::class, 'store']);
                    Route::put('/dashboard/plans/{plan}', [PlansController::class, 'update']);
                    Route::delete('/dashboard/plans/{plan}', [PlansController::class, 'delete']);

                    Route::post('/dashboard/coupons', [CouponsController::class, 'store']);
                    Route::put('/dashboard/coupons/{coupon}', [CouponsController::class, 'update']);
                    Route::delete('/dashboard/coupons/{coupon}', [CouponsController::class, 'delete']);

                    Route::get('/dashboard/admin/plans', [AdminController::class, 'plans']);
                    Route::get('/dashboard/admin/users', [AdminController::class, 'users']);
                    Route::get('/dashboard/admin/refunds', [AdminController::class, 'refunds']);
                    Route::post('/dashboard/admin/refunds/{id}', [AdminController::class, 'processRefund']);
                });

                Route::get('/dashboard/cards', [CardController::class, 'index']);
                Route::post('/dashboard/cards', [CardController::class, 'store']);
                Route::delete('/dashboard/cards/{id}', [CardController::class, 'destroy']);

                Route::post('/dashboard/signature', [SignatureController::class, 'signature']);
                Route::get('/dashboard/history', [SignatureController::class, 'history']);
                Route::get('/dashboard/log', [SignatureController::class, 'log']);
            });
        });
    }
}
