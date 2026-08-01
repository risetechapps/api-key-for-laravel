<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alinha sessions.user_id (bigint padrão do Laravel) ao id UUID das
     * autenticações. É sintaxe exclusiva do PostgreSQL (ALTER COLUMN ... USING),
     * e a tabela `sessions` pertence à app host — pode não existir no ambiente de
     * teste (SQLite em memória). Guardado nos dois eixos para não quebrar a suíte.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('sessions')) {
            return;
        }

        DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE varchar(36) USING user_id::text');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('sessions')) {
            return;
        }

        DB::statement('DELETE FROM sessions');
        DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE bigint USING NULL');
    }
};
