<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail novo aguardando confirmação.
 *
 * A troca só vale quando o dono do endereço novo clica no link. Gravar direto
 * em `email` deixaria um erro de digitação trancar o usuário para fora da
 * conta, e deixaria quem roubou uma sessão tomar a conta trocando o e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });
    }
};
