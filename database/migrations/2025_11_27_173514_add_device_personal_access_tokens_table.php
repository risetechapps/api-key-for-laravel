<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if(Schema::hasTable('personal_access_tokens')){

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->after('expires_at', function (Blueprint $table) {
                    $table->text('device')->nullable();
                });
            });
        }

    }

    public function down(): void
    {

    }
};
