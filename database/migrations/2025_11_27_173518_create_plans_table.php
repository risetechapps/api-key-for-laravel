<?php

use Illuminate\Database\Migrations\Migration;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use Illuminate\Database\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // nullable pelo mesmo motivo do api_keys: code-generate não suporta o
            // sqlite dos testes; em produção (pgsql) o code é sempre preenchido.
            $table->codeGenerate()->nullable();
            if (DB::getDriverName() === 'pgsql') {
                $table->caseInsensitiveText('name')->unique();
            } else {
                $table->string('name')->unique();
            }
            $table->string('description')->nullable();

            $table->unsignedBigInteger('request_limit')->default(0);

            $table->enum('billing_cycle',BillingCycle::cases());
            $table->decimal('price', 8, 2);

            $table->jsonb('features')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
