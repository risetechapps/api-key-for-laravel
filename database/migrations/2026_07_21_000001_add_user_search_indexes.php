<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Trigram indexes for the admin user search.
     *
     * AdminController::users() filters with LOWER(col) LIKE '%term%'. A leading
     * wildcard cannot use a B-tree, so the search was a sequential scan over the
     * whole users table. pg_trgm indexes exactly this shape.
     *
     * PostgreSQL only, and best effort: CREATE EXTENSION needs privileges the
     * application role may not have. If it cannot be created the search keeps
     * working, just without the index — so a restricted database is not a reason
     * to fail the migration.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('api-key: could not enable pg_trgm; admin user search will not be indexed', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS authentications_name_trgm_idx ON authentications USING gin (LOWER(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS authentications_email_trgm_idx ON authentications USING gin (LOWER(email) gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS authentications_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS authentications_email_trgm_idx');
    }
};
