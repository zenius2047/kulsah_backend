<?php

use App\Services\RealtimePresenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PaystackWebhookController;

Route::post('/webhooks/paystack', PaystackWebhookController::class);

Route::get('/heartbeat', function (Request $request, RealtimePresenceService $presenceService) {
    if ($request->user()) {
        $presenceService->touch($request->user(), $request->integer('active_conversation_id') ?: null);
    }

    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
        'timestamp' => now()->toIso8601String(),
    ]);
})->middleware('optional.sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
