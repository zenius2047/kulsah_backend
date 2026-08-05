import hashlib
import hmac
import json
import os
import sys
import time
import unittest
from pathlib import Path

from fastapi.testclient import TestClient

sys.path.append(str(Path(__file__).resolve().parents[1]))

from app.main import app


class TestFastAPIEndpoints(unittest.TestCase):
    def setUp(self) -> None:
        os.environ.setdefault("FASTAPI_SHARED_SECRET", "test-secret")
        self.client = TestClient(app)

    def _signed_headers(self, method: str, path: str, body: str) -> dict[str, str]:
        timestamp = str(int(time.time()))
        signature = hmac.new(
            os.environ["FASTAPI_SHARED_SECRET"].encode("utf-8"),
            "\n".join([timestamp, method.upper(), path, body]).encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

        return {
            "X-Kulsah-Timestamp": timestamp,
            "X-Kulsah-Signature": signature,
            "X-Kulsah-Service": "laravel",
            "Content-Type": "application/json",
        }

    def test_health_endpoint_returns_ok(self) -> None:
        response = self.client.get("/health")

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["status"], "ok")
        self.assertEqual(payload["service"], "kulsah-ai-recommendation")

    def test_events_endpoint_records_signal(self) -> None:
        body = json.dumps(
            {
                "user_id": 210,
                "event_type": "search",
                "value": 1,
                "terms": ["food", "recipe"],
            },
            separators=(",", ":"),
        )
        response = self.client.post(
            "/events",
            data=body,
            headers=self._signed_headers("POST", "/events", body),
        )

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["user_id"], 210)
        self.assertEqual(payload["message"], "Event recorded successfully.")
        self.assertIn("signals", payload)
        self.assertIn("search_terms", payload["signals"])

    def test_recommend_endpoint_returns_ranked_videos(self) -> None:
        body = json.dumps(
            {
                "user_id": 210,
                "limit": 3,
                "search_query": "food",
                "interest_terms": ["cooking", "ramen"],
                "peer_strength": 0.3,
                "followed_creator_ids": [12],
                "subscribed_creator_ids": [13],
                "liked_video_ids": [101],
                "bookmarked_video_ids": [102],
                "favorite_categories": ["food", "music"],
                "favorite_creator_ids": [12, 13],
                "include_breakdown": True,
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
                        "age_hours": 7,
                        "history_affinity": 0.8,
                    },
                    {
                        "video_id": 102,
                        "creator_id": 13,
                        "title": "City travel vlog",
                        "creator_name": "wanderwithme",
                        "category": "travel",
                        "tags": ["travel", "vlog"],
                        "watch_time_score": 0.51,
                        "engagement_score": 0.47,
                        "interest_match": 0.22,
                        "freshness": 0.38,
                        "creator_quality": 0.4,
                        "peer_score": 0.12,
                        "search_score": 0.11,
                        "viral_boost": 0.02,
                        "view_velocity": 18,
                        "share_velocity": 2,
                        "completion_rate": 0.44,
                        "age_hours": 42,
                        "history_affinity": 0.1,
                    },
                ],
            },
            separators=(",", ":"),
        )
        response = self.client.post(
            "/recommend",
            data=body,
            headers=self._signed_headers("POST", "/recommend", body),
        )

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["user_id"], 210)
        self.assertEqual(payload["meta"]["source"], "provided_candidates")
        self.assertEqual(len(payload["videos"]), 2)
        self.assertGreaterEqual(payload["videos"][0]["score"], payload["videos"][1]["score"])
        self.assertIn("breakdown", payload["videos"][0])


if __name__ == "__main__":
    unittest.main()
