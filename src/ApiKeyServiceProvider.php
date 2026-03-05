<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
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
use RiseTechApps\FormRequest\RulesRegistry;

class ApiKeyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {

        $rulesRegistry = $this->app->make(RulesRegistry::class);

        if ($this->app->runningInConsole()) {

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


        Config::set('auth.providers.users.model', \RiseTechApps\ApiKey\Models\Authentication\Authentication::class);

        $this->setRules($rulesRegistry);

        $this->app->booted(function () {
            if (file_exists(base_path('routes/routes.php'))) {
                Route::namespace('')
                    ->group(base_path('routes/routes.php'));
            }
        });

        Feature::define('package-feature', function (\RiseTechApps\ApiKey\Models\Authentication\Authentication $user, string $feature) {
            // 1. Carrega o plano ativo com os dados do plano pai
            $activeUserPlan = $user->activePlan()->with('plan')->first();

            // 2. Se não tiver plano ativo, bloqueia tudo
            if (!$activeUserPlan || !$activeUserPlan->plan) {
                return false;
            }

            // 3. Verifica se a string $feature está dentro do array 'features' do plano
            $allowedFeatures = $activeUserPlan->plan->features ?? [];

            return in_array($feature, $allowedFeatures);
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
        $router->aliasMiddleware('check.limit.plan', CheckRequestLimitMiddleware::class);
        $router->aliasMiddleware('api.key.origin', ApiKeyOriginValidatorMiddleware::class);
        $router->aliasMiddleware('feature', CheckPlanFeatureMiddleware::class);

        $router->pushMiddlewareToGroup('web', DisableRouteWebMiddleware::class);

        $router->middlewareGroup('plan', [
            'api.key',
            'check.active.plan',
            'check.limit.plan',
            'api.key.origin',
            'language'
        ]);

        Route::middleware(['plan'])->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/routes/routes.php');
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

//046.699.172-00
//04669917200
//11991609296
//11991609246
