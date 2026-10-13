<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Jobs\GenerateVideoExport;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Video;
use App\Models\VideoExport;
use App\Services\VideoExportRenderer;
use App\Services\VideoExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoExportTest extends TestCase
{
    use RefreshDatabase;

    private function video(): Video
    {
        return Video::factory()->create([
            'fallback_mp4_url' => 'https://res.cloudinary.com/test/video/upload/final.mp4',
            'metadata' => ['allow_download' => true],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('exports');
        config(['video.storage_disk' => 'exports']);
    }

    private function viewer(): User
    {
        return User::factory()->create()->refresh();
    }

    public function test_repeated_requests_queue_one_export_and_leave_playback_unchanged(): void
    {
        $video = $this->video();
        $original = $video->fresh()->getAttributes();
        $viewer = $this->viewer();
        $service = app(VideoExportService::class);
        $first = $service->request($video, $viewer);
        $second = $service->request($video, $viewer);
        $this->assertSame($first->token, $second->token);
        Queue::assertPushed(GenerateVideoExport::class, 1);
        $this->assertSame($original, $video->fresh()->getAttributes());
    }

    public function test_worker_stores_private_export_and_requests_reuse_it(): void
    {
        $video = $this->video();
        $viewer = $this->viewer();
        $service = app(VideoExportService::class);
        $export = $service->request($video, $viewer);
        $renderer = $this->mock(VideoExportRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturnUsing(fn ($video, $output) => file_put_contents($output, 'watermarked-mp4'));
        (new GenerateVideoExport($video->id, $export->token))->handle($service, $renderer);
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $this->assertSame('private', Storage::disk('exports')->getVisibility($export->path));
        $this->assertSame('watermarked-mp4', Storage::disk('exports')->get($export->path));
        $this->assertSame($export->token, $service->request($video, $viewer)->token);
        Queue::assertPushed(GenerateVideoExport::class, 1);
    }

    public function test_failure_can_be_retried_without_replacing_playback(): void
    {
        $video = $this->video();
        $viewer = $this->viewer();
        $service = app(VideoExportService::class);
        $export = $service->request($video, $viewer);
        $job = new GenerateVideoExport($video->id, $export->token);
        $renderer = $this->mock(VideoExportRenderer::class);
        $renderer->shouldReceive('render')->once()->andThrow(new \RuntimeException('encoder unavailable'));
        try {
            $job->handle($service, $renderer);
            $this->fail('Expected encoder failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('pending', $export->fresh()->status);
            $job->failed($error);
        }
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame($export->token, $service->request($video, $viewer)->token);
        $retried = $service->request($video, $viewer, true);
        $this->assertNotSame($export->token, $retried->token);
        $this->assertSame('pending', $retried->status);
        Queue::assertPushed(GenerateVideoExport::class, 2);
    }

    public function test_new_handle_source_and_watermark_version_invalidate_exports(): void
    {
        $service = app(VideoExportService::class);
        $video = $this->video();
        $viewer = $this->viewer();
        $export = $service->request($video, $viewer);
        Storage::disk('exports')->put('old.mp4', 'old');
        $export->update(['path' => 'old.mp4', 'status' => 'ready']);
        $video->user->update(['username' => 'changed_creator']);
        $replacement = $service->request($video, $viewer);
        $this->assertNotSame($export->token, $replacement->token);
        Storage::disk('exports')->assertMissing('old.mp4');
        $video->update(['fallback_mp4_url' => 'https://res.cloudinary.com/test/video/upload/revised.mp4']);
        $next = $service->request($video, $viewer);
        $this->assertNotSame($replacement->token, $next->token);
        config(['video.export_version' => '2']);
        $this->assertNotSame($next->token, $service->request($video, $viewer)->token);
    }

    public function test_export_mode_enforces_permissions_and_authenticated_delivery(): void
    {
        $video = $this->video();
        $viewer = $this->viewer();
        $url = '/api/v1/general/videos/'.$video->id;
        $this->actingAs($viewer)->withoutMiddleware(RoleMiddleware::class);
        $this->getJson($url.'?export=video')->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $export = VideoExport::firstOrFail();
        Storage::disk('exports')->put('ready.mp4', 'watermarked');
        $export->update(['status' => 'ready', 'path' => 'ready.mp4']);
        $this->getJson($url.'?export=video')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->get($url.'?export=video&delivery='.$export->token)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $video->update(['metadata' => ['allow_download' => false]]);
        $this->get($url.'?export=video&delivery='.$export->token)->assertForbidden();
        $this->getJson($url.'?export=video')->assertForbidden();
        Queue::assertPushed(GenerateVideoExport::class, 1);
    }

    public function test_blocked_premium_and_moderated_videos_cannot_export(): void
    {
        $video = $this->video();
        $viewer = $this->viewer();
        $this->actingAs($viewer)->withoutMiddleware(RoleMiddleware::class);
        $url = '/api/v1/general/videos/'.$video->id.'?export=video';
        $video->update(['visibility' => 'premium']);
        $this->getJson($url)->assertForbidden();
        $video->update(['visibility' => 'public', 'status' => 'hidden']);
        $this->getJson($url)->assertForbidden();
        $video->update(['status' => 'ready']);
        UserBlock::create(['blocker_id' => $video->user_id, 'blocked_id' => $viewer->id]);
        $this->getJson($url)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_worker_rechecks_eligibility_before_and_after_rendering(): void
    {
        $video = $this->video();
        $service = app(VideoExportService::class);
        $export = $service->request($video, $this->viewer());
        $renderer = $this->mock(VideoExportRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturnUsing(function ($video, $output): void {
            file_put_contents($output, 'watermarked');
            $video->update(['status' => 'removed']);
        });
        (new GenerateVideoExport($video->id, $export->token))->handle($service, $renderer);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertEmpty(Storage::disk('exports')->allFiles());
        (new GenerateVideoExport($video->id, $export->token))->handle($service, $renderer);
        $this->assertSame('failed', $export->fresh()->status);
    }

    public function test_deleted_videos_cannot_deliver_and_orphaned_objects_are_pruned(): void
    {
        $video = $this->video();
        $viewer = $this->viewer();
        $export = app(VideoExportService::class)->request($video, $viewer);
        Storage::disk('exports')->put('deleted.mp4', 'watermarked');
        $export->update(['path' => 'deleted.mp4', 'status' => 'ready']);
        $video->delete();
        $this->actingAs($viewer)->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/general/videos/'.$video->id.'?export=video&delivery='.$export->token)->assertNotFound();
        $this->artisan('videos:prune-exports')->assertSuccessful();
        Storage::disk('exports')->assertMissing('deleted.mp4');
        $this->assertDatabaseCount('video_exports', 0);
    }

    public function test_public_metadata_is_escaped_and_premium_metadata_is_generic(): void
    {
        $video = $this->video();
        $video->update(['title' => 'Secret <script>alert(1)</script>', 'caption' => 'Private caption', 'poster_url' => 'https://example.test/secret-thumbnail.jpg']);
        $this->get('/videos/'.$video->id)->assertOk()->assertSee('Secret &lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $video->update(['visibility' => 'premium']);
        $this->get('/videos/'.$video->id)->assertOk()->assertDontSee('Secret')->assertDontSee('Private caption')->assertDontSee('secret-thumbnail')->assertDontSee('final.mp4');
        $this->actingAs($this->viewer())->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/general/videos/'.$video->id.'?export=link')->assertOk()
            ->assertJsonPath('data.url', rtrim(config('app.url'), '/').'/videos/'.$video->id);
        $video->update(['status' => 'removed']);
        $this->get('/videos/'.$video->id)->assertNotFound();
    }

    public function test_export_requires_authentication_and_creator_permission(): void
    {
        $video = $this->video();
        $this->getJson('/api/v1/general/videos/'.$video->id.'?export=video')->assertUnauthorized();
        $owner = $video->user;
        $this->actingAs($owner)->withoutMiddleware(RoleMiddleware::class)
            ->patchJson('/api/v1/creator/videos/'.$video->id, ['allow_download' => false])->assertOk();
        $this->assertFalse(data_get($video->fresh()->metadata, 'allow_download'));
        $this->getJson('/api/v1/general/videos/'.$video->id.'?export=video')->assertForbidden();
        $this->actingAs($this->viewer())
            ->patchJson('/api/v1/creator/videos/'.$video->id, ['allow_download' => true])->assertForbidden();
    }

    public function test_stale_work_is_reclaimed_and_old_delivery_tokens_are_rejected(): void
    {
        $video = $this->video();
        $viewer = $this->viewer();
        $service = app(VideoExportService::class);
        $export = $service->request($video, $viewer);
        $export->update(['status' => 'processing', 'updated_at' => now()->subHours(2)]);
        $replacement = $service->request($video, $viewer);
        $this->assertNotSame($export->token, $replacement->token);
        $this->actingAs($viewer)->withoutMiddleware(RoleMiddleware::class)
            ->getJson('/api/v1/general/videos/'.$video->id.'?export=video&delivery='.$export->token)->assertStatus(409);
        Queue::assertPushed(GenerateVideoExport::class, 2);
    }

    public function test_association_files_require_real_configuration(): void
    {
        config(['app_links.ios_app_ids' => [], 'app_links.android_targets' => []]);
        $this->getJson('/.well-known/apple-app-site-association')->assertStatus(503);
        $this->getJson('/.well-known/assetlinks.json')->assertStatus(503);
        config(['app_links.ios_app_ids' => ['test-team.test-bundle'], 'app_links.android_targets' => [
            ['package_name' => 'test.example.app', 'sha256_cert_fingerprints' => ['test-fingerprint']],
        ]]);
        $this->getJson('/.well-known/apple-app-site-association')->assertOk()->assertJsonPath('applinks.details.0.paths.0', '/videos/*');
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.package_name', 'test.example.app');
    }
}
