<?php

use RiseTechApps\ApiKey\Models\Coupon\Coupon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Coupon Validation', function () {
    it('returns valid when active, not expired, and under limit', function () {
        $coupon = Coupon::factory()->create([
            'is_active' => true,
            'expires_at' => now()->addDay(),
            'max_uses' => 10,
            'uses' => 5,
        ]);

        expect($coupon->isValid())->toBeTrue();
    });

    it('returns invalid when inactive', function () {
        $coupon = Coupon::factory()->inactive()->create([
            'expires_at' => now()->addDay(),
        ]);

        expect($coupon->isValid())->toBeFalse();
    });

    it('returns invalid when expired', function () {
        $coupon = Coupon::factory()->create([
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        expect($coupon->isValid())->toBeFalse();
    });

    it('returns invalid when max uses reached', function () {
        $coupon = Coupon::factory()->create([
            'is_active' => true,
            'max_uses' => 10,
            'uses' => 10,
        ]);

        expect($coupon->isValid())->toBeFalse();
    });

    it('returns valid when max uses is null (unlimited)', function () {
        $coupon = Coupon::factory()->create([
            'is_active' => true,
            'max_uses' => null,
            'uses' => 999,
        ]);

        expect($coupon->isValid())->toBeTrue();
    });

    it('returns valid when no expiration date', function () {
        $coupon = Coupon::factory()->create([
            'is_active' => true,
            'expires_at' => null,
        ]);

        expect($coupon->isValid())->toBeTrue();
    });
});

describe('Gateway Coupon ID', function () {
    it('returns gateway_coupon_id when set', function () {
        $coupon = Coupon::factory()->create([
            'gateway_coupon_id' => 'gateway_123',
            'code' => 'CODE456',
        ]);

        expect($coupon->getGatewayCouponId())->toBe('gateway_123');
    });

    it('returns code when gateway_coupon_id is null', function () {
        $coupon = Coupon::factory()->create([
            'gateway_coupon_id' => null,
            'code' => 'CODE456',
        ]);

        expect($coupon->getGatewayCouponId())->toBe('CODE456');
    });
});
