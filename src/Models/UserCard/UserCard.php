<?php

namespace RiseTechApps\ApiKey\Models\UserCard;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

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
    ];

    // Identificadores do gateway. GET /dashboard/cards serializa o model cru, então
    // sem isto eles iam para o navegador em toda listagem de cartões. Não são
    // credenciais — mover dinheiro no Mercado Pago exige o access token, que fica
    // no servidor —, mas são as referências usadas para tokenizar e cobrar o cartão
    // salvo, e nenhuma tela precisa delas.
    protected $hidden = [
        'mp_customer_id',
        'mp_card_id',
    ];

    // Sem o cast, o valor devolvido é o que o driver entregar (bool no
    // PostgreSQL, inteiro no SQLite dos testes) e isso vai direto para o JSON de
    // GET /dashboard/cards, que serializa o model cru. O cast fixa o contrato da
    // API em true/false independente do banco.
    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function authentication()
    {
        return $this->belongsTo(Authentication::class);
    }
}
