<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membro da equipe criado por integração (API): `external_id` é o id da
 * pessoa no sistema do cliente. Não tem login aqui (ver
 * TeamService::provisionExternal); o sistema do cliente age em nome dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('team_role');
            $table->unique(['account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
