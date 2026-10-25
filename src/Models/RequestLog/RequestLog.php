<?php

namespace RiseTechApps\ApiKey\Models\RequestLog;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $authentication_id
 * @property string $endpoint
 * @property string $method
 * @property string $response_code
 * @property Carbon|null $requested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RequestLog extends Model
{
    use HasUuids;

    protected $fillable = ['authentication_id', 'endpoint', 'requested_at', 'method', 'response_code', 'ip'];

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
