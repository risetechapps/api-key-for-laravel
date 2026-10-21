<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Um pagamento confirma uma assinatura, nunca duas.
 *
 * Checkout, webhook e reconciliação podem confirmar o mesmo pagamento quase ao
 * mesmo tempo. subscribeToPlan() já serializa isso com lock por usuário; o
 * índice é a garantia de último nível, no banco, contra qualquer caminho que
 * escape do lock.
 *
 * Bases que passaram pela corrida antiga podem ter o mesmo payment_id em mais de
 * uma assinatura. A migration não escolhe sozinha qual delas descartar — uma é a
 * que o cliente usa, a outra pode ter histórico de consumo —, então para e lista
 * os ids para o operador resolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::table('user_plans')
            ->select('payment_id')
            ->whereNotNull('payment_id')
            ->groupBy('payment_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(50)
            ->pluck('payment_id');

        if ($duplicated->isNotEmpty()) {
            throw new RuntimeException(
                'user_plans tem payment_id repetido; resolva antes de migrar '
                .'(mantenha uma assinatura por pagamento e anule o payment_id das demais): '
                .$duplicated->implode(', ')
            );
        }

        Schema::table('user_plans', function (Blueprint $table) {
            $table->unique('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropUnique(['payment_id']);
        });
    }
};
