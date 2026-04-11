# risetechapps/api-key-for-laravel - API Key Management & Subscription Plans

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![GitHub Actions](https://github.com/risetechapps/api-key-for-laravel/actions/workflows/main.yml/badge.svg)](https://github.com/risetechapps/api-key-for-laravel/actions)
[![Tests](https://img.shields.io/badge/tests-63%20passing-green.svg)](tests)
[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12-red.svg)](https://laravel.com)

## About

`risetechapps/api-key-for-laravel` is a comprehensive solution for API key management, subscription plans, discount coupons, and request logging in Laravel applications.

### Features

- 🔐 **Secure API Key Management** with bcrypt hashing
- 📊 **Subscription Plans** with billing cycles and feature limits
- ⏰ **Grace Period** for expired subscriptions
- 🎟️ **Coupon System** with usage limits and expiration dates
- 🛡️ **Origin Validation** for API keys (CORS-like protection)
- 📈 **Request Throttling** and rate limiting
- 🔄 **Event System** for auditing and notifications
- 💾 **Caching Layer** for improved performance
- 🌍 **Internationalization** support (English & Portuguese)
- ✅ **Comprehensive Test Suite** with Pest

## Requirements

- PHP ^8.4
- Laravel ^12
- Laravel Sanctum ^4.0

## Installation

```bash
composer require risetechapps/api-key-for-laravel
```

## Configuration

### 1. Publish and Run Migrations

```bash
php artisan vendor:publish --provider="RiseTechApps\ApiKey\ApiKeyServiceProvider" --tag="migrations"
php artisan migrate
```

### 2. Add the `HasApiKey` Trait

```php
// app/Models/User.php

use RiseTechApps\ApiKey\Traits\HasApiKey;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiKey;
    // ...
}
```

### 3. Publish Configuration (Optional)

```bash
php artisan vendor:publish --provider="RiseTechApps\ApiKey\ApiKeyServiceProvider" --tag="config"
```

### 4. Publish Translations (Optional)

```bash
php artisan vendor:publish --provider="RiseTechApps\ApiKey\ApiKeyServiceProvider" --tag="lang"
```

## Configuration Options

```php
// config/api-key.php

return [
    // Grace period days for expired subscriptions
    'grace_period_days' => 3,
    
    // Token expiration time in minutes
    'token_expiration' => 60,
    
    // Token refresh time in minutes
    'token_refresh' => 1440,
    
    // Bcrypt algorithm for API key hashing
    'bcrypt_algorithm' => PASSWORD_BCRYPT,
];
```

## Usage

### API Key Authentication

Protect your routes with the `plan` middleware group:

```php
Route::middleware(['api', 'plan'])->group(function () {
    Route::get('/api/v1/protected', function () {
        return response()->json(['message' => 'Access granted']);
    });
});
```

Send requests with the API key header:

```bash
curl -H "X-API-KEY: your-api-key-here" \
     -H "Origin: https://yourdomain.com" \
     https://api.yourapp.com/api/v1/protected
```

### Subscription Plans

```php
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Enums\BillingCycle;

// Create a plan
$plan = Plan::create([
    'name' => 'Premium',
    'description' => 'Premium plan with 10k requests',
    'request_limit' => 10000,
    'price' => 29.99,
    'billing_cycle' => BillingCycle::MONTHLY,
    'features' => ['feature1', 'feature2'],
]);

// Subscribe a user
$user->subscribeToPlan($plan);
```

### Grace Period

Expired subscriptions automatically enter a grace period:

```php
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

$userPlan = UserPlan::where('authentication_id', $user->id)->first();

if ($userPlan->isInGracePeriod()) {
    $daysRemaining = $userPlan->getGracePeriodRemainingDays();
    // Notify user about renewal
}
```

### Coupon System

```php
use RiseTechApps\ApiKey\Models\Coupon\Coupon;

$coupon = Coupon::create([
    'code' => 'SAVE20',
    'discount_percentage' => 20,
    'max_uses' => 100,
    'valid_until' => now()->addMonth(),
]);

// Validate coupon
if ($coupon->isValid()) {
    // Apply discount
}
```

### Events

The package fires events for auditing:

```php
use RiseTechApps\ApiKey\Events\ApiKeyCreated;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Events\RequestLimitReached;

// Listen to events
Event::listen(ApiKeyCreated::class, function ($event) {
    // Log API key creation
});

Event::listen(PlanExpired::class, function ($event) {
    // Send notification
});
```

### Artisan Commands

Check for expired plans:

```bash
# Check all expired plans
php artisan apikey:check-expired

# Check only grace period entries
php artisan apikey:check-expired --grace-only
```

## Middleware

| Middleware | Description |
|------------|-------------|
| `api.key` | Validates API key authentication |
| `check.active.plan` | Ensures user has an active subscription |
| `check.limit.plan` | Validates request limits |
| `api.key.origin` | Validates request origin |
| `plan` | Combined middleware group (api.key + check.active.plan + check.limit.plan + api.key.origin + language) |

## Models

| Model | Description |
|-------|-------------|
| `ApiKey` | API key with bcrypt hashing and origin validation |
| `Plan` | Subscription plans with features and billing cycles |
| `Coupon` | Discount coupons with usage tracking |
| `UserPlan` | User subscription with grace period support |
| `RequestLog` | API request logging |
| `Authentication` | User model for API authentication |

## Testing

Run the test suite with Pest:

```bash
# Run all tests
vendor/bin/pest

# Run specific test suite
vendor/bin/pest --filter="UserPlan"

# Run with coverage
vendor/bin/pest --coverage
```

### Test Coverage

- **Unit Tests**: Models, Services
- **Feature Tests**: HTTP Controllers, Middleware, Events, Commands
- **Total**: 63 test cases

### Required for Testing

Enable SQLite extension in `php.ini`:
```ini
extension=pdo_sqlite
extension=sqlite3
```

## Security Features

- ✅ **Bcrypt hashing** for API keys (not stored in plain text)
- ✅ **Rate limiting** on authentication endpoints
- ✅ **Origin validation** prevents unauthorized domains
- ✅ **Automatic deactivation** of expired API keys
- ✅ **Secure token generation** with configurable expiration

## Performance Optimizations

- 🚀 **API key caching** with Redis/Memcached support
- 🚀 **Eager loading** on subscription queries
- 🚀 **Query optimization** with proper indexing
- 🚀 **Lazy loading** for avatar generation

## Changelog

### Version 1.7.1
- Added comprehensive test suite (63 tests)
- Implemented grace period for expired subscriptions
- Added event system for auditing
- Improved API key security with bcrypt hashing
- Added origin validation middleware
- Implemented caching layer for performance
- Fixed multiple bugs and security issues
- Added internationalization support

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security-related issues, please email apps@risetech.com.br instead of using the issue tracker.

## Credits

- [Rise Tech](https://github.com/risetechapps)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
