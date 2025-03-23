<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;
use RiseTechApps\ApiKey\Services\AuthService;

return new class extends Migration {
    public function up(): void
    {
        Schema::createExtensionIfNotExists('citext');

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
            $table->caseInsensitiveText('email');
            $table->string('password');
            $table->string('locale')->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->enum('status', AuthService::statusLogin())->nullable()->default(AuthService::$ENABLE);
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentications');
    }
};
