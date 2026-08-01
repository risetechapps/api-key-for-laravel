<?php

use Illuminate\Database\Migrations\Migration;
// Tipado pelo Blueprint core (que o Blueprint do tpetry estende): no pgsql o
// runtime recebe o Blueprint do tpetry — os métodos pg-específicos seguem
// disponíveis — e no SQLite dos testes recebe o core, sem TypeError.
use Illuminate\Database\Schema\Blueprint;
use RiseTechApps\ApiKey\Services\AuthService;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // citext é uma extensão exclusiva do PostgreSQL. Fora do pgsql (ex.: o
        // SQLite usado nos testes) o método createExtensionIfNotExists nem existe
        // no builder, então o guard de driver evita quebrar toda a suíte — o
        // caseInsensitiveText do email já é protegido do mesmo jeito abaixo.
        if (DB::getDriverName() === 'pgsql') {
            Schema::createExtensionIfNotExists('citext');
        }

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
            $table->enum('marital_status', AuthService::maritalStatusProfile())->default('SINGLE')->nullable();

            if (DB::getDriverName() === 'pgsql') {
                $table->caseInsensitiveText('email');
            } else {
                $table->string('email');
            }
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
