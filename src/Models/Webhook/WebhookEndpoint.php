<?php

namespace RiseTechApps\ApiKey\Models\Webhook;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * @property string $id
 * @property string $authentication_id
 * @property string $url
 * @property string|null $secret obsoleto desde a 1.3.0: as entregas usam Authentication::webhookSecret()
 * @property array<int, string>|null $events
 * @property bool $active
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEndpoint extends Model
{
    use HasUuids;

    protected $fillable = ['authentication_id', 'url', 'secret', 'events', 'active', 'consecutive_failures', 'disabled_at'];

    protected $hidden = ['secret'];

    protected $casts = [
        // Obsoleto: a coluna fica só para o down() da migração 1.3.0. Todas as
        // entregas são assinadas com o segredo da conta.
        'secret' => 'encrypted',
        'events' => 'array',
        'active' => 'boolean',
        'consecutive_failures' => 'integer',
        'disabled_at' => 'datetime',
    ];

    public function listensTo(string $event): bool
    {
        return $this->events === null || $this->events === [] || in_array($event, $this->events, true);
    }

    /** @return BelongsTo<Authentication, $this> */
    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
