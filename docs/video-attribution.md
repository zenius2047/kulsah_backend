# External video attribution

Exports are generated **on demand** on the existing `videos` queue. This avoids
encoding videos that are never downloaded or cannot be exported. Normal uploads,
processed assets, edits and playback responses are unchanged. The worker fetches
the final Cloudinary MP4; it deliberately fails if a trusted final MP4 is missing
rather than exporting an original that might omit edits. The logo is copied from
the existing creator console's `public/logo.png`.

Changed files are grouped by responsibility:

| Area | Files |
| --- | --- |
| Export lifecycle and permissions | `app/Services/VideoExportService.php`, `app/Models/VideoExport.php`, `database/migrations/2026_10_11_000001_create_video_exports_table.php` |
| Queue and FFmpeg | `app/Jobs/GenerateVideoExport.php`, `app/Services/VideoExportRenderer.php`, `config/video.php`, `public/brand/kulsah-logo.png` |
| Existing API integration | `app/Http/Controllers/Api/V1/Video/VideoController.php`, `app/Services/VideoService.php` |
| Public links and platform associations | `app/Http/Controllers/VideoShareController.php`, `resources/views/videos/share.blade.php`, `routes/web.php`, `config/app_links.php` |
| Cleanup | `app/Console/Commands/PruneVideoExports.php`, `routes/console.php` |
| Verification | `tests/Feature/VideoExportTest.php`, `tests/Feature/VideoExportConcurrencyTest.php`, `tests/Support/request-video-export.php`, `tests/Support/verify-video-export-rendering.php` |

## Minimal contract additions

There was no video export/share endpoint or public canonical video route in this
backend. The existing authenticated video detail route now accepts an optional
export mode, before its normal owner/admin detail checks and response caching:

- `GET /api/v1/general/videos/{id}?export=link` returns
  `{"data":{"url":"<APP_URL>/videos/<id>"}}`.
- `GET /api/v1/general/videos/{id}?export=video` requests/reuses an export.
  It returns `{"data":{"status":"pending|processing|ready|failed","url":null,"error":null}}`.
  Pending/processing return HTTP 202 with `Retry-After: 3`. Ready returns an
  authenticated delivery URL in `data.url`; failed returns a user-facing error.
- `retry=1` explicitly retries a failed export. Polling a failed export does not
  silently enqueue another job. A stale pending/processing request can be
  reclaimed after an hour, allowing recovery from lost jobs or worker death.
- Follow `data.url` **with the same bearer authentication** to download the private
  MP4. Every delivery rechecks the current creator, moderation, download setting,
  viewer blocks and source fingerprint. HTTP 409 means prepare again. There is
  no public storage URL fallback. The creator detail endpoint accepts the same
  modes.
- The existing creator `PATCH /api/v1/creator/videos/{id}` accepts `allow_download`
  and stores it in the existing `metadata.allow_download` permission, whose
  default remains false.

No changes are made to normal playback fields. Export-specific fields appear
only in the explicitly requested export response. HTTP 401/403/404 are terminal;
clients must never fall back to a playback/source URL.

The new public **web** route `/videos/{id}` uses `APP_URL` as the canonical origin.
It serves escaped, server-rendered Open Graph metadata and Kulsah branding. Paid
videos have a generic page without titles, captions, creator handles, thumbnails
or playback URLs. Exporting premium video files is prohibited, including for
subscribers: permission to view premium content is not permission to redistribute
it. Premium links continue to lead to an access-gated app destination. Hidden,
removed and unavailable videos return 404.

## Mobile integration

The Kulsah React Native repository was not available in the supplied workspace.
The nearby Kulscan applications are ticket-scanning apps. Native sharing UI,
navigation and device storage therefore still require integration in the actual
Kulsah app; this backend does not claim those flows are complete.

Use the existing share UI and device storage abstraction:

1. Share link: request `export=link`, then share the returned canonical URL.
2. Share video/download: request `export=video`, show preparation while pending or
   processing, poll every three seconds while the screen/action remains active,
   and offer explicit retry on failure. Stop polling on cancellation/unmount.
3. Download `data.url` with bearer headers to a unique temporary `.mp4` file. Check
   HTTP status before using it, and discard partial/error downloads. Use that
   same file for the native video share sheet or existing save-to-library flow.
4. Report opening the share sheet as an attempt, not an external publication.
   Delete completed download temp files after the device save succeeds. Retain
   shared files until the platform no longer needs them; prune abandoned cached
   files on a later launch rather than deleting while a receiving app reads them.
5. Route `/videos/{id}` universal/app links to the existing video destination,
   where the authenticated viewing/subscription rules still apply.

## Deployment

- Run `php artisan migrate --force` and restart existing media workers after
  deployment. Use a real asynchronous queue connection. The existing media worker
  consumes `videos` with timeout 1800 and Redis retry-after 2100; exports use a
  1200-second job timeout, three attempts and 30/90-second backoff. Shared Redis
  cache is required for `WithoutOverlapping` worker locks. Keep retry-after longer
  than the worker timeout.
