<?php

namespace RiseTechApps\ApiKey\Models\Webhook;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * @property string $id
 * @property string|null $webhook_endpoint_id null na entrega para URL avulsa (WebhookDispatcher::dispatchTo)
 * @property string|null $authentication_id conta dona da entrega
 * @property string|null $url destino da entrega para URL avulsa
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookEndpoint|null $endpoint
 * @property-read Authentication|null $authentication
 */
class WebhookDelivery extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $fillable = ['webhook_endpoint_id', 'authentication_id', 'url', 'event', 'payload', 'status', 'attempts', 'response_code', 'error', 'next_attempt_at', 'delivered_at'];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'response_code' => 'integer',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return BelongsTo<Authentication, $this> */
    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }

    /** Para onde a entrega vai: a URL do endpoint ou a URL avulsa. */
    public function targetUrl(): ?string
    {
        return $this->webhook_endpoint_id !== null ? $this->endpoint?->url : $this->url;
    }

    /** A conta que assina: a do endpoint ou a gravada na entrega avulsa. */
    public function owner(): ?Authentication
    {
        return $this->webhook_endpoint_id !== null ? $this->endpoint?->authentication : $this->authentication;
    }
}
