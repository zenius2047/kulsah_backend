import sys
import unittest
from pathlib import Path

from fastapi.testclient import TestClient

sys.path.append(str(Path(__file__).resolve().parents[1]))

from app.main import app


class TestFastAPIEndpoints(unittest.TestCase):
    def setUp(self) -> None:
        self.client = TestClient(app)

    def test_health_endpoint_returns_ok(self) -> None:
        response = self.client.get("/health")

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["status"], "ok")
        self.assertEqual(payload["service"], "kulsah-ai-recommendation")

    def test_events_endpoint_records_signal(self) -> None:
        response = self.client.post(
            "/events",
            json={
                "user_id": 210,
                "event_type": "search",
                "value": 1,
                "terms": ["food", "recipe"],
            },
        )

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["user_id"], 210)
        self.assertEqual(payload["message"], "Event recorded successfully.")
        self.assertIn("signals", payload)
        self.assertIn("search_terms", payload["signals"])

    def test_recommend_endpoint_returns_ranked_videos(self) -> None:
        response = self.client.post(
            "/recommend",
            json={
                "user_id": 210,
                "limit": 3,
                "search_query": "food",
                "interest_terms": ["cooking", "ramen"],
                "peer_strength": 0.3,
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
                    },
                ],
            },
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
