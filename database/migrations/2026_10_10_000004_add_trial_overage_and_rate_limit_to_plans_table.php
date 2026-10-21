<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração comercial do plano: dias de teste, preço por requisição
 * excedente (null = bloqueia no limite, como antes), teto de excedente e
 * limite de requisições por minuto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->decimal('overage_price', 10, 4)->nullable();
            $table->unsignedBigInteger('overage_max_requests')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
        });

        Schema::table('authentications', function (Blueprint $table) {
            // Um teste por conta, não por plano: sem isto bastaria alternar
            // entre planos para nunca pagar.
            $table->timestamp('trial_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['trial_days', 'overage_price', 'overage_max_requests', 'rate_limit_per_minute']);
        });

        Schema::table('authentications', function (Blueprint $table) {
            $table->dropColumn('trial_used_at');
        });
    }
};