- FFmpeg must have libx264, AAC, PNG decoding and the drawtext filter. FFprobe is
  also required. Install a font or set `VIDEO_FFMPEG_FONT_FILE` to an existing
  readable font that supports the app's usernames. The renderer copies the font
  into its temporary directory to avoid placing arbitrary paths into filters.
- `VIDEO_STORAGE_DISK` must be the existing private storage provider with working
  read-stream/download and delete operations. Export objects are private under
  `videos/exports/<creator>/<video>/<token>.mp4`. Do not expose this prefix through
  a public CDN or public bucket ACL.
- Set `CLOUDINARY_CLOUD_NAME` to the existing account. Final processed MP4 URLs
  must use its HTTPS `res.cloudinary.com/<account>/video/upload/` path. Redirects
  are refused. FFmpeg gets local files with restricted file/pipe protocols.
- Keep the shipped official `public/brand/kulsah-logo.png`. Increment
  `VIDEO_EXPORT_VERSION` for watermark design changes. Source/edit timestamps,
  processed URLs, creator handle and logo bytes also invalidate the cached export.
- Run the existing scheduler. `videos:prune-exports` runs hourly and removes
  exports for deleted, restricted or changed videos. The export row intentionally
  survives deletion of its video so object cleanup remains possible. Superseded
  objects are also deleted when requesting a replacement. Objects cannot be
  recalled from devices after downloading.
- `APP_URL` must be the **existing public HTTPS origin**, with `/videos/*`,
  `/brand/*` and the association paths routed to Laravel. If the public website
  uses another origin from the API, route those paths to this backend at that
  existing public origin; do not substitute an invented domain. Disable CDN
  caching for video pages and export responses to avoid stale preview access.
- Configure `KULSAH_IOS_APP_IDS` as a JSON array containing the actual
  `<Apple team ID>.<bundle ID>` values. The backend serves
  `/.well-known/apple-app-site-association` without an extension. Add
  `applinks:<existing public host>` to the actual app's Associated Domains
  entitlement, and route the video path in the app.
- Configure `KULSAH_ANDROID_APP_TARGETS` as a JSON array of objects with
  `package_name` and `sha256_cert_fingerprints` (an array of the actual release /
  Play App Signing SHA-256 certificate fingerprints). The backend serves
  `/.well-known/assetlinks.json`. Add a verified Android HTTPS intent filter for
  the existing host and `/videos/` path prefix. Association endpoints return 503
  until configured. Serve them directly with JSON content type and no redirects.
- Run `php artisan config:cache` after setting the real identifiers. App IDs,
  credentials and certificate fingerprints are intentionally not fabricated.

Universal links open the video when the configured app is installed; otherwise
the browser serves the branded page. Same-origin browser navigation can remain
in the browser depending on the platform. A guaranteed browser-to-app button
needs the actual app's existing deep-link scheme/navigation integration.

## Verification

Verified in the supplied environment:

- All **13 attribution tests passed (72 assertions)**, including simultaneous processes queuing
  one job, export reuse, failed-work recovery, unchanged video records, creator
  handle/source/version invalidation, private delivery, authentication, creator
  settings, block/moderation rules, deletion cleanup and preview privacy.
- Portrait 360×640, landscape 640×360 and square 480×480 FFmpeg checks passed.
  Audio/video duration differences stayed within 150 ms, all outputs used
  H.264/AAC/yuv420p and fast-start, and source hashes stayed unchanged. Frames at
  1 and 6 seconds were inspected for both corners and the literal creator handle.
- Formatting checks for the added PHP files and `git diff --check` passed.
- A broader 34-test run passed the attribution checks and edit-renderer checks,
  but did not pass as a whole: two existing upload tests require GD, which the
  container lacks, and six existing pipeline tests received 403 account-policy
  responses. Those failures are not presented as successful regression checks.
  The edit-renderer tests also emitted seven PHPUnit notices.

The test container used the workspace's installed development dependencies and
the existing `kulsah_testing` database. No live database migrations were applied.

Run the focused export and concurrency tests with the existing PHPUnit setup:
`php vendor/bin/phpunit --filter=VideoExport`.

Run `php tests/Support/verify-video-export-rendering.php` in the media runtime for
11-second portrait, landscape and square MP4 fixtures. This checks unchanged
source hashes, dimensions, H.264/AAC, yuv420p, synchronized audio, fast-start and
literal creator handles containing filter metacharacters. Setting
`VIDEO_EXPORT_QA_DIRECTORY` preserves frames at 1 and 6 seconds for inspecting
both watermark corners.

Live Cloudinary/S3 delivery, receiving-platform publication/link previews,
real-device native sharing/download and installed-app link resolution require
deployment credentials and the actual mobile app. Attribution does not detect
external reposts or recall files already downloaded.
