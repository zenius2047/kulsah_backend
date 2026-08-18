# Kulsah Challenge Engine

## Reused platform systems

Challenge entries reference the existing `videos` table. Reactions and views are read from `video_likes` and `video_views`; no challenge-specific copies are maintained. Creator identity always comes from the authenticated `users` record. Cash and wallet-credit rewards settle through `WalletService` and the existing double-entry wallet ledger. Laravel queues, Redis, broadcasting, notifications, policies, resources, and the scheduler remain the infrastructure layer.

The current application has no organization or generic media registry. Organization and sponsor-media identifiers are therefore nullable, forward-compatible identifiers without foreign keys. Challenge media that exists today references processed Kulsah videos through `challenge_media.video_id`.

## Lifecycle

All status changes pass through `ChallengeLifecycleService`; clients cannot mass-assign status. The normal path is:

`draft → pending_review → approved → scheduled/active → submissions_closed → voting_closed → judging → integrity_review → results_pending → finalized → rewards_processing → completed → archived`

Pause, rejection, cancellation, voiding, and resumption are restricted to the transition map. The scheduled lifecycle job is idempotent and advances only when the relevant server-side timestamp is due.

## Rules, entries, and voting

Rules are versioned relational records with a scope, operator, and JSON value. Eligibility returns structured failures and is snapshotted when an entry is accepted. Entry creation locks the challenge row before participant and per-creator limits are checked. The submitted video must belong to the authenticated creator and have `ready` processing status.

Voting uses one ballot per challenge/user and normalized ballot choices. Single, multiple, ranked-choice, and points-allocation modes share the same schema. Ranked choice accepts arbitrary consecutive ranks and configurable `rank_points`.

## Scoring and ranking

Point mode calculates `raw metric × configured point_value`. Weighted-normalized mode converts each metric to 0–100 using max-ratio normalization (`entry raw / maximum raw × 100`); jury scores are already normalized from criterion ranges. It then calculates `normalized × weight_bps / 10000`. Component details and final totals use decimal database columns and are persisted in immutable score snapshots.

Default tie breaks are final score descending, submission time ascending, then entry ID ascending. Finalization locks the challenge, confirms integrity review, freezes scores, ranks entries, persists winner history, and allocates every prize whose rank range contains the winner rank.

## Integrity and rewards

Integrity flags preserve evidence and are resolved rather than deleted. The initial analyzer detects per-user view velocity; new detectors can add flag types without schema changes. A challenge configured with `review_before_finalization` cannot finalize while any flag is open.

Reward allocations are separate from attempts. Each attempt has a unique `challenge-reward:{allocation_id}` idempotency key. USD cash and wallet-credit rewards transfer from the `challenge_rewards_fund` system wallet into the winner's pending wallet balance. Other currencies and non-cash rewards remain controlled/manual fulfillment records instead of pretending that fulfillment occurred.

## API

- `GET /api/v1/challenges`
- `GET /api/v1/challenges/{challenge}`
- `GET /api/v1/challenges/{challenge}/leaderboard`
- `POST /api/v1/challenges/{challenge}/entries`
- `PUT /api/v1/challenges/{challenge}/ballot`
- `PUT /api/v1/challenges/{challenge}/entries/{entry}/jury-scores`
- `POST /api/v1/creator/challenges`
- `PATCH /api/v1/creator/challenges/{challenge}`
- `POST /api/v1/creator/challenges/{challenge}/transition`
- `POST /api/v1/creator/challenges/{challenge}/finalize`

All endpoints require Sanctum authentication and relevant platform/challenge roles. High-risk mutation routes are rate-limited.
