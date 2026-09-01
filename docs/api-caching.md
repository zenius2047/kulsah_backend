# API Caching

Kulsah uses Redis as the primary application cache. The configured failover order is:

```text
Redis -> database cache -> in-memory array cache
```

This keeps API reads available during a temporary Redis outage. Production and Docker should still run Redis; fallback is resilience, not a Redis replacement.

## Cached reads

- video creator pages, video detail, watched videos, feed, and recommendations through the existing video/feed cache services
- creator subscription plans through the existing subscription cache
- discovery and event pages for 60 seconds
- community pages and video comments for 30 seconds
- KulCoin package and gift catalogs for 300 seconds

Financial balances, ledgers, transactions, upload/render progress, authentication responses, and developer credentials are deliberately not response-cached.

Response-cached endpoints include this diagnostic header:

```text
X-Cache: MISS
X-Cache: HIT
```

Cache keys include the authenticated viewer, request path, sorted query parameters, and `Accept` header, preventing one viewer's personalized state from being served to another.

## Local Docker verification

```powershell
docker compose up -d redis
docker exec kulsah-redis redis-cli -n 1 PING
php artisan config:clear
```

The Redis command should return `PONG`. The host PHP process uses `REDIS_HOST=127.0.0.1`; the Laravel Docker service overrides it with `REDIS_HOST=redis` inside the Compose network.
