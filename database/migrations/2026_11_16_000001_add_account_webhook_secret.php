<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Um segredo de webhook por conta, no lugar de um por endpoint.
 *
 * Todas as entregas da conta (endpoints cadastrados e URLs avulsas, como o
 * `webhook_url` de cada pedido do app) passam a ser assinadas com ele. As
 * entregas para URL avulsa não têm endpoint: guardam a conta e a URL.
 *
 * Contas que já têm endpoint herdam o segredo do mais antigo, para que o
 * sistema do cliente continue validando sem mudar nada. Os demais endpoints da
 * conta, se tinham segredos próprios, passam a usar esse (ver UPGRADE.md).
 * Contas sem endpoint ganham o segredo no primeiro uso.
 *
 * `webhook_endpoints.secret` fica, anulável, só para o `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authentications', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable();
        });

        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->text('secret')->nullable()->change();
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('webhook_endpoint_id')->nullable()->change();
            $table->foreignUuid('authentication_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url', 2048)->nullable();

            $table->index(['authentication_id', 'created_at']);
        });

        DB::table('webhook_deliveries')
            ->whereNull('authentication_id')
            ->update([
                'authentication_id' => DB::table('webhook_endpoints')
                    ->select('authentication_id')
                    ->whereColumn('webhook_endpoints.id', 'webhook_deliveries.webhook_endpoint_id'),
            ]);

        // O mais antigo de cada conta. Cifrado como o cast `encrypted` cifra,
        // então o valor só é decifrado e cifrado de novo, nunca exposto.
        $seen = [];

        DB::table('webhook_endpoints')
            ->whereNotNull('secret')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['authentication_id', 'secret'])
            ->each(function (object $endpoint) use (&$seen) {
                if (isset($seen[$endpoint->authentication_id])) {
                    return;
                }

                $seen[$endpoint->authentication_id] = true;

                DB::table('authentications')
                    ->where('id', $endpoint->authentication_id)
                    ->whereNull('webhook_secret')
                    ->update(['webhook_secret' => Crypt::encryptString(Crypt::decryptString($endpoint->secret))]);
            });
    }

    public function down(): void
    {
        // Entregas para URL avulsa não cabem no esquema antigo.
        DB::table('webhook_deliveries')->whereNull('webhook_endpoint_id')->delete();

        // Endpoints criados depois da migração não têm segredo próprio: voltam
        // com o da conta, que é o que o cliente usa hoje.
        DB::table('webhook_endpoints')
            ->whereNull('secret')
            ->get(['id', 'authentication_id'])
            ->each(function (object $endpoint) {
                $secret = DB::table('authentications')->where('id', $endpoint->authentication_id)->value('webhook_secret');

                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update([
                    'secret' => $secret ?? Crypt::encryptString('whsec_'.bin2hex(random_bytes(20))),
                ]);
            });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['authentication_id', 'created_at']);
            $table->dropConstrainedForeignId('authentication_id');
            $table->dropColumn('url');
            $table->uuid('webhook_endpoint_id')->nullable(false)->change();
        });

        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->text('secret')->nullable(false)->change();
        });

        Schema::table('authentications', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
