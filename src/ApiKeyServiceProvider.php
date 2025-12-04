<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\ApiKey\Commands\DeactivateExpiredPlansCommand;
use RiseTechApps\ApiKey\Commands\SyncModulesCommand;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Enums\BillingMethod;
use RiseTechApps\ApiKey\Http\Middlewares\AuthenticateApiKey;
use RiseTechApps\ApiKey\Http\Middlewares\CheckActivePlanMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckModuleAccessMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckRequestLimitMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\LanguageMiddleware;
use RiseTechApps\ApiKey\Models\Authentication;
use RiseTechApps\ApiKey\Models\Coupon;

class ApiKeyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {

            $this->commands([
                SyncModulesCommand::class,
                DeactivateExpiredPlansCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'migrations');

            $this->publishes([
                __DIR__ . '/routes/routes.php' => base_path('routes/routes.php'),
            ], 'routes');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/lang');

        $this->registerRouter();
        $this->registerRepository();


        Config::set('auth.providers.users.model', Authentication::class);

        $this->setRules();

        $this->app->booted(function () {
            if (file_exists(base_path('routes/routes.php'))) {
                Route::namespace('')
                    ->group(base_path('routes/routes.php'));
            }
        });
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {

    }

    protected function registerRouter(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('language', LanguageMiddleware::class);
        $router->aliasMiddleware('api.key', AuthenticateApiKey::class);
        $router->aliasMiddleware('check.active.plan', CheckActivePlanMiddleware::class);
        $router->aliasMiddleware('check.module', CheckModuleAccessMiddleware::class);
        $router->aliasMiddleware('check.limit.plan', CheckRequestLimitMiddleware::class);


        $router->middlewareGroup('plan', [
            'api.key',
            'check.active.plan',
            'check.module',
            'check.limit.plan',
            'language'
        ]);

        Route::middleware(['plan'])->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/routes/routes.php');
        });
    }

    protected function registerRepository(): void
    {
        if ($this->app->providerIsLoaded(\RiseTechApps\Repository\RepositoryServiceProvider::class)) {
            $this->app->bind(Repositories\Plan\PlanRepository::class, Repositories\Plan\PlanEloquentRepository::class);
            $this->app->bind(Repositories\Module\ModuleRepository::class, Repositories\Module\ModuleEloquentRepository::class);
            $this->app->bind(Repositories\Coupon\CouponRepository::class, Repositories\Coupon\CouponEloquentRepository::class);
        }
    }

    private function setRules(): void
    {
        $defaultRules = [
            'register' => [
                'name' => 'bail|required|min:5',
                'email' => 'bail|required|email|unique:authentications,email',
                'password' => 'bail|required|min:8',
                'password_confirmation' => 'bail|required|min:8|same:password',
            ],

            'login' => [
                'email' => 'bail|required|email|max:255|exists:authentications,email',
                'password' => 'bail|required|min:8',
            ],

            'profile' => [
                'name' => 'bail|required|string|min:5',
                'cpf' => 'bail|required|min:11|cpf|unique:authentications,cpf',
                'rg' => 'bail|min:5',
                'birth_date' => 'bail|required|date',
                'cellphone' => 'bail|required|min:11|cellphone',
                'telephone' => 'bail',
                'genre' => 'bail',
                'nationality' => 'bail',
                'naturalness' => 'bail',
                'marital_status' => 'bail',
                'email' => 'bail|required|email|unique:authentications,email',
                'address.country' => 'bail|required|string|min:2',
                'address.state' => 'bail|required|string|min:2',
                'address.city' => 'bail|required|string|min:2',
                'address.zip_code' => 'bail|required',
                'address.district' => 'bail|required|min:5',
                'address.address' => 'bail|required|min:5',
                'address.number' => 'bail|required',
            ],

            'plan' => [
                'name' => 'bail|required|min:5|max:255|unique:plans,name',
                'description' => 'bail|nullable',
                'request_limit' => 'bail|required|integer|min:0',
                'price' => 'bail|required|numeric|min:0.01',
                'billing_cycle' => 'bail|required|in:' . implode(',', BillingCycle::values()),
                'is_active' => 'bail|required|boolean',
                'modules.*' => 'bail|required|uuid|exists:modules,id',
            ],

            'signature' => [
                'plan' => 'bail|required|uuid|exists:plans,id',
                'method' => 'bail|required|in:' . implode(',', BillingMethod::values()),
                'method_data' => 'bail|required',
                'coupon_code' => 'bail|nullable|string',
            ],

            'coupon' => [
                'code' => 'bail|required|string|unique:coupons,code',
                'type' => 'bail|required|in:percentage,fixed',
                'value' => 'required|numeric|min:0|max:100|decimal:0,2',
                'max_uses' => 'bail|required|numeric|min:1',
                'expires_at' => 'bail|required|date_format:Y-m-d',
            ]
        ];

        Config::set(
            'rules.forms',
            array_replace_recursive($defaultRules, config('rules.forms', []))
        );
    }
}
