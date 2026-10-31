<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada uso de cupom, por conta, para o limite `coupons.max_uses_per_user`.
 *
 * Uma linha por reserva: nasce com o `claimUse()` do checkout e sai com o
 * `releaseUse()` quando a compra não vinga. Sem chave estrangeira para a conta:
 * conta excluída leva os dados dela, e o uso que fez do cupom não volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->uuid('authentication_id');
            $table->timestamps();

            $table->index(['coupon_id', 'authentication_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
