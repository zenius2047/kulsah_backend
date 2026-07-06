<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Feed\SubscriptionController;
use App\Http\Controllers\Api\V1\Wallet\WalletController;

// Fan routes
Route::prefix('fan')
    ->middleware(['auth:sanctum', 'role:fan'])
    ->group(function () {
        Route::post('/subscription-plans/{subscriptionPlan}/subscribe', [SubscriptionController::class, 'subscribe']);
    });

// Creator routes
Route::prefix('creator')
    ->middleware(['auth:sanctum', 'role:creator'])
    ->group(function () {
        Route::get('/subscription-plans', [SubscriptionController::class, 'index']);
        Route::post('/subscription-plans', [SubscriptionController::class, 'store']);
        Route::patch('/subscription-plans/{subscriptionPlan}', [SubscriptionController::class, 'update']);
        Route::post('/subscription-plans/{subscriptionPlan}/disable', [SubscriptionController::class, 'disablePlan']);
        Route::post('/subscriptions/{subscription}/block', [SubscriptionController::class, 'blockSubscriber']);
    });

// Shared routes
Route::prefix('creator-fan')
    ->middleware(['auth:sanctum', 'role:creator|fan'])
    ->group(function () {
        Route::get('/creators/{creator}/subscription-plans', [SubscriptionController::class, 'showCreatorPlans']);
    });

// General routes
Route::prefix('general')->middleware(['auth:sanctum', 'role:admin|fan|creator'])
    ->group(function () {
        Route::post('/upload-avatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('/update-profile', [ProfileController::class, 'updateProfile']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::get('/wallet/ledger', [WalletController::class, 'ledger']);
        Route::post('/wallet/transfer', [WalletController::class, 'transfer']);
        Route::post('/wallet/top-up', [WalletController::class, 'topUp']);
    });
