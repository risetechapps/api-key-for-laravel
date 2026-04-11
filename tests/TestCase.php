<?php

namespace RiseTechApps\ApiKey\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use RiseTechApps\ApiKey\ApiKeyServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
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

        $app['config']->set('api-key.grace_period_days', 3);
        $app['config']->set('api-key.token_expiration', 60);
        $app['config']->set('api-key.token_refresh', 1440);
        $app['config']->set('api-key.bcrypt_algorithm', PASSWORD_BCRYPT);

        // Set up filesystem for avatars
        $app['config']->set('filesystems.disks.avatars', [
            'driver' => 'local',
            'root' => sys_get_temp_dir() . '/avatars',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
