<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurações editadas pelo painel admin (gateways de pagamento).
 *
 * Valem por cima do `.env`: uma chave sem linha aqui continua vindo da config.
 * O valor é sempre criptografado com a APP_KEY, porque a maior parte do que
 * mora aqui é credencial de gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_settings');
    }
};
