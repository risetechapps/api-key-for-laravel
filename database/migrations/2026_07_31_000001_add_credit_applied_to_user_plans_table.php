<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Proration credit consumed when this subscription was bought.
     *
     * Without it, `payment_amount` below `plans.price` has no explanation: the
     * discount could have come from a coupon or from the unused days of the
     * previous plan, and the two are indistinguishable after the fact. Support
     * and accounting both need to tell them apart.
     */
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->decimal('credit_applied', 10, 2)->nullable()->after('payment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropColumn('credit_applied');
        });
    }
};
