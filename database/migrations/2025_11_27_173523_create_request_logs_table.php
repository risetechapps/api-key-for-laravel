<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('authentication_id')->constrained()->onDelete('cascade');
            $table->string('endpoint');
            $table->string('method');
            $table->string('response_code');
            $table->timestamp('requested_at')->default(now());
            $table->timestamps();

            $table->index('authentication_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
