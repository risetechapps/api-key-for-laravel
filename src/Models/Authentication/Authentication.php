<?php

namespace RiseTechApps\ApiKey\Models\Authentication;


use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
use RiseTechApps\ApiKey\Notifications\ResetPasswordNotification;
use RiseTechApps\CodeGenerate\Traits\HasCodeGenerate;
use RiseTechApps\HasUuid\Traits\HasUuid;
use RiseTechApps\Media\Contracts\MediaContract;
use RiseTechApps\Media\Models\Media;
use RiseTechApps\Media\Traits\HasMediaSuite\HasMediaSuite;
use RiseTechApps\ToUpper\Traits\HasToUpper;

/**
 * @property string $id
 * @property string|null $code
 * @property string $name
 * @property string $email
 * @property string|null $rg
 * @property string|null $cpf
 * @property string|null $birth_date
 * @property string|null $telephone
 * @property string|null $cellphone
 * @property string|null $nationality
 * @property string|null $naturalness
 * @property string|null $marital_status
 * @property string|null $locale
 * @property string|null $status
 * @property string|null $role
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read ApiKey|null $apiKey
 * @property-read UserPlan|null $activePlan
 */
class Authentication extends Authenticatable implements HasLocalePreference, MediaContract
{

    use HasFactory, SoftDeletes, HasUuid, HasToUpper, Notifiable;
    use MustVerifyEmail, HasApiTokens, HasAddress, HasCodeGenerate;
    use HasMediaSuite;

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

    // No $guarded here on purpose: Eloquent honours $fillable and ignores
    // $guarded when both are set, so listing email_verified_at / status / role
    // there read as protection that was never in effect. They are protected by
    // their absence from $fillable, and must only be set through forceFill(),
    // markEmailAsVerified() or an explicit assignment.

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

    // HasToUpper normaliza strings para maiúsculo, mas estes são identificadores /
    // vocabulário controlado comparados com constantes lowercase
    // (AuthService::$ENABLE = 'enabled', $CLIENT = 'client') e com o e-mail de
    // login. Uppercase aqui violaria o enum de status/role (CHECK/enum no banco) e
    // quebraria o match — inclusive em produção quando o status é alterado.
    protected array $no_upper = ['email', 'status', 'role', 'locale'];

    #[\Override]
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
        } catch (\Exception) {
            return null;
        }
    }

    #[\Override]
    public function sendEmailVerificationNotification(): void
    {
        $notification = Config::get('api-key.notifications.email_verify', EmailVerifyNotification::class);
        $this->notify(new $notification());
    }

    #[\Override]
    public function sendPasswordResetNotification($token): void
    {
        $notification = Config::get('api-key.notifications.reset_password', ResetPasswordNotification::class);
        $this->notify(new $notification($token));
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
     *
     * @param int         $status     Código HTTP da resposta.
     * @param bool        $countUsage Se a requisição deve contar na cota do plano.
     *                                O CheckRequestLimitMiddleware já reserva a cota
     *                                de forma atômica antes de processar a requisição,
     *                                então ele passa false e usa este método apenas
     *                                para o log. Mantido para chamadas fora do ciclo
     *                                de request.
     * @param string|null $planId     Plano ativo já resolvido pelo middleware. Quando
     *                                informado, evita reconsultar user_plans.
     * @param string|null $endpoint   Caminho da requisição. Passado explicitamente
     *                                porque este método roda depois da resposta, onde
     *                                depender do helper request() é frágil.
     * @param string|null $method     Método HTTP da requisição.
     */
    public function requestUsed(
        int $status = 0,
        bool $countUsage = true,
        ?string $planId = null,
        ?string $endpoint = null,
        ?string $method = null
    ): void {
        $activePlanId = $planId ?? UserPlan::where('authentication_id', $this->id)
            ->where('active', true)
            ->where('end_date', '>=', now())
            ->value('id');

        if (!$activePlanId) {
            return;
        }

        RequestLog::create([
            'authentication_id' => $this->id,
            'endpoint' => $endpoint ?? request()->path(),
            'requested_at' => now(),
            'method' => $method ?? request()->method(),
            'response_code' => $status,
        ]);

        if (!$countUsage) {
            return;
        }

        // Atomic increment using update to avoid race conditions
        UserPlan::where('id', $activePlanId)->increment('requests_used');
    }
}
