<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $id = $table->uuid('id')->primary();

            // gen_random_uuid() é função do PostgreSQL; fora dele (ex.: SQLite dos
            // testes) o id é gerado pela aplicação (trait HasUuid no boot do model).
            if (DB::connection()->getDriverName() === 'pgsql') {
                $id->default(DB::raw('gen_random_uuid()'));
            }

            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
