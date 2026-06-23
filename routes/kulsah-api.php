<?php

use Illuminate\Support\Facades\Route;

// Fan routes
Route::prefix('fan')
    ->middleware(['auth:sanctum', 'role:fan'])
    ->group(function () {

    });

// Creator routes
Route::prefix('creator')
    ->middleware(['auth:sanctum', 'role:creator'])
    ->group(function () {

    });

// Shared routes
Route::prefix('creator-fan')
    ->middleware(['auth:sanctum', 'role:creator|fan'])
    ->group(function () {

    });

// General routes
Route::prefix('general')
    ->middleware(['auth:sanctum', 'role:admin|fan|creator'])
    ->group(function () {

    });