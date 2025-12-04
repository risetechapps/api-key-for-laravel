<?php


use Illuminate\Support\Facades\Route;


Route::middleware(['api', 'plan'])->prefix('api/v1/')->group(function () {

});
