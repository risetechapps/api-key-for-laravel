<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro da cobrança de validação de cartão e do seu estorno.
 *
 * Salvar um cartão cobra um valor simbólico e o estorna em seguida, mas o
 * estorno era best-effort: se falhasse, o único vestígio era um Log::warning.
 * O cliente ficava com a cobrança no cartão e ninguém tinha como saber quais
 * validações ficaram pendentes. Com estas colunas a pendência é consultável e
 * o comando `api-key:retry-validation-refunds` consegue reprocessá-la.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->string('validation_payment_id')->nullable()->after('mp_card_id');
            $table->timestamp('validation_refunded_at')->nullable()->after('validation_payment_id');
        });

        // Índice sobre a pendência em si: o comando de reprocessamento busca
        // exatamente "tem cobrança e não tem estorno", que tende a ser um punhado
        // de linhas numa tabela que só cresce.
        Schema::table('user_cards', function (Blueprint $table) {
            $table->index(['validation_refunded_at', 'validation_payment_id'], 'user_cards_pending_validation_refund_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->dropIndex('user_cards_pending_validation_refund_index');
            $table->dropColumn(['validation_payment_id', 'validation_refunded_at']);
        });
    }
};
