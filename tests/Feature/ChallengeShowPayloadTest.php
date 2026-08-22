<?php

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeEntry;
use App\Models\ChallengeMedia;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeShowPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenge_show_returns_official_video_and_entries(): void
    {
        $viewer = User::factory()->create();
        $adminRole = Role::create(['name' => 'admin']);
        $viewer->roles()->attach($adminRole->id);

        $challengeCreator = User::factory()->create([
            'name' => 'Challenge Host',
            'username' => 'challenge_host',
            'avatar' => 'https://example.com/host.png',
        ]);
        $entryCreator = User::factory()->create([
            'name' => 'Entry Creator',
            'username' => 'entry_creator',
            'avatar' => 'https://example.com/entry.png',
        ]);

        $challenge = Challenge::factory()->active()->create([
            'created_by_user_id' => $challengeCreator->id,
            'host_user_id' => $challengeCreator->id,
            'title' => 'Summer Dance Challenge',
            'description' => 'Show us your best move.',
            'visibility' => 'public',
            'status' => ChallengeStatus::Active,
            'submission_starts_at' => now()->subDay(),
            'submission_ends_at' => now()->addDay(),
            'voting_starts_at' => now()->subHour(),
            'voting_ends_at' => now()->addDay(),
        ]);

        $officialVideo = Video::factory()->create([
            'user_id' => $challengeCreator->id,
            'title' => 'Official Challenge Video',
            'caption' => 'Watch the official challenge video',
            'streaming_url' => 'https://cdn.example.com/challenge-official.m3u8',
            'cdn_url' => 'https://cdn.example.com/challenge-official.m3u8',
            'poster_url' => 'https://cdn.example.com/challenge-official.jpg',
            'thumbnail_url' => 'https://cdn.example.com/challenge-official-thumb.jpg',
        ]);

        $challenge->update(['official_sound_id' => $officialVideo->id]);

        ChallengeMedia::create([
            'challenge_id' => $challenge->id,
            'video_id' => $officialVideo->id,
            'role' => 'challenge_video',
            'sort_order' => 0,
            'metadata' => [],
        ]);

        $entryVideo = Video::factory()->create([
            'user_id' => $entryCreator->id,
            'title' => 'Contest Entry',
            'caption' => 'My challenge entry',
            'streaming_url' => 'https://cdn.example.com/entry.m3u8',
            'cdn_url' => 'https://cdn.example.com/entry.m3u8',
            'poster_url' => 'https://cdn.example.com/entry.jpg',
            'thumbnail_url' => 'https://cdn.example.com/entry-thumb.jpg',
        ]);

        $entry = ChallengeEntry::create([
            'challenge_id' => $challenge->id,
            'creator_id' => $entryCreator->id,
            'video_id' => $entryVideo->id,
            'submission_number' => 1,
            'caption' => 'My challenge entry',
            'status' => 'active',
            'submitted_at' => now()->subHour(),
            'current_score' => 87.5,
        ]);

        $response = $this->actingAs($viewer)->withoutMiddleware()->getJson("/api/v1/general/challenges/{$challenge->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $challenge->id)
            ->assertJsonPath('data.can_vote', true)
            ->assertJsonPath('data.has_user_voted', false)
            ->assertJsonPath('data.official_video.id', (string) $officialVideo->id)
            ->assertJsonPath('data.official_video.videoUrl', 'https://cdn.example.com/challenge-official.m3u8')
            ->assertJsonPath('data.official_video.thumbnailUrl', 'https://cdn.example.com/challenge-official.jpg')
            ->assertJsonPath('data.entries.0.id', (string) $entry->id)
            ->assertJsonPath('data.entries.0.userName', 'Entry Creator')
            ->assertJsonPath('data.entries.0.userHandle', 'entry_creator')
            ->assertJsonPath('data.entries.0.userAvatar', 'https://example.com/entry.png')
            ->assertJsonPath('data.entries.0.videoUrl', 'https://cdn.example.com/entry.m3u8')
            ->assertJsonPath('data.entries.0.thumbnailUrl', 'https://cdn.example.com/entry.jpg')
            ->assertJsonPath('data.entries.0.caption', 'My challenge entry')
            ->assertJsonPath('data.entries.0.votes', 87.5)
            ->assertJsonPath('data.entries.0.isLiked', false)
            ->assertJsonPath('data.entries.0.isVoted', false)
            ->assertJsonPath('data.entries.0.originalSound', true)
            ->assertJsonPath('data.entries.0.tag', 'ChallengeEntry')
            ->assertJsonPath('data.entries.0.isVote', true);
    }
}
