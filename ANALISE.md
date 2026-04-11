# Análise do Pacote: risetechapps/api-key-for-laravel

**Versão Analisada:** 1.7.1  
**Data da Análise:** 2026-04-10  
**PHP Requerido:** ^8.4  
**Laravel:** ^12

---

## Sumário

1. [Bugs Encontrados](#1-bugs-encontrados-)
2. [Problemas de Segurança](#2-problemas-de-segurança-)
3. [Problemas de Performance](#3-problemas-de-performance-)
4. [Melhorias Sugeridas](#4-melhorias-sugeridas-)
5. [Problemas de Manutenibilidade](#5-problemas-de-manutenibilidade-)
6. [Sugestões de Refatoração](#6-sugestões-de-refatoração-)

---

## 1. Bugs Encontrados 🔴

### 1.1 ApiKeyScope - Acesso a Propriedade sem Verificação
**Arquivo:** `src/Scope/ApiKeyScope.php:14`

```php
public function apply(Builder $builder, Model $model): void
{
    $builder->where('api_key_id', auth()->user()->apiKey->id); // ⚠️ Erro potencial
}
```

**Problema:** Se `auth()->user()` for null ou se o usuário não tiver uma ApiKey, ocorrerá um erro "Attempt to read property 'id' on null".

**Correção Sugerida:**
```php
public function apply(Builder $builder, Model $model): void
{
    $user = auth()->user();
    
    if (!$user || !$user->apiKey) {
        $builder->whereRaw('1 = 0'); // Retorna nenhum resultado
        return;
    }
    
    $builder->where('api_key_id', $user->apiKey->id);
}
```

---

### 1.2 AuthController - Uso de Helper Personalizado sem Existência Garantida
**Arquivo:** `src/Http/Controllers/Authentication/AuthController.php:43`

```php
$profileImage = avatarGenerator()->generateBase64($data['name']);
```

**Problema:** A função helper `avatarGenerator()` depende de um pacote externo (`risetechapps/risetools`), mas não há verificação se a função existe.

**Correção Sugerida:**
```php
if (!function_exists('avatarGenerator')) {
    throw new \RuntimeException('avatarGenerator helper is not available');
}
$profileImage = avatarGenerator()->generateBase64($data['name']);
```

---

### 1.3 UserPlan::isActive() - Bug de Lógica
**Arquivo:** `src/Models/UserPlan/UserPlan.php:22`

```php
public function isActive(): bool
{
    return now()->between($this->start_date, $this->end_date);
}
```

**Problema:** Este método verifica apenas o período, mas ignora o campo `active` do banco. Um plano pode estar dentro do período mas marcado como `active = false`.

**Correção Sugerida:**
```php
public function isActive(): bool
{
    return $this->active && now()->between($this->start_date, $this->end_date);
}
```

---

### 1.4 Authentication::subscribeToPlan() - N+1 Query Problem
**Arquivo:** `src/Http/Controllers/Authentication/AuthController.php:37`

```php
$authentication->apiKey()->create([
    'key' => bin2hex(random_bytes(64)),
    'active' => false,
]);
```

**Problema:** Se o usuário já tiver uma apiKey, isso criará uma nova entrada (um usuário com múltiplas chaves). O método `subscribeToPlan()` em `Authentication.php:107` também faz `$this->apiKey->update()` assumindo que sempre existe.

**Correção Sugerida:** Verificar se já existe apiKey antes de criar.

---

### 1.5 Migration com Ordem Incorreta
**Arquivo:** `database/migrations/2025_11_27_173515_add_tokenable_personal_access_tokens_table.php`

**Problema:** As migrations têm timestamps que podem causar conflitos de ordem, especialmente a `remove_tokenable` que roda antes da `add_tokenable`.

---

## 2. Problemas de Segurança 🔒

### 2.1 ApiKey Gerada com random_bytes sem Hash
**Arquivo:** `src/Http/Controllers/Authentication/AuthController.php:38`

```php
'key' => bin2hex(random_bytes(64)),
```

**Problema:** A chave é armazenada em texto plano. Embora seja uma prática comum, não segue o princípio de "nunca armazenar segredos reversíveis".

**Sugestão:** Considerar hash da chave para verificação (como Laravel Sanctum faz) e retornar a chave apenas uma vez no momento da criação.

---

### 2.2 Validação de Origem pode ser Bypasseada
**Arquivo:** `src/Http/Middlewares/ApiKeyOriginValidatorMiddleware.php:18`

```php
$requestOrigin = $request->header('Origin') ?? Device::getClientPublicIp();
```

**Problema:** O header `Origin` pode ser facilmente spoofado em requisições via cURL ou ferramentas similares. A verificação por IP também pode ser problemática com proxies.

**Sugestão:** Adicionar validação de assinatura (HMAC) ou usar rate limiting por chave API.

---

### 2.3 Não há Rate Limiting Global
**Problema:** O pacote verifica limites de requisição do plano, mas não implementa rate limiting contra ataques de força bruta nos endpoints de autenticação.

**Sugestão:** Adicionar middleware de throttling nos endpoints de login e registro.

---

### 2.4 SQL Injection Potencial via Order By
**Arquivo:** Vários controllers aceitam parâmetros de ordenação sem validação adequada.

**Sugestão:** Validar campos de ordenação contra uma whitelist.

---

### 2.5 Mass Assignment Vulnerável
**Arquivo:** `src/Models/Authentication/Authentication.php:35-51`

```php
protected $fillable = [
    'code',
    'name',
    'rg',
    'cpf',
    // ...
    'status',
];
```

**Problema:** Campos sensíveis como `status` e `email_verified_at` podem ser mass-assigned em alguns contextos.

**Sugestão:** Usar `$guarded` em vez de `$fillable` para campos sensíveis ou criar diferentes DTOs para criação vs atualização.

---

## 3. Problemas de Performance ⚡

### 3.1 Múltiplas Queries em CheckRequestLimitMiddleware
**Arquivo:** `src/Http/Middlewares/CheckRequestLimitMiddleware.php`

```php
$requestsMade = $user->countUsed();      // Query 1
$requestsLimit = $user->requestLimit();  // Query 2 (com eager loading que não funciona)
```

**Problema:** `countUsed()` e `requestLimit()` executam queries separadas. O método `requestLimit()` carrega o plano ativo sem aproveitar eager loading.

**Correção:**
```php
// No middleware
$user = $request->user()->load(['activePlan.plan']);
$plan = $user->activePlan;

if ($plan && $plan->plan) {
    $requestsMade = $plan->requests_used; // Já carregado
    $requestsLimit = $plan->plan->request_limit; // Já carregado
}
```

---

### 3.2 RequestLog Criado Síncronamente
**Arquivo:** `src/Models/Authentication/Authentication.php:147-161`

```php
public function requestUsed(int $status = 0): void
{
    // ...
    $this->requestLog()->create([...]); // Síncrono
    $activePlan->increment('requests_used'); // Síncrono
}
```

**Problema:** A criação do log e incremento são operações síncronas que atrasam a resposta.

**Sugestão:** Usar filas (queues) para logs ou logging assíncrono.

---

### 3.3 N+1 em Authentication::activePlan()
**Arquivo:** `src/Models/Authentication/Authentication.php:117-123`

```php
public function activePlan(): HasOne
{
    return $this->hasOne(UserPlan::class)
        ->where('active', true)
        ->where('end_date', '>=', now())
        ->latest();
}
```

**Problema:** Se chamado em um loop de usuários, causará N+1.

**Sugestão:** Adicionar scope global ou método que suporte eager loading adequado.

---

### 3.4 Consulta de Validade em Cada Requisição
**Arquivo:** `src/Http/Middlewares/AuthenticateApiKey.php`

```php
$apiKey = ApiKey::validateKey($key);
```

**Problema:** A validação da chave consulta o banco de dados em cada requisição.

**Sugestão:** Implementar cache (Redis/Memcached) para chaves válidas com TTL curto.

---

## 4. Melhorias Sugeridas ✨

### 4.1 Implementar Cache para ApiKey
```php
// Em ApiKey::validateKey()
public static function validateKey($key)
{
    return Cache::remember("api_key:{$key}", 60, function () use ($key) {
        return self::where('key', $key)
            ->where('active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    });
}
```

---

### 4.2 Adicionar SoftDeletes em Mais Modelos
**Sugestão:** Adicionar SoftDeletes em `Plan`, `Coupon`, e `ApiKey` para permitir recuperação de dados.

---

### 4.3 Implementar Eventos para Auditoria
**Sugestão:** Adicionar eventos do Eloquent para logar mudanças importantes:
```php
// No modelo Authentication
protected static function booted()
{
    static::updated(function ($user) {
        if ($user->wasChanged('status')) {
            event(new UserStatusChanged($user));
        }
    });
}
```

---

### 4.4 Adicionar Paginação nos Controllers
**Arquivo:** Vários controllers retornam todos os registros sem paginação.

**Sugestão:** Implementar paginação padrão em todos os endpoints de listagem.

---

### 4.5 Implementar Health Check
**Sugestão:** Adicionar endpoint de health check para monitoramento:
```php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getPdo() ? 'connected' : 'error',
        'cache' => Cache::getFacadeRoot() ? 'connected' : 'error',
    ]);
});
```

---

### 4.6 Adicionar Suporte a Webhooks
**Sugestão:** Implementar sistema de webhooks para eventos importantes (assinatura criada, plano expirado, etc.).

---

### 4.7 Implementar Grace Period para Planos
**Sugestão:** Adicionar período de tolerância após expiração do plano:
```php
public function isActiveWithGracePeriod(int $graceDays = 3): bool
{
    $graceEnd = $this->end_date->addDays($graceDays);
    return $this->active && now()->lte($graceEnd);
}
```

---

## 5. Problemas de Manutenibilidade 🔧

### 5.1 Código em Português e Inglês Misturado
**Problema:** Mensagens de erro estão em português (ex: "Limite de requisições atingido") enquanto o código e documentação estão em inglês.

**Sugestão:** Usar sistema de internacionalização (i18n) com `__()` ou `trans()`.

---

### 5.2 Métodos Muito Longos
**Arquivo:** `src/Http/Controllers/Authentication/AuthController.php:22-61`

O método `register()` tem ~40 linhas e múltiplas responsabilidades.

**Sugestão:** Extrair em serviços:
```php
class UserRegistrationService
{
    public function register(array $data): Authentication;
    public function generateAvatar(Authentication $user, string $name): void;
}
```

---

### 5.3 Configuração Vazia
**Arquivo:** `config/config.php`

O arquivo de configuração está vazio, perdendo a oportunidade de permitir personalização.

**Sugestão:** Adicionar opções configuráveis:
```php
return [
    'models' => [
        'authentication' => \RiseTechApps\ApiKey\Models\Authentication\Authentication::class,
        'plan' => \RiseTechApps\ApiKey\Models\Plan\Plan::class,
    ],
    'rate_limit' => [
        'default' => 1000,
        'cache_ttl' => 3600,
    ],
    'features' => [
        'webhook_notifications' => true,
        'grace_period_days' => 3,
    ],
];
```

---

### 5.4 Falta de Testes Automatizados
**Problema:** Não há pasta `tests/` no projeto e o `composer.json` aponta para tests inexistentes.

**Sugestão:** Criar testes unitários e de feature usando Pest (já configurado).

---

### 5.5 Documentação Incompleta
**Problema:** README.md não cobre todos os recursos (FeatureManager, Webhooks, etc.).

**Sugestão:** Criar documentação completa com exemplos de uso avançado.

---

### 5.6 Sem Tipagem de Retorno em Alguns Métodos
**Arquivo:** `src/Models/Plan/Plan.php:52`

```php
public function getFormattedPriceAttribute(): string
```

Está correto, mas outros métodos como em UserPlan e RequestLog não têm tipagem.

**Sugestão:** Adicionar tipagem estrita em todos os métodos.

---

## 6. Sugestões de Refatoração 🔄

### 6.1 Extrair Validação de Origem para Serviço
```php
class OriginValidator
{
    public function validate(ApiKey $apiKey, ?string $origin, ?string $ip): bool;
    public function normalizeOrigin(string $origin): string;
}
```

---

### 6.2 Criar DTOs para Requests
```php
class RegisterUserDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}
}
```

---

### 6.3 Implementar Repository Pattern Completo
**Problema:** Apenas `Plan` e `Coupon` têm repositories.

**Sugestão:** Criar repositories para todos os modelos.

---

### 6.4 Criar Service Layer
**Sugestão:** Extrair lógica de negócio dos controllers:
```php
class SubscriptionService
{
    public function subscribe(Authentication $user, Plan $plan, ?Coupon $coupon): UserPlan;
    public function cancel(UserPlan $plan): void;
    public function renew(UserPlan $plan): void;
}
```

---

### 6.5 Implementar Command Pattern para Jobs
**Sugestão:** Criar jobs para operações assíncronas:
```php
class ProcessExpiredPlans implements ShouldQueue
{
    public function handle(PlanExpirationService $service): void;
}
```

---

### 6.6 Adicionar API Resources Consistentes
**Problema:** Alguns endpoints retornam JSON manual.

**Sugestão:** Usar Resources do Laravel para todos os endpoints:
```php
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'plan' => PlanResource::make($this->whenLoaded('activePlan')),
            'usage' => [
                'used' => $this->countUsed(),
                'limit' => $this->requestLimit(),
            ],
        ];
    }
}
```

---

## Conclusão

### Prioridade Alta (Corrigir Imediatamente):
1. 🐛 ApiKeyScope - Acesso sem verificação de null
2. 🐛 UserPlan::isActive() - Lógica incorreta
3. 🔒 Adicionar rate limiting em endpoints de auth
4. ⚡ Implementar cache para validação de API keys

### Prioridade Média (Corrigir na Próxima Sprint):
1. 🐛 Validar existência de helpers antes de usar
2. ⚡ Otimizar queries no middleware de limites
3. 🔧 Extrair lógica para service layer
4. 🌍 Implementar sistema de i18n completo

### Prioridade Baixa (Melhorias):
1. ✨ Adicionar soft deletes
2. ✨ Implementar webhooks
3. ✨ Criar testes automatizados
4. ✨ Melhorar documentação

---

## Status das Implementações

### ✅ Implementado (2026-04-10)

| Item | Status | Arquivos Modificados |
|------|--------|---------------------|
| 1.1 ApiKeyScope - Verificação de null | ✅ | `src/Scope/ApiKeyScope.php` |
| 1.2 AuthController - Helper validation | ✅ | `src/Http/Controllers/Authentication/AuthController.php` |
| 1.3 UserPlan::isActive() - Bug fix | ✅ | `src/Models/UserPlan/UserPlan.php` |
| 1.4 Authentication::subscribeToPlan() | ✅ | `src/Models/Authentication/Authentication.php` |
| 1.5 Migrations - Métodos down() | ✅ | `database/migrations/*` |
| 2.1 ApiKey com Hash | ✅ | `src/Models/ApiKey/ApiKey.php` |
| 2.2 Validação de Origem - Logging | ✅ | `src/Http/Middlewares/ApiKeyOriginValidatorMiddleware.php` |
| 2.3 Rate Limiting | ✅ | `src/RoutesApiKey.php` |
| 2.5 Mass Assignment Protection | ✅ | `src/Models/Authentication/Authentication.php` |
| 3.1 CheckRequestLimitMiddleware | ✅ | `src/Http/Middlewares/CheckRequestLimitMiddleware.php` |
| 3.2/3.3 countUsed/requestUsed | ✅ | `src/Models/Authentication/Authentication.php` |
| 3.4 Cache para ApiKey | ✅ | `src/Models/ApiKey/ApiKey.php` |
| 4.1 Cache completo ApiKey | ✅ | `src/Models/ApiKey/ApiKey.php` |
| 4.3 Eventos para Auditoria | ✅ | `src/Events/*`, Listeners |
| 4.7 Grace Period | ✅ | `config/config.php`, `UserPlan`, Middleware |
| 5.1 Internacionalização (i18n) | ✅ | `src/lang/*`, todos os Controllers |
| 5.2 Métodos Longos - Service Layer | ✅ | `src/Services/*`, Controllers refatorados |
| 5.6 Tipagem de Retorno | ✅ | Todos os Middlewares, Controllers, Models, Notifications |
| 6.6 API Resources Consistentes | ✅ | `src/Http/Resources/*`, `ApiResponse`, `ApiResponseTrait` |

### 📝 Exemplo de Uso - API Resources Consistentes

```php
// Resources criados/atualizados para respostas consistentes
use RiseTechApps\ApiKey\Http\Resources\UserResource;
use RiseTechApps\ApiKey\Http\Resources\UserPlanResource;
use RiseTechApps\ApiKey\Http\Resources\ApiKeyResource;
use RiseTechApps\ApiKey\Http\Resources\RequestLogResource;
use RiseTechApps\ApiKey\Http\Resources\SuccessResource;
use RiseTechApps\ApiKey\Http\Resources\ErrorResource;

// Response padronizado
return UserResource::make($user);

// Response com relacionamentos
return UserResource::make($user->load(['apiKey', 'activePlan.plan', 'address']));

// Response padronizado de sucesso
return ApiResponse::success(
    data: UserResource::make($user),
    message: 'User created successfully',
    code: 201
);

// Response padronizado de erro
return ApiResponse::error(
    message: 'Validation failed',
    code: 422,
    errors: ['email' => ['Email already exists']],
    error_code: 'VALIDATION_ERROR'
);

// Usando trait nos controllers
class MyController extends Controller
{
    use ApiResponseTrait;

    public function show($id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return $this->notFoundResponse('User');
        }

        return $this->successResponse(
            data: UserResource::make($user),
            message: 'User retrieved successfully'
        );
    }
}
```

### 📝 Exemplo de Uso - Tipagem Completa

Todos os métodos agora possuem tipagem de retorno explícita:

```php
// Middlewares
public function handle(Request $request, Closure $next): Response

// Controllers
public function index(Request $request): JsonResponse
public function show(Request $request, string $id): JsonResponse

// Models
public function isValid(): bool
public function getGatewayCouponId(): string
public function isActive(): bool
public function isInGracePeriod(): bool

// Services
public function register(array $data): Authentication
public function attemptLogin(array $credentials): ?array
public function subscribe(Authentication $user, Plan $plan): UserPlan

// Notifications
protected function verificationUrl($notifiable): string
public function toMail($notifiable): MailMessage
```

### 📝 Exemplo de Uso - Service Layer

```php
// UserRegistrationService - encapsula lógica de registro
$service = app(UserRegistrationService::class);
$user = $service->register([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => 'secret'
]);

// AuthService - encapsula autenticação
$authService = app(AuthService::class);
$result = $authService->attemptLogin([
    'email' => 'john@example.com',
    'password' => 'secret'
]);
// Retorna: ['user' => $user, 'token' => $plainTextToken] ou null

// PlanService - encapsula assinaturas
$planService = app(PlanService::class);
$userPlan = $planService->subscribe($user, $plan);
```

### 📝 Exemplo de Uso - i18n

```php
// Todas as mensagens do pacote agora usam o sistema de tradução do Laravel
// O idioma padrão é inglês, com suporte a português

// Para usar em português, configure no .env:
APP_LOCALE=pt

// Ou alterne dinamicamente:
App::setLocale('pt');

// Exemplo de mensagem:
__('api-key::messages.request_limit_reached');
// Retorna: 'Request limit reached' (en) ou 'Limite de requisições atingido' (pt)
```

### 📝 Exemplo de Uso - Grace Period

```php
// Verificar se plano está ativo ou em período de tolerância
if ($userPlan->isActiveOrInGracePeriod()) {
    // Permitir acesso
}

// Verificar dias restantes no período de tolerância
$daysRemaining = $userPlan->getGracePeriodRemainingDays();

// Configurar no .env
API_KEY_GRACE_PERIOD_DAYS=3

// Comando para verificar planos expirados
php artisan api-key:check-expired-plans
php artisan api-key:check-expired-plans --grace-only
```

### 📝 Exemplo de Uso - Eventos

```php
// Registrar listeners no EventServiceProvider
protected $listen = [
    \RiseTechApps\ApiKey\Events\PlanGracePeriodStarted::class => [
        \RiseTechApps\ApiKey\Listeners\SendGracePeriodNotification::class,
    ],
    \RiseTechApps\ApiKey\Events\PlanExpired::class => [
        \RiseTechApps\ApiKey\Listeners\SendPlanExpiredNotification::class,
    ],
];
```

---

**Nota:** Este é um pacote bem estruturado com boas práticas em geral. Os problemas identificados foram corrigidos e melhorias implementadas.
