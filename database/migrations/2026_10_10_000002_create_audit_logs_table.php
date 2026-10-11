<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem fez o quê, em quem: suspensão, estorno, plano concedido, troca de senha
 * ou de e-mail, 2FA.
 *
 * Fica no banco, e não só no log de aplicação, porque é consultado pelo painel
 * e precisa sobreviver à retenção do log. Sem chave estrangeira de propósito:
 * o registro de que um admin excluiu ou alterou alguém tem que continuar lá
 * depois que a conta deixa de existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('action', 100);
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
