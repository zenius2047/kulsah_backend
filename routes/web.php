<?php

use App\Http\Controllers\WebAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/videos/{video}', [\App\Http\Controllers\VideoShareController::class, 'show'])->name('videos.share');

Route::get('/.well-known/apple-app-site-association', function () {
    $ids = config('app_links.ios_app_ids');
    abort_if(empty($ids), 503, 'iOS app association is not configured.');
    return response()->json(['applinks' => ['apps' => [], 'details' => array_map(
        fn ($id) => ['appID' => $id, 'paths' => ['/videos/*']], $ids,
    )]]);
});

Route::get('/.well-known/assetlinks.json', function () {
    $targets = config('app_links.android_targets');
    abort_if(empty($targets), 503, 'Android app association is not configured.');
    return response()->json(array_map(fn ($target) => [
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => $target['package_name'],
            'sha256_cert_fingerprints' => $target['sha256_cert_fingerprints']],
    ], $targets));
});

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'create'])->name('login');
    Route::post('/login', [WebAuthController::class, 'store'])->name('web.login');
});

Route::post('/logout', [WebAuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/oauth/third-party/authorize', function (Request $request) {
    $authorizeUrl = url('/oauth/authorize');
    $query = $request->query();

    if (! auth()->guard('web')->check()) {
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect('/login');
    }

    return redirect()->away($authorizeUrl . (empty($query) ? '' : '?' . http_build_query($query)));
});
