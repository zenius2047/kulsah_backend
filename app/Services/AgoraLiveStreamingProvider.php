<?php

namespace App\Services;

use App\Contracts\LiveStreamingProviderInterface;
use App\Models\LiveProviderIdentity;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AgoraLiveStreamingProvider implements LiveStreamingProviderInterface
{
    public function channelName(LiveSession $live): string
    {
        return $live->provider_channel ?: 'kulsah-live-'.Str::lower(Str::random(32));
    }

    public function credentials(LiveSession $live, User $user, string $role): array
    {
        $identity = LiveProviderIdentity::query()->firstOrCreate(
            ['provider' => 'agora', 'user_id' => $user->id],
            ['provider_uid' => $this->uid($user)]
        );

        $ttl = $role === 'broadcaster'
            ? (int) config('agora.publisher_token_ttl', 900)
            : (int) config('agora.viewer_token_ttl', 900);
        $expiresAt = now()->addSeconds($ttl);

        $this->assertConfigured();
        $this->loadOfficialTokenBuilder();

        $token = \RtcTokenBuilder2::buildTokenWithUid(
            (string) config('agora.app_id'),
            (string) config('agora.app_certificate'),
            (string) $live->provider_channel,
            (int) $identity->provider_uid,
            $role === 'broadcaster' ? \RtcTokenBuilder2::ROLE_PUBLISHER : \RtcTokenBuilder2::ROLE_SUBSCRIBER,
            $ttl,
            $ttl
        );

        return [
            'provider' => 'agora',
            'app_id' => config('agora.app_id'),
            'channel' => $live->provider_channel,
            'uid' => (int) $identity->provider_uid,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'role' => $role,
        ];
    }

    public function renewCredentials(LiveSession $live, User $user, string $role): array
    {
        return $this->credentials($live, $user, $role);
    }

    public function startRecording(LiveSession $live): array
    {
        $this->assertRecordingConfigured();
        $baseUrl = $this->recordingBaseUrl();
        $appId = (string) config('agora.app_id');
        $uid = '0';

        try {
            $acquired = $this->recordingRequest('post', "$baseUrl/$appId/cloud_recording/acquire", [
                'cname' => $live->provider_channel,
                'uid' => $uid,
                'clientRequest' => [
                    'resourceExpiredHour' => (int) config('agora.recording_resource_expiry_hours', 24),
                ],
            ]);

            $resourceId = (string) ($acquired['resourceId'] ?? '');
            if ($resourceId === '') {
                throw new RuntimeException('Agora did not return a recording resource ID.');
            }

            $started = $this->recordingRequest(
                'post',
                "$baseUrl/$appId/cloud_recording/resourceid/".rawurlencode($resourceId).'/mode/mix/start',
                [
                    'cname' => $live->provider_channel,
                    'uid' => $uid,
                    'clientRequest' => [
                        'token' => $this->recordingRtcToken($live),
                        'recordingFileConfig' => ['avFileType' => ['hls', 'mp4']],
                        'storageConfig' => $this->storageConfig(),
                    ],
                ]
            );

            return [
                'provider' => 'agora',
                'status' => 'recording',
                'resource_id' => $resourceId,
                'sid' => $started['sid'] ?? null,
                'raw' => $started,
            ];
        } catch (RequestException $e) {
            throw new RuntimeException('Agora recording could not be started.', 0, $e);
        }
    }

    public function stopRecording(LiveSession $live, ?string $resourceId = null, ?string $sid = null): array
    {
        if (! $resourceId || ! $sid) {
            return ['provider' => 'agora', 'status' => 'failed', 'resource_id' => $resourceId, 'sid' => $sid];
        }

        $appId = (string) config('agora.app_id');
        $response = $this->recordingRequest(
            'delete',
            $this->recordingBaseUrl()."/$appId/cloud_recording/resourceid/".rawurlencode($resourceId).'/sid/'.rawurlencode($sid).'/mode/mix/stop',
            ['cname' => $live->provider_channel, 'uid' => '0', 'clientRequest' => []]
        );

        return ['provider' => 'agora', 'status' => 'processing', 'resource_id' => $resourceId, 'sid' => $sid, 'raw' => $response];
    }

    public function queryRecording(LiveSession $live, ?string $resourceId = null, ?string $sid = null): array
    {
        if (! $resourceId || ! $sid) {
            return ['provider' => 'agora', 'status' => 'failed', 'resource_id' => $resourceId, 'sid' => $sid];
        }

        $appId = (string) config('agora.app_id');
        $response = $this->recordingRequest(
            'get',
            $this->recordingBaseUrl()."/$appId/cloud_recording/resourceid/".rawurlencode($resourceId).'/sid/'.rawurlencode($sid).'/mode/mix/query'
        );

        return ['provider' => 'agora', 'status' => 'processing', 'resource_id' => $resourceId, 'sid' => $sid, 'raw' => $response];
    }

    public function end(LiveSession $live): void
    {
    }

    private function assertConfigured(): void
    {
        if (! config('agora.enabled') || ! config('agora.app_id') || ! config('agora.app_certificate')) {
            throw new RuntimeException('Agora is not configured for token generation.');
        }
    }

    private function assertRecordingConfigured(): void
    {
        $this->assertConfigured();
        if (! config('agora.recording_enabled') || ! config('live.features.recording')) {
            throw new RuntimeException('Agora Cloud Recording is disabled.');
        }
        foreach (['customer_id', 'customer_secret', 'recording_storage_bucket', 'recording_storage_access_key', 'recording_storage_secret_key'] as $key) {
            if (! config("agora.$key")) {
                throw new RuntimeException("Agora recording configuration is incomplete: $key.");
            }
        }
    }

    private function recordingBaseUrl(): string
    {
        return 'https://api.agora.io/v1/apps';
    }

    private function recordingRequest(string $method, string $url, ?array $payload = null): array
    {
        $request = Http::withBasicAuth((string) config('agora.customer_id'), (string) config('agora.customer_secret'))
            ->acceptJson()
            ->timeout((int) config('agora.recording_timeout', 15));
        $response = $method === 'get' ? $request->get($url) : ($method === 'delete' ? $request->delete($url, $payload ?? []) : $request->post($url, $payload ?? []));

        return $response->throw()->json();
    }

    private function storageConfig(): array
    {
        return [
            'vendor' => (int) config('agora.recording_storage_vendor', 1),
            'region' => (string) config('agora.recording_storage_region'),
            'bucket' => (string) config('agora.recording_storage_bucket'),
            'accessKey' => (string) config('agora.recording_storage_access_key'),
            'secretKey' => (string) config('agora.recording_storage_secret_key'),
            'fileNamePrefix' => [(string) config('agora.recording_storage_prefix', 'kulsah/live')],
        ];
    }

    private function recordingRtcToken(LiveSession $live): string
    {
        return $this->credentials($live, $live->creator, 'broadcaster')['token'];
    }

    private function loadOfficialTokenBuilder(): void
    {
        require_once base_path('app/Support/Agora/Official/RtcTokenBuilder2.php');
    }

    private function uid(User $user): int
    {
        return max(1, (int) $user->id);
    }
}