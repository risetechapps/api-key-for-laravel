<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipe da conta: pessoas que entram no painel com login próprio e agem na
 * conta de um dono (o cliente que assina o plano).
 *
 * O membro é uma Authentication com `account_id` (o dono) e `team_role`
 * (admin ou agent). O dono tem `account_id` nulo. Ver Support\Team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->uuid('account_id')->nullable()->after('id')->index();
            $table->string('team_role', 16)->nullable()->after('account_id');
        });

        // Quantos membros o plano inclui, além do dono. 0 = só o dono.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('team_members_limit')->default(0);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->uuid('invited_by')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();   // sha256 do token do link
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('team_members_limit');
        });

        Schema::table('authentications', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->dropColumn(['account_id', 'team_role']);
        });
    }
};
