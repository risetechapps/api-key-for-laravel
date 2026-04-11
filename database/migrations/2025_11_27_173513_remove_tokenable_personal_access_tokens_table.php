<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if(Schema::hasTable('personal_access_tokens')){

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropcolumn('tokenable_id');
            });

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropcolumn('tokenable_type');
            });
        }
    }

    public function down(): void
    {
        if(Schema::hasTable('personal_access_tokens')){
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if(!Schema::hasColumn('personal_access_tokens', 'tokenable_id')){
                    $table->unsignedBigInteger('tokenable_id')->after('id');
                }
            });

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if(!Schema::hasColumn('personal_access_tokens', 'tokenable_type')){
                    $table->string('tokenable_type')->after('tokenable_id');
                }
            });
        }
    }
};
