<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Várias chaves por conta: cada uma com nome, permissões (features que ela
 * pode chamar; null = todas as do plano), limite por minuto próprio e registro
 * do último uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('name', 100)->nullable();
            $table->json('scopes')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['name', 'scopes', 'rate_limit_per_minute', 'last_used_at']);
        });
    }
};
