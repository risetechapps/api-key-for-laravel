<?php

namespace RiseTechApps\ApiKey\Models\RequestLog;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;

/**
 * @property string $id
 * @property string $authentication_id
 * @property string|null $api_key_id sem FK: continua apontando para a chave depois de excluída
 * @property string $endpoint
 * @property string $method
 * @property string $response_code
 * @property string|null $ip
 * @property Carbon|null $requested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ApiKey|null $apiKey
 */
class RequestLog extends Model
{
    use HasUuids;

    protected $fillable = ['authentication_id', 'api_key_id', 'endpoint', 'requested_at', 'method', 'response_code', 'ip'];

    /**
     * Sem o cast, requested_at era serializado como string "crua" do banco
     * (ex.: "2026-06-07 20:00:00"), sem fuso. No front, new Date() interpretava
     * isso como horário local do navegador, gerando deslocamento de horas.
     * Com o cast, vira ISO8601 com timezone e o navegador converte corretamente.
     */
    protected $casts = [
        'requested_at' => 'datetime',
    ];

    /** Null quando a chave foi excluída (o `api_key_id` continua no log). */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
