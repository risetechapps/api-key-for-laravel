<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    /**
     * Remove o campo manual `features_description`. A descrição dos planos
     * passa a vir exclusivamente dos metadados das features registradas
     * (FeatureRegistry / tabela plan_features).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'features_description')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('features_description');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'features_description')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->jsonb('features_description')->nullable()->after('features');
        });
    }
};
