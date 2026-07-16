<?php

namespace App\Providers;

use App\Jobs\VideoUploaded;
use App\Models\OAuthClient;
use App\Services\FeedService;
use App\Services\VideoCacheService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Laravel\Passport\Contracts\DeviceUserCodeViewResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\Http\Responses\SimpleViewResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passport::useClientModel(OAuthClient::class);

        $this->app->singleton(AuthorizationViewResponse::class, fn () => new SimpleViewResponse('passport.authorize'));
        $this->app->singleton(DeviceAuthorizationViewResponse::class, fn () => new SimpleViewResponse('passport.device.authorize'));
        $this->app->singleton(DeviceUserCodeViewResponse::class, fn () => new SimpleViewResponse('passport.device.user-code'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(VideoUploaded::class, function (VideoUploaded $event): void {
            app(FeedService::class)->invalidateFeedCaches();
            app(VideoCacheService::class)->invalidateCreator((int) $event->video->user_id);
        });
    }
}
