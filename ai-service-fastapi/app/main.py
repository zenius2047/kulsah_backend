import json
import math
import os
import random
from dataclasses import dataclass
from typing import Any, Literal

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

try:
    import redis
except ImportError:  # pragma: no cover - dependency may be unavailable in some environments.
    redis = None


app = FastAPI(
    title="Kulsah AI Recommendation Service",
    version="1.0.0",
    description="Unified recommendation engine for watch, peer, search, and viral signals.",
)


SignalType = Literal["watch", "like", "share", "save", "search", "follow"]


# Candidate videos can arrive from Laravel with partial or fully enriched signals.
class VideoCandidate(BaseModel):
    video_id: int
    creator_id: int | None = None
    title: str | None = None
    creator_name: str | None = None
    category: str | None = None
    tags: list[str] = Field(default_factory=list)
    watch_time_score: float | None = None
    engagement_score: float | None = None
    interest_match: float | None = None
    freshness: float | None = None
    creator_quality: float | None = None
    peer_score: float | None = None
    search_score: float | None = None
    viral_boost: float | None = None
    view_velocity: float | None = None
    share_velocity: float | None = None
    completion_rate: float | None = None
    age_hours: float | None = None


class RecommendationRequest(BaseModel):
    user_id: int
    limit: int = Field(default=20, ge=1, le=100)
    search_query: str | None = None
    interest_terms: list[str] = Field(default_factory=list)
    peer_strength: float = Field(default=0.0, ge=0.0, le=1.0)
    videos: list[VideoCandidate] = Field(default_factory=list)
    include_breakdown: bool = False


class EventPayload(BaseModel):
    user_id: int
    event_type: SignalType
    video_id: int | None = None
    value: float = Field(default=1.0, ge=0.0)
    terms: list[str] = Field(default_factory=list)


class RecommendationItem(BaseModel):
    video_id: int
    score: float
    breakdown: dict[str, float] | None = None


class RecommendationResponse(BaseModel):
    user_id: int
    videos: list[RecommendationItem]
    meta: dict[str, Any]


def clamp(value: float, minimum: float = 0.0, maximum: float = 1.0) -> float:
    return max(minimum, min(maximum, value))


def normalize(value: float | None, fallback: float = 0.5) -> float:
    if value is None:
        return fallback
    return clamp(float(value))


def tokenize(text: str | None) -> set[str]:
    if not text:
        return set()

    cleaned = "".join(ch.lower() if ch.isalnum() else " " for ch in text)
    return {token for token in cleaned.split() if token}


def compute_overlap_score(query_tokens: set[str], candidate_tokens: set[str]) -> float:
    if not query_tokens or not candidate_tokens:
        return 0.0

    intersection = len(query_tokens & candidate_tokens)
    if intersection == 0:
        return 0.0

    return clamp(intersection / max(len(query_tokens), len(candidate_tokens)))


@dataclass
class SignalStore:
    client: Any | None = None
    memory: dict[str, dict[str, Any]] | None = None

    @classmethod
    def from_env(cls) -> "SignalStore":
        # Prefer Redis in deployed environments, but keep local dev working without it.
        redis_url = os.getenv("REDIS_URL")

        if redis is not None and redis_url:
            try:
                client = redis.Redis.from_url(redis_url, decode_responses=True, socket_connect_timeout=1)
                client.ping()
                return cls(client=client, memory={})
            except Exception:
                pass

        return cls(client=None, memory={})

    def _key(self, user_id: int) -> str:
        return f"user:{user_id}:signals"

    def get(self, user_id: int) -> dict[str, Any]:
        if self.client is not None:
            raw = self.client.get(self._key(user_id))
            if not raw:
                return {}
            try:
                return json.loads(raw)
            except json.JSONDecodeError:
                return {}

        return dict(self.memory.get(self._key(user_id), {})) if self.memory is not None else {}

    def record(self, payload: EventPayload) -> dict[str, Any]:
        # Keep the signal object small and append-only so it is cheap to read back.
        signals = self.get(payload.user_id)
        events = signals.setdefault("events", {})
        events[payload.event_type] = float(events.get(payload.event_type, 0.0)) + float(payload.value)

        if payload.video_id is not None:
            video_events = signals.setdefault("video_events", {})
            video_key = str(payload.video_id)
            video_bucket = video_events.setdefault(video_key, {})
            video_bucket[payload.event_type] = float(video_bucket.get(payload.event_type, 0.0)) + float(payload.value)

            if payload.event_type in {"watch", "like", "share", "save"}:
                signals["peer_score"] = clamp(float(signals.get("peer_score", 0.0)) + payload.value * 0.02)

            if payload.event_type == "share":
                signals["viral_bias"] = clamp(float(signals.get("viral_bias", 0.0)) + payload.value * 0.05)

        if payload.event_type == "search" and payload.terms:
            search_terms = signals.setdefault("search_terms", {})
            for term in payload.terms:
                search_terms[term.lower()] = float(search_terms.get(term.lower(), 0.0)) + payload.value

        if payload.event_type == "follow" and payload.terms:
            followed_creators = signals.setdefault("followed_creators", {})
            for term in payload.terms:
                followed_creators[term.lower()] = float(followed_creators.get(term.lower(), 0.0)) + payload.value

        if self.client is not None:
            self.client.set(self._key(payload.user_id), json.dumps(signals), ex=1800)
        elif self.memory is not None:
            self.memory[self._key(payload.user_id)] = signals

        return signals


