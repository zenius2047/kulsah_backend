<?php

namespace App\Providers;

use App\Jobs\ExtractChallengeCoverFrame;
use App\Jobs\VideoUploaded;
use App\Models\ChallengeMedia;
use App\Models\OAuthClient;
use App\Services\FeedService;
use App\Services\VideoCacheService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Laravel\Passport\Contracts\DeviceUserCodeViewResponse;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Laravel\Passport\Passport;

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
        RateLimiter::for('challenge-create', fn (Request $request) => Limit::perHour(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('challenge-entries', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('challenge-ballots', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('challenge-jury', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('challenge-finalize', fn (Request $request) => Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()));

        Event::listen(VideoUploaded::class, function (VideoUploaded $event): void {
            app(FeedService::class)->invalidateFeedCaches();
            app(VideoCacheService::class)->invalidateCreator((int) $event->video->user_id);
            ChallengeMedia::query()->where('video_id', $event->video->id)->pluck('id')
                ->each(fn (int $id) => ExtractChallengeCoverFrame::dispatch($id));
        });
    }
}
