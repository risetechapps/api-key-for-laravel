<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\ApiKey\Commands\DeactivateExpiredPlansCommand;
use RiseTechApps\ApiKey\Commands\SyncModulesCommand;
use RiseTechApps\ApiKey\Http\Middlewares\AuthenticateApiKey;
use RiseTechApps\ApiKey\Http\Middlewares\CheckActivePlanMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckModuleAccessMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckRequestLimitMiddleware;
use RiseTechApps\ApiKey\Models\Authentication;

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
                __DIR__.'/routes/routes.php' => base_path('routes/routes.php'),
            ], 'routes');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        app('router')->aliasMiddleware('api.key', AuthenticateApiKey::class);
        app('router')->aliasMiddleware('check.active.plan', CheckActivePlanMiddleware::class);
        app('router')->aliasMiddleware('check.module', CheckModuleAccessMiddleware::class);
        app('router')->aliasMiddleware('check.limite.plan', CheckRequestLimitMiddleware::class);

        Config::set('auth.providers.users.model', Authentication::class);

        $this->setRules();

        $router = $this->app->make(Router::class);

        $router->middlewareGroup('plan', [
            'api.key',
            'check.active.plan',
            'check.module',
            'check.limite.plan'
        ]);

        Route::middleware(['plan'])->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/routes/routes.php');
        });

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
        // Register the main class to use with the facade
        $this->app->singleton('authapikey', function () {
            return new AuthApiKey();
        });
    }

    private function setRules(): void
    {
        Config::set('rules.forms', [
            'register' => [
                'name' => 'bail|required|min:5',
                'email' => 'bail|required|email|unique:authentications,email',
                'password' => 'bail|required|min:8',
                'password_confirmation' => 'bail|required|min:8|same:password',
            ],

            'login' => [
                'email' => 'required|email|max:255|exists:authentications,email',
                'password' => 'required|min:8',
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
                'name' => 'bail|required|min:5',
                'price' => 'bail|required|numeric|min:1',
                'request_limit' => 'bail|required|numeric:min:0',
                'duration_days' => 'bail|required|integer|min:1',
                'modules' => 'bail'
            ],

            'module' => [
                'name' => 'bail|required|string|min:5',
            ],

            'plan_associate' => [
                'auth_id' => 'bail|required',
                'plan_id' => 'bail|required',
            ]
        ]);
    }
}
