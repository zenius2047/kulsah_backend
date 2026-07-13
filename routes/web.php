<?php

use App\Http\Controllers\WebAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
