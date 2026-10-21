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
 * @property string $secret
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
        // Cifrado com a APP_KEY: é preciso o valor original para assinar, então
        // não dá para guardar só um hash.
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
