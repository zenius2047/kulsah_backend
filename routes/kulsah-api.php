<?php

use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Challenge\ChallengeController;
use App\Http\Controllers\Api\V1\Cloudinary\CloudinaryWebhookController;
use App\Http\Controllers\Api\V1\Community\CommunityPostController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\Creator\CreatorDashboardController;
use App\Http\Controllers\Api\V1\Discovery\DiscoveryController;
use App\Http\Controllers\Api\V1\Event\EventController;
use App\Http\Controllers\Api\V1\Feed\FeedController;
use App\Http\Controllers\Api\V1\Feed\SocialController;
use App\Http\Controllers\Api\V1\Feed\SubscriptionController;
use App\Http\Controllers\Api\V1\KulCoin\KulCoinController;
use App\Http\Controllers\Api\V1\Kulscan\CreatorDashboardController as KulscanCreatorDashboardController;
use App\Http\Controllers\Api\V1\Kulscan\CreatorEventsController;
use App\Http\Controllers\Api\V1\Media\MessageAttachmentController;
use App\Http\Controllers\Api\V1\Music\MusicController;
use App\Http\Controllers\Api\V1\Video\VideoController;
use App\Http\Controllers\Api\V1\Wallet\WalletController;
use App\Http\Controllers\Api\V1\Live\LiveController;
use App\Http\Controllers\Api\V1\Admin\VideoBoostAdminController;
use App\Http\Controllers\Api\V1\Admin\KycApplicationController;
use App\Http\Controllers\Api\V1\Creator\VideoBoostController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\StickerController;
use App\Http\Controllers\Api\V1\UserNotificationController;
use App\Http\Controllers\Api\V1\VoiceCallController;
use App\Http\Controllers\Api\V1\AiController;
use Illuminate\Support\Facades\Route;


Route::prefix('creator/kyc')
    ->middleware(['auth:sanctum', 'role:creator'])
    ->group(function () {
        Route::get('/application', [KycApplicationController::class, 'myApplication']);
        Route::post('/applications', [KycApplicationController::class, 'submit'])->middleware('throttle:10,1');
        Route::post('/applications/{application}/resubmissions', [KycApplicationController::class, 'resubmit'])->whereNumber('application')->middleware('throttle:10,1');
    });

Route::pattern('video', '[0-9]+');
Route::pattern('playlist', '[0-9]+');
Route::pattern('challenge', '[0-9]+');
Route::pattern('entry', '[0-9]+');
Route::pattern('invite', '[0-9]+');
Route::pattern('integrityFlag', '[0-9]+');
Route::pattern('allocation', '[0-9]+');
Route::pattern('attachment', '[0-9]+');
Route::pattern('conversation', '[0-9]+');
Route::pattern('message', '[0-9]+');
Route::pattern('liveSession', '[0-9a-fA-F-]{36}');
Route::pattern('cohostRequest', '[0-9]+');
Route::pattern('battle', '[0-9]+');
Route::pattern('user', '[0-9]+');
Route::pattern('musicTrack', '[^/]+');

Route::post('/cloudinary/webhook', [CloudinaryWebhookController::class, 'store']);

