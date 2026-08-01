<?php

namespace RiseTechApps\ApiKey\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RiseTechApps\ApiKey\ApiKeyServiceProvider;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\Address\AddressServiceProvider;
use RiseTechApps\CodeGenerate\CodeGenerateServiceProvider;
use RiseTechApps\FormRequest\FormRequestServiceProvider;
use RiseTechApps\Media\MediaServiceProvider;
use RiseTechApps\Repository\RepositoryServiceProvider;
use RiseTechApps\RiseTools\RiseToolsServiceProvider;
use RiseTechApps\ToUpper\ToUpperServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // As factories do pacote ficam em Database\Factories, mas os models estão
        // sob RiseTechApps\ApiKey\Models — a convenção padrão do Laravel não liga
        // os dois. Este resolver mapeia Model => Database\Factories\<Model>Factory.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            // CodeGenerate registra o macro de Blueprint `codeGenerate()` usado
            // nas migrations; FormRequest fornece a RulesRegistry e a migration da
            // tabela `form_requests`. Nenhum é auto-descoberto pelo Testbench.
            CodeGenerateServiceProvider::class,
            FormRequestServiceProvider::class,
            // RiseTools registra os macros de resposta (jsonSuccess/jsonGone/…),
            // o helper avatarGenerator() e o Device usado pelos middlewares.
            RiseToolsServiceProvider::class,
            // ToUpper: sem ele o config 'to-upper' fica vazio e o `ignore_attributes`
            // default (password/password_confirm) não vale — a senha seria
            // normalizada para maiúsculo e o cast `hashed` re-hashearia, quebrando
            // o login.
            ToUpperServiceProvider::class,
            // Media fornece o binding PathGeneratorContract usado no fluxo de
            // avatar do registro (addMediaFromDisk) e a migration da tabela media.
            MediaServiceProvider::class,
            // Address traz a migration da tabela `addresses`. O Authentication tem
            // relação `address()` e o ProfileController faz eager load dela em
            // show(); sem este provider o endpoint estoura com "no such table:
            // addresses" e não pode ser testado.
            AddressServiceProvider::class,
            // Sanctum registra o driver do guard 'sanctum' (usado por auth:sanctum
            // no /me e pelo createToken) e a migration de personal_access_tokens.
            SanctumServiceProvider::class,
            // Antes do ApiKeyServiceProvider de propósito: o boot() dele só liga
            // PlanRepository/CouponRepository às implementações Eloquent quando
            // este provider já está carregado. Sem ele, qualquer controller que
            // receba um repository por injeção é irresolvível ("Target
            // [PlanRepository] is not instantiable") e não pode ser testado.
            RepositoryServiceProvider::class,
            ApiKeyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // O pacote code-generate gera o `code` dos models, mas seu factory de
        // driver não suporta sqlite (só mysql/pgsql/sqlsrv). Nos testes o code não
        // é essencial (as factories já o definem, e as colunas são nullable), então
        // desligamos o hard-fail: a criação segue sem code em vez de estourar.
        $app['config']->set('code-generate.throw_on_error', false);

        // Filas rodam de forma síncrona nos testes. Em produção o pacote usa as
        // conexões 'redis'/'logs' (Horizon), que não existem no Testbench; sem
        // anular isto, despachar um listener/job enfileirado estoura. E-mail vai
        // para o driver 'array' (capturado, nunca enviado de verdade).
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('api-key.queue.connection', null);
        $app['config']->set('api-key.queue.name', null);
        $app['config']->set('api-key.request_log.queue', null);
        $app['config']->set('api-key.request_log.connection', null);

        // Guard/provider explícitos para o Auth::attempt do AuthService: garante
        // que o provider 'users' resolva o model Authentication via eloquent,
        // independentemente do default do skeleton do Testbench.
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.guards.sanctum', ['driver' => 'sanctum', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => Authentication::class]);

        $app['config']->set('api-key.grace_period_days', 3);
        $app['config']->set('api-key.token_expiration', 60);
        $app['config']->set('api-key.token_refresh', 1440);
        $app['config']->set('api-key.bcrypt_algorithm', PASSWORD_BCRYPT);

        // Set up filesystem for avatars
        $app['config']->set('filesystems.disks.avatars', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/avatars',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineRoutes($router): void
    {
        // Endpoint sintético usado pelos testes de middleware: exercita o grupo
        // 'plan' (api.key + check.active.plan + check.limit.plan + api.key.origin
        // + language) sem depender das rotas reais do pacote.
        $router->middleware('plan')->get('/api/v1/test-endpoint', function () {
            return response()->json(['message' => 'ok']);
        });

        // Falha do servidor: a cota reservada antes do request tem que voltar.
        $router->middleware('plan')->get('/api/v1/test-endpoint-server-error', function () {
            abort(500);
        });

        // Erro do cliente: a cota continua consumida, senão bastaria mandar
        // requisição malformada para nunca gastar o plano.
        $router->middleware('plan')->get('/api/v1/test-endpoint-client-error', function () {
            abort(422);
        });
    }
}
