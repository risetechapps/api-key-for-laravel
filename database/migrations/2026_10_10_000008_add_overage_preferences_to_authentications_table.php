<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escolha do assinante sobre o excedente.
 *
 * Desligado por padrão: o plano pode oferecer excedente, mas cobrar além do
 * contratado exige que o cliente tenha ligado. Fica na conta, e não na
 * assinatura, para sobreviver às renovações.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->boolean('overage_enabled')->default(false);
            // Teto pessoal de gasto com excedente por período, em reais. Null:
            // vale só o teto do plano.
            $table->decimal('overage_spend_cap', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->dropColumn(['overage_enabled', 'overage_spend_cap']);
        });
    }
};
