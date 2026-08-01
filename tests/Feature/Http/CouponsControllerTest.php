<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Authentication::factory()->create(['role' => 'admin']);
    $this->user = Authentication::factory()->create(['role' => 'user']);
});

function couponPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'BLACKFRIDAY',
        'type' => 'percentage',
        'value' => 15,
        'max_uses' => 100,
        'expires_at' => now()->addMonth()->format('Y-m-d'),
    ], $overrides);
}

describe('Reading coupons', function () {
    it('requires authentication', function () {
        $this->getJson('/api/v1/dashboard/coupons')->assertStatus(401);
    });

    it('lists coupons for a signed-in user', function () {
        Coupon::factory()->count(3)->create();
        $this->actingAs($this->user, 'sanctum');

        expect($this->getJson('/api/v1/dashboard/coupons')->assertStatus(200)->json('data'))
            ->toHaveCount(3);
    });

    it('reads a single coupon', function () {
        $coupon = Coupon::factory()->create(['code' => 'PROMO10']);
        $this->actingAs($this->user, 'sanctum');

        $this->getJson("/api/v1/dashboard/coupons/{$coupon->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.code', 'PROMO10');
    });

    it('404s a coupon that does not exist', function () {
        $this->actingAs($this->user, 'sanctum');

        $this->getJson('/api/v1/dashboard/coupons/'.Str::uuid())->assertStatus(404);
    });
});

describe('Coupon administration', function () {
    it('refuses writes from a non-admin', function () {
        $coupon = Coupon::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload())->assertStatus(403);
        $this->putJson("/api/v1/dashboard/coupons/{$coupon->getKey()}", couponPayload())->assertStatus(403);
        $this->deleteJson("/api/v1/dashboard/coupons/{$coupon->getKey()}")->assertStatus(403);

        expect(Coupon::count())->toBe(1);
    });

    it('creates a coupon', function () {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload())->assertStatus(200);

        $coupon = Coupon::where('code', 'BLACKFRIDAY')->first();

        expect($coupon)->not->toBeNull()
            ->and($coupon->type)->toBe('percentage')
            ->and((int) $coupon->max_uses)->toBe(100)
            ->and($coupon->uses)->toBe(0);
    });

    it('derives the gateway id from the code', function () {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload())->assertStatus(200);

        expect(Coupon::where('code', 'BLACKFRIDAY')->value('gateway_coupon_id'))->toBe('blackfriday');
    });

    it('refuses a duplicate code', function () {
        Coupon::factory()->create(['code' => 'BLACKFRIDAY']);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload())->assertStatus(422);

        expect(Coupon::count())->toBe(1);
    });

    it('refuses a percentage above 100', function () {
        // Desconto acima de 100% inverteria o sinal do total a pagar.
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload(['value' => 150]))->assertStatus(422);
    });

    it('refuses max_uses below one', function () {
        // Cupom que nasce sem nenhuma vaga não tem uso; aceitá-lo só produz um
        // código que falha no checkout.
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload(['max_uses' => 0]))->assertStatus(422);
    });

    it('refuses an unknown discount type', function () {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/coupons', couponPayload(['type' => 'buy_one_get_one']))
            ->assertStatus(422);
    });

    it('updates a coupon', function () {
        $coupon = Coupon::factory()->create(['code' => 'ANTIGO', 'value' => 5]);
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/v1/dashboard/coupons/{$coupon->getKey()}", couponPayload([
            'code' => 'ANTIGO',
            'value' => 25,
        ]))->assertStatus(200);

        expect((float) $coupon->fresh()->value)->toBe(25.0);
    });

    it('deletes a coupon', function () {
        $coupon = Coupon::factory()->create();
        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/v1/dashboard/coupons/{$coupon->getKey()}")->assertStatus(200);

        expect(Coupon::find($coupon->getKey()))->toBeNull();
    });
});
