<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Índice único no e-mail, sem diferença de caixa.
 *
 * Até aqui a unicidade era só da validação, e duas requisições juntas (cadastro
 * ou aceite de convite) passavam as duas e criavam duas contas com o mesmo
 * e-mail. Contas excluídas entram na conta: a validação do cadastro também as
 * considera, e a exclusão troca o e-mail por um endereço próprio dela.
 *
 * Com e-mails repetidos no banco, o índice não tem como ser criado: a
 * migration para e diz quantos são, sem mexer em nada. Resolva-os (ver
 * UPGRADE.md) e rode de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::query()->fromSub(
            DB::table('authentications')
                ->selectRaw('LOWER(email) AS normalized')
                ->groupByRaw('LOWER(email)')
                ->havingRaw('COUNT(*) > 1'),
            'duplicates',
        )->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "authentications tem {$duplicates} e-mail(s) repetido(s), sem diferença de caixa, e o índice único não pode ser criado. ".
                'Liste-os com: SELECT LOWER(email), COUNT(*) FROM authentications GROUP BY 1 HAVING COUNT(*) > 1; '.
                'resolva cada um (ver UPGRADE.md) e rode a migration de novo. Nada foi alterado.'
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.Authentication::EMAIL_UNIQUE_INDEX.' ON authentications (LOWER(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.Authentication::EMAIL_UNIQUE_INDEX);
    }
};
