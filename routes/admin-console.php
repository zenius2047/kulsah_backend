<?php

use App\Http\Controllers\Api\V1\Admin\ConsoleController;
use App\Http\Controllers\Api\V1\Admin\RevenueRuleController;
use Illuminate\Support\Facades\Route;

Route::post('login', [ConsoleController::class, 'login'])->middleware('throttle:10,1');
Route::post('invitations/accept', [ConsoleController::class, 'acceptInvitation'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('session', [ConsoleController::class, 'session']);
    Route::post('logout', [ConsoleController::class, 'logout']);
    Route::get('dashboard', [ConsoleController::class, 'dashboard']);
    Route::get('revenue', [ConsoleController::class, 'revenue']);
    Route::get('revenue-rules', [RevenueRuleController::class, 'index']);
    Route::post('revenue-rules/preview', [RevenueRuleController::class, 'preview']);
    Route::post('revenue-rules', [RevenueRuleController::class, 'store']);
    Route::get('revenue-rules/{rule}/history', [RevenueRuleController::class, 'history']);
    Route::post('revenue-rules/{rule}/approve', [RevenueRuleController::class, 'approve']);
    Route::post('revenue-rules/{rule}/reject', [RevenueRuleController::class, 'reject']);
    Route::get('search', [ConsoleController::class, 'search']);
    Route::get('coin-overview', [ConsoleController::class, 'coinOverview']);
    Route::get('coin-config', [ConsoleController::class, 'coinConfig']);
    Route::get('related/{resource}/{id}', [ConsoleController::class, 'related']);
    Route::get('alerts', [ConsoleController::class, 'alerts']);
    Route::post('alerts/read', [ConsoleController::class, 'readAlerts']);
    Route::get('profile', [ConsoleController::class, 'profile']);
    Route::patch('profile', [ConsoleController::class, 'updateProfile']);
    Route::post('profile/avatar', [ConsoleController::class, 'updateAvatar']);
    Route::delete('profile/avatar', [ConsoleController::class, 'removeAvatar']);
    Route::post('password', [ConsoleController::class, 'password']);
    Route::get('preferences', [ConsoleController::class, 'preferences']);
    Route::patch('preferences', [ConsoleController::class, 'savePreferences']);
    Route::get('resources/{resource}', [ConsoleController::class, 'index']);
    Route::get('resources/{resource}/{id}', [ConsoleController::class, 'show']);
    Route::post('resources/{resource}', [ConsoleController::class, 'store']);
    Route::patch('resources/{resource}/{id}', [ConsoleController::class, 'update']);
    Route::post('actions', [ConsoleController::class, 'action']);
});
