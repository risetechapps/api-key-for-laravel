<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP de onde veio cada requisição, para a lista do painel. 45 caracteres
 * cabem um IPv6 completo. Nulo nas linhas gravadas antes desta coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('response_code');
        });
    }

    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }
};
