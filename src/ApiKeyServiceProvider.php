<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\ApiKey\Console\Commands\Billing\ProcessRenewalsCommand;
use RiseTechApps\ApiKey\Console\Commands\CheckExpiredPlans;
use RiseTechApps\ApiKey\Console\Commands\CheckInstallationCommand;
use RiseTechApps\ApiKey\Console\Commands\MakeAdminCommand;
use RiseTechApps\ApiKey\Console\Commands\PruneRequestLogsCommand;
use RiseTechApps\ApiKey\Console\Commands\ReconcilePendingPaymentsCommand;
use RiseTechApps\ApiKey\Console\Commands\RetryValidationRefundsCommand;
use RiseTechApps\ApiKey\Console\Commands\RotateApiKeysCommand;
use RiseTechApps\ApiKey\Events\PaymentRejected;
use RiseTechApps\ApiKey\Events\PlanCancelled;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;
use RiseTechApps\ApiKey\Events\PlanRefunded;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Http\Middlewares\AdminMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\ApiKeyOriginValidatorMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\AuthenticateApiKey;
use RiseTechApps\ApiKey\Http\Middlewares\CheckActivePlanMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckPlanFeatureMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\CheckRequestLimitMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\DisableRouteWebMiddleware;
use RiseTechApps\ApiKey\Http\Middlewares\LanguageMiddleware;
use RiseTechApps\ApiKey\Listeners\SendGracePeriodNotification;
use RiseTechApps\ApiKey\Listeners\SendPaymentRejectedNotification;
use RiseTechApps\ApiKey\Listeners\SendPlanActivatedNotification;
use RiseTechApps\ApiKey\Listeners\SendPlanCancelledNotification;
use RiseTechApps\ApiKey\Listeners\SendPlanExpiredNotification;
use RiseTechApps\ApiKey\Listeners\SendPlanRefundedNotification;
use RiseTechApps\ApiKey\Listeners\SendRequestLimitReachedNotification;
use RiseTechApps\ApiKey\Listeners\SendUsageThresholdNotification;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;
use RiseTechApps\ApiKey\Rules\AuthenticationRules;
use RiseTechApps\ApiKey\Rules\CouponRules;
use RiseTechApps\ApiKey\Rules\PlanRules;
use RiseTechApps\ApiKey\Rules\SignatureRules;
use RiseTechApps\ApiKey\Services\FeatureRegistry;
use RiseTechApps\FormRequest\RulesRegistry;
use RiseTechApps\Repository\RepositoryServiceProvider;

class ApiKeyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/config.php', 'api-key'
        );

        $rulesRegistry = $this->app->make(RulesRegistry::class);

        $packageRoot = dirname(__DIR__);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $packageRoot.'/config/config.php' => config_path('api-key.php'),
            ], 'api-key-config');

            $this->publishes([
                $packageRoot.'/database/migrations' => database_path('migrations'),
            ], 'api-key-migrations');

            $this->publishes([
                __DIR__.'/routes/routes.php' => base_path('routes/routes.php'),
            ], 'api-key-routes');

            $this->publishes([
                __DIR__.'/lang' => resource_path('lang/vendor/api-key'),
            ], 'api-key-lang');

            $this->publishes([
                $packageRoot.'/resources/js' => resource_path('js'),
                $packageRoot.'/resources/css' => resource_path('css'),
            ], 'api-key-frontend');

            $this->publishes([
                $packageRoot.'/resources/views/app.blade.php' => resource_path('views/vendor/api-key/app.blade.php'),
            ], 'api-key-views');

            $this->publishes([
                $packageRoot.'/stubs/package.json' => base_path('package.json'),
                $packageRoot.'/stubs/vite.config.ts' => base_path('vite.config.ts'),
                $packageRoot.'/stubs/tsconfig.json' => base_path('tsconfig.json'),
            ], 'api-key-build');

            $this->publishes([
                $packageRoot.'/dist' => public_path('vendor/api-key'),
            ], 'api-key-assets');
        }

        $this->loadMigrationsFrom($packageRoot.'/database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'api-key');
        $this->loadViewsFrom($packageRoot.'/resources/views', 'api-key');

        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => url('/reset-password?token='.$token.'&email='.urlencode((string) $notifiable->getEmailForPasswordReset())));

        $this->registerRouter();
        $this->registerRepository();
        $this->registerSpaRoute();

        // Replacing the host application's user model is a big thing for a package
        // to do, and it used to happen unconditionally and silently. Still on by
        // default (the package's routes and middlewares assume it), but an app that
        // has its own user model can now opt out.
        if (config('api-key.override_auth_provider', true)) {
            Config::set(
                'auth.providers.users.model',
                Authentication::class
            );
        }

        $this->setRules($rulesRegistry);

        $this->registerEventListeners();

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

            if (config('api-key.request_log.prune_enabled', true)) {
                // Off-peak: the batched delete competes with the writes that the
                // request path is still doing against this table.
                $schedule->command('api-key:prune-logs')
                    ->dailyAt('03:30')
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->appendOutputTo(storage_path('logs/prune-logs.log'));
            }

            if (config('api-key.reconciliation.payments_enabled', true)) {
                // De quinze em quinze minutos porque do outro lado há dinheiro
                // cobrado sem assinatura entregue. O comando ignora esperas com
                // menos de 15 minutos, então isto não corre contra o webhook —
                // só alcança o que ele não entregou. Sem pendências, custa uma
                // consulta indexada que não devolve nada.
                $schedule->command('api-key:reconcile-payments')
                    ->everyFifteenMinutes()
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->appendOutputTo(storage_path('logs/reconcile-payments.log'));
            }

            if (config('api-key.reconciliation.validation_refunds_enabled', true)) {
                // De hora em hora: o valor da validação está no cartão do
                // cliente até voltar, mas o estorno já foi tentado uma vez no
                // cadastro, então aqui se trata da exceção.
                $schedule->command('api-key:retry-validation-refunds')
                    ->hourly()
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->appendOutputTo(storage_path('logs/validation-refunds.log'));
            }
        });
    }

    /**
     * Register the application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('apikey', fn ($app) => new FeatureManager);

        $this->app->singleton('apikey.features', fn ($app) => new FeatureRegistry(
            $app->make('apikey')
        ));

        $this->app->alias('apikey.features', FeatureRegistry::class);

        $this->registerCommands();
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckExpiredPlans::class,
                CheckInstallationCommand::class,
                MakeAdminCommand::class,
                ProcessRenewalsCommand::class,
                PruneRequestLogsCommand::class,
                ReconcilePendingPaymentsCommand::class,
                RetryValidationRefundsCommand::class,
                RotateApiKeysCommand::class,
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

        if (! $spaEnabled && config('api-key.disable_web_middleware.enabled', true)) {
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
            $this->loadRoutesFrom(__DIR__.'/routes/routes.php');
        }
    }

    protected function registerSpaRoute(): void
    {
        if (! config('api-key.spa.enabled', false)) {
            return;
        }

        Route::middleware(['web'])
            ->group(function () {
                Route::get('/{any?}', fn () => view('api-key::app'))
                    ->where('any', '^(?!api).*$')
                    ->name('api-key.spa');
            });
    }

    protected function registerRepository(): void
    {
        if ($this->app->providerIsLoaded(RepositoryServiceProvider::class)) {
            $this->app->bind(PlanRepository::class, Repositories\Plan\PlanEloquentRepository::class);
            $this->app->bind(Repositories\Coupon\CouponRepository::class, Repositories\Coupon\CouponEloquentRepository::class);
        }
    }

    /**
     * Registra os listeners de notificação do pacote.
     * Listeners em pacotes não são auto-descobertos (a auto-discovery do Laravel
     * varre apenas o app/Listeners da aplicação host), então o vínculo
     * evento → listener precisa ser explícito aqui.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(PlanGracePeriodStarted::class, SendGracePeriodNotification::class);
        Event::listen(PlanExpired::class, SendPlanExpiredNotification::class);
        Event::listen(PlanCancelled::class, SendPlanCancelledNotification::class);
        Event::listen(PlanRefunded::class, SendPlanRefundedNotification::class);
        Event::listen(PaymentRejected::class, SendPaymentRejectedNotification::class);
        Event::listen(PlanChanged::class, SendPlanActivatedNotification::class);
        Event::listen(RequestLimitReached::class, SendRequestLimitReachedNotification::class);
        Event::listen(PlanUsageThresholdReached::class, SendUsageThresholdNotification::class);
    }

    private function setRules(RulesRegistry $rulesRegistry): void
    {
        $rulesRegistry->register(AuthenticationRules::class);
        $rulesRegistry->register(PlanRules::class);
        $rulesRegistry->register(CouponRules::class);
        $rulesRegistry->register(SignatureRules::class);
    }
}
