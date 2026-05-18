# risetechapps/api-key-for-laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![GitHub Actions](https://github.com/risetechapps/api-key-for-laravel/actions/workflows/main.yml/badge.svg)](https://github.com/risetechapps/api-key-for-laravel/actions)
[![Tests](https://img.shields.io/badge/tests-63%20passing-green.svg)](tests)
[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12-red.svg)](https://laravel.com)

Gerenciamento de API keys, planos de assinatura e um painel SPA Vue 3 pronto para uso — tudo em um único pacote Laravel.

## Funcionalidades

- **Gerenciamento Seguro de API Keys** — hash bcrypt, nunca armazenado em texto puro
- **Planos de Assinatura** — ciclos de cobrança, limites de requisições, feature flags
- **FeatureRegistry** — registre features dos planos em código, sincronização automática no banco, exposto ao painel admin
- **Período de Carência** — janela de tolerância configurável após expiração do plano
- **Sistema de Cupons** — limite de usos, data de expiração, descontos percentuais
- **Validação de Origem** — proteção por API key similar ao CORS
- **Throttling e Rate Limiting** — contadores atômicos por usuário
- **Sistema de Eventos** — `PlanChanged`, `PlanExpired`, `GracePeriodStarted`, `UserStatusChanged`
- **Notificações por E-mail** — alertas de carência, expiração de plano, redefinição de senha (pt-BR)
- **Fluxo de Redefinição de Senha** — ciclo completo de recuperação com URLs assinadas apontando para a SPA
- **Verificação de E-mail** — redireciona para a SPA após o clique
- **Integração MercadoPago** — checkout com Secure Fields, cartões salvos, webhook, estornos
- **Painel SPA Vue 3** — assets pré-compilados, sem necessidade de Node.js no servidor host
- **Internacionalização** — inglês e português (pt-BR), detectado automaticamente via `Accept-Language`
- **Camada de Cache** — suporte a Redis/Memcached para validação de API keys
- **Suite de Testes** — 63 testes Pest

## Requisitos

- PHP ^8.4
- Laravel ^12
- Laravel Sanctum ^4.0

---

## Instalação

```bash
composer require risetechapps/api-key-for-laravel
```

### 1. Publicar e executar as migrations

```bash
php artisan vendor:publish --tag="api-key-migrations"
php artisan migrate
```

### 2. Publicar a configuração

```bash
php artisan vendor:publish --tag="api-key-config"
```

---

## Modos de operação

### Modo A — Somente API (padrão)

O pacote expõe endpoints REST sob `api/v1/`. O painel SPA fica desativado. Use este modo quando tiver seu próprio frontend ou precisar apenas da API.

`.env`:
```
API_KEY_SPA_ENABLED=false
```

### Modo B — API + Painel SPA

O pacote também serve um painel Vue 3 pré-compilado. Não é necessário Node.js no servidor host — os assets são distribuídos junto com o pacote, como o Laravel Horizon ou o Telescope.

**Passo 1 — publicar os assets:**

```bash
php artisan vendor:publish --tag="api-key-assets"
```

Isso copia os arquivos pré-compilados do `dist/` para `public/vendor/api-key/`.

**Passo 2 — habilitar a SPA no `.env`:**

```
API_KEY_SPA_ENABLED=true
```

Quando habilitada:
- Uma rota catch-all `/{any}` serve `resources/views/vendor/api-key/app.blade.php` para todos os caminhos que não sejam da API.
- O `DisableRouteWebMiddleware` é desabilitado automaticamente para que o navegador alcance o frontend.

**Para customizar o shell Blade** (título, meta tags, scripts de analytics):

```bash
php artisan vendor:publish --tag="api-key-views"
```

Isso copia o `app.blade.php` para `resources/views/vendor/api-key/app.blade.php`.

---

## Rotas

### Rotas automáticas (embutidas)

Quando `API_KEY_ROUTES_ENABLED=true` (padrão), o pacote registra as rotas automaticamente sob `api/v1/`:

| Método | URI | Descrição |
|--------|-----|-----------|
| `POST` | `api/v1/register` | Registrar novo usuário |
| `POST` | `api/v1/login` | Login e recebimento do token Sanctum |
| `POST` | `api/v1/logout` | Revogar token atual |
| `GET` | `api/v1/auth/me` | Obter usuário autenticado |
| `GET` | `api/v1/email/verify/{id}/{hash}` | Verificar e-mail |
| `POST` | `api/v1/forgot-password` | Enviar e-mail de redefinição de senha |
| `POST` | `api/v1/reset-password` | Redefinir senha com token |
| `GET` | `api/v1/dashboard/plans` | Listar planos disponíveis |
| `POST` | `api/v1/dashboard/checkout/process` | Processar pagamento |
| `POST` | `api/v1/dashboard/checkout/coupon` | Validar cupom |
| `POST` | `api/v1/dashboard/checkout/webhook` | Webhook do MercadoPago |
| `GET` | `api/v1/dashboard/profile` | Obter perfil |
| `PUT` | `api/v1/dashboard/profile` | Atualizar perfil |
| `POST` | `api/v1/dashboard/profile/regenerate-key` | Regenerar API key |
| `GET` | `api/v1/dashboard/cards` | Listar cartões salvos |
| `POST` | `api/v1/dashboard/cards` | Adicionar cartão |
| `DELETE` | `api/v1/dashboard/cards/{id}` | Remover cartão |
| `GET` | `api/v1/dashboard/history` | Histórico de assinaturas |
| `GET` | `api/v1/dashboard/log` | Log de requisições |

Rotas exclusivas de admin (requerem middleware `admin`):

| Método | URI | Descrição |
|--------|-----|-----------|
| `POST/PUT/DELETE` | `api/v1/dashboard/plans/{plan}` | Criar / atualizar / excluir planos |
| `POST/PUT/DELETE` | `api/v1/dashboard/coupons/{coupon}` | Criar / atualizar / excluir cupons |
| `GET` | `api/v1/dashboard/admin/plans` | Listar todos os planos (visão admin) |
| `GET` | `api/v1/dashboard/admin/users` | Listar usuários com assinaturas |
| `GET` | `api/v1/dashboard/admin/refunds` | Listar pagamentos com opção de estorno |
| `POST` | `api/v1/dashboard/admin/refunds/{id}` | Processar estorno via MercadoPago |
| `GET` | `api/v1/dashboard/admin/features` | Listar features registradas (`FeatureRegistry`) |

### Registro manual de rotas com `RoutesApiKey`

Use quando precisar montar as rotas do pacote dentro do seu próprio arquivo de rotas com opções específicas (prefixo, middlewares, etc.):

```php
// routes/api.php

use RiseTechApps\ApiKey\RoutesApiKey;

RoutesApiKey::register([
    'prefix'     => 'api/v1',
    'middleware' => ['api'],
]);
```

> Desabilite as rotas automáticas primeiro: `API_KEY_ROUTES_ENABLED=false`

---

## Protegendo suas próprias rotas

Use o grupo de middleware `plan` para proteger qualquer rota. Ele valida a API key, garante uma assinatura ativa, verifica os limites de requisição e valida a origem da requisição:

```php
Route::middleware(['api', 'plan'])->group(function () {
    Route::get('/api/v1/data', fn() => response()->json(['ok' => true]));
});
```

Envie a API key no header da requisição:

```bash
curl -H "X-API-KEY: sua-api-key-aqui" \
     -H "Origin: https://seudominio.com" \
     https://api.seuapp.com/api/v1/data
```

### Middleware `feature`

Restrinja uma rota a planos que tenham uma feature específica habilitada:

```php
Route::middleware(['api', 'plan', 'feature:relatorios_avancados'])->group(function () {
    Route::get('/api/v1/relatorios', ReportController::class);
});
```

---

## Planos de Assinatura

```php
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Enums\BillingCycle;

$plan = Plan::create([
    'name'          => 'Premium',
    'description'   => 'Plano premium com 10 mil requisições/mês',
    'request_limit' => 10000,
    'price'         => 29.99,
    'billing_cycle' => BillingCycle::MONTHLY,
    'features'      => ['relatorios_avancados', 'exportar_csv'],
]);

// Assinar um usuário
$user->subscribeToPlan($plan);
```

### Período de carência

Assinaturas expiradas entram automaticamente no período de carência. O usuário mantém o acesso enquanto o prazo corre:

```php
$userPlan = $user->activePlanWithGracePeriod()->first();

if ($userPlan?->isInGracePeriod()) {
    $dias = $userPlan->getGracePeriodRemainingDays();
}

// Ou simplesmente:
$user->hasActivePlan();     // true durante o período de carência
$user->isInGracePeriod();   // true somente durante o período de carência
```

---

## FeatureRegistry

O `FeatureRegistry` é uma forma de declarar em código quais features existem na sua aplicação. As features são registradas em PHP, persistidas automaticamente na tabela `plan_features` e expostas ao painel admin para configuração dos planos.

### Registrando features

Registre as features no `AppServiceProvider::boot()` da sua aplicação:

```php
use RiseTechApps\ApiKey\Facades\FeatureRegistry;

public function boot(): void
{
    FeatureRegistry::register('api_requests', [
        'name'        => 'Requisições via API',
        'description' => 'Permite consumo via chave de API',
        'icon'        => 'ph-key',
    ]);

    FeatureRegistry::register('exportar_csv', [
        'name'        => 'Exportar CSV',
        'description' => 'Exportação de dados em formato CSV',
        'icon'        => 'ph-file-csv',
    ]);
}
```

### Como funciona

1. `register()` armazena os metadados em memória e auto-define um resolver no `FeatureManager`, fazendo o middleware `feature:key` funcionar imediatamente.
2. A feature é inserida/atualizada na tabela `plan_features` (falha silenciosa se a tabela ainda não existir — seguro chamar antes das migrations rodarem).
3. O painel admin busca as features em `GET /dashboard/admin/features` e as renderiza como checkboxes ao criar ou editar um plano.

### Protegendo rotas por feature

```php
// Exige que o plano ativo tenha 'exportar_csv' no array de features
Route::middleware(['api', 'plan', 'feature:exportar_csv'])->group(function () {
    Route::get('/api/v1/export', ExportController::class);
});
```

### Sincronização manual com o banco

Se as migrations rodarem depois que as features já foram registradas (ex: em um comando Artisan), force a sincronização:

```php
FeatureRegistry::sync();
```

### Métodos disponíveis

```php
FeatureRegistry::all();         // array com todas as features registradas
FeatureRegistry::get('key');    // metadados de uma feature específica (ou null)
FeatureRegistry::keys();        // array com todas as chaves registradas
FeatureRegistry::has('key');    // bool
FeatureRegistry::sync();        // upsert de todas as features no banco
```

> **Atenção:** O `FeatureRegistry` usa sua própria tabela `plan_features` e não conflita com o `laravel/pennant`, que utiliza a tabela `features`.

---

## MercadoPago

### Configuração

Adicione ao `.env` da aplicação:

```env
MP_PUBLIC_KEY=APP_USR-...
MP_ACCESS_TOKEN=APP_USR-...
MP_WEBHOOK_SECRET=seu-webhook-secret
```

> **Não adicione** `VITE_MP_PUBLIC_KEY`. A chave pública é entregue ao frontend pelo endpoint autenticado `/auth/me` (campo `mp_public_key`), funcionando corretamente com os assets pré-compilados da SPA sem precisar de variável de build.

### Webhook

Cadastre a URL do webhook na sua conta do MercadoPago:

```
https://seudominio.com/api/v1/dashboard/checkout/webhook
```

Defina `MP_WEBHOOK_SECRET` com o secret gerado pelo MercadoPago para verificação HMAC.

### Cartões salvos

Os dados do cartão são tokenizados via MercadoPago Secure Fields (iframes) diretamente no navegador — números de cartão nunca chegam ao seu servidor. A tokenização do CVV de cartões salvos também ocorre no frontend via `mp.createCardToken()`.

---

## Sistema de Cupons

```php
use RiseTechApps\ApiKey\Models\Coupon\Coupon;

$coupon = Coupon::create([
    'code'                => 'LANCAMENTO50',
    'discount_percentage' => 50,
    'max_uses'            => 200,
    'valid_until'         => now()->addMonth(),
]);

if ($coupon->isValid()) {
    // aplicar desconto no checkout
}
```

---

## Eventos e Notificações

O pacote dispara eventos automaticamente. Os listeners embutidos enviam notificações por e-mail em português (pt-BR) por padrão.

| Evento | Listener | Notificação |
|--------|----------|-------------|
| `PlanChanged` | — | *(implemente seu próprio listener)* |
| `GracePeriodStarted` | `SendGracePeriodNotification` | `GracePeriodStartedNotification` |
| `PlanExpired` | `SendPlanExpiredNotification` | `PlanExpiredNotification` |
| `UserStatusChanged` | — | *(implemente seu próprio listener)* |
| `RequestLimitReached` | — | *(implemente seu próprio listener)* |

### Ouvindo eventos

```php
// app/Providers/EventServiceProvider.php

use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\UserStatusChanged;

protected $listen = [
    PlanChanged::class => [
        \App\Listeners\BoasVindasNovoAssinante::class,
    ],
    UserStatusChanged::class => [
        \App\Listeners\AuditoriaStatusUsuario::class,
    ],
];
```

---

## Referência de Middlewares

| Alias | Classe | Descrição |
|-------|--------|-----------|
| `api.key` | `AuthenticateApiKey` | Valida a API key do header `X-API-KEY` |
| `check.active.plan` | `CheckActivePlanMiddleware` | Exige assinatura ativa ou em período de carência |
| `check.limit.plan` | `CheckRequestLimitMiddleware` | Rejeita requisições acima do limite do plano |
| `api.key.origin` | `ApiKeyOriginValidatorMiddleware` | Valida o header `Origin` contra as origens permitidas |
| `language` | `LanguageMiddleware` | Define o locale a partir do `Accept-Language` (`pt-BR` → `pt`) |
| `admin` | `AdminMiddleware` | Exige `role = admin` |
| `feature` | `CheckPlanFeatureMiddleware` | Exige feature específica no plano atual |
| `plan` | *(grupo)* | Combina `api.key + check.active.plan + check.limit.plan + api.key.origin + language` |

---

## Comandos Artisan

```bash
# Verificar todos os planos e disparar eventos de expiração/carência
php artisan apikey:check-expired

# Verificar apenas planos em período de carência
php artisan apikey:check-expired --grace-only

# Processar renovações agendadas (roda automaticamente todo dia às 08:00)
php artisan billing:process-renewals

# Promover um usuário a admin
php artisan apikey:make-admin {email}
```

---

## Referência de Configuração

```php
// config/api-key.php

return [
    'grace_period_days' => 3,

    'rate_limit' => [
        'cache_ttl' => 3600,
    ],

    'cache' => [
        'enabled' => true,
        'ttl'     => 300,       // segundos — cache geral de API key
        'prefix'  => 'api_key_',
    ],

    'cache_ttl' => [
        'validation' => 300,    // cache de validação de API key
        'origin'     => 60,     // cache de validação de origem
    ],

    'disable_web_middleware' => [
        'enabled' => true,      // desabilitado automaticamente quando spa.enabled = true
    ],

    'auth_throttle' => [
        'enabled'       => true,
        'attempts'      => 5,
        'decay_minutes' => 1,
    ],

    'header_name'      => 'X-API-KEY',
    'default_language' => 'pt',     // 'pt' ou 'en'

    'routes' => [
        'enabled' => true,
        'prefix'  => '',
    ],

    'middleware_group' => [
        'plan' => [
            'api.key',
            'check.active.plan',
            'check.limit.plan',
            'api.key.origin',
            'language',
        ],
    ],

    'mercadopago' => [
        'public_key'     => env('MP_PUBLIC_KEY'),
        'access_token'   => env('MP_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
    ],

    'demo_user_id'   => env('API_KEY_DEMO_USER_ID'),
    'internal_token' => env('API_INTERNAL_TOKEN'),

    'spa' => [
        'enabled' => false,
    ],
];
```

### Variáveis de Ambiente

| Variável | Descrição | Padrão |
|----------|-----------|--------|
| `API_KEY_GRACE_PERIOD_DAYS` | Dias de carência após expiração do plano | `3` |
| `API_KEY_CACHE_ENABLED` | Habilitar cache de API key | `true` |
| `API_KEY_CACHE_TTL` | TTL do cache geral (segundos) | `300` |
| `API_KEY_CACHE_TTL_VALIDATION` | TTL do cache de validação (segundos) | `300` |
| `API_KEY_CACHE_TTL_ORIGIN` | TTL do cache de origem (segundos) | `60` |
| `API_KEY_RATE_LIMIT_CACHE_TTL` | TTL do contador de rate limit (segundos) | `3600` |
| `API_KEY_DISABLE_WEB_MIDDLEWARE` | Anexar `DisableRouteWebMiddleware` ao grupo `web` | `true` |
| `API_KEY_AUTH_THROTTLE_ENABLED` | Habilitar throttle nos endpoints de autenticação | `true` |
| `API_KEY_AUTH_THROTTLE_ATTEMPTS` | Máximo de tentativas de login/registro | `5` |
| `API_KEY_AUTH_THROTTLE_DECAY` | Janela de decaimento do throttle (minutos) | `1` |
| `API_KEY_HEADER_NAME` | Header HTTP que carrega a API key | `X-API-KEY` |
| `API_KEY_DEFAULT_LANGUAGE` | Locale padrão | `pt` |
| `API_KEY_ROUTES_ENABLED` | Registrar rotas do pacote automaticamente | `true` |
| `API_KEY_ROUTES_PREFIX` | Prefixo das rotas | `''` |
| `API_KEY_SPA_ENABLED` | Servir o painel SPA Vue | `false` |
| `API_KEY_DEMO_USER_ID` | `authentication.id` para o endpoint de demonstração pública | — |
| `API_INTERNAL_TOKEN` | Token secreto para chamadas servidor-a-servidor | — |
| `MP_PUBLIC_KEY` | Chave pública do MercadoPago | — |
| `MP_ACCESS_TOKEN` | Access token do MercadoPago | — |
| `MP_WEBHOOK_SECRET` | Secret HMAC do webhook do MercadoPago | — |

---

## Referência de Tags de Publicação

| Tag | O que publica | Quando usar |
|-----|---------------|-------------|
| `api-key-migrations` | Migrations do banco de dados | Sempre |
| `api-key-config` | `config/api-key.php` | Para alterar valores de configuração |
| `api-key-lang` | Arquivos de tradução em `resources/lang/vendor/api-key/` | Para sobrescrever mensagens |
| `api-key-assets` | SPA pré-compilada em `public/vendor/api-key/` | Modo B (SPA habilitada) |
| `api-key-views` | Shell Blade `app.blade.php` | Para customizar o `<head>` HTML |
| `api-key-frontend` | Arquivos-fonte Vue em `resources/js/` e `resources/css/` | Customização nível 2 |
| `api-key-build` | `package.json`, `vite.config.ts`, `tsconfig.json` | Customização nível 2 |

---

## Customização

### Nível 1 — Configuração e sobrescritas (sem Node.js)

Tudo que pode ser alterado sem tocar no código Vue ou Blade:

- **Valores de config** — publique `api-key-config` e edite `config/api-key.php`
- **Mensagens de tradução** — publique `api-key-lang` e edite os arquivos PHP/JSON
- **Shell Blade** — publique `api-key-views` para alterar título, meta tags, fontes ou injetar scripts
- **Grupo de middlewares** — reordene ou substitua middlewares em `middleware_group.plan`
- **Eventos** — registre seus próprios listeners para `PlanChanged`, `UserStatusChanged`, etc.

### Nível 2 — Customização completa do frontend (Node.js necessário)

Publique o código-fonte Vue e o build config, depois trabalhe diretamente no frontend:

```bash
# 1. Publicar os arquivos-fonte Vue
php artisan vendor:publish --tag="api-key-frontend"

# 2. Publicar o build config (package.json, vite.config.ts, tsconfig.json)
php artisan vendor:publish --tag="api-key-build"

# 3. Instalar dependências
npm install

# 4. Iniciar o servidor de desenvolvimento
npm run dev

# 5. Build para produção (publica em public/vendor/api-key/ automaticamente via vite.config.ts)
npm run build
```

Após executar `npm run build`, rode `php artisan view:clear` se o painel não refletir as mudanças imediatamente.

---

## Testes

```bash
# Rodar todos os testes
vendor/bin/pest

# Filtrar por suite
vendor/bin/pest --filter="UserPlan"

# Relatório de cobertura
vendor/bin/pest --coverage
```

Habilite o SQLite no `php.ini` para o banco de testes:

```ini
extension=pdo_sqlite
extension=sqlite3
```

---

## Segurança

- API keys são armazenadas como hashes bcrypt
- Endpoints de autenticação têm rate limiting por padrão
- Header de origem é validado por API key
- Redefinição de senha usa o mecanismo de URL assinada do Laravel
- Assinaturas de webhook são verificadas via HMAC

Reporte problemas de segurança para apps@risetech.com.br em vez do issue tracker público.

---

## Créditos

- [Rise Tech](https://github.com/risetechapps)
- [Todos os contribuidores](../../contributors)

## Licença

The MIT License (MIT). Consulte o [arquivo de licença](LICENSE.md) para mais informações.
