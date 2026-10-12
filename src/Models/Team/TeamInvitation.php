<?php

namespace RiseTechApps\ApiKey\Models\Team;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\HasUuid\Traits\HasUuid;

/**
 * @property string $id
 * @property string $account_id
 * @property string|null $invited_by
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Authentication|null $account
 *
 * Convite para a equipe de uma conta. O link leva um token aleatório; aqui
 * fica só o hash (quem lê o banco não consegue aceitar o convite).
 */
class TeamInvitation extends Model
{
    use HasUuid;

    protected $table = 'team_invitations';

    protected $fillable = ['account_id', 'invited_by', 'name', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /** @return BelongsTo<Authentication, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Authentication::class, 'account_id');
    }

    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', self::hashToken($token))->first();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
