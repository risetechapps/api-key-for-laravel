<?php

namespace RiseTechApps\ApiKey\Models\PendingPayment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\HasUuid\Traits\HasUuid;

/**
 * Compra que o gateway deixou em análise.
 *
 * Vive só entre a tentativa e o desfecho. Enquanto `settled_at` for nulo, existe
 * dinheiro em jogo sem assinatura correspondente — e é esse conjunto que o
 * webhook resolve e o `api-key:reconcile-payments` varre quando o webhook não
 * chega.
 *
 * @property string $id
 * @property string $authentication_id
 * @property string $plan_id
 * @property string $payment_id
 * @property string $amount
 * @property string|null $coupon_id
 * @property string|null $credit_applied
 * @property string $status
 * @property string|null $outcome
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Authentication|null $authentication
 * @property-read Plan|null $plan
 * @property-read Coupon|null $coupon
 */
class PendingPayment extends Model
{
    use HasUuid;

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_REJECTED = 'rejected';

    protected $fillable = [
        'authentication_id',
        'plan_id',
        'payment_id',
        'amount',
        'coupon_id',
        'credit_applied',
        'status',
        'outcome',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'credit_applied' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    /** @return BelongsTo<Authentication, $this> */
    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Ainda sem desfecho: o gateway não disse se aprovou ou recusou. */
    public function scopeUnsettled($query)
    {
        return $query->whereNull('settled_at');
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Registra o desfecho.
     *
     * Idempotente: uma reentrega do webhook, ou o comando de reconciliação
     * passando depois dele, não pode devolver a reserva do cupom duas vezes.
     */
    public function settle(string $outcome, string $status): bool
    {
        if ($this->isSettled()) {
            return false;
        }

        $this->update([
            'outcome' => $outcome,
            'status' => $status,
            'settled_at' => now(),
        ]);

        return true;
    }
}
