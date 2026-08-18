# Video upload and processing

Kulsah uses one video pipeline for posts, challenges, instructions, entries, messages, and other video purposes.

## Data flow

1. The authenticated client calls `POST /api/v1/media/video-uploads` with filename, MIME type, byte size, and purpose.
2. Laravel creates the `videos` record and returns a short-lived private-storage PUT URL. Storage credentials and permanent raw URLs are never returned.
3. The client uploads bytes directly to primary object storage.
4. The client calls `POST /api/v1/media/video-uploads/{video}/complete`.
5. Laravel locks the video, verifies ownership, object existence, and byte size, marks upload complete, and queues `ProcessVideoJob`. Repeated completion calls do not queue duplicate work.
6. The processing job verifies the retained source, atomically claims processing, and imports it into the existing Cloudinary integration using a deterministic public ID.
7. Cloudinary provides HLS, fallback MP4, poster, and media metadata. Laravel marks the video ready and emits the existing `VideoUploaded` completion event.
8. APIs expose HLS as `playback.url`; raw storage locations remain internal.

## States

`upload_status`: `initialized → uploading → uploaded`, with `upload_failed` available for upload failures.

`processing_status`: `initialized → queued → processing → ready`, or `processing_failed`.

Legacy `status` and `render_status` remain synchronized for existing feed/editor integrations.

## Retry and retention

Owners can call `POST /api/v1/media/videos/{video}/retry-processing` after `processing_failed`. Retry uses the original private-storage object and does not require another upload. Processing never deletes the authoritative raw source.

## Challenge rules

- Draft challenges may reference videos that are still processing.
- A challenge cannot become scheduled or active while required challenge/instruction videos lack ready HLS output.
- Entries, ballots, jury scores, metric scoring, leaderboards, ranking, and finalization require ready HLS videos.
- Challenge requests accept persisted video IDs only and prohibit local `file:///` media URIs.
- Cover-frame extraction is queued and saved on the challenge media association after Cloudinary processing finishes.
