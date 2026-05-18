# risetechapps/api-key-for-laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![GitHub Actions](https://github.com/risetechapps/api-key-for-laravel/actions/workflows/main.yml/badge.svg)](https://github.com/risetechapps/api-key-for-laravel/actions)
[![Tests](https://img.shields.io/badge/tests-63%20passing-green.svg)](tests)
[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12-red.svg)](https://laravel.com)

API key management, subscription plans, and a ready-to-use Vue 3 SPA dashboard — all in a single Laravel package.

## Features

- **Secure API Key Management** — bcrypt hashing, not stored in plain text
- **Subscription Plans** — billing cycles, request limits, feature flags
- **FeatureRegistry** — register plan features in code, auto-sync to database, exposed to admin UI
- **Grace Period** — configurable tolerance window after plan expiration
- **Coupon System** — usage limits, expiration dates, percentage discounts
- **Origin Validation** — CORS-like protection per API key
- **Request Throttling & Rate Limiting** — per-user counters with atomic increments
- **Event System** — `PlanChanged`, `PlanExpired`, `GracePeriodStarted`, `UserStatusChanged`
- **Automated Email Notifications** — grace period alerts, plan expiry, password reset (pt-BR)
- **Password Reset Flow** — full forgot/reset cycle with signed URLs pointing to SPA
- **Email Verification** — redirects to SPA after click
- **MercadoPago Integration** — Secure Fields checkout, saved cards, webhook, refunds
- **Vue 3 SPA Dashboard** — pre-built assets, zero Node.js required on host
- **Internationalization** — English and Portuguese (pt-BR), auto-detected from `Accept-Language`
- **Caching Layer** — Redis/Memcached support for API key validation
- **Comprehensive Test Suite** — 63 Pest tests

## Requirements

- PHP ^8.4
- Laravel ^12
- Laravel Sanctum ^4.0

---

## Installation

```bash
composer require risetechapps/api-key-for-laravel
```

### 1. Publish and run migrations

```bash
php artisan vendor:publish --tag="api-key-migrations"
php artisan migrate
```

### 2. Publish configuration

```bash
php artisan vendor:publish --tag="api-key-config"
```

---

## Modes of operation

### Mode A — API only (default)

The package exposes REST endpoints under `api/v1/`. The SPA dashboard is disabled. Use this mode when you have your own frontend or just need the API.

`.env`:
```
API_KEY_SPA_ENABLED=false
```

### Mode B — API + SPA dashboard

The package also serves a pre-built Vue 3 dashboard. No Node.js is required on the host — assets are shipped with the package just like Laravel Horizon or Telescope.

**Step 1 — publish assets:**

```bash
php artisan vendor:publish --tag="api-key-assets"
```

This copies the pre-built `dist/` files to `public/vendor/api-key/`.

**Step 2 — enable SPA in `.env`:**

```
API_KEY_SPA_ENABLED=true
```

When enabled:
- A catch-all web route `/{any}` serves `resources/views/vendor/api-key/app.blade.php` for all non-API paths.
- `DisableRouteWebMiddleware` is automatically disabled so browsers can reach the frontend.

**To customize the Blade shell** (title, meta tags, analytics scripts):

```bash
php artisan vendor:publish --tag="api-key-views"
```

This copies `app.blade.php` to `resources/views/vendor/api-key/app.blade.php`.

---

## Routes

### Automatic routes (built-in)

When `API_KEY_ROUTES_ENABLED=true` (default), the package registers routes automatically under `api/v1/`:

| Method | URI | Description |
|--------|-----|-------------|
| `POST` | `api/v1/register` | Register a new user |
| `POST` | `api/v1/login` | Login and receive a Sanctum token |
| `POST` | `api/v1/logout` | Revoke current token |
| `GET` | `api/v1/auth/me` | Get authenticated user |
| `GET` | `api/v1/email/verify/{id}/{hash}` | Verify email address |
| `POST` | `api/v1/forgot-password` | Send password reset email |
| `POST` | `api/v1/reset-password` | Reset password with token |
| `GET` | `api/v1/dashboard/plans` | List available plans |
| `POST` | `api/v1/dashboard/checkout/process` | Process payment |
| `POST` | `api/v1/dashboard/checkout/coupon` | Validate coupon |
| `POST` | `api/v1/dashboard/checkout/webhook` | MercadoPago webhook |
| `GET` | `api/v1/dashboard/profile` | Get profile |
| `PUT` | `api/v1/dashboard/profile` | Update profile |
| `POST` | `api/v1/dashboard/profile/regenerate-key` | Regenerate API key |
| `GET` | `api/v1/dashboard/cards` | List saved cards |
| `POST` | `api/v1/dashboard/cards` | Add card |
| `DELETE` | `api/v1/dashboard/cards/{id}` | Remove card |
| `GET` | `api/v1/dashboard/history` | Subscription history |
| `GET` | `api/v1/dashboard/log` | Request log |

Admin-only routes (requires `admin` middleware):

| Method | URI | Description |
|--------|-----|-------------|
| `POST/PUT/DELETE` | `api/v1/dashboard/plans/{plan}` | Create / update / delete plans |
| `POST/PUT/DELETE` | `api/v1/dashboard/coupons/{coupon}` | Create / update / delete coupons |
| `GET` | `api/v1/dashboard/admin/plans` | List all plans (admin view) |
| `GET` | `api/v1/dashboard/admin/users` | List users with subscriptions |
| `GET` | `api/v1/dashboard/admin/refunds` | List payments with refund option |
| `POST` | `api/v1/dashboard/admin/refunds/{id}` | Process a refund via MercadoPago |
| `GET` | `api/v1/dashboard/admin/features` | List registered features (from `FeatureRegistry`) |

### Manual route registration with `RoutesApiKey`

Use this when you need to mount the package routes inside your own route file with specific options (prefix, middleware, etc.):

```php
// routes/api.php

use RiseTechApps\ApiKey\RoutesApiKey;

RoutesApiKey::register([
    'prefix'     => 'api/v1',
    'middleware' => ['api'],
]);
```

> Disable automatic routes first: `API_KEY_ROUTES_ENABLED=false`

---

## Protecting your own routes

Use the `plan` middleware group to protect any route. It validates the API key, ensures an active subscription, checks request limits, and validates the request origin:

```php
Route::middleware(['api', 'plan'])->group(function () {
    Route::get('/api/v1/data', fn() => response()->json(['ok' => true]));
});
```

Send the API key in the request header:

```bash
curl -H "X-API-KEY: your-api-key-here" \
     -H "Origin: https://yourdomain.com" \
     https://api.yourapp.com/api/v1/data
```

### `feature` middleware

Restrict a route to plans that have a specific feature enabled:

```php
Route::middleware(['api', 'plan', 'feature:advanced_reports'])->group(function () {
    Route::get('/api/v1/reports', ReportController::class);
});
```

---

## Subscription Plans

```php
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Enums\BillingCycle;

$plan = Plan::create([
    'name'          => 'Premium',
    'description'   => 'Premium plan with 10k requests/month',
    'request_limit' => 10000,
    'price'         => 29.99,
    'billing_cycle' => BillingCycle::MONTHLY,
    'features'      => ['advanced_reports', 'export_csv'],
]);

// Subscribe a user
$user->subscribeToPlan($plan);
```

---

## FeatureRegistry

`FeatureRegistry` is a code-first way to declare which features exist in your application. Features are registered in PHP, automatically persisted to the `plan_features` database table, and exposed to the admin dashboard for plan configuration.

### Registering features

Register features in your `AppServiceProvider::boot()`:

```php
use RiseTechApps\ApiKey\Facades\FeatureRegistry;

public function boot(): void
{
    FeatureRegistry::register('api_requests', [
        'name'        => 'Requisições via API',
        'description' => 'Permite consumo via chave de API',
        'icon'        => 'ph-key',
    ]);

    FeatureRegistry::register('export_csv', [
        'name'        => 'Exportar CSV',
        'description' => 'Exportação de dados em formato CSV',
        'icon'        => 'ph-file-csv',
    ]);
}
```

### How it works

1. `register()` stores the metadata in memory and auto-defines a resolver in `FeatureManager` so the `feature:key` middleware works immediately.
2. The feature is upserted into the `plan_features` table (silent fail if the table doesn't exist yet — safe to call before migrations run).
3. The admin dashboard fetches features from `GET /dashboard/admin/features` and renders them as checkboxes when creating or editing a plan.

### Protecting routes by feature

```php
// Requires the active plan to have 'export_csv' in its features array
Route::middleware(['api', 'plan', 'feature:export_csv'])->group(function () {
    Route::get('/api/v1/export', ExportController::class);
});
```

### Syncing to database manually

If you run migrations after features are already registered (e.g. in an Artisan command), force-sync them:

```php
FeatureRegistry::sync();
```

### Available methods

```php
FeatureRegistry::all();         // array of all registered features
FeatureRegistry::get('key');    // metadata for a specific feature (or null)
FeatureRegistry::keys();        // array of registered keys
FeatureRegistry::has('key');    // bool
FeatureRegistry::sync();        // upsert all to database
```

> **Note:** `FeatureRegistry` uses its own `plan_features` table and does not conflict with `laravel/pennant` which uses the `features` table.

### Grace period

Expired subscriptions automatically enter a grace period. The user keeps access while the clock ticks:

```php
$userPlan = $user->activePlanWithGracePeriod()->first();

if ($userPlan?->isInGracePeriod()) {
    $days = $userPlan->getGracePeriodRemainingDays();
}

// Or simply:
$user->hasActivePlan();     // true during grace period
$user->isInGracePeriod();   // true only during grace period
```

---

## MercadoPago

### Configuration

Add to your `.env`:

```env
MP_PUBLIC_KEY=APP_USR-...
MP_ACCESS_TOKEN=APP_USR-...
MP_WEBHOOK_SECRET=your-webhook-secret
```

> **Do not** add `VITE_MP_PUBLIC_KEY`. The public key is delivered to the frontend through the authenticated `/auth/me` endpoint (`mp_public_key` field), so it works correctly with the pre-built SPA assets without needing a build-time variable.

### Webhook

Register the webhook URL in your MercadoPago account:

```
https://yourdomain.com/api/v1/dashboard/checkout/webhook
```

Set `MP_WEBHOOK_SECRET` to the secret MercadoPago generates for HMAC verification.

### Saved cards

Cards are tokenized via MercadoPago Secure Fields (iframes) directly in the browser — raw card numbers never reach your server. CVV tokenization for saved cards also happens on the frontend via `mp.createCardToken()`.

---

## Coupon System

```php
use RiseTechApps\ApiKey\Models\Coupon\Coupon;

$coupon = Coupon::create([
    'code'                => 'LAUNCH50',
    'discount_percentage' => 50,
    'max_uses'            => 200,
    'valid_until'         => now()->addMonth(),
]);

if ($coupon->isValid()) {
    // apply discount at checkout
}
```

---

## Events and Notifications

The package fires events automatically. Built-in listeners send email notifications in Portuguese (pt-BR) by default.

| Event | Listener | Notification |
|-------|----------|--------------|
| `PlanChanged` | — | *(hook into this yourself)* |
| `GracePeriodStarted` | `SendGracePeriodNotification` | `GracePeriodStartedNotification` |
| `PlanExpired` | `SendPlanExpiredNotification` | `PlanExpiredNotification` |
| `UserStatusChanged` | — | *(hook into this yourself)* |
| `RequestLimitReached` | — | *(hook into this yourself)* |

### Listening to events

```php
// app/Providers/EventServiceProvider.php

use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\UserStatusChanged;

protected $listen = [
    PlanChanged::class => [
        \App\Listeners\WelcomeNewSubscriber::class,
    ],
    UserStatusChanged::class => [
        \App\Listeners\AuditUserStatus::class,
    ],
];
```

---

## Middleware Reference

| Alias | Class | Description |
|-------|-------|-------------|
| `api.key` | `AuthenticateApiKey` | Validates API key from `X-API-KEY` header |
| `check.active.plan` | `CheckActivePlanMiddleware` | Requires an active or grace-period subscription |
| `check.limit.plan` | `CheckRequestLimitMiddleware` | Rejects requests over the plan limit |
| `api.key.origin` | `ApiKeyOriginValidatorMiddleware` | Validates `Origin` header against allowed origins |
| `language` | `LanguageMiddleware` | Sets app locale from `Accept-Language` (`pt-BR` → `pt`) |
| `admin` | `AdminMiddleware` | Requires `role = admin` |
| `feature` | `CheckPlanFeatureMiddleware` | Requires specific feature on current plan |
| `plan` | *(group)* | Combines `api.key + check.active.plan + check.limit.plan + api.key.origin + language` |

---

## Artisan Commands

```bash
# Check all plans and fire expiry/grace-period events
php artisan apikey:check-expired

# Check only plans currently in grace period
php artisan apikey:check-expired --grace-only

# Process scheduled renewals (runs daily at 08:00 automatically)
php artisan billing:process-renewals

# Promote a user to admin
php artisan apikey:make-admin {email}
```

---

## Configuration Reference

```php
// config/api-key.php

return [
    'grace_period_days' => 3,

    'rate_limit' => [
        'cache_ttl' => 3600,
    ],

    'cache' => [
        'enabled' => true,
        'ttl'     => 300,       // seconds — general API key cache
        'prefix'  => 'api_key_',
    ],

    'cache_ttl' => [
        'validation' => 300,    // API key validation cache
        'origin'     => 60,     // Origin validation cache
    ],

    'disable_web_middleware' => [
        'enabled' => true,      // auto-disabled when spa.enabled = true
    ],

    'auth_throttle' => [
        'enabled'       => true,
        'attempts'      => 5,
        'decay_minutes' => 1,
    ],

    'header_name'      => 'X-API-KEY',
    'default_language' => 'pt',     // 'pt' or 'en'

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

    'demo_user_id'  => env('API_KEY_DEMO_USER_ID'),
    'internal_token' => env('API_INTERNAL_TOKEN'),

    'spa' => [
        'enabled' => false,
    ],
];
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `API_KEY_GRACE_PERIOD_DAYS` | Grace period days after plan expiry | `3` |
| `API_KEY_CACHE_ENABLED` | Enable API key caching | `true` |
| `API_KEY_CACHE_TTL` | General cache TTL (seconds) | `300` |
| `API_KEY_CACHE_TTL_VALIDATION` | Validation cache TTL (seconds) | `300` |
| `API_KEY_CACHE_TTL_ORIGIN` | Origin cache TTL (seconds) | `60` |
| `API_KEY_RATE_LIMIT_CACHE_TTL` | Rate limit counter TTL (seconds) | `3600` |
| `API_KEY_DISABLE_WEB_MIDDLEWARE` | Attach `DisableRouteWebMiddleware` to `web` group | `true` |
| `API_KEY_AUTH_THROTTLE_ENABLED` | Enable auth endpoint throttling | `true` |
| `API_KEY_AUTH_THROTTLE_ATTEMPTS` | Max login/register attempts | `5` |
| `API_KEY_AUTH_THROTTLE_DECAY` | Throttle decay window (minutes) | `1` |
| `API_KEY_HEADER_NAME` | HTTP header carrying the API key | `X-API-KEY` |
| `API_KEY_DEFAULT_LANGUAGE` | Fallback locale | `pt` |
| `API_KEY_ROUTES_ENABLED` | Auto-register package routes | `true` |
| `API_KEY_ROUTES_PREFIX` | Route prefix | `''` |
| `API_KEY_SPA_ENABLED` | Serve Vue SPA dashboard | `false` |
| `API_KEY_DEMO_USER_ID` | `authentication.id` for the public demo endpoint | — |
| `API_INTERNAL_TOKEN` | Secret for server-to-server calls | — |
| `MP_PUBLIC_KEY` | MercadoPago public key | — |
| `MP_ACCESS_TOKEN` | MercadoPago access token | — |
| `MP_WEBHOOK_SECRET` | MercadoPago webhook HMAC secret | — |

---

## Publish Tags Reference

| Tag | What it publishes | When to use |
|-----|-------------------|-------------|
| `api-key-migrations` | Database migrations | Always |
| `api-key-config` | `config/api-key.php` | When you need to change config values |
| `api-key-lang` | Translation files to `resources/lang/vendor/api-key/` | To override messages |
| `api-key-assets` | Pre-built SPA to `public/vendor/api-key/` | Mode B (SPA enabled) |
| `api-key-views` | `app.blade.php` Blade shell | To customize HTML head |
| `api-key-frontend` | Vue source files to `resources/js/` and `resources/css/` | Level 2 customization |
| `api-key-build` | `package.json`, `vite.config.ts`, `tsconfig.json` | Level 2 customization |

---

## Customization

### Level 1 — Configuration and overrides (no Node.js)

Everything you can change without touching Vue or Blade source:

- **Config values** — publish `api-key-config` and edit `config/api-key.php`
- **Translation messages** — publish `api-key-lang` and edit the PHP/JSON files
- **Blade shell** — publish `api-key-views` to change title, meta tags, fonts, or inject scripts
- **Middleware group** — reorder or replace middlewares in `middleware_group.plan`
- **Events** — register your own listeners for `PlanChanged`, `UserStatusChanged`, etc.

### Level 2 — Full frontend customization (Node.js required)

Publish the Vue source and build config, then work directly in the frontend:

```bash
# 1. Publish Vue source files
php artisan vendor:publish --tag="api-key-frontend"

# 2. Publish build tooling (package.json, vite.config.ts, tsconfig.json)
php artisan vendor:publish --tag="api-key-build"

# 3. Install dependencies
npm install

# 4. Start the dev server
npm run dev

# 5. Build for production (publishes to public/vendor/api-key/ automatically via vite.config.ts)
npm run build
```

After running `npm run build`, run `php artisan view:clear` if the dashboard doesn't pick up changes immediately.

---

## Testing

```bash
# Run all tests
vendor/bin/pest

# Filter by suite
vendor/bin/pest --filter="UserPlan"

# Coverage report
vendor/bin/pest --coverage
```

Enable SQLite in `php.ini` for the test database:

```ini
extension=pdo_sqlite
extension=sqlite3
```

---

## Security

- API keys are stored as bcrypt hashes
- Auth endpoints are rate-limited by default
- Origin header is validated per API key
- Password reset uses Laravel's signed URL mechanism
- Webhook signatures are verified via HMAC

Report security issues to apps@risetech.com.br rather than the public issue tracker.

---

## Changelog

### Version 1.8.0
- Added Vue 3 SPA dashboard with pre-built assets (zero Node.js on host)
- Implemented full password reset flow (forgot + reset) with SPA redirect
- Email verification now redirects to SPA instead of returning JSON
- Added `GracePeriodStartedNotification` and `PlanExpiredNotification` in Portuguese
- Added `ResetPasswordNotification` override in Portuguese
- All controller strings moved to i18n (`lang/pt` and `lang/en`)
- `LanguageMiddleware` normalizes browser locales (`pt-BR` → `pt`)
- `default_language` changed to `pt`
- Added `API_KEY_SPA_ENABLED` config key and catch-all SPA route
- Added MercadoPago, demo user, and internal token config keys
- Added `api-key-assets`, `api-key-views`, `api-key-build`, `api-key-frontend` publish tags

### Version 1.7.1
- Comprehensive test suite (63 tests)
- Grace period for expired subscriptions
- Event system for auditing
- API key bcrypt hashing
- Origin validation middleware
- Caching layer

---

## Credits

- [Rise Tech](https://github.com/risetechapps)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
