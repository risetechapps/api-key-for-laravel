<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('plans', 'features_description')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->jsonb('features_description')->nullable()->after('features');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('plans', 'features_description')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('features_description');
        });
    }
};
