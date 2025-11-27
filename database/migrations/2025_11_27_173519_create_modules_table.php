<?php

use Illuminate\Database\Migrations\Migration;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            if (DB::getDriverName() === 'pgsql') {
                $table->caseInsensitiveText('name');
            } else {
                $table->string('name');
            }

            $table->string('module');
            $table->string('description')->nullable();
            $table->boolean('status');
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
