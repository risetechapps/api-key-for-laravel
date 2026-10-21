<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * start_date / end_date were `date`, but every write stores a full timestamp
     * (now(), now()->addDays(...)) and every read compares against now().
     *
     * The database silently truncated the time and then promoted the value back
     * to midnight when comparing, so `end_date >= now()` turned false at 00:00 on
     * the expiry day — subscriptions ended up to a full day early.
     */
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->timestamp('start_date')->change();
            $table->timestamp('end_date')->change();
        });

        // Legacy rows were truncated to midnight, which under the corrected
        // comparison would expire them at the very start of their last day.
        // Push those (and only those) to the end of that day so no subscriber
        // loses time that was already paid for.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::table('user_plans')
                ->whereRaw("end_date = date_trunc('day', end_date)")
                ->update([
                    'end_date' => DB::raw("end_date + interval '23 hours 59 minutes 59 seconds'"),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->change();
        });
    }
};
