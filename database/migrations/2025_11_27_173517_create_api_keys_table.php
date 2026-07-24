<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // nullable: em produção o code é sempre gerado (driver pgsql do pacote
            // code-generate), mas esse gerador não suporta sqlite, então nos testes
            // o model pode ser criado sem code. Não afeta o preenchimento em prod.
            $table->codeGenerate()->nullable();
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
