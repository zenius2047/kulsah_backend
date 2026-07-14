<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\KulCoin\KulCoinController;
use App\Http\Controllers\Api\V1\Feed\FeedController;
use App\Http\Controllers\Api\V1\Feed\SocialController;
use App\Http\Controllers\Api\V1\Feed\SubscriptionController;
use App\Http\Controllers\Api\V1\Video\VideoController;
use App\Http\Controllers\Api\V1\Wallet\WalletController;

// Fan and creator routes
Route::prefix('fan')
    ->middleware(['auth:sanctum', 'role:creator|fan'])
    ->group(function () {
        Route::post('/subscription-plans/{subscriptionPlan}/subscribe', [SubscriptionController::class, 'subscribe']);
    });

// Creator routes
Route::prefix('creator')
    ->middleware(['auth:sanctum', 'role:creator'])
    ->group(function () {
        Route::get('/videos', [VideoController::class, 'index']);
        Route::get('/videos/analytics', [VideoController::class, 'analytics']);
        Route::get('/videos/{video}', [VideoController::class, 'creatorShow']);
        Route::post('/videos/drafts', [VideoController::class, 'draft']);
        Route::post('/videos', [VideoController::class, 'store']);
        Route::get('/video-playlists', [VideoController::class, 'playlists']);
        Route::get('/video-playlists/{playlist}', [VideoController::class, 'showPlaylist']);
        Route::get('/video-playlists/{playlist}/videos', [VideoController::class, 'playlistVideos']);
        Route::post('/video-playlists', [VideoController::class, 'storePlaylist']);
        Route::patch('/video-playlists/{playlist}', [VideoController::class, 'updatePlaylist']);
        Route::delete('/video-playlists/{playlist}', [VideoController::class, 'destroyPlaylist']);
        Route::post('/video-playlists/{playlist}/videos/{video}', [VideoController::class, 'moveToPlaylist']);
        Route::delete('/video-playlists/{playlist}/videos/{video}', [VideoController::class, 'removeFromPlaylist']);
        Route::post('/videos/{video}/upload', [VideoController::class, 'upload']);
        Route::patch('/videos/{video}/progress', [VideoController::class, 'updateProgress']);
        Route::patch('/videos/{video}', [VideoController::class, 'update']);
        Route::get('/videos/{video}/progress', [VideoController::class, 'progress']);
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
        Route::get('/feed', [FeedController::class, 'index']);
        Route::get('/recommendations', [FeedController::class, 'recommendations']);
        Route::get('/videos/watched', [VideoController::class, 'watched']);
        Route::get('/videos/{video}', [VideoController::class, 'show']);
        Route::post('/videos/{video}/view', [VideoController::class, 'view']);
        Route::get('/kulcoin/wallet', [KulCoinController::class, 'wallet']);
        Route::get('/kulcoin/ledger', [KulCoinController::class, 'ledger']);
        Route::get('/kulcoin/packages', [KulCoinController::class, 'packages']);
        Route::get('/kulcoin/gifts', [KulCoinController::class, 'gifts']);
        Route::post('/kulcoin/purchase', [KulCoinController::class, 'purchase']);
        Route::post('/kulcoin/gifts/send', [KulCoinController::class, 'sendGift']);
        Route::post('/kulcoin/votes', [KulCoinController::class, 'vote']);
        Route::post('/kulcoin/bonus', [KulCoinController::class, 'bonus']);
        Route::post('/videos/{video}/like', [SocialController::class, 'like']);
        Route::delete('/videos/{video}/like', [SocialController::class, 'unlike']);
        Route::post('/videos/{video}/bookmark', [SocialController::class, 'bookmark']);
        Route::delete('/videos/{video}/bookmark', [SocialController::class, 'unbookmark']);
        Route::post('/videos/{video}/comments', [SocialController::class, 'comment']);
        Route::get('/videos/{video}/comments', [SocialController::class, 'comments']);
        Route::post('/videos/{video}/comments/{comment}/reply', [SocialController::class, 'reply']);
        Route::post('/videos/{video}/comments/{comment}/like', [SocialController::class, 'likeComment']);
        Route::delete('/videos/{video}/comments/{comment}/like', [SocialController::class, 'unlikeComment']);
        Route::post('/creators/{creator}/follow', [SocialController::class, 'follow']);
        Route::delete('/creators/{creator}/follow', [SocialController::class, 'unfollow']);
        Route::post('/upload-avatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('/upload-banner', [ProfileController::class, 'uploadBanner']);
        Route::post('/update-profile', [ProfileController::class, 'updateProfile']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::get('/wallet/ledger', [WalletController::class, 'ledger']);
        Route::post('/wallet/transfer', [WalletController::class, 'transfer']);
        Route::post('/wallet/top-up', [WalletController::class, 'topUp']);
    });
