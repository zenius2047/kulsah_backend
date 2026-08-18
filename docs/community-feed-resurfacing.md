# Community Feed Rewatch and Resurfacing

Community posts are never permanently removed because a user viewed them. The feed builds a bounded candidate pool, calculates a personalized score, applies diversity rules, and suppresses posts recently served in the same session. A view changes rank; it does not change eligibility.

## Tracking semantics

- Impression: a post was returned in a feed response. Short-session protection uses `community:feed:session:{userId}`.
- View: text/image/poll content met the configured visibility percentage and duration.
- Watch: an accepted video view with measurable playback duration or completion.
- Engagement: a like, comment, share, gift, or poll vote. New engagement updates `community_posts.last_activity_at`.

The existing `viewed_contents` record remains available to generic Discovery features. Community recommendation history is stored in `community_post_views`, one aggregate row per user/post pair. Short repeat calls update recent watch state and maximum completion, but do not increment aggregate/public counts until the cooldown expires.

## Ranking and resurfacing

The internal score combines recency, weighted engagement, trending velocity, followed-creator affinity, prior engagement, new activity, partial-watch resurfacing, elapsed time, and recent/repeat/completion penalties.

View penalties decay through `<1h`, `1-6h`, `6-24h`, `24-72h`, and `>72h` bands. Completion below 25% reduces suppression; completion at or above 90% strengthens temporary suppression. Activity newer than the viewer's last view receives a boost.

A diversity pass limits repeated creators and content types inside a sliding window. Deferred posts fill remaining slots when alternatives are limited. Ranking scores are never serialized.

## API

The implementation extends the existing authenticated `/general/community` API. The posts endpoint remains the feed, the existing view endpoint records views, and history is the only added endpoint:

- `GET /api/v1/general/community/posts`
- `POST /api/v1/general/community/posts/{post}/view`
- `GET /api/v1/general/community/history?type=all`

Text/image view request:

```json
{
  "visible_percentage": 75,
  "visible_duration_seconds": 2.1
}
```

Video watch request:

```json
{
  "watch_duration_seconds": 18.4,
  "completion_percentage": 72.3,
  "completed": false
}
```

An accepted response includes the aggregate view count, last-view timestamp, current/max completion, and `meta.meaningful`/`meta.counted`. `counted=false` means history was updated inside the public-count cooldown.

Feed resources expose only the authenticated viewer's private state: `has_viewed`, `view_count`, `last_viewed_at`, `completion_percentage`, `liked`, `commented`, and `has_voted`. No API exposes another user's history.

## Configuration and caching

`config/community.php` contains environment-overridable visibility/watch thresholds, count cooldown, candidate limits, ranking weights, resurfacing thresholds, feed-session TTL/window, and diversity limits.

Redis is recommended in production. Session keys are user-specific and expire automatically; the array cache remains suitable for tests.

## Events and analytics

- `CommunityPostViewed` is dispatched for meaningful views.
- `CommunityVideoWatched` is dispatched for accepted video watches.

Queued analytics listeners can be attached later. The aggregate schema supports total and unique views, repeats, average watch duration/completion, completion rate, milestones, repeat viewers, and rewatch rate without exposing viewer identities.

## Deferred

- This backend workspace has no React Native client. The client still needs visibility detection, debounced watch reporting, and final flushes on pause/background/unmount.
- Saved-post support should reuse an established Community save/bookmark abstraction if one is added later; this change does not introduce a competing endpoint or table.
- Cursor pagination can replace the preserved page contract when clients adopt opaque ranking cursors.
- Dedicated persistent impressions, hot-post aggregation jobs, ML recommendations, sponsored weighting, and A/B experiments remain extensions of this foundation.
