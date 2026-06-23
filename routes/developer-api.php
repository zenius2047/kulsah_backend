<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')
    ->prefix('apps')
    ->group(function () {

    });

Route::middleware('auth:sanctum')
    ->prefix('webhooks')
    ->group(function () {

    });