<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro do estorno concedido a uma assinatura.
 *
 * Sem estas colunas não há como distinguir uma assinatura encerrada de uma
 * assinatura devolvida, nem impedir que o mesmo pagamento seja estornado duas
 * vezes — a única prova ficaria no extrato do Mercado Pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('cancelled_at');

            // Id do estorno no gateway, não do pagamento: é o que o suporte usa
            // para conferir a devolução do lado do Mercado Pago.
            $table->string('refund_id')->nullable()->after('refunded_at');

            $table->index('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropIndex(['refunded_at']);
            $table->dropColumn(['refunded_at', 'refund_id']);
        });
    }
};
