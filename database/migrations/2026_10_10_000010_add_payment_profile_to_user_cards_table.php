<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de pagamento do Mercado Pago (Pagamentos Automáticos) do cartão.
 *
 * É o que permite cobrar o cartão salvo sem o CVV do titular: a renovação e o
 * excedente passam a ir pela Orders API com o perfil, em vez de tokenizar o
 * cartão de novo. Nulo enquanto o cartão não tiver perfil, e nesse caso a
 * cobrança segue pelo caminho antigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->string('mp_payment_profile_id')->nullable();
            $table->string('mp_payment_profile_status', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->dropColumn(['mp_payment_profile_id', 'mp_payment_profile_status']);
        });
    }
};
