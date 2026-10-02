<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    public function test_ai_generation_is_proxied_without_exposing_the_provider_key(): void
    {
        config()->set('services.google_ai.api_key', 'server-only-key');
        config()->set('services.google_ai.model', 'gemini-test');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'A concise recommendation.']]],
                ]],
            ]),
        ]);

        $response = $this->withoutMiddleware()->postJson('/api/v1/general/ai/generate', [
            'use_case' => 'creator_power_move',
            'context' => ['views' => 1200],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.text', 'A concise recommendation.')
            ->assertJsonMissing(['server-only-key']);
        Http::assertSentCount(1);
    }

    public function test_unknown_ai_use_case_is_rejected_before_calling_provider(): void
    {
        config()->set('services.google_ai.api_key', 'server-only-key');
        Http::fake();

        $this->withoutMiddleware()->postJson('/api/v1/general/ai/generate', [
            'use_case' => 'arbitrary_prompt',
            'context' => [],
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }
}
