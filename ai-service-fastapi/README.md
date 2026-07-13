# Kulsah AI Recommendation Service

This service powers the Kulsah unified recommendation layer described in the feed system design.
It is responsible for ranking videos using a mix of behavior, peer, search, and virality signals.

## What it does

The FastAPI service exposes a small recommendation API that:

- ranks candidate videos for a user
- supports cold-start recommendations when no candidates are passed in
- stores lightweight user signals in Redis when available
- falls back to in-memory signal storage for local development
- accepts event ingestion for watch, like, share, save, search, and follow activity

## Core ranking model

The service combines the signals from the design doc into one final score:

- `AI score` from watch time, engagement, interest match, freshness, and creator quality
- `Peer score` from social influence and followed creators
- `Search score` from keyword relevance and search-session behavior
- `Viral boost` from velocity and stored virality bias
- `History affinity` from Laravel-provided user history such as follows, subscriptions, likes, bookmarks, and favorite categories

Final ranking:

```text
Final Score = (0.55 × AI Score) + (0.20 × Peer Score) + (0.25 × Search Score) + Viral Boost
```

AI score:

```text
AI Score =
0.40 × Watch Time Score
+ 0.25 × Engagement Score
+ 0.20 × Interest Match
+ 0.10 × Freshness
+ 0.05 × Creator Quality
```

## Endpoints

### `GET /`

Health-style home route.

Response:

```json
{
  "message": "Kulsah AI Recommendation Service Running"
}
```

### `GET /health`

Returns the service status and whether Redis is active.

Response:

```json
{
  "status": "ok",
  "service": "kulsah-ai-recommendation",
  "store": "redis"
}
```

The `store` value is `memory` when Redis is unavailable.

### `POST /recommend`

Generates a ranked list of videos for a user.

#### Request body

```json
{
  "user_id": 210,
  "limit": 20,
  "search_query": "@chefkofi",
  "interest_terms": ["food", "cooking"],
  "peer_strength": 0.3,
  "include_breakdown": true,
  "videos": [
    {
      "video_id": 101,
      "creator_id": 12,
      "title": "Quick ramen recipe",
      "creator_name": "chefkofi",
      "category": "food",
      "tags": ["food", "ramen", "cooking"],
      "watch_time_score": 0.94,
      "engagement_score": 0.88,
      "interest_match": 0.91,
      "freshness": 0.75,
      "creator_quality": 0.82,
      "peer_score": 0.61,
      "search_score": 0.95,
      "viral_boost": 0.12,
      "view_velocity": 160,
      "share_velocity": 18,
      "completion_rate": 0.89,
      "age_hours": 7
    }
  ]
}
```

#### Response body

```json
{
  "user_id": 210,
  "videos": [
    {
      "video_id": 101,
      "score": 0.9642,
      "breakdown": {
        "ai_score": 0.9011,
        "peer_score": 0.582,
        "search_score": 0.9438,
        "viral_boost": 0.165
      }
    }
  ],
  "meta": {
    "candidate_count": 1,
    "returned_count": 1,
    "source": "provided_candidates",
    "ranking_formula": "0.55*AI + 0.20*Peer + 0.25*Search + ViralBoost"
  }
}
```

If `videos` is omitted or empty, the service generates cold-start candidates automatically.

### `POST /recommendations`

Laravel exposes this route as a dedicated feed-helper endpoint and returns ranked video IDs only.

It uses the same FastAPI ranking engine under the hood, but the response is intentionally minimal:

```json
{
  "data": [101, 102, 103],
  "meta": {
    "cache_hit": false,
    "cache_key": "feed:user:210:limit:20:page:1:v1:abc123def456",
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 3,
      "last_page": 1,
      "has_more_pages": false
    }
  }
}
```

### `POST /events`

Records a lightweight signal for a user.

Supported event types:

- `watch`
- `like`
- `share`
- `save`
- `search`
- `follow`

#### Example

```json
{
  "user_id": 210,
  "event_type": "search",
  "value": 1,
  "terms": ["chefkofi", "food", "recipe"]
}
```

This endpoint is useful for updating Redis-backed user state in real time.

## Data model

### `VideoCandidate`

The recommendation engine accepts a list of candidate videos with optional scoring hints.

Important fields:

- `video_id` required
- `title`, `tags`, `category`, `creator_name` used for text matching
- `watch_time_score`, `engagement_score`, `interest_match`, `freshness`, `creator_quality` used by the AI score
- `peer_score`, `search_score`, `viral_boost` used by the unified ranking formula
- `view_velocity`, `share_velocity`, `completion_rate`, `age_hours` help with trend and freshness adjustments

### `RecommendationRequest`

Main inputs:

- `user_id`
- `limit`
- `search_query`
- `interest_terms`
- `peer_strength`
- `followed_creator_ids`
- `subscribed_creator_ids`
- `liked_video_ids`
- `bookmarked_video_ids`
- `favorite_categories`
- `favorite_creator_ids`
- `videos`
- `include_breakdown`

## Signal storage

The service looks for `REDIS_URL`.

- If Redis is available, user signals are stored under keys like `user:{id}:signals`
- If Redis is not available, the service uses an in-memory store

Stored signal groups include:

- `events`
- `video_events`
- `search_terms`
- `followed_creators`
- `peer_score`
- `viral_bias`

## How Laravel should use it

Laravel acts as the orchestrator:

1. User opens the feed
2. Laravel assembles candidate videos from the database and cache
3. Laravel sends the candidates to FastAPI
4. FastAPI returns ranked video IDs and scores
5. Laravel enriches the response with full video data
6. Laravel caches the final feed

## Example Laravel request

```json
{
  "user_id": 210,
  "limit": 20,
  "search_query": null,
  "interest_terms": ["music", "dance"],
  "peer_strength": 0.2,
  "include_breakdown": false,
  "videos": [
    { "video_id": 1 },
    { "video_id": 2 }
  ]
}
```

In a production flow, Laravel should send richer video objects so the service can use all available ranking signals.

## Local development

### Run the service

```bash
cd ai-service-fastapi
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

### Docker

The service already has a Dockerfile and is wired into `docker-compose.yml`.

### Dependencies

The service uses:

- `fastapi`
- `uvicorn`
- `redis`
- `pydantic`
- `numpy`

## Notes

- The current implementation is MVP-friendly and deterministic.
- The scoring hooks are structured so a future PyTorch model can replace the formula without changing the API contract.
- The response format is intentionally simple so Laravel can enrich and cache results efficiently.

## Service-to-service signing

If `FASTAPI_SHARED_SECRET` is set, the service requires Laravel to send HMAC-signed requests.

Laravel should include these headers:

- `X-Kulsah-Timestamp`
- `X-Kulsah-Signature`
- `X-Kulsah-Service: laravel`

The signature is computed over:

```text
timestamp
HTTP method
request path
raw JSON body
```

Requests older than `FASTAPI_SIGNATURE_TTL_SECONDS` are rejected.
