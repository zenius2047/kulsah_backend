<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiController extends Controller
{
    private const USE_CASES = [
        'audience_retention',
        'collaboration_match',
        'content_item_strategy',
        'content_library_audit',
        'creator_analytics_audit',
        'creator_identity',
        'creator_library_audit',
        'creator_power_move',
        'live_chat_summary',
        'revenue_advice',
        'smart_replies',
        'sonic_audit',
        'store_description',
        'ticket_recommendation',
    ];

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'use_case' => ['required', 'string', Rule::in(self::USE_CASES)],
            'context' => ['sometimes', 'array', 'max:20'],
            'context.*' => ['nullable'],
        ]);

        $apiKey = (string) config('services.google_ai.api_key');
        abort_if($apiKey === '', 503, 'AI assistance is not configured.');

        $useCase = $validated['use_case'];
        $context = $validated['context'] ?? [];
        $prompt = $this->promptFor($useCase, $context);
        $model = (string) config('services.google_ai.model', 'gemini-2.5-flash');

        $startedAt = microtime(true);
        try {
            $response = Http::timeout(25)
                ->retry(1, 250)
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.urlencode($apiKey),
                    [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'temperature' => 0.5,
                            'maxOutputTokens' => 300,
                            ...($useCase === 'creator_identity' ? ['responseMimeType' => 'application/json'] : []),
                        ],
                    ]
                );
        } catch (\Throwable $exception) {
            Log::warning('ai.generation.failed', [
                'user_id' => $request->user()?->id,
                'use_case' => $useCase,
                'model' => $model,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ]);
            abort(502, 'AI assistance is temporarily unavailable.');
        }

        if (! $response->successful()) {
            report(new \RuntimeException('Google AI request failed with status '.$response->status()));
            abort(502, 'AI assistance is temporarily unavailable.');
        }

        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
        abort_if($text === '', 502, 'AI assistance returned an empty response.');

        $structured = null;
        if ($useCase === 'creator_identity') {
            $structured = json_decode($text, true);
        }

        Log::info('ai.generation.completed', [
            'user_id' => $request->user()?->id,
            'use_case' => $useCase,
            'model' => $model,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'prompt_tokens' => data_get($response->json(), 'usageMetadata.promptTokenCount'),
            'output_tokens' => data_get($response->json(), 'usageMetadata.candidatesTokenCount'),
        ]);

        return response()->json([
            'data' => [
                'text' => $text,
                'structured' => is_array($structured) ? $structured : null,
            ],
        ]);
    }

    private function promptFor(string $useCase, array $context): string
    {
        $safeContext = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return match ($useCase) {
            'creator_power_move' => "Give one concise, actionable content strategy for this creator dashboard context: {$safeContext}",
            'creator_analytics_audit' => "Act as a creator performance analyst. Give a specific two-sentence audit based only on: {$safeContext}",
            'content_library_audit', 'creator_library_audit' => "Give one concise strategic recommendation for this creator library: {$safeContext}",
            'content_item_strategy' => "Give one sentence of advice for the next installment of this content: {$safeContext}",
            'creator_identity' => "Create a tasteful unique creator handle and Kulsah ID for this context: {$safeContext}. Return only JSON with keys handle and id. The ID format is KUL-0000-AA.",
            'revenue_advice' => "Give one concise, responsible revenue recommendation based on: {$safeContext}",
            'store_description' => "Write one magnetic but truthful product description. Do not invent product properties. Context: {$safeContext}",
            'live_chat_summary' => "Summarize the tone of this live chat in one short moderation sentence: {$safeContext}",
            'sonic_audit' => "Write one concise sonic-profile observation based on: {$safeContext}",
            'ticket_recommendation' => "Recommend one of the supplied ticket options in one sentence, based only on: {$safeContext}",
            'audience_retention' => "Give one concise and respectful audience-retention action based on: {$safeContext}",
            'collaboration_match' => "Assess creator collaboration fit in one concise sentence based on: {$safeContext}",
            'smart_replies' => "Return exactly three short, friendly replies separated by newlines for this message context: {$safeContext}",
        };
    }
}
