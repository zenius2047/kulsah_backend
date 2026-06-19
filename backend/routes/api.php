<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;

/*
|--------------------------------------------------------------------------
| API V1 ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // AUTH ROUTES (guest)
    Route::prefix('auth')->group(function () {

        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/forgotton-password', [AuthController::class, 'forgottonPassword']);
        Route::post('/reset-password', [AuthController::class,'resetPassword']);
            // frontend calls this to get redirect URL
        Route::get('/{provider}/redirect', [AuthController::class, 'redirectToProvider']);

        // frontend sends provider + token/code here
        Route::post('/{provider}/callback', [AuthController::class, 'socialLogin']);


    });

    // FAN ROUTES
    Route::prefix('fan')->middleware(['auth:sanctum', 'role:fan'])->group(function () {

    });

    // CREATOR ROUTES
    Route::prefix('creator')->middleware(['auth:sanctum', 'role:creator'])->group(function () {

    });

    // SHARED ROUTES
    Route::prefix('creator-fan')->middleware(['auth:sanctum', 'role:creator|fan'])->group(function () {
        // activate account route
        Route::prefix('auth')->group(function () {
            Route::post('/activate', [AuthController::class, 'activateAccount']);
            Route::post('/resend', [AuthController::class,'resendOtp']);
           

        });

    });

    // ADMIN ROUTES
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {

    });

    // all
    Route::get('/user', function (Request $request) {
    return $request->user();
    })->middleware('auth:sanctum');


});

