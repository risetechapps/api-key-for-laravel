<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qual gateway é o cartão salvo.
 *
 * Um cartão só pode ser cobrado pelo gateway que o guardou. Trocar o gateway
 * ativo no painel vale para compras e cartões novos; os já salvos continuam
 * sendo cobrados pelo gateway de origem enquanto ele estiver configurado.
 *
 * Os cartões do Mercado Pago seguem em `mp_customer_id` / `mp_card_id`. Os
 * demais usam as colunas genéricas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->string('gateway', 20)->default('mercadopago');
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_card_id')->nullable();
            // Primeira cobrança da recorrência no cartão (Pagar.me): as
            // seguintes a referenciam em `payment_origin`, sem CVV.
            $table->string('gateway_first_charge_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'gateway_customer_id', 'gateway_card_id', 'gateway_first_charge_id']);
        });
    }
};
