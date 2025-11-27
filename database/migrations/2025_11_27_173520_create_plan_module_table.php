<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_module', function (Blueprint $table) {
            $table->foreignUuid('plan_id')->references('id')->on('plans')->onDelete('cascade');
            $table->foreignUuid('module_id')->references('id')->on('modules')->onDelete('cascade');

            $table->primary(['plan_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_module');
    }
};