Route::get('/creator/video-boost/campaigns', [VideoBoostController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:creator']);
Route::post('/creator/video-boost/campaigns', [VideoBoostController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:creator', 'throttle:payments']);
Route::post('/creator/video-boost/campaigns/{campaign}/cancel', [VideoBoostController::class, 'cancel'])
    ->middleware(['auth:sanctum', 'role:creator'])->whereNumber('campaign');

Route::prefix('media')
    ->middleware(['auth:sanctum', 'role:creator'])
    ->group(function () {
        Route::post('/video-uploads', [VideoController::class, 'initFastUpload']);
        Route::post('/video-uploads/{video}/complete', [VideoController::class, 'completeFastUpload']);
        Route::post('/videos/{video}/retry-processing', [VideoController::class, 'retryProcessing']);
    });

Route::prefix('media')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::post('/message-uploads', [MessageAttachmentController::class, 'init']);
        Route::post('/message-uploads/{attachment}/complete', [MessageAttachmentController::class, 'complete']);
    });

Route::prefix('creator/music')
    ->middleware(['optional.sanctum', 'throttle:music'])
    ->group(function () {
        Route::get('/', [MusicController::class, 'index'])->name('music.index');
        Route::get('/{musicTrack}', [MusicController::class, 'show'])->name('music.show');
        Route::get('/{musicTrack}/stream', [MusicController::class, 'stream'])->middleware('throttle:music-stream')->name('music.stream');
    });

Route::prefix('general')
    ->middleware(['optional.sanctum'])
    ->group(function () {
        Route::get('/feed', [FeedController::class, 'index']);
        Route::get('/recommendations', [FeedController::class, 'recommendations']);
    });

Route::prefix('general')
    ->middleware(['auth:sanctum', 'role:admin|fan|creator'])
    ->group(function () {
        Route::post('/ai/generate', [AiController::class, 'generate'])->middleware('throttle:20,1');
        Route::get('/discovery', [DiscoveryController::class, 'index'])->middleware('cache.api:60');
        Route::get('/discovery/hashtags', [DiscoveryController::class, 'hashtags'])->middleware('cache.api:60');
        Route::post('/discovery/view', [DiscoveryController::class, 'view']);

        Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount']);
        Route::get('/notifications', [UserNotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [UserNotificationController::class, 'markRead']);
        Route::prefix('stickers')->group(function () {
            Route::get('/', [StickerController::class, 'index']);
            Route::get('/search', [StickerController::class, 'search']);
            Route::get('/recent', [StickerController::class, 'recent']);
            Route::get('/favorites', [StickerController::class, 'favorites']);
            Route::post('/', [StickerController::class, 'store']);
            Route::get('/packs/{stickerPack}', [StickerController::class, 'pack']);
            Route::post('/{sticker}/favorite', [StickerController::class, 'favorite']);
            Route::post('/{sticker}/use', [StickerController::class, 'use']);
            Route::delete('/{sticker}', [StickerController::class, 'destroy']);
        });
        Route::get('/conversations/requests', [ConversationController::class, 'requests']);
        Route::get('/conversations/search', [ConversationController::class, 'search']);
        Route::post('/conversations/reports', [ConversationController::class, 'report']);
        Route::post('/conversations/{messageRequest}/accept', [ConversationController::class, 'acceptRequest']);
        Route::post('/conversations/{messageRequest}/decline', [ConversationController::class, 'declineRequest']);
        Route::post('/conversations/{messageRequest}/block', [ConversationController::class, 'blockRequest']);
        Route::post('/conversations/{messageRequest}/cancel', [ConversationController::class, 'cancelRequest']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read']);
        Route::post('/conversations/{conversation}/typing/start', [ConversationController::class, 'typingStart']);
        Route::post('/conversations/{conversation}/typing/stop', [ConversationController::class, 'typingStop']);
        Route::post('/conversations/{conversation}/calls', [VoiceCallController::class, 'start'])->middleware('throttle:10,1');
        Route::get('/voice-calls/{voiceCall}', [VoiceCallController::class, 'show']);
        Route::post('/voice-calls/{voiceCall}/accept', [VoiceCallController::class, 'accept']);
        Route::post('/voice-calls/{voiceCall}/decline', [VoiceCallController::class, 'decline']);
        Route::post('/voice-calls/{voiceCall}/end', [VoiceCallController::class, 'end']);
        Route::post('/voice-calls/{voiceCall}/credentials', [VoiceCallController::class, 'credentials'])->middleware('throttle:live-token');

        Route::prefix('challenges')
            ->group(function () {
                Route::get('/', [ChallengeController::class, 'index']);
                Route::get('/{challenge}', [ChallengeController::class, 'show']);
                Route::put('/{challenge}/ballot', [ChallengeController::class, 'ballot'])->middleware('throttle:challenge-ballots');
                Route::get('/{challenge}/leaderboard', [ChallengeController::class, 'leaderboard']);
            });
        Route::get('/challenge-rewards', [ChallengeController::class, 'myRewards']);

        Route::get('/community/posts', [CommunityPostController::class, 'index'])->middleware('cache.api:30');
        Route::get('/community/history', [CommunityPostController::class, 'history']);
        Route::get('/community/posts/{communityPost}', [CommunityPostController::class, 'show'])->middleware('cache.api:30');
        Route::post('/community/posts/{communityPost}/view', [CommunityPostController::class, 'view']);
        Route::get('/community/posts/{communityPost}/comments', [CommunityPostController::class, 'comments'])->middleware('cache.api:30');
        Route::post('/community/posts/{communityPost}/comments', [CommunityPostController::class, 'comment']);
        Route::post('/community/posts/{communityPost}/like', [CommunityPostController::class, 'like']);
        Route::delete('/community/posts/{communityPost}/like', [CommunityPostController::class, 'unlike']);
        Route::post('/community/posts/{communityPost}/share', [CommunityPostController::class, 'share']);
        Route::post('/community/posts/{communityPost}/gift', [CommunityPostController::class, 'gift']);
        Route::post('/community/posts/{communityPost}/poll/vote', [CommunityPostController::class, 'vote']);

        Route::get('/events', [EventController::class, 'index'])->middleware('cache.api:60');
        Route::get('/events/{event}', [EventController::class, 'show'])->middleware('cache.api:60');
        Route::post('/events/{event}/tickets/purchase', [EventController::class, 'purchaseTicket']);
        Route::post('/events/tickets/verify', [EventController::class, 'verifyTicket']);

        Route::get('/videos/watched', [VideoController::class, 'watched']);
        Route::post('/videos/{video}/view', [VideoController::class, 'view']);
        Route::get('/videos/{video}', [VideoController::class, 'show']);

        Route::get('/kulcoin/wallet', [KulCoinController::class, 'wallet']);
        Route::get('/kulcoin/ledger', [KulCoinController::class, 'ledger']);
        Route::get('/kulcoin/packages', [KulCoinController::class, 'packages'])->middleware('cache.api:300');
        Route::get('/kulcoin/gifts', [KulCoinController::class, 'gifts'])->middleware('cache.api:300');
        Route::post('/kulcoin/purchase', [KulCoinController::class, 'purchase']);
        Route::post('/kulcoin/gifts/send', [KulCoinController::class, 'sendGift']);
        Route::post('/kulcoin/votes', [KulCoinController::class, 'vote']);
        Route::post('/kulcoin/bonus', [KulCoinController::class, 'bonus']);

        Route::post('/videos/{video}/like', [SocialController::class, 'like']);
        Route::delete('/videos/{video}/like', [SocialController::class, 'unlike']);
        Route::post('/videos/{video}/bookmark', [SocialController::class, 'bookmark']);
        Route::delete('/videos/{video}/bookmark', [SocialController::class, 'unbookmark']);
        Route::post('/videos/{video}/comments', [SocialController::class, 'comment']);
        Route::get('/videos/{video}/comments', [SocialController::class, 'comments'])->middleware('cache.api:30');
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
        Route::post('/payments/paystack/initialize', [PaymentController::class, 'initialize'])->middleware('throttle:payments');
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
        Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->middleware('throttle:payments');
    });

Route::prefix('fan')
    ->middleware(['auth:sanctum', 'role:creator|fan'])
    ->group(function () {
        Route::post('/subscription-plans/{subscriptionPlan}/subscribe', [SubscriptionController::class, 'subscribe']);
    });

Route::prefix('creator')
    ->middleware(['auth:sanctum', 'role:creator|admin'])
    ->group(function () {
        Route::prefix('kulscan')
            ->group(function () {
                Route::get('/dashboard', [KulscanCreatorDashboardController::class, 'show'])->middleware('cache.api:60');
                Route::get('/events', [CreatorEventsController::class, 'index'])->middleware('cache.api:60');
                Route::get('/events/{event}', [CreatorEventsController::class, 'show'])->middleware('cache.api:60');
            });

        Route::get('/dashboard', [CreatorDashboardController::class, 'show'])->middleware('cache.api:60');
        Route::get('/videos', [VideoController::class, 'index']);
        Route::get('/videos/analytics', [VideoController::class, 'analytics']);
        Route::post('/videos/drafts', [VideoController::class, 'draft']);
        Route::post('/videos', [VideoController::class, 'store']);
        Route::post('/videos/{video}/duet-draft', [VideoController::class, 'duetDraft']);
        Route::post('/videos/uploads/init', [VideoController::class, 'initFastUpload']);
        Route::post('/videos/{video}/upload/complete', [VideoController::class, 'completeFastUpload']);
        Route::post('/videos/{video}/processing/retry', [VideoController::class, 'retryProcessing']);
        Route::get('/video-playlists', [VideoController::class, 'playlists']);
        Route::post('/video-playlists', [VideoController::class, 'storePlaylist']);
        Route::post('/video-playlists/{playlist}/videos/bulk', [VideoController::class, 'moveManyToPlaylist']);
        Route::post('/video-playlists/{playlist}/videos/{video}', [VideoController::class, 'moveToPlaylist']);
        Route::get('/video-playlists/{playlist}/videos', [VideoController::class, 'playlistVideos']);
        Route::get('/video-playlists/{playlist}', [VideoController::class, 'showPlaylist']);
        Route::patch('/video-playlists/{playlist}', [VideoController::class, 'updatePlaylist']);
        Route::delete('/video-playlists/{playlist}', [VideoController::class, 'destroyPlaylist']);
        Route::delete('/video-playlists/{playlist}/videos/{video}', [VideoController::class, 'removeFromPlaylist']);
        Route::post('/videos/{video}/upload', [VideoController::class, 'upload']);
        Route::post('/videos/{video}/edits', [VideoController::class, 'edit']);
        Route::patch('/videos/{video}/progress', [VideoController::class, 'updateProgress']);
        Route::get('/videos/{video}', [VideoController::class, 'creatorShow']);
        Route::patch('/videos/{video}', [VideoController::class, 'update']);
        Route::get('/videos/{video}/progress', [VideoController::class, 'progress']);

        Route::post('/community/posts', [CommunityPostController::class, 'store']);

        Route::prefix('challenges')->group(function () {
            Route::get('/', [ChallengeController::class, 'creatorIndex']);
            Route::post('/', [ChallengeController::class, 'store']);
            Route::post('/draft', [ChallengeController::class, 'draft']);
            Route::patch('/{challenge}', [ChallengeController::class, 'update']);
            Route::delete('/{challenge}', [ChallengeController::class, 'destroyDraft']);
            Route::post('/{challenge}/transition', [ChallengeController::class, 'transition']);
            Route::post('/{challenge}/entries', [ChallengeController::class, 'submitEntry'])->middleware('throttle:challenge-entries');
            Route::delete('/{challenge}/entries/{entry}', [ChallengeController::class, 'withdraw']);
            Route::put('/{challenge}/entries/{entry}/jury-scores', [ChallengeController::class, 'juryScore']);
            Route::post('/{challenge}/entries/{entry}/select-winner', [ChallengeController::class, 'selectWinner']);
            Route::post('/{challenge}/finalize', [ChallengeController::class, 'finalize'])->middleware('throttle:challenge-finalize');
            Route::post('/{challenge}/settle-creator-battle', [ChallengeController::class, 'settleCreatorBattle']);
            Route::post('/{challenge}/invites', [ChallengeController::class, 'inviteParticipant']);
            Route::post('/{challenge}/invites/{invite}/accept', [ChallengeController::class, 'acceptInvite']);
            Route::post('/{challenge}/jury', [ChallengeController::class, 'inviteJury']);
            Route::post('/{challenge}/integrity-flags/{integrityFlag}/resolve', [ChallengeController::class, 'resolveIntegrity']);
            Route::post('/{challenge}/reward-allocations/{allocation}/process', [ChallengeController::class, 'processReward']);
        });

        Route::get('/events', [EventController::class, 'creatorIndex'])->middleware('cache.api:60');
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/events/{event}', [EventController::class, 'creatorShow'])->middleware('cache.api:60');
        Route::patch('/events/{event}', [EventController::class, 'update']);

        Route::get('/subscription-plans', [SubscriptionController::class, 'index']);
        Route::get('/audience', [SubscriptionController::class, 'audience']);
        Route::post('/subscription-plans', [SubscriptionController::class, 'store']);
        Route::patch('/subscription-plans/{subscriptionPlan}', [SubscriptionController::class, 'update']);
        Route::post('/subscription-plans/{subscriptionPlan}/disable', [SubscriptionController::class, 'disablePlan']);
        Route::post('/subscriptions/{subscription}/block', [SubscriptionController::class, 'blockSubscriber']);
    });

Route::prefix('creator-fan')
    ->middleware(['auth:sanctum', 'role:creator|fan'])
    ->group(function () {
        Route::get('/creators/{creator}/subscription-plans', [SubscriptionController::class, 'showCreatorPlans']);
    });




Route::prefix('general')
    ->middleware(['optional.sanctum'])
    ->group(function () {
        Route::get('/live', [LiveController::class, 'index']);
        Route::get('/live/{liveSession}', [LiveController::class, 'show']);
    });

Route::prefix('general')
    ->middleware(['auth:sanctum', 'role:admin|fan|creator'])
    ->group(function () {
        Route::post('/live/{liveSession}/preview', [LiveController::class, 'preview'])->middleware('throttle:live-token');
        Route::post('/live/{liveSession}/join', [LiveController::class, 'join']);
        Route::post('/live/{liveSession}/leave', [LiveController::class, 'leave']);
        Route::post('/live/{liveSession}/comments', [LiveController::class, 'comment']);
        Route::post('/live/{liveSession}/likes', [LiveController::class, 'like']);
        Route::post('/live/{liveSession}/gifts', [LiveController::class, 'gift']);
        Route::post('/live/{liveSession}/reports', [LiveController::class, 'report']);
        Route::post('/live/{liveSession}/cohost-requests', [LiveController::class, 'requestCohost']);
        Route::get('/live/{liveSession}/participants', [LiveController::class, 'participants']);
        Route::post('/live/{liveSession}/cohosts/credentials', [LiveController::class, 'cohostCredentials'])->middleware('throttle:live-token');
        Route::post('/live/{liveSession}/cohosts/leave', [LiveController::class, 'leaveCohost']);
        Route::post('/live/cohost-requests/{cohostRequest}/accept', [LiveController::class, 'acceptCohost']);
        Route::post('/live/cohost-requests/{cohostRequest}/decline', [LiveController::class, 'declineCohost']);
        Route::post('/live/battles/{battle}/accept', [LiveController::class, 'acceptBattle']);
        Route::post('/live/battles/{battle}/votes', [LiveController::class, 'voteBattle']);
        Route::post('/live/battles/{battle}/score', [LiveController::class, 'scoreBattle']);
        Route::post('/live/battles/{battle}/end', [LiveController::class, 'endBattle']);
    });

Route::prefix('creator')
    ->middleware(['auth:sanctum', 'role:creator|admin'])
    ->group(function () {
        Route::post('/live', [LiveController::class, 'store'])->middleware('throttle:live-create');
        Route::post('/live/{liveSession}/start', [LiveController::class, 'start'])->middleware('throttle:live-token');
        Route::post('/live/{liveSession}/confirm', [LiveController::class, 'confirm'])->middleware('throttle:live-token');
        Route::post('/live/{liveSession}/reconnect', [LiveController::class, 'reconnect'])->middleware('throttle:live-token');
        Route::post('/live/{liveSession}/heartbeat', [LiveController::class, 'heartbeat']);
        Route::post('/live/{liveSession}/end', [LiveController::class, 'end']);
        Route::post('/live/{liveSession}/moderate', [LiveController::class, 'moderate'])->middleware('throttle:live-moderation');
        Route::post('/live/{liveSession}/cohosts/invite', [LiveController::class, 'inviteCohost'])->middleware('throttle:live-token');
        Route::delete('/live/{liveSession}/cohosts/{user}', [LiveController::class, 'removeCohost'])->middleware('throttle:live-moderation');
        Route::post('/live/{liveSession}/battles/invite', [LiveController::class, 'inviteBattle'])->middleware('throttle:live-token');
        Route::get('/live/{liveSession}/battle-creators', [LiveController::class, 'battleCreators']);
        Route::get('/live/{liveSession}/analytics', [LiveController::class, 'analytics']);
    });
