<?php

namespace RiseTechApps\ApiKey\Models\Authentication;


use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\HasApiTokens;
use RiseTechApps\Address\Traits\HasAddress\HasAddress;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\UserStatusChanged;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\EmailVerifyNotification;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\Media\Models\Media;
use RiseTechApps\Media\Traits\HasConversionsMedia\HasConversionsMedia;
use RiseTechApps\ToUpper\Traits\HasToUpper;
use Spatie\MediaLibrary\HasMedia;

class Authentication extends Authenticatable implements HasLocalePreference, HasMedia
{

    use SoftDeletes, HasUuid, HasToUpper, Notifiable;
    use MustVerifyEmail, HasApiTokens, HasAddress, HasCodeGenerate;
    use HasConversionsMedia;

    protected $fillable = [
        'code',
        'name',
        'rg',
        'cpf',
        'birth_date',
        'telephone',
        'cellphone',
        'nationality',
        'naturalness',
        'marital_status',
        'email',
        'password',
        'locale',
    ];

    protected $guarded = [
        'email_verified_at',
        'status',
        'role',
    ];

    protected $hidden = [
        'id',
        'code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'status' => 'string',
        'role' => 'string',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::updated(function ($user) {
            // Fire event when user status changes
            if ($user->wasChanged('status')) {
                UserStatusChanged::dispatch(
                    $user,
                    $user->getOriginal('status'),
                    $user->status
                );
            }
        });
    }

    public function preferredLocale()
    {
        return $this->locale;
    }

    public function getPhotoProfile(): ?Media
    {
        try {
            $photo = $this->getMedia('profile')->first();

            if (is_null($photo)) {
                return null;
            }

            return $photo;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new EmailVerifyNotification());
    }

    public function apiKey(): HasOne
    {
        return $this->hasOne(ApiKey::class);
    }

    public function subscribeToPlan(Plan $plan)
    {
        $previousPlan = $this->activePlan?->plan;

        $this->activePlan()?->update(['active' => false]);

        $userPlan = UserPlan::create([
            'authentication_id' => $this->id,
            'plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => now()->addDays(BillingCycle::convertInDays($plan->billing_cycle)),
            'active' => true,
        ]);

        if ($this->apiKey) {
            $this->apiKey->update(['active' => true]);
        } else {
            // Key will be automatically hashed by the model's boot method
            $this->apiKey()->create([
                'key' => bin2hex(random_bytes(64)),
                'active' => true,
            ]);
        }

        $userPlan->load(['plan']);

        // Fire PlanChanged event
        PlanChanged::dispatch($this, $plan, $userPlan, $previousPlan);

        return $userPlan;
    }

    public function userPlan(): HasMany
    {
        return $this->hasMany(UserPlan::class,);
    }

    /**
     * Get the currently active plan (within valid date range).
     * Note: This does not consider grace period. Use activePlanWithGracePeriod() for that.
     */
    public function activePlan(): HasOne
    {
        return $this->hasOne(UserPlan::class)
            ->where('active', true)
            ->where('end_date', '>=', now())
            ->latest();
    }

    /**
     * Get the active plan including grace period.
     * This returns plans that are either:
     * - Currently active (within date range)
     * - Expired but within grace period
     */
    public function activePlanWithGracePeriod(): HasOne
    {
        $graceDays = Config::get('api-key.grace_period_days', 3);

        return $this->hasOne(UserPlan::class)
            ->where('active', true)
            ->where('end_date', '>=', now()->subDays($graceDays))
            ->latest();
    }

    /**
     * Check if user has an active plan (including grace period).
     */
    public function hasActivePlan(): bool
    {
        return $this->activePlanWithGracePeriod()->exists();
    }

    /**
     * Check if user's plan is in grace period.
     */
    public function isInGracePeriod(): bool
    {
        $plan = $this->activePlanWithGracePeriod()->first();

        return $plan?->isInGracePeriod() ?? false;
    }

    /**
     * Scope to eager load active plan with plan relationship.
     * Use this when querying multiple users to avoid N+1.
     */
    public function scopeWithActivePlan($query)
    {
        return $query->with(['activePlan.plan']);
    }

    /**
     * Get cached count of used requests for the active plan.
     * Uses eager loaded data if available.
     */
    public function countUsed(): int
    {
        // Check if already eager loaded
        if ($this->relationLoaded('activePlan')) {
            return $this->activePlan?->requests_used ?? 0;
        }

        $plan = $this->activePlan()->first();

        return $plan?->requests_used ?? 0;
    }

    /**
     * Get request limit from the active plan.
     * Uses eager loaded data if available.
     */
    public function requestLimit(): int
    {
        // Check if already eager loaded
        if ($this->relationLoaded('activePlan')) {
            return (int) ($this->activePlan?->plan?->request_limit ?? 0);
        }

        $plan = $this->activePlan()->with('plan')->first();

        if ($plan && $plan->plan) {
            return (int) $plan->plan->request_limit;
        }

        return 0;
    }

    public function requestLog(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    /**
     * Log request usage and increment counter.
     * Optimized to use update for counter to avoid race conditions.
     */
    public function requestUsed(int $status = 0): void
    {
        // Use a single query to get active plan ID without loading full model
        $activePlanId = UserPlan::where('authentication_id', $this->id)
            ->where('active', true)
            ->where('end_date', '>=', now())
            ->value('id');

        if (!$activePlanId) {
            return;
        }

        // Use insert for better performance (no model events)
        RequestLog::insert([
            'authentication_id' => $this->id,
            'endpoint' => request()->path(),
            'requested_at' => now(),
            'method' => request()->method(),
            'response_code' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Atomic increment using update to avoid race conditions
        UserPlan::where('id', $activePlanId)->increment('requests_used');
    }
}
