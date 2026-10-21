<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas do ciclo de cobrança da assinatura.
 *
 * - Retentativa da renovação recusada: quantas tentativas já foram, quando é a
 *   próxima e quando o pacote desistiu.
 * - Forma de pagamento (cartão ou PIX), que decide como a renovação acontece.
 * - Período de teste.
 * - Excedente: requisições além do limite do plano e a cobrança delas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('renewal_attempts')->default(0);
            $table->timestamp('next_renewal_attempt_at')->nullable();
            $table->timestamp('renewal_failed_at')->nullable();

            $table->string('payment_method', 20)->nullable();
            $table->boolean('is_trial')->default(false);

            $table->unsignedBigInteger('overage_requests')->default(0);
            $table->decimal('overage_amount', 10, 2)->nullable();
            $table->string('overage_payment_id')->nullable();
            $table->timestamp('overage_billed_at')->nullable();
            $table->timestamp('overage_paid_at')->nullable();

            $table->index('next_renewal_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropIndex(['next_renewal_attempt_at']);
            $table->dropColumn([
                'renewal_attempts', 'next_renewal_attempt_at', 'renewal_failed_at',
                'payment_method', 'is_trial',
                'overage_requests', 'overage_amount', 'overage_payment_id', 'overage_billed_at', 'overage_paid_at',
            ]);
        });
    }
};
