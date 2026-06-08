<?php

namespace RiseTechApps\ApiKey\Models\RequestLog;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\HasUuid\Traits\HasUuid;

class RequestLog extends Model
{
    use HasUuid;

    protected $fillable = ['authentication_id', 'endpoint', 'requested_at', 'method', 'response_code'];

    /**
     * Sem o cast, requested_at era serializado como string "crua" do banco
     * (ex.: "2026-06-07 20:00:00"), sem fuso. No front, new Date() interpretava
     * isso como horário local do navegador, gerando deslocamento de horas.
     * Com o cast, vira ISO8601 com timezone e o navegador converte corretamente.
     */
    protected $casts = [
        'requested_at' => 'datetime',
    ];
}
