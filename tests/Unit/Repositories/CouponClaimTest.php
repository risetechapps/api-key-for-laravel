<?php

use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Repositories\Coupon\CouponEloquentRepository;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Instanciado direto: o binding do contrato só é registrado quando o
    // RepositoryServiceProvider está carregado, e ele não faz parte do Testbench.
    $this->repository = new CouponEloquentRepository();
});

describe('Coupon claim', function () {
    it('claims a use and moves the counter', function () {
        $coupon = Coupon::factory()->create(['max_uses' => 5, 'uses' => 0]);

        expect($this->repository->claimUse($coupon))->toBeTrue()
            ->and($coupon->fresh()->uses)->toBe(1);
    });

    it('keeps the in-memory model in step with the row', function () {
        $coupon = Coupon::factory()->create(['max_uses' => 5, 'uses' => 0]);

        $this->repository->claimUse($coupon);

        // Sem o sync, quem já tinha o model em mãos seguiria lendo o valor antigo.
        expect($coupon->uses)->toBe(1)
            ->and($coupon->isDirty())->toBeFalse();
    });

    it('refuses the claim that would exceed max_uses', function () {
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        expect($this->repository->claimUse($coupon))->toBeTrue()
            ->and($this->repository->claimUse($coupon))->toBeFalse()
            ->and($coupon->fresh()->uses)->toBe(1);
    });

    it('is the check itself, not a check followed by an increment', function () {
        // O bug corrigido: isValid() lia `uses` e o incremento vinha depois da
        // cobrança, então dois checkouts simultâneos liam o mesmo valor e ambos
        // passavam. Aqui os dois "concorrentes" partem do mesmo model carregado
        // antes de qualquer claim — exatamente o cenário que estourava max_uses —
        // e mesmo assim só um consegue a vaga.
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        $first = Coupon::find($coupon->getKey());
        $second = Coupon::find($coupon->getKey());

        expect($first->isValid())->toBeTrue()
            ->and($second->isValid())->toBeTrue();

        $claims = array_filter([
            $this->repository->claimUse($first),
            $this->repository->claimUse($second),
        ]);

        expect($claims)->toHaveCount(1)
            ->and($coupon->fresh()->uses)->toBe(1);
    });

    it('allows unlimited claims when max_uses is null', function () {
        $coupon = Coupon::factory()->create(['max_uses' => null, 'uses' => 0]);

        expect($this->repository->claimUse($coupon))->toBeTrue()
            ->and($this->repository->claimUse($coupon))->toBeTrue()
            ->and($coupon->fresh()->uses)->toBe(2);
    });

    it('refuses an inactive coupon', function () {
        $coupon = Coupon::factory()->inactive()->create(['uses' => 0]);

        expect($this->repository->claimUse($coupon))->toBeFalse()
            ->and($coupon->fresh()->uses)->toBe(0);
    });

    it('refuses an expired coupon', function () {
        $coupon = Coupon::factory()->expired()->create(['uses' => 0]);

        expect($this->repository->claimUse($coupon))->toBeFalse()
            ->and($coupon->fresh()->uses)->toBe(0);
    });

    it('matches isValid on a coupon expiring today', function () {
        // expires_at é cast de data (meia-noite) e isValid() rejeita assim que
        // isPast(); o WHERE do claim tem que concordar com isso.
        $coupon = Coupon::factory()->create(['expires_at' => now()->startOfDay(), 'uses' => 0]);

        expect($coupon->isValid())->toBeFalse()
            ->and($this->repository->claimUse($coupon))->toBeFalse();
    });
});

describe('Coupon release', function () {
    it('hands a claimed use back', function () {
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        $this->repository->claimUse($coupon);
        $this->repository->releaseUse($coupon);

        expect($coupon->fresh()->uses)->toBe(0);
    });

    it('frees the slot for the next buyer', function () {
        // O ponto do release: um cartão recusado não pode queimar a última vaga.
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        $this->repository->claimUse($coupon);
        $this->repository->releaseUse($coupon);

        expect($this->repository->claimUse($coupon->fresh()))->toBeTrue();
    });

    it('never drops the counter below zero', function () {
        $coupon = Coupon::factory()->create(['uses' => 0]);

        $this->repository->releaseUse($coupon);

        expect($coupon->fresh()->uses)->toBe(0);
    });
});
