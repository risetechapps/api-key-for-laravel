<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tentativas de compra que o gateway deixou em análise.
 *
 * Um pagamento `pending`/`in_process` não vira assinatura na hora: o Mercado
 * Pago decide depois e avisa por webhook. Até aqui nada era gravado nesse
 * intervalo, então uma recusa posterior não tinha a quem se referir — o
 * comprador não era avisado, a reserva do cupom ficava queimada para sempre e
 * um webhook que não chegasse deixava o pagamento órfão, sem ninguém perceber.
 *
 * Esta tabela é o registro dessa espera. Ela existe só até o desfecho: assim que
 * o pagamento é aprovado ou recusado, a linha é marcada como resolvida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('authentication_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('plan_id')->constrained()->onDelete('cascade');

            // Único: o mesmo pagamento não pode gerar duas esperas, e é por ele
            // que o webhook reencontra a tentativa.
            $table->string('payment_id')->unique();

            $table->decimal('amount', 10, 2);

            // Reserva a devolver se o pagamento for recusado. Sem guardar qual
            // cupom foi usado, a vaga se perde: o claim acontece antes de falar
            // com o gateway e nada mais liga a tentativa ao cupom.
            $table->foreignUuid('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('credit_applied', 10, 2)->nullable();

            // Último status conhecido no gateway, e o desfecho quando houver.
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            // O conjunto que o webhook e o comando de reconciliação procuram:
            // o que ainda não teve desfecho.
            $table->index(['settled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payments');
    }
};
