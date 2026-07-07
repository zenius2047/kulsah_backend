<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // fillable attributes
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'avatar',
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

    //define relationship with sessions
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

    public function videos()
    {
        return $this->hasMany(Video::class);
    }

    public function videoLikes()
    {
        return $this->hasMany(VideoLike::class);
    }

    public function videoBookmarks()
    {
        return $this->hasMany(VideoBookmark::class);
    }

    public function follows()
    {
        return $this->hasMany(UserFollow::class, 'follower_id');
    }

    public function followers()
    {
        return $this->hasMany(UserFollow::class, 'followed_id');
    }

    //GET avatar attribute
    public function getAvatarAttribute($value)
    {
    if (!$value) {
        return null;
    }
    // If already a full URL, return as is
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }
     return Storage::disk('s3')->url($value);
    }



}
