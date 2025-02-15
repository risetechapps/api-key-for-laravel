<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\ApiKey\Services\AuthService;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('authentications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('rg')->nullable();
            $table->string('cpf')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('telephone')->nullable();
            $table->string('cellphone')->nullable();
            $table->enum('genre', AuthService::genreProfile())->default('MASCULINE');
            $table->string('nationality')->nullable();
            $table->string('naturalness')->nullable();
            $table->enum('marital_status', AuthService::maritalStatusProfile())->default("SINGLE")->nullable();
            $table->string('email');
            $table->string('password');
            $table->string('locale')->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->enum('status', AuthService::statusLogin())->nullable()->default(AuthService::$ENABLE);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentications');
    }
};
