<?php

namespace App\Services;

use App\Contracts\LiveStreamingProviderInterface;
use App\Enums\LiveRecordingStatus;
use App\Models\LiveRecording;
use App\Models\LiveSession;

class LiveRecordingService
{
    public function __construct(private readonly LiveStreamingProviderInterface $provider)
    {
    }

    public function request(LiveSession $live): LiveRecording
    {
        return LiveRecording::query()->updateOrCreate(
            ['live_session_id' => $live->id],
            [
                'provider' => $live->provider,
                'status' => LiveRecordingStatus::REQUESTED,
                'replay_state' => 'private',
                'metadata' => [],
            ]
        );
    }

    public function start(LiveSession $live): LiveRecording
    {
        $recording = $live->recordings()->latest()->first() ?: $this->request($live);
        $result = $this->provider->startRecording($live);

        $recording->update([
            'status' => LiveRecordingStatus::RECORDING,
            'provider_resource_id' => $result['resource_id'] ?? null,
            'provider_sid' => $result['sid'] ?? null,
            'started_at' => now(),
            'metadata' => $result,
        ]);

        return $recording->fresh();
    }

    public function stop(LiveSession $live): ?LiveRecording
    {
        $recording = $live->recordings()->latest()->first();
        if (! $recording || $recording->status !== LiveRecordingStatus::RECORDING) {
            return $recording;
        }

        $result = $this->provider->stopRecording($live, $recording->provider_resource_id, $recording->provider_sid);
        $recording->update([
            'status' => LiveRecordingStatus::PROCESSING,
            'stopped_at' => now(),
            'metadata' => array_merge($recording->metadata ?? [], ['stop' => $result]),
        ]);

        return $recording->fresh();
    }

    public function query(LiveRecording $recording): LiveRecording
    {
        $result = $this->provider->queryRecording($recording->live, $recording->provider_resource_id, $recording->provider_sid);
        $recording->update([
            'status' => $this->statusFromProvider($result['raw'] ?? $result),
            'metadata' => array_merge($recording->metadata ?? [], ['query' => $result]),
        ]);

        return $recording->fresh();
    }

    private function statusFromProvider(array $response): LiveRecordingStatus
    {
        $state = strtolower((string) ($response['serverResponse']['status'] ?? $response['status'] ?? 'processing'));

        return match ($state) {
            'success', 'completed', 'stopped', '2' => LiveRecordingStatus::READY,
            'fail', 'failed', '3' => LiveRecordingStatus::FAILED,
            default => LiveRecordingStatus::PROCESSING,
        };
    }
}