<?php

namespace RiseTechApps\ApiKey\Models\UserCard;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;

/**
 * @property string $id
 * @property string $authentication_id
 * @property string $holder_name
 * @property string $last_four
 * @property string $brand
 * @property string $expiry_month
 * @property string $expiry_year
 * @property string|null $mp_customer_id
 * @property string|null $mp_card_id
 * @property bool $is_default
 * @property string|null $validation_payment_id
 * @property Carbon|null $validation_refunded_at
 * @property string|null $mp_payment_profile_id
 * @property string|null $mp_payment_profile_status
 * @property string $gateway
 * @property string|null $gateway_customer_id
 * @property string|null $gateway_card_id
 * @property string|null $gateway_first_charge_id
 * @property Authentication|null $authentication
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserCard extends Model
{
    protected $fillable = [
        'authentication_id',
        'holder_name',
        'last_four',
        'brand',
        'expiry_month',
        'expiry_year',
        'mp_customer_id',
        'mp_card_id',
        'is_default',
        'validation_payment_id',
        'validation_refunded_at',
        'mp_payment_profile_id',
        'mp_payment_profile_status',
        'gateway',
        'gateway_customer_id',
        'gateway_card_id',
        'gateway_first_charge_id',
    ];

    // Identificadores do gateway. GET /dashboard/cards serializa o model cru, então
    // sem isto eles iam para o navegador em toda listagem de cartões. Não são
    // credenciais — mover dinheiro no Mercado Pago exige o access token, que fica
    // no servidor —, mas são as referências usadas para tokenizar e cobrar o cartão
    // salvo, e nenhuma tela precisa delas.
    // `mp_card_id` NÃO entra aqui: o pagamento em um clique depende dele. O SDK
    // do Mercado Pago tokeniza um cartão salvo com
    // `createCardToken({ cardId, securityCode })`, no navegador do dono do
    // cartão — é o fluxo desenhado pelo próprio gateway. Escondê-lo fazia a
    // tela nunca reconhecer o cartão como vinculado e pedir o número completo a
    // cada compra, anulando o recurso.
    //
    // `mp_customer_id` continua oculto: identifica o cliente no gateway, não é
    // usado por nenhuma tela e a listagem serializa o model cru.
    protected $hidden = [
        'mp_customer_id',
        // Rastro interno da cobrança de validação: serve ao reprocessamento do
        // estorno, não à tela de cartões do cliente.
        'validation_payment_id',
        'validation_refunded_at',
        // A tela só precisa saber se o cartão já cobra sozinho
        // (`automatic_billing`), não qual é o perfil no gateway.
        'mp_payment_profile_id',
        'mp_payment_profile_status',
        // Pagar.me: o cartão é cobrado pelo servidor, com a secret key. A tela
        // não precisa de nenhum desses ids.
        'gateway_customer_id',
        'gateway_card_id',
        'gateway_first_charge_id',
    ];

    protected $appends = ['automatic_billing', 'usable'];

    // Sem o cast, o valor devolvido é o que o driver entregar (bool no
    // PostgreSQL, inteiro no SQLite dos testes) e isso vai direto para o JSON de
    // GET /dashboard/cards, que serializa o model cru. O cast fixa o contrato da
    // API em true/false independente do banco.
    protected $casts = [
        'is_default' => 'boolean',
        'validation_refunded_at' => 'datetime',
    ];

    /**
     * Cartões cuja cobrança de validação foi feita mas não estornada.
     *
     * É o conjunto que o `api-key:retry-validation-refunds` reprocessa: o
     * estorno é best-effort no cadastro, então uma falha de rede deixa o valor
     * cobrado no cartão do cliente até alguém devolver.
     */
    public function scopePendingValidationRefund($query)
    {
        return $query->whereNotNull('validation_payment_id')
            ->whereNull('validation_refunded_at');
    }

    /**
     * Se o cartão tem perfil de Pagamentos Automáticos pronto para cobrar sem
     * CVV. O painel usa para saber se ainda precisa mandar o token do perfil.
     */
    public function hasReadyPaymentProfile(): bool
    {
        return $this->mp_payment_profile_id !== null
            && strtoupper((string) $this->mp_payment_profile_status) === 'READY';
    }

    /**
     * Se a renovação cobra este cartão sozinha, sem CVV. Nos outros gateways
     * todo cartão salvo cobra assim; no Mercado Pago só com perfil de
     * Pagamentos Automáticos.
     */
    public function getAutomaticBillingAttribute(): bool
    {
        return isset(GatewayRegistry::DRIVERS[(string) $this->gateway])
            ? $this->gateway_card_id !== null
            : $this->hasReadyPaymentProfile();
    }

    /**
     * Torna este o cartão padrão da conta, o que a renovação cobra.
     *
     * Por query, nos dois passos: desmarcar todos e depois `update()` no
     * model não grava nada quando o model já estava como padrão na memória
     * (o Eloquent não vê mudança), e a conta ficava sem cartão padrão.
     */
    public function makeDefault(): void
    {
        static::where('authentication_id', $this->authentication_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_default' => false]);

        static::whereKey($this->getKey())->update(['is_default' => true]);

        $this->setAttribute('is_default', true)->syncOriginalAttribute('is_default');
    }

    /**
     * Cartões que o servidor pode cobrar: os do gateway ativo, com o cartão
     * guardado nele. Trocar de gateway tira de uso os cartões do anterior,
     * até o assinante cadastrar um no gateway novo.
     */
    public function scopeChargeable($query)
    {
        return $query->where('gateway', PaymentSettingsService::activeGateway())
            ->where(fn ($q) => $q->whereNotNull('mp_card_id')->orWhereNotNull('gateway_card_id'));
    }

    /**
     * O cartão que a cobrança automática usa: o padrão, se for do gateway
     * ativo; senão o mais recente do gateway ativo. Depois de trocar de
     * gateway o padrão pode ter ficado num cartão do anterior.
     */
    public static function forCharging(string $authenticationId): ?self
    {
        return static::where('authentication_id', $authenticationId)
            ->chargeable()
            ->orderByDesc('is_default')
            ->latest()
            ->first();
    }

    /** Se o cartão é do gateway ativo, o único que cobra (`usable` no JSON). */
    public function getUsableAttribute(): bool
    {
        return ($this->gateway ?? 'mercadopago') === PaymentSettingsService::activeGateway();
    }

    public function authentication()
    {
        return $this->belongsTo(Authentication::class);
    }
}
