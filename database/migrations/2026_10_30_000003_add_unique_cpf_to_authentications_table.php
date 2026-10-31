<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Índice único no CPF, comparando só os dígitos.
 *
 * Como no e-mail, a unicidade era só da validação: duas gravações juntas
 * passavam as duas, e "529.982.247-25" e "52998224725" nem eram vistos como o
 * mesmo CPF. CPF vazio fica de fora (o campo é opcional até o perfil ser
 * preenchido). Conta excluída tem o CPF apagado, então não ocupa o número.
 *
 * Com CPFs repetidos no banco, a migration para e diz quantos são, sem mexer
 * em nada. Resolva-os (ver UPGRADE.md) e rode de novo.
 */
return new class extends Migration
{
    private const DIGITS = "regexp_replace(cpf, '\\D', '', 'g')";

    public function up(): void
    {
        $duplicates = DB::query()->fromSub(
            DB::table('authentications')
                ->selectRaw(self::DIGITS.' AS digits')
                ->whereRaw(self::DIGITS." <> ''")
                ->groupByRaw(self::DIGITS)
                ->havingRaw('COUNT(*) > 1'),
            'duplicates',
        )->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "authentications tem {$duplicates} CPF(s) repetido(s), comparando só os dígitos, e o índice único não pode ser criado. ".
                "Liste-os com: SELECT regexp_replace(cpf, '\\D', '', 'g'), COUNT(*) FROM authentications WHERE regexp_replace(cpf, '\\D', '', 'g') <> '' GROUP BY 1 HAVING COUNT(*) > 1; ".
                'resolva cada um (ver UPGRADE.md) e rode a migration de novo. Nada foi alterado.'
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.Authentication::CPF_UNIQUE_INDEX.' ON authentications (('.self::DIGITS.')) WHERE '.self::DIGITS." <> ''");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.Authentication::CPF_UNIQUE_INDEX);
    }
};