signal_store = SignalStore.from_env()


def derive_watch_score(candidate: VideoCandidate, signals: dict[str, Any]) -> float:
    # Watch score blends the candidate hint with lightweight user history.
    base = normalize(candidate.watch_time_score, fallback=0.5)
    completion = normalize(candidate.completion_rate, fallback=base)
    velocity = normalize(candidate.view_velocity / 100.0, fallback=0.0) if candidate.view_velocity is not None else 0.0
    history_boost = normalize(float(signals.get("events", {}).get("watch", 0.0)) / 20.0, fallback=0.0)
    return clamp((0.7 * base) + (0.2 * completion) + (0.1 * velocity) + (0.05 * history_boost))


def derive_engagement_score(candidate: VideoCandidate, signals: dict[str, Any]) -> float:
    # Engagement is stronger when the video is already proving shareable.
    base = normalize(candidate.engagement_score, fallback=0.5)
    share_velocity = normalize(candidate.share_velocity / 100.0, fallback=0.0) if candidate.share_velocity is not None else 0.0
    history_boost = normalize(
        (
            float(signals.get("events", {}).get("like", 0.0))
            + float(signals.get("events", {}).get("share", 0.0))
            + float(signals.get("events", {}).get("save", 0.0))
        )
        / 30.0,
        fallback=0.0,
    )
    return clamp((0.75 * base) + (0.15 * share_velocity) + (0.1 * history_boost))


def derive_interest_match(candidate: VideoCandidate, request: RecommendationRequest, signals: dict[str, Any]) -> float:
    # Search terms and interest terms influence the feed immediately.
    base = normalize(candidate.interest_match, fallback=0.35)
    candidate_tokens = tokenize(candidate.title) | set(token.lower() for token in candidate.tags)
    query_tokens = tokenize(request.search_query) | {term.lower() for term in request.interest_terms}
    query_tokens |= set(signals.get("search_terms", {}).keys())
    profile_boost = compute_overlap_score(query_tokens, candidate_tokens)
    return clamp((0.6 * base) + (0.4 * profile_boost))


def derive_freshness(candidate: VideoCandidate) -> float:
    # Fresh content should decay gradually instead of dropping off instantly.
    if candidate.freshness is not None:
        return normalize(candidate.freshness, fallback=0.5)

    if candidate.age_hours is None:
        return 0.5

    age = max(candidate.age_hours, 0.0)
    return clamp(math.exp(-age / 48.0))


def derive_creator_quality(candidate: VideoCandidate) -> float:
    return normalize(candidate.creator_quality, fallback=0.55)


def derive_peer_score(candidate: VideoCandidate, request: RecommendationRequest, signals: dict[str, Any]) -> float:
    # Peer influence comes from both the request context and stored user state.
    base = normalize(candidate.peer_score, fallback=0.0)
    user_peer = normalize(request.peer_strength, fallback=0.0)
    history_peer = normalize(float(signals.get("peer_score", 0.0)), fallback=0.0)
    followed_creator_boost = 0.0
    if candidate.creator_name:
        followed = signals.get("followed_creators", {})
        followed_creator_boost = 0.2 if candidate.creator_name.lower() in followed else 0.0

    return clamp((0.45 * base) + (0.35 * user_peer) + (0.2 * history_peer) + followed_creator_boost)


def derive_search_score(candidate: VideoCandidate, request: RecommendationRequest, signals: dict[str, Any]) -> float:
    # Search intent should be able to reshape the feed in the same session.
    base = normalize(candidate.search_score, fallback=0.0)
    query_tokens = tokenize(request.search_query) | {term.lower() for term in request.interest_terms}
    candidate_tokens = (
        tokenize(candidate.title)
        | set(token.lower() for token in candidate.tags)
        | ({candidate.creator_name.lower()} if candidate.creator_name else set())
        | ({candidate.category.lower()} if candidate.category else set())
    )

    keyword_match = compute_overlap_score(query_tokens, candidate_tokens)
    session_boost = normalize(sum(signals.get("search_terms", {}).values()) / 10.0, fallback=0.0)
    post_search_watch = normalize(float(signals.get("events", {}).get("watch", 0.0)) / 30.0, fallback=0.0)
    return clamp((0.45 * base) + (0.35 * keyword_match) + (0.1 * session_boost) + (0.1 * post_search_watch))


def derive_viral_boost(candidate: VideoCandidate, signals: dict[str, Any]) -> float:
    # Viral boost is intentionally additive so fast-moving content can surface quickly.
    base = normalize(candidate.viral_boost, fallback=0.0)
    velocity_boost = 0.0
    if candidate.view_velocity is not None:
        velocity_boost += normalize(candidate.view_velocity / 250.0, fallback=0.0) * 0.15
    if candidate.share_velocity is not None:
        velocity_boost += normalize(candidate.share_velocity / 120.0, fallback=0.0) * 0.15

    stored_bias = normalize(float(signals.get("viral_bias", 0.0)), fallback=0.0)
    return clamp(base + velocity_boost + stored_bias)


