<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('authentication_id');
            $table->foreign('authentication_id')->references('id')->on('authentications')->cascadeOnDelete();
            $table->string('holder_name');
            $table->string('last_four', 4);
            $table->string('brand');
            $table->string('expiry_month', 2);
            $table->string('expiry_year', 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cards');
    }
};
