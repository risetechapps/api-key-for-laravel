<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". But, if you type-hint
| your closure's $request parameter, Pest will automatically detect and use the correct
| test case class.
|
*/

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods which you can use
| to assert different things. All of these methods are available as functions in the global
| namespace.
|
*/

expect()->extend('toBeUuid', function () {
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    return $this->value->toMatch($pattern);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may need to create some additional helper
| functions to make your tests more readable. This file is a great place to define those
| helpers.
|
*/

function createUser(array $attributes = []): Authentication
{
    return Authentication::factory()->create($attributes);
}

function createPlan(array $attributes = []): Plan
{
    return Plan::factory()->create($attributes);
}

function createApiKey(array $attributes = []): ApiKey
{
    return ApiKey::factory()->create($attributes);
}
