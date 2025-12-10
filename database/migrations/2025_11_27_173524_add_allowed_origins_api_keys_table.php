<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if(Schema::hasTable('api_keys')){
            Schema::table('api_keys', function (Blueprint $table) {
                $table->json('allowed_origins')->nullable()->after('key');
            });
        }

    }

    public function down(): void
    {

    }
};
