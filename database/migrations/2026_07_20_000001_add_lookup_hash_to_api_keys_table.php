<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a deterministic lookup column so an API key can be found with a single
     * indexed SELECT instead of scanning every active row with bcrypt.
     *
     * The column stores hash_hmac('sha256', $plainKey, <secret>) — deterministic,
     * so it is searchable, and keyed, so a database dump alone does not allow
     * offline enumeration. The bcrypt hash in `key` remains the actual credential
     * check; lookup_hash only narrows the candidate set to one row.
     *
     * Existing rows stay NULL: the plain key was never stored, so it cannot be
     * backfilled here. ApiKey::validateKey() falls back to the legacy scan for
     * those rows only, and backfills lookup_hash the first time each legacy key
     * is used — so the scanned set shrinks to zero on its own.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('lookup_hash', 64)->nullable()->after('key');
            $table->index('lookup_hash');
        });

        // The plain index on `key` is dead weight: no query ever filters by the
        // bcrypt hash. The unique constraint (which has its own index) stays.
        if (Schema::hasIndex('api_keys', 'api_keys_key_index')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->dropIndex('api_keys_key_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex(['lookup_hash']);
            $table->dropColumn('lookup_hash');
            $table->index('key');
        });
    }
};
