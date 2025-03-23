<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->foreignUuid('authentication_id')->constrained()->onDelete('cascade');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('authentication_id');
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
