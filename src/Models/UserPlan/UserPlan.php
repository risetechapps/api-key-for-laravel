<?php

namespace RiseTechApps\ApiKey\Models\UserPlan;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\HasUuid\Traits\HasUuid;

/**
 * As colunas anotadas porque a análise estática não tem como descobrir o schema
 * de um model sozinha — sem isto todo acesso a property vira "undefined". Vale
 * também para o autocomplete da IDE.
 *
 * @property string $id
 * @property string $authentication_id
 * @property string $plan_id
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property bool $active
 * @property int $requests_used
 * @property string|null $payment_id
 * @property string|null $payment_amount
 * @property string|null $credit_applied
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Plan|null $plan
 * @property-read Authentication|null $authentication
 */
class UserPlan extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['authentication_id', 'plan_id', 'start_date', 'end_date', 'active', 'requests_used', 'payment_id', 'payment_amount', 'credit_applied', 'cancelled_at'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'cancelled_at' => 'datetime',
        'active' => 'boolean',
        'payment_amount' => 'decimal:2',
        'credit_applied' => 'decimal:2',
    ];

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Authentication, $this> */
    public function authentication(): BelongsTo
    {
        return $this->belongsTo(Authentication::class);
    }

    /**
     * The subscriber asked not to be charged again.
     *
     * Cancelling is not revoking: the period that was paid for keeps working to
     * its end_date, and only the automatic renewal is called off. Conflating the
     * two would mean a customer who cancels on day 2 of 30 loses 28 days they
     * already paid for.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Currency value of the days already paid for and not yet used.
     *
     * Switching plans mid-cycle runs through subscribeToPlan(), which deactivates
     * the current subscription and starts a fresh one — so a user who upgraded on
     * day 3 of 30 simply lost the 27 days they had paid for. This is what those
     * days are worth, to be credited against the new purchase.
     *
     * Prorated over the real length of *this* subscription rather than over the
     * plan's nominal cycle, so a period that was itself shortened or extended
     * (an earlier switch, a manual adjustment) is still measured correctly.
     *
     * Returns 0 when there is nothing to credit: free plan, inactive
     * subscription, or a period that has already run out. Timestamps are
     * compared as integers on purpose — Carbon's diff methods changed their
     * signed/absolute behaviour between major versions, and a sign flip here
     * would hand out credit for a period that already ended.
     */
    public function unusedCredit(): float
    {
        $price = (float) ($this->plan?->price ?? 0);

        if ($price <= 0 || ! $this->active || ! $this->start_date || ! $this->end_date) {
            return 0.0;
        }

        $now = now()->getTimestamp();
        $ends = $this->end_date->getTimestamp();

        // Expired, or inside the grace period: the paid window is over.
        if ($now >= $ends) {
            return 0.0;
        }

        $total = $ends - $this->start_date->getTimestamp();

        if ($total <= 0) {
            return 0.0;
        }

        $remaining = $ends - $now;

        // A start_date in the future would put the ratio above 1 and credit more
        // than was ever paid.
        return round($price * min(1.0, $remaining / $total), 2);
    }

    /**
     * Check if the plan is currently active (within valid date range and active flag).
     */
    public function isActive(): bool
    {
        return $this->active && now()->between($this->start_date, $this->end_date);
    }

    /**
     * Check if the plan has expired (end_date is in the past).
     */
    public function isExpired(): bool
    {
        return now()->gt($this->end_date);
    }

    /**
     * Get the grace period end date.
     * Returns null if grace period is disabled (0 days).
     */
    public function getGracePeriodEndDate(): ?Carbon
    {
        $graceDays = Config::get('api-key.grace_period_days', 3);

        if ($graceDays <= 0) {
            return null;
        }

        return $this->end_date->copy()->addDays($graceDays);
    }

    /**
     * Check if the plan is within the grace period.
     * Returns false if grace period is disabled or plan is still active.
     */
    public function isInGracePeriod(): bool
    {
        if ($this->isActive()) {
            return false;
        }

        $graceEnd = $this->getGracePeriodEndDate();

        if (! $graceEnd) {
            return false;
        }

        return now()->between($this->end_date, $graceEnd);
    }

    /**
     * Check if the plan is active OR in grace period.
     * This is the main method to check if user can access the service.
     */
    public function isActiveOrInGracePeriod(): bool
    {
        return $this->isActive() || $this->isInGracePeriod();
    }

    /**
     * Get the remaining days in grace period.
     * Returns 0 if not in grace period or grace period is disabled.
     */
    public function getGracePeriodRemainingDays(): int
    {
        if (! $this->isInGracePeriod()) {
            return 0;
        }

        $graceEnd = $this->getGracePeriodEndDate();

        return now()->diffInDays($graceEnd, false);
    }

    /**
     * Check if the plan is completely expired (past grace period).
     */
    public function isCompletelyExpired(): bool
    {
        if ($this->isActive()) {
            return false;
        }

        $graceEnd = $this->getGracePeriodEndDate();

        if (! $graceEnd) {
            return $this->isExpired();
        }

        return now()->gt($graceEnd);
    }
}
