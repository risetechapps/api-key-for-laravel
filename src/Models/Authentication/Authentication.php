<?php

namespace RiseTechApps\ApiKey\Models\Authentication;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use RiseTechApps\Address\Traits\HasAddress\HasAddress;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\UserStatusChanged;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\EmailVerifyNotification;
use RiseTechApps\ApiKey\Notifications\ResetPasswordNotification;
use RiseTechApps\ApiKey\Services\AuthService;
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
 * @property string $password
 * @property string|null $pending_email
 * @property Carbon|null $trial_used_at
 * @property bool $overage_enabled
 * @property string|null $overage_spend_cap
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
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
 * @property string|null $account_id dono da conta, quando é membro da equipe
 * @property string|null $team_role admin ou agent (ver Support\Team)
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ApiKey|null $apiKey
 * @property-read UserPlan|null $activePlan
 * @property-read Authentication|null $account
 */
class Authentication extends Authenticatable implements HasLocalePreference, MediaContract
{
    use HasAddress, HasApiTokens, HasCodeGenerate, MustVerifyEmail;
    use HasFactory, HasToUpper, HasUuid, Notifiable, SoftDeletes;
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
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'status' => 'string',
        'role' => 'string',
        'password' => 'hashed',
        'trial_used_at' => 'datetime',
        'overage_enabled' => 'boolean',
        'overage_spend_cap' => 'decimal:2',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    // HasToUpper normaliza strings para maiúsculo, mas estes são identificadores /
    // vocabulário controlado comparados com constantes lowercase
    // (AuthService::$ENABLE = 'enabled', $CLIENT = 'client') e com o e-mail de
    // login. Uppercase aqui violaria o enum de status/role (CHECK/enum no banco) e
    // quebraria o match — inclusive em produção quando o status é alterado.
    protected array $no_upper = ['email', 'pending_email', 'status', 'role', 'team_role', 'external_id', 'locale', 'two_factor_secret', 'two_factor_recovery_codes'];

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

    /**
     * Se a conta pode entrar no painel e usar a API.
     *
     * Null conta como habilitada: é o default da coluna, e contas criadas antes
     * de o status ser checado não têm valor gravado.
     */
    public function isEnabled(): bool
    {
        return $this->status === null || $this->status === AuthService::$ENABLE;
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
        $this->notify(new $notification);
    }

    #[\Override]
    public function sendPasswordResetNotification($token): void
    {
        $notification = Config::get('api-key.notifications.reset_password', ResetPasswordNotification::class);
        $this->notify(new $notification($token));
    }

    /**
     * Chave principal: a mais antiga da conta.
     *
     * Continua existindo depois das várias chaves por conta para que tudo que
     * fala em "a chave do usuário" (perfil, regenerar, origens) siga
     * funcionando. A requisição autenticada por API key troca esta relação pela
     * chave usada de fato (AuthenticateApiKey).
     *
     * @return HasOne<ApiKey, $this>
     */
    // ── Equipe ───────────────────────────────────────────────────────────────
    // `account_id` e `team_role` ficam fora do $fillable: só o fluxo de convite
    // (TeamService) os grava, com forceFill.

