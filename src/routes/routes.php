<?php


use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\ProfileController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Modules\ModulesController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans\PlansController;


Route::middleware(['api'])->prefix('api/v1/')->group(function () {


    RiseTechApps\ApiKey\AuthApiKey::routes();


    Route::get('test', [\App\Http\Controllers\ClientController::class, 'test']);
});
