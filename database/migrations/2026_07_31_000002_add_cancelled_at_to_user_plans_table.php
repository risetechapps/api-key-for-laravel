<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the subscriber asked to stop renewing.
     *
     * There was no way for a customer to cancel: no route, no flag, no column.
     * A subscriber with a saved card was charged again at every `end_date` and
     * the only exit was an administrator issuing a refund by hand.
     *
     * One column rather than a `cancelled_at` / `auto_renew` pair: two fields
     * carrying the same fact eventually disagree, and the renewal path needs a
     * single thing to read. Null means "renews"; a timestamp means "runs to
     * end_date and stops", which is also the audit trail of when it was asked for.
     */
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('credit_applied');
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
