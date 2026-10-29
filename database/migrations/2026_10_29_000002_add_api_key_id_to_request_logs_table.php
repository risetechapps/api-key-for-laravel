<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave que fez cada requisição, para filtrar o histórico por chave (ex.: o
 * que uma chave vazada fez antes de ser regenerada).
 *
 * Sem chave estrangeira de propósito: a chave excluída some de `api_keys`, e o
 * log dela precisa continuar dizendo que foi ela. Nulo nas linhas gravadas
 * antes desta coluna e nas requisições sem API key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->uuid('api_key_id')->nullable()->after('authentication_id');
            $table->index(['api_key_id', 'requested_at'], 'request_logs_key_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropIndex('request_logs_key_time_idx');
            $table->dropColumn('api_key_id');
        });
    }
};
