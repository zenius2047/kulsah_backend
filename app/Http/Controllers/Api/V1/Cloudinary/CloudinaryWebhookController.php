<?php

namespace App\Http\Controllers\Api\V1\Cloudinary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cloudinary\CloudinaryWebhookRequest;
use App\Services\CloudinaryWebhookService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CloudinaryWebhookController extends Controller
{
    public function store(CloudinaryWebhookRequest $request, CloudinaryWebhookService $webhookService): JsonResponse
    {
        try {
            $result = $webhookService->process(
                payload: $request->all(),
                body: $request->getContent(),
                timestamp: $request->header('X-Cld-Timestamp') ?: $request->header('X-Cloudinary-Timestamp'),
                signature: $request->header('X-Cld-Signature') ?: $request->header('X-Cloudinary-Signature'),
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Invalid Cloudinary webhook signature.') {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 403);
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => $result['status'] === 'duplicate'
                ? 'Cloudinary webhook event already processed.'
                : 'Cloudinary webhook processed successfully.',
            'status' => $result['status'],
        ]);
    }
}