    /**
     * Dono da conta, quando esta Authentication é membro da equipe dele.
     *
     * @return BelongsTo<Authentication, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::class, 'account_id');
    }

    /** @return HasMany<Authentication, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(self::class, 'account_id');
    }

    /** Membro criado por integração: sem login aqui (ver TeamService::provisionExternal). */
    public function isExternal(): bool
    {
        return $this->external_id !== null;
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function teamInvitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class, 'account_id');
    }

    public function isTeamMember(): bool
    {
        return $this->account_id !== null;
    }

    /** A conta em nome de quem age: o dono, para membros; ela mesma, para o dono. */
    public function owningAccount(): self
    {
        return $this->isTeamMember() ? ($this->account ?? $this) : $this;
    }

    /** Membros que o plano ativo inclui (0 = só o dono). */
    public function teamMembersLimit(): int
    {
        return (int) ($this->activePlan?->plan->team_members_limit ?? 0);
    }

    public function apiKey(): HasOne
    {
        // Ordenação simples, e não oldestOfMany(): esse desempata com MAX(id),
        // e o PostgreSQL não tem MAX para uuid. No eager load o HasOne fica com
        // a primeira linha de cada dono, que pela ordem é a mais antiga.
        return $this->hasOne(ApiKey::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class)->orderBy('created_at');
    }

    /**
     * Inicia uma assinatura nova, encerrando a que estiver rodando.
     *
     * `$attributes` grava o rastro do pagamento (payment_id, payment_amount,
     * credit_applied) no mesmo INSERT da assinatura. Gravar depois, num update
     * separado, abria uma janela em que a assinatura existia sem payment_id — e
     * o webhook do mesmo pagamento, que chega segundos depois, não a reconhecia
     * e assinava de novo.
     *
     * Com payment_id informado a chamada é idempotente: checkout, webhook e
     * reconciliação podem confirmar o mesmo pagamento em qualquer ordem, e só o
     * primeiro assina. Os demais recebem a assinatura existente, com
     * `wasRecentlyCreated` falso, e completam nela só os campos que faltarem.
     *
     * Tudo roda numa transação com a linha do usuário travada, o que serializa
     * assinaturas concorrentes do mesmo usuário. O evento sai depois do commit.
     */
    public function subscribeToPlan(Plan $plan, array $attributes = []): UserPlan
    {
        $paymentId = isset($attributes['payment_id']) ? (string) $attributes['payment_id'] : null;

        if ($paymentId !== null) {
            $attributes['payment_id'] = $paymentId;
        }

        [$userPlan, $previousPlan] = DB::transaction(function () use ($plan, $attributes, $paymentId): array {
            static::withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($paymentId !== null) {
                $existing = UserPlan::where('authentication_id', $this->getKey())
                    ->where('payment_id', $paymentId)
                    ->first();

                if ($existing) {
                    $missing = array_filter(
                        $attributes,
                        fn ($value, $key) => $existing->getAttribute($key) === null && $value !== null,
                        ARRAY_FILTER_USE_BOTH
                    );

                    if ($missing) {
                        $existing->update($missing);
                    }

                    return [$existing, null];
                }
            }

            $previousPlan = $this->activePlan()->with('plan')->first()?->plan;

            // Todas as ativas, e não só a do activePlan(): uma assinatura em
            // carência (end_date já passou) também está ativa, e ficava ligada
            // ao lado da nova — a renovação, que roda justamente perto do fim do
            // período, deixava duas assinaturas ativas para o mesmo usuário.
            $this->userPlan()->where('active', true)->update(['active' => false]);

            // end_date pode vir de fora (período de teste); o resto é sempre
            // decidido aqui.
            $endDate = $attributes['end_date'] ?? now()->addDays(BillingCycle::convertInDays($plan->billing_cycle));
            unset($attributes['end_date']);

            $userPlan = UserPlan::create([
                ...$attributes,
                'authentication_id' => $this->id,
                'plan_id' => $plan->id,
                'start_date' => now(),
                'end_date' => $endDate,
                'active' => true,
            ]);

            $apiKey = $this->apiKey()->first();

            if ($apiKey) {
                // Todas as chaves voltam: o vencimento desativou todas. Uma a
                // uma, e não num UPDATE só, para o ApiKeyStatusChanged sair.
                $this->apiKeys()->where('active', false)->get()->each->update(['active' => true]);
                $apiKey->refresh();
            } else {
                // Key will be automatically hashed by the model's boot method
                $apiKey = $this->apiKey()->create([
                    'name' => 'Principal',
                    'key' => bin2hex(random_bytes(64)),
                    'active' => true,
                ]);
            }

            $this->setRelation('apiKey', $apiKey);

            return [$userPlan, $previousPlan];
        });

        $userPlan->load(['plan']);
        $this->unsetRelation('activePlan');

        if ($userPlan->wasRecentlyCreated) {
            PlanChanged::dispatch($this, $plan, $userPlan, $previousPlan);
        }

        return $userPlan;
    }

    /** @return HasMany<UserPlan, $this> */
    public function userPlan(): HasMany
    {
        return $this->hasMany(UserPlan::class);
    }

    /**
     * Get the currently active plan (within valid date range).
     * Note: This does not consider grace period. Use activePlanWithGracePeriod() for that.
     */
    /** @return HasOne<UserPlan, $this> */
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
    /** @return HasOne<UserPlan, $this> */
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

    public function requestLog(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    /**
     * Log request usage and increment counter.
     *
     * @param  int  $status  Código HTTP da resposta.
     * @param  bool  $countUsage  Se a requisição deve contar na cota do plano.
     *                            O CheckRequestLimitMiddleware já reserva a cota
     *                            de forma atômica antes de processar a requisição,
     *                            então ele passa false e usa este método apenas
     *                            para o log. Mantido para chamadas fora do ciclo
     *                            de request.
     * @param  string|null  $planId  Plano ativo já resolvido pelo middleware. Quando
     *                               informado, evita reconsultar user_plans.
     * @param  string|null  $endpoint  Caminho da requisição. Passado explicitamente
     *                                 porque este método roda depois da resposta, onde
     *                                 depender do helper request() é frágil.
     * @param  string|null  $method  Método HTTP da requisição.
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

        if (! $activePlanId) {
            return;
        }

        RequestLog::create([
            'authentication_id' => $this->id,
            'endpoint' => $endpoint ?? request()->path(),
            'requested_at' => now(),
            'method' => $method ?? request()->method(),
            'response_code' => $status,
        ]);

        if (! $countUsage) {
            return;
        }

        // Atomic increment using update to avoid race conditions
        UserPlan::where('id', $activePlanId)->increment('requests_used');
    }
}