def score_video(candidate: VideoCandidate, request: RecommendationRequest, signals: dict[str, Any]) -> tuple[float, dict[str, float]]:
    # This is the MVP ranking formula from the design doc.
    ai_score = (
        0.40 * derive_watch_score(candidate, signals)
        + 0.25 * derive_engagement_score(candidate, signals)
        + 0.20 * derive_interest_match(candidate, request, signals)
        + 0.10 * derive_freshness(candidate)
        + 0.05 * derive_creator_quality(candidate)
    )

    peer_score = derive_peer_score(candidate, request, signals)
    search_score = derive_search_score(candidate, request, signals)
    viral_boost = derive_viral_boost(candidate, signals)

    final_score = clamp((0.55 * ai_score) + (0.20 * peer_score) + (0.25 * search_score) + viral_boost)
    breakdown = {
        "ai_score": round(ai_score, 4),
        "peer_score": round(peer_score, 4),
        "search_score": round(search_score, 4),
        "viral_boost": round(viral_boost, 4),
    }
    return round(final_score, 4), breakdown


def build_cold_start_candidates(request: RecommendationRequest) -> list[VideoCandidate]:
    # Generate a synthetic set of candidates so brand-new users still get a feed.
    rng = random.Random(request.user_id)
    topics = [
        "music",
        "comedy",
        "fashion",
        "food",
        "sports",
        "tech",
        "travel",
        "education",
        "dance",
        "gaming",
    ]

    seed_terms = [term.lower() for term in request.interest_terms]
    if not seed_terms and request.search_query:
        seed_terms = [request.search_query.lower()]
    candidates: list[VideoCandidate] = []

    for index in range(request.limit):
        topic = topics[index % len(topics)]
        overlap = 0.15 if seed_terms and topic in " ".join(seed_terms) else 0.0
        base = 0.45 + (0.05 * (index % 5))
        candidates.append(
            VideoCandidate(
                video_id=10_000 + rng.randint(0, 90_000) + index,
                creator_id=1_000 + rng.randint(0, 9_000),
                title=f"Trending {topic.title()} Clip {index + 1}",
                creator_name=f"{topic}_creator_{index + 1}",
                category=topic,
                tags=[topic, "trending"],
                watch_time_score=clamp(base + overlap),
                engagement_score=clamp(0.4 + rng.random() * 0.4),
                interest_match=clamp(0.3 + overlap),
                freshness=clamp(0.75 - (index * 0.03)),
                creator_quality=clamp(0.45 + rng.random() * 0.4),
                peer_score=clamp(0.2 + rng.random() * 0.3),
                search_score=clamp(overlap + rng.random() * 0.2),
                viral_boost=clamp(rng.random() * 0.15),
                view_velocity=rng.uniform(10, 250),
                share_velocity=rng.uniform(1, 60),
                completion_rate=rng.uniform(0.35, 0.95),
                age_hours=rng.uniform(1, 72),
            )
        )

    return candidates


@app.get("/")
def home() -> dict[str, str]:
    return {"message": "Kulsah AI Recommendation Service Running"}


@app.get("/health")
def health() -> dict[str, Any]:
    # Useful for Docker health checks and quick deployment verification.
    return {
        "status": "ok",
        "service": "kulsah-ai-recommendation",
        "store": "redis" if signal_store.client is not None else "memory",
    }


@app.post("/events")
def ingest_event(payload: EventPayload) -> dict[str, Any]:
    # Laravel can call this after watches, likes, shares, saves, searches, and follows.
    signals = signal_store.record(payload)
    return {
        "message": "Event recorded successfully.",
        "user_id": payload.user_id,
        "signals": signals,
    }


@app.post("/recommend", response_model=RecommendationResponse)
def recommend(request: RecommendationRequest) -> RecommendationResponse:
    # Rank the supplied candidate set, or fall back to cold-start generation.
    signals = signal_store.get(request.user_id)
    candidates = request.videos or build_cold_start_candidates(request)

    if not candidates:
        raise HTTPException(status_code=422, detail="No candidates were provided and cold start generation failed.")

    scored = []
    for candidate in candidates:
        score, breakdown = score_video(candidate, request, signals)
        item = RecommendationItem(
            video_id=candidate.video_id,
            score=score,
            breakdown=breakdown if request.include_breakdown else None,
        )
        scored.append(item)

    scored.sort(key=lambda item: item.score, reverse=True)
    top_videos = scored[: request.limit]

    return RecommendationResponse(
        user_id=request.user_id,
        videos=top_videos,
        meta={
            "candidate_count": len(candidates),
            "returned_count": len(top_videos),
            "source": "provided_candidates" if request.videos else "cold_start",
            "ranking_formula": "0.55*AI + 0.20*Peer + 0.25*Search + ViralBoost",
        },
    )
