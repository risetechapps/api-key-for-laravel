<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\ApiKey\Http\Middlewares\AdminMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\ApiKeyOriginValidatorMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\AuthenticateApiKey;
use RiseTechApps\ApiKey\Http\Middlewares\CheckActivePlanMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckPlanFeatureMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckRequestLimitMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\DisableRouteWebMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\LanguageMiddleware;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Rules\AuthenticationRules;
use RiseTechApps\ApiKey\Rules\CouponRules;
use RiseTechApps\ApiKey\Rules\PlanRules;
use RiseTechApps\ApiKey\Rules\SignatureRules;
use RiseTechApps\ApiKey\Console\Commands\Billing\ProcessRenewalsCommand;
use RiseTechApps\ApiKey\Console\Commands\CheckExpiredPlans;
use RiseTechApps\ApiKey\Console\Commands\MakeAdminCommand;
use RiseTechApps\FormRequest\RulesRegistry;

class ApiKeyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/config.php', 'api-key'
        );

        $rulesRegistry = $this->app->make(RulesRegistry::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('api-key.php'),
            ], 'api-key-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'api-key-migrations');

            $this->publishes([
                __DIR__ . '/routes/routes.php' => base_path('routes/routes.php'),
            ], 'api-key-routes');

            $this->publishes([
                __DIR__ . '/lang' => resource_path('lang/vendor/api-key'),
            ], 'api-key-lang');

            $this->publishes([
                __DIR__ . '/../resources/js/' => resource_path('js/'),
                __DIR__ . '/../resources/css/' => resource_path('css/'),
            ], 'api-key-frontend');

            $this->publishes([
                __DIR__ . '/../resources/views/app.blade.php' => resource_path('views/vendor/api-key/app.blade.php'),
            ], 'api-key-views');

            $this->publishes([
                __DIR__ . '/../stubs/package.json'  => base_path('package.json'),
                __DIR__ . '/../stubs/vite.config.ts' => base_path('vite.config.ts'),
                __DIR__ . '/../stubs/tsconfig.json'  => base_path('tsconfig.json'),
            ], 'api-key-build');

            $this->publishes([
                __DIR__ . '/../dist' => public_path('vendor/api-key'),
            ], 'api-key-assets');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/lang', 'api-key');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'api-key');

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return url('/reset-password?token=' . $token . '&email=' . urlencode($notifiable->getEmailForPasswordReset()));
        });

        $this->registerRouter();
        $this->registerRepository();
        $this->registerSpaRoute();


        Config::set('auth.providers.users.model', \RiseTechApps\ApiKey\Models\Authentication\Authentication::class);

        $this->setRules($rulesRegistry);

        $this->app->booted(function () {
            if (file_exists(base_path('routes/routes.php'))) {
                Route::namespace('')
                    ->group(base_path('routes/routes.php'));
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('billing:process-renewals')
                ->dailyAt('08:00')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/renewals.log'));
        });
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->singleton('apikey', function ($app) {
            return new \RiseTechApps\ApiKey\FeatureManager();
        });

        $this->registerCommands();
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckExpiredPlans::class,
                MakeAdminCommand::class,
                ProcessRenewalsCommand::class,
            ]);
        }
    }

    protected function registerRouter(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('admin', AdminMiddleware::class);
        $router->aliasMiddleware('language', LanguageMiddleware::class);
        $router->aliasMiddleware('api.key', AuthenticateApiKey::class);
        $router->aliasMiddleware('check.active.plan', CheckActivePlanMiddleware::class);
        $router->aliasMiddleware('check.limit.plan', CheckRequestLimitMiddleware::class);
        $router->aliasMiddleware('api.key.origin', ApiKeyOriginValidatorMiddleware::class);
        $router->aliasMiddleware('feature', CheckPlanFeatureMiddleware::class);

        $spaEnabled = config('api-key.spa.enabled', false);

        if (!$spaEnabled && config('api-key.disable_web_middleware.enabled', true)) {
            $router->pushMiddlewareToGroup('web', DisableRouteWebMiddleware::class);
        }

        $middlewareGroup = config('api-key.middleware_group.plan', [
            'api.key',
            'check.active.plan',
            'check.limit.plan',
            'api.key.origin',
            'language',
        ]);
        $router->middlewareGroup('plan', $middlewareGroup);

        if (config('api-key.routes.enabled', true)) {
            Route::middleware(['plan'])->group(function () {
                $this->loadRoutesFrom(__DIR__ . '/routes/routes.php');
            });
        }
    }

    protected function registerSpaRoute(): void
    {
        if (!config('api-key.spa.enabled', false)) {
            return;
        }

        Route::middleware(['web'])
            ->group(function () {
                Route::get('/{any}', fn() => view('api-key::app'))
                    ->where('any', '^(?!api).*$')
                    ->name('api-key.spa');
            });
    }

    protected function registerRepository(): void
    {
        if ($this->app->providerIsLoaded(\RiseTechApps\Repository\RepositoryServiceProvider::class)) {
            $this->app->bind(PlanRepository::class, Repositories\Plan\PlanEloquentRepository::class);
            $this->app->bind(Repositories\Coupon\CouponRepository::class, Repositories\Coupon\CouponEloquentRepository::class);
        }
    }

    private function setRules(RulesRegistry $rulesRegistry): void
    {
        $rulesRegistry->register(AuthenticationRules::class);
        $rulesRegistry->register(PlanRules::class);
        $rulesRegistry->register(CouponRules::class);
        $rulesRegistry->register(SignatureRules::class);
    }
}
