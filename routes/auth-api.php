<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;

/*
|--------------------------------------------------------------------------
| AUTH API ROUTES
|--------------------------------------------------------------------------
*/

// Public Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/forgotton-password', [AuthController::class, 'forgottonPassword']);
Route::post('/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Temporary compatibility route
Route::post('/{endpoint}', [AuthController::class, 'socialLogin'])
    ->where('endpoint', 'social-login\s*');

// Check username availability
Route::post('/check-username', [AuthController::class, 'existUsername']);

// Authenticated users
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/activate', [AuthController::class, 'activateAccount']);
    Route::post('/resend', [AuthController::class, 'resendOtp']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/switch-role', [AuthController::class, 'switchRole']);
    Route::post('/update-vibe', [AuthController::class, 'updateVibe']);

    // Backward compatibility for clients that accidentally append a trailing
    // space to the me endpoint, which arrives as /me%20 in the browser.
    Route::get('/me{trailing}', [AuthController::class, 'me'])
        ->where('trailing', '\s*');

    Route::get('/me', [AuthController::class, 'me']);
});
