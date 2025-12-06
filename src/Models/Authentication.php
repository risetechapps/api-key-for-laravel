<?php

namespace RiseTechApps\ApiKey\Models;


use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use RiseTechApps\Address\Traits\HasAddress\HasAddress;
use RiseTechApps\ApiKey\Enums\BillingCycle;
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
        'email_verified_at',
        'status',
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
        $this->activePlan()?->update(['active' => false]);

        $userPlan =  UserPlan::create([
            'authentication_id' => $this->id,
            'plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => now()->addDays(BillingCycle::convertInDays($plan->billing_cycle)),
            'active' => true,
        ]);

        $this->apiKey->update(['active' => true]);

        return $userPlan;
    }

    public function userPlan(): HasMany
    {
        return $this->hasMany(UserPlan::class,);
    }

    public function activePlan(): HasOne
    {
        return $this->hasOne(UserPlan::class)
            ->where('active', true)
            ->where('end_date', '>=', now())
            ->latest();
    }

    public function hasModule($moduleName): bool
    {
        $userPlan = $this->activePlan()->with('plan.modules')->first();

        if (! $userPlan || ! $userPlan->plan) {
            return false;
        }

        return $userPlan->plan->modules()->where('module', $moduleName)->exists();
    }

    public function countUsed(): int
    {
        $plan = $this->activePlan()->first();

        return $plan?->requests_used ?? 0;
    }

    public function requestLimit(): int
    {
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
    public function requestUsed(int $status = 0): void
    {
        if (! $activePlan = $this->activePlan()->first()) {
            return;
        }

        $this->requestLog()->create([
            'endpoint' => request()->path(),
            'requested_at' => now(),
            'method' => request()->method(),
            'response_code' => $status
        ]);

        $activePlan->increment('requests_used');
    }
}
