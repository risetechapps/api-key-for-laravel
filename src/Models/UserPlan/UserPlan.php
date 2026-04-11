<?php

namespace RiseTechApps\ApiKey\Models\UserPlan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\HasUuid\Traits\HasUuid;

class UserPlan extends Model
{
    use HasUuid;

    protected $fillable = ['authentication_id', 'plan_id', 'start_date', 'end_date', 'active', 'requests_used'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'active' => 'boolean',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
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
    public function getGracePeriodEndDate(): ?\Carbon\Carbon
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

        if (!$graceEnd) {
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
        if (!$this->isInGracePeriod()) {
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

        if (!$graceEnd) {
            return $this->isExpired();
        }

        return now()->gt($graceEnd);
    }
}
