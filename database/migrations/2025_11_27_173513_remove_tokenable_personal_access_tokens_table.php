<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            // Sanctum's own migration did not run (e.g. it is not published).
            // Create the base table ourselves; the tokenable morph columns are
            // added as UUID morphs by a later migration in this package.
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });

            return;
        }

        // Sanctum already created the table with bigint morphs — drop them so a
        // later migration can re-add them as UUID morphs.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
                $table->dropColumn('tokenable_id');
            }
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('personal_access_tokens', 'tokenable_type')) {
                $table->dropColumn('tokenable_type');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if (! Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
                    $table->unsignedBigInteger('tokenable_id')->after('id');
                }
            });

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if (! Schema::hasColumn('personal_access_tokens', 'tokenable_type')) {
                    $table->string('tokenable_type')->after('tokenable_id');
                }
            });
        }
    }
};
