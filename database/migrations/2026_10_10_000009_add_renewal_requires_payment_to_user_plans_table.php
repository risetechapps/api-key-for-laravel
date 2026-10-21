<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renovação que não pode ser cobrada automaticamente e espera o assinante
 * pagar pelo painel.
 *
 * Diferente da recusa do cartão (que ganha novas tentativas): aqui tentar de
 * novo não muda nada — não há cartão, o assinante paga com PIX, ou a conta do
 * Mercado Pago exige o CVV em toda cobrança. Guarda o motivo para o painel e o
 * e-mail dizerem o que fazer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->string('renewal_requires_payment', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropColumn('renewal_requires_payment');
        });
    }
};
