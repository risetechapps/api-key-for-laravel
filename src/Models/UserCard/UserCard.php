<?php

namespace RiseTechApps\ApiKey\Models\UserCard;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

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
    ];

    // Identificadores do gateway. GET /dashboard/cards serializa o model cru, então
    // sem isto eles iam para o navegador em toda listagem de cartões. Não são
    // credenciais — mover dinheiro no Mercado Pago exige o access token, que fica
    // no servidor —, mas são as referências usadas para tokenizar e cobrar o cartão
    // salvo, e nenhuma tela precisa delas.
    protected $hidden = [
        'mp_customer_id',
        'mp_card_id',
        // Rastro interno da cobrança de validação: serve ao reprocessamento do
        // estorno, não à tela de cartões do cliente.
        'validation_payment_id',
        'validation_refunded_at',
    ];

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

    public function authentication()
    {
        return $this->belongsTo(Authentication::class);
    }
}
