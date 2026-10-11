<?php

namespace RiseTechApps\ApiKey\Models\AuditLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\HasUuid\Traits\HasUuid;

/**
 * @property string $id
 * @property string|null $actor_id
 * @property string|null $subject_id
 * @property string $action
 * @property array<string, mixed>|null $context
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read Authentication|null $actor
 * @property-read Authentication|null $subject
 */
class AuditLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'subject_id', 'action', 'context', 'ip', 'user_agent'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Authentication, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Authentication::class, 'actor_id')->withTrashed();
    }

    /** @return BelongsTo<Authentication, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Authentication::class, 'subject_id')->withTrashed();
    }
}
