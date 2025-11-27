<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->codeGenerate();
            if (DB::getDriverName() === 'pgsql') {
                $table->caseInsensitiveText('name')->unique();
            } else {
                $table->string('name')->unique();
            }
            $table->string('description')->nullable();

            $table->unsignedBigInteger('request_limit')->default(0);

            $table->enum('billing_cycle', \RiseTechApps\ApiKey\Enums\BillingCycle::cases());
            $table->decimal('price', 8, 2);

            // ID do Preço no Stripe (Cashier

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
