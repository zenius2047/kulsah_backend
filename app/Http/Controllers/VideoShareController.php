<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\VideoExportService;

class VideoShareController extends Controller
{
    public function show(Video $video, VideoExportService $exports)
    {
        $creator = $video->user()->first();
        abort_unless($creator && $creator->console_status === 'Active' && $video->status === 'ready'
            && $video->processing_status?->value === 'ready'
            && in_array($video->visibility, ['public', 'premium'], true)
            && in_array(data_get($video->metadata, 'console_status', 'Published'), ['Approved', 'Published', 'Active'], true), 404);
        $public = $exports->publicPreviewAllowed($video);
        $canonical = rtrim(config('app.url'), '/').'/videos/'.$video->id;

        return response()->view('videos.share', [
            'canonical' => $canonical,
            'public' => $public,
            'title' => $public ? ($video->title ?: 'Video by @'.$creator->username) : 'Watch on Kulsah',
            'description' => $public ? mb_substr((string) ($video->caption ?: 'Watch @'.$creator->username.' on Kulsah.'), 0, 300) : 'Open Kulsah to view this video. Access may require a subscription.',
            'thumbnail' => $public ? ($video->poster_url ?: $video->thumbnail_url) : null,
            'handle' => $public ? '@'.ltrim((string) $creator->username, '@') : null,
            'playback' => $public ? ($video->fallback_mp4_url ?: $video->rendered_url) : null,
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', $public ? 'index, follow' : 'noindex, noarchive');
    }
}
