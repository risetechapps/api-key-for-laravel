<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->string('mp_customer_id')->nullable()->after('expiry_year');
            $table->string('mp_card_id')->nullable()->after('mp_customer_id');
            $table->boolean('is_default')->default(false)->after('mp_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->dropColumn(['mp_customer_id', 'mp_card_id', 'is_default']);
        });
    }
};
