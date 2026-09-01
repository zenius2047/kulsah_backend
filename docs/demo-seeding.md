# Demo data seeding

Run the complete, idempotent demo dataset with:

```bash
php artisan db:seed
```

For a disposable local database, `php artisan migrate:fresh --seed` creates the schema and dataset together. That command deletes existing database data and must not be used against a shared or production database.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@kulsah.com` | `Admin@123` |
| Fan | `fan@kulsah.com` | `Fan@123` |
| Fan | `fans@kulsah.com` | `Fans@123` |
| Creator | `creator@kulsah.com` | `Creator@123` |

The additional creator accounts (`zuri@kulsah.com`, `tunde@kulsah.com`, `naledi@kulsah.com`, `kwame@kulsah.com`, and `amina@kulsah.com`) all use `Creator@123`.

## Seeded domains

- Roles, creator/fan profiles, onboarding vibes, follows, subscription plans, and active, expired, cancelled, and blocked subscription histories with audit actions.
- An 18-video curated feed plus 200 generated ready duets with source relationships, multiple layouts, posters, metadata, likes, and views. Uploading, transcoding, and retryable-failure records exercise lifecycle UI.
- Seventeen creator-owned playlists spanning tutorials, challenge guides, choreography, comedy, fitness, travel, design, subscriber content, and an intentionally empty draft playlist.
- More than 200 community text, image, video, poll, and subscriber posts with views, votes, likes, shares, comments, and remote media.
- Six physical and online events covering upcoming, ongoing, past, and draft states, with ticket tiers, purchases, active and checked-in tickets, verification signatures, and QR URLs.
- USD wallets and KulCoin wallets, 200 gift catalog items, more than 200 delivered gifts, transactions, and balanced demo ledger entries.
- Four curated challenges plus 200 generated standard challenges and 200 generated Creator Battles across active, scheduled, submissions-closed, and completed states.
- Challenge collaborators, sponsors, pools, prizes, rules, media, invites, entries, eligibility snapshots, judging stages, scoring components, jury data, ballots, scores, integrity review, winner selection, rewards, audit logs, and a completed Creator Battle settlement.
- Three OAuth developer clients (web, Android PKCE, and iOS PKCE) and read/unread video mention notifications.

The web OAuth demo client ID is `33000000-0000-4000-8000-000000000001` and its local-development secret is `DemoClientSecret@123`.

Operational data such as activation and password reset codes, sessions, personal access tokens, OAuth authorization/access/refresh/device codes, cache rows, queue jobs, failed jobs, batches, and webhook deliveries is intentionally not seeded because it is transient or security-sensitive. OAuth client registrations and application notifications are seeded because they are user-facing application data.

`DatabaseSeederTest` verifies that every non-transient business table contains linked demo records, that playlist videos belong to their playlist owner, and that running the complete seeder twice does not add duplicate rows.

## Public media

`DemoMedia` centralizes the remote media catalog. It uses Cloudinary's documented public demo cloud and generates short portrait MP4 variants plus poster frames. Each video stores its provider, public asset ID, and documentation source in metadata.

- Cloudinary video delivery documentation: <https://cloudinary.com/documentation/video_manipulation_and_delivery>
- Cloudinary demo URLs are development fixtures and should be replaced with Kulsah-owned media before production use.
