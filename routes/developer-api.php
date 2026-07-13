<?php

use App\Http\Controllers\Api\V1\Developer\OAuthClientController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')
    ->prefix('apps')
    ->group(function () {
        Route::get('/', [OAuthClientController::class, 'index']);
        Route::get('/{client}', [OAuthClientController::class, 'show']);
        Route::post('/', [OAuthClientController::class, 'store']);
        Route::post('/{client}/regenerate-secret', [OAuthClientController::class, 'regenerateSecret']);
        Route::delete('/{client}', [OAuthClientController::class, 'destroy']);
    });

Route::middleware('auth:sanctum')
    ->prefix('webhooks')
    ->group(function () {

    });
