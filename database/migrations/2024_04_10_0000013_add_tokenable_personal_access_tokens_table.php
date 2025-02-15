<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\AuthService\AuthService;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->after('id', function (Blueprint $table) {
                $table->uuidMorphs('tokenable');
            });
        });
    }

    public function down(): void
    {

    }
};
