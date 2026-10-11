<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use Throwable;

/**
 * Direitos do titular sobre a própria conta (LGPD, art. 18): exportar os dados
 * e excluir a conta.
 */
class AccountService
{
    /**
     * Campos de identificação apagados na exclusão.
     */
    private const PERSONAL_FIELDS = [
        'code', 'rg', 'cpf', 'birth_date', 'telephone', 'cellphone',
        'nationality', 'naturalness', 'marital_status', 'locale',
    ];

    /**
     * Exclui a conta.
     *
     * Anonimiza em vez de apagar a linha. As assinaturas pagas ficam, porque
     * são registro de pagamento que a lei manda guardar (art. 16, I) e o que
     * sustenta um estorno ou uma contestação depois; o vínculo delas passa a
     * ser com uma conta sem nenhum dado pessoal. O e-mail vira um endereço
     * inválido e único, o que libera o original para um cadastro novo.
     *
     * Sai tudo o que identifica ou dá acesso: dados de perfil, endereço, foto,
     * cartões salvos, log de requisições, API key e tokens. A assinatura em
     * curso é encerrada na hora, o que também impede a renovação de cobrar.
     */
    public function delete(Authentication $user): void
    {
        // Lidos antes: a linha local some na transação, e é ela que tem os ids
        // do cartão e do perfil no gateway.
        $cards = UserCard::where('authentication_id', $user->getKey())->get();

        DB::transaction(function () use ($user) {
            UserPlan::where('authentication_id', $user->getKey())
                ->where('active', true)
                ->update(['active' => false, 'cancelled_at' => now()]);

            UserCard::where('authentication_id', $user->getKey())->delete();
            RequestLog::where('authentication_id', $user->getKey())->delete();

            $user->apiKey()->delete();
            $user->tokens()->delete();
            $user->address()->delete();

            $user->forceFill([
                ...array_fill_keys(self::PERSONAL_FIELDS, null),
                'name' => 'Conta excluída',
                'email' => 'deleted+'.$user->getKey().'@invalid.invalid',
                'password' => Hash::make(Str::random(64)),
                'status' => AuthService::$DISABLE,
                'email_verified_at' => null,
            ])->save();

            $user->delete();
        });

        // Fora da transação: apaga arquivo em disco, que não volta num rollback.
        // Falhar aqui não deve desfazer a exclusão já gravada.
        try {
            $user->clearMediaCollection('profile');
        } catch (Throwable $e) {
            report($e);
        }

        // Também depois do commit, e pelo mesmo motivo: o gateway não volta
        // num rollback. Cada falha fica no log, sem desfazer a exclusão.
        foreach ($cards as $card) {
            try {
                app(MpCustomerService::class)->removeCard($card);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Todos os dados da conta, em JSON, escritos direto na saída.
     *
     * O log de requisições pode ter centenas de milhares de linhas, então vai
     * por cursor, sem montar o array inteiro na memória.
     */
    public function streamExport(Authentication $user): void
    {
        $user->loadMissing(['address', 'apiKey']);

        $profile = [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            ...$user->only(self::PERSONAL_FIELDS),
            'status' => $user->status,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];

        $address = $user->address?->only([
            'zip_code', 'address', 'number', 'complement', 'district', 'city', 'state', 'country',
        ]);

        $apiKey = $user->apiKey ? [
            'active' => (bool) $user->apiKey->active,
            'allowed_origins' => $user->apiKey->allowed_origins,
            'created_at' => $user->apiKey->created_at?->toIso8601String(),
        ] : null;

        $subscriptions = UserPlan::with('plan')
            ->where('authentication_id', $user->getKey())
            ->orderBy('start_date')
            ->get()
            ->map(fn (UserPlan $p) => [
                'plan' => $p->plan?->name,
                'start_date' => $p->start_date?->toIso8601String(),
                'end_date' => $p->end_date?->toIso8601String(),
                'active' => (bool) $p->active,
                'payment_id' => $p->payment_id,
                'payment_amount' => $p->payment_amount,
                'credit_applied' => $p->credit_applied,
                'cancelled_at' => $p->cancelled_at?->toIso8601String(),
                'refunded_at' => $p->refunded_at?->toIso8601String(),
            ]);

        $cards = UserCard::where('authentication_id', $user->getKey())
            ->get(['holder_name', 'brand', 'last_four', 'expiry_month', 'expiry_year', 'created_at'])
            ->map(fn (UserCard $c) => [
                'holder_name' => $c->holder_name,
                'brand' => $c->brand,
                'last_four' => $c->last_four,
                'expiry' => sprintf('%02d/%s', (int) $c->expiry_month, $c->expiry_year),
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        echo '{"exported_at":'.json_encode(now()->toIso8601String(), $flags);
        echo ',"profile":'.json_encode($profile, $flags);
        echo ',"address":'.json_encode($address, $flags);
        echo ',"api_key":'.json_encode($apiKey, $flags);
        echo ',"subscriptions":'.json_encode($subscriptions, $flags);
        echo ',"cards":'.json_encode($cards, $flags);
        echo ',"request_logs":[';

        $first = true;

        foreach (RequestLog::where('authentication_id', $user->getKey())->orderBy('requested_at')->orderBy('id')->lazy(1000) as $log) {
            echo ($first ? '' : ',').json_encode([
                'endpoint' => $log->endpoint,
                'method' => $log->method,
                'response_code' => $log->response_code,
                'requested_at' => $log->requested_at?->toIso8601String(),
            ], $flags);

            $first = false;
        }

        echo ']}';
    }
}
