<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Composite indexes for the two hot access paths.
     *
     * Both tables previously had only the foreign key index, so every lookup
     * matched on authentication_id and then filtered/sorted the remainder in
     * memory — cheap per user, expensive once a user accumulates history.
     */
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            // Covers the single most executed query in the package:
            // WHERE authentication_id = ? AND active = ? AND end_date >= ?
            // (active plan resolution, run by two middlewares on every request).
            $table->index(
                ['authentication_id', 'active', 'end_date'],
                'user_plans_active_lookup_idx'
            );
        });

        Schema::table('request_logs', function (Blueprint $table) {
            // Covers both the paginated history (ORDER BY requested_at DESC)
            // and the dashboard's daily count (requested_at range).
            $table->index(
                ['authentication_id', 'requested_at'],
                'request_logs_user_time_idx'
            );
        });

        Schema::table('user_cards', function (Blueprint $table) {
            // Renewal billing resolves the default card per user, once per plan.
            $table->index(
                ['authentication_id', 'is_default'],
                'user_cards_default_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropIndex('user_plans_active_lookup_idx');
        });

        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropIndex('request_logs_user_time_idx');
        });

        Schema::table('user_cards', function (Blueprint $table) {
            $table->dropIndex('user_cards_default_idx');
        });
    }
};
