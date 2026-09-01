<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = [
        'total_followers',
        'total_subscribers',
        'total_likes',
    ];

    // fillable attributes
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'avatar',
        'banner',
        'bio',
        'location',
        'activation_otp',
        'verified_at',
        'verified',
        'activated',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'verified_at' => 'datetime',
            'verified' => 'boolean',
            'activated' => 'boolean',
        ];
    }

    // define relationship with roles
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    // define relationship with sessions
    public function sessions()
    {
        return $this->hasMany(Session::class);
    }

    // define relationship with onboarding
    public function onboarding()
    {
        return $this->hasOne(Onboarding::class);
    }

    // fans this user has subscribed to
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'subscriber_id');
    }

    // fans subscribed to this creator
    public function subscribers()
    {
        return $this->hasMany(Subscription::class, 'creator_id');
    }

    // plans created by this creator
    public function subscriptionPlans()
    {
        return $this->hasMany(SubscriptionPlan::class, 'creator_id');
    }

    // define relationship with password reset tokens
    public function passwordResetTokens()
    {
        return $this->hasMany(PasswordResetToken::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function kulCoinWallet()
    {
        return $this->hasOne(KulCoinWallet::class);
    }

    public function kulCoinTransactions()
    {
        return $this->hasMany(KulCoinTransaction::class);
    }

    public function videos()
    {
        return $this->hasMany(Video::class);
    }

    public function createdChallenges()
    {
        return $this->hasMany(Challenge::class, 'created_by_user_id');
    }

    public function challengeEntries()
    {
        return $this->hasMany(ChallengeEntry::class, 'creator_id');
    }

    public function communityPosts()
    {
        return $this->hasMany(CommunityPost::class);
    }

    public function communityPostViews()
    {
        return $this->hasMany(CommunityPostView::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function eventTicketPurchases()
    {
        return $this->hasMany(EventTicketPurchase::class, 'buyer_id');
    }

    public function eventTickets()
    {
        return $this->hasMany(EventTicket::class, 'buyer_id');
    }

    public function videoPlaylists()
    {
        return $this->hasMany(VideoPlaylist::class);
    }

    public function videoLikes()
    {
        return $this->hasMany(VideoLike::class);
    }

    // likes received on this user's videos
    public function likesReceived()
    {
        return $this->hasManyThrough(
            VideoLike::class,
            Video::class,
            'user_id',   // videos.user_id
            'video_id',  // video_likes.video_id
            'id',
            'id'
        );
    }

    public function videoBookmarks()
    {
        return $this->hasMany(VideoBookmark::class);
    }

    public function conversationParticipants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['role', 'last_read_message_id', 'last_read_at', 'archived_at', 'unread_count'])
            ->withTimestamps();
    }

    public function notificationDevices()
    {
        return $this->hasMany(NotificationDevice::class);
    }

    public function notificationPreference()
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function follows()
    {
        return $this->hasMany(UserFollow::class, 'follower_id');
    }

    public function followers()
    {
        return $this->hasMany(UserFollow::class, 'followed_id');
    }

    public function blocks()
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    public function blockedBy()
    {
        return $this->hasMany(UserBlock::class, 'blocked_id');
    }

    public function isFanOf(User $other): bool
    {
        return UserFollow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $other->id)
            ->exists();
    }

    public function areMutualFans(User $other): bool
    {
        return $this->isFanOf($other) && $other->isFanOf($this);
    }

    public function hasActiveSubscriptionTo(User $creator): bool
    {
        return Subscription::query()
            ->where('subscriber_id', $this->id)
            ->where('creator_id', $creator->id)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function isBlockedBy(User $other): bool
    {
        return UserBlock::query()
            ->where('blocker_id', $other->id)
            ->where('blocked_id', $this->id)
            ->exists();
    }

    public function isBlocking(User $other): bool
    {
        return UserBlock::query()
            ->where('blocker_id', $this->id)
            ->where('blocked_id', $other->id)
            ->exists();
    }

    public function signalPreference(): NotificationPreference
    {
        return $this->notificationPreference()->firstOrCreate([
            'user_id' => $this->id,
        ], [
            'messages' => true,
            'challenge_updates' => true,
            'live_events' => true,
            'commerce' => true,
            'marketing' => false,
            'quiet_hours' => null,
            'signal_message_policy' => 'people_i_may_know',
            'signal_allow_contact_sync' => true,
            'signal_discoverable_by_phone' => true,
            'signal_discoverable_by_search' => true,
        ]);
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }

    // Get avatar attribute
    public function getAvatarAttribute($value)
    {
        return $this->resolveS3MediaUrl($value);
    }

    // Get banner attribute
    public function getBannerAttribute($value)
    {
        return $this->resolveS3MediaUrl($value);
    }

    private function resolveS3MediaUrl($value)
    {
        if (! $value) {
            return null;
        }

        // If already a full URL, return as is.
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return Storage::disk('s3')->url($value);
    }

    public function getTotalFollowersAttribute(): int
    {
        if (array_key_exists('followers_count', $this->attributes)) {
            return (int) $this->attributes['followers_count'];
        }

        return (int) $this->followers()->count();
    }

    public function getTotalSubscribersAttribute(): int
    {
        if (array_key_exists('subscribers_count', $this->attributes)) {
            return (int) $this->attributes['subscribers_count'];
        }

        return (int) $this->subscribers()->count();
    }

    public function getTotalLikesAttribute(): int
    {
        if (array_key_exists('likes_received_count', $this->attributes)) {
            return (int) $this->attributes['likes_received_count'];
        }

        if ($this->relationLoaded('videos')) {
            return (int) $this->videos->sum(fn ($video) => (int) ($video->likes_count ?? $video->likes()->count()));
        }

        return (int) DB::table('video_likes')
            ->join('videos', 'videos.id', '=', 'video_likes.video_id')
            ->where('videos.user_id', $this->id)
            ->count();
    }

    public function liveSessions()
    {
        return $this->hasMany(LiveSession::class, 'creator_id');
    }

    public function liveViewerSessions()
    {
        return $this->hasMany(LiveViewerSession::class);
    }

    public function liveCohostRequests()
    {
        return $this->hasMany(LiveCohostRequest::class, 'requester_id');
    }

    public function liveCohosts()
    {
        return $this->hasMany(LiveCohost::class);
    }

    public function liveModerators()
    {
        return $this->hasMany(LiveModerator::class);
    }

    public function liveModerationActions()
    {
        return $this->hasMany(LiveModerationAction::class, 'actor_id');
    }

    public function liveProviderIdentity()
    {
        return $this->hasOne(LiveProviderIdentity::class);
    }

    public function liveBattlesAsCreator()
    {
        return $this->hasMany(LiveBattle::class, 'creator_id');
    }

    public function liveBattlesAsOpponent()
    {
        return $this->hasMany(LiveBattle::class, 'opponent_id');
    }
}

