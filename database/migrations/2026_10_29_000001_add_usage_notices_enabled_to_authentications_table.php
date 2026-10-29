<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência do assinante pelos avisos informativos por e-mail (uso em 80%,
 * chave vencendo, excedente iniciado). Ligado por padrão. Cobrança, segurança e
 * expiração de plano não passam por aqui e saem sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->boolean('usage_notices_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->dropColumn('usage_notices_enabled');
        });
    }
};
