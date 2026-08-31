# Kulsah Live Streaming

Kulsah owns Live session state, access control, engagement, moderation, analytics, and KulCoin accounting. Agora owns realtime media transport and optional cloud recording. All Agora credentials are issued by Laravel through `LiveStreamingProviderInterface`.

## Configuration

Set the Live and Agora values listed in `.env.example`. Keep `AGORA_APP_CERTIFICATE` and `AGORA_CUSTOMER_SECRET` server-side; they must never be returned in API resources or logs.

## Runtime requirements

- Redis for presence, heartbeats, unique-viewer sets, like aggregation, and short-lived Live state.
- Reverb/broadcasting for comments, likes, gifts, moderation, status, co-hosting, and Battle events.
- A queue worker for notifications, analytics rollups, recording/replay processing, and reconciliation.
- Laravel scheduler for stale heartbeat cleanup, abandoned viewer-session reconciliation, recording reconciliation, and expired co-host/Battle state.

## Agora setup

Create an Agora RTC project and keep its App ID and App Certificate in the backend environment. Production projects should enable strict host/co-host authentication so only Laravel-authorized creators and accepted co-hosts can publish.

`AgoraTokenService` issues short-lived role-specific tokens through `LiveStreamingProviderInterface`: viewers receive Audience tokens, while creators and active accepted co-hosts receive Broadcaster tokens.

Cloud Recording is optional and remains disabled unless both the Kulsah and Agora recording flags are enabled and storage is configured.

## Domain and financial flow

Live eligibility and explicit status transitions are enforced by the Live services. Viewer access checks visibility, Fans, subscriptions, blocks, platform suspension, and Live bans. Redis handles temporary high-frequency state; analytics services finalize PostgreSQL aggregates when a Live ends.

Gifts reuse the existing Kulsah gift catalog and KulCoin wallet ledger. Wallet locking and idempotency are handled transactionally before a gift event is broadcast. Redis is never financial truth.

## Testing

Feature tests use `FakeLiveStreamingProvider` and do not call Agora:

```text
php artisan test --filter=LiveStreamingTest
```

## Production note

The repository vendors Agora's official maintained PHP `AccessToken2`, `Util`, and `RtcTokenBuilder2` sources under `app/Support/Agora/Official`. The provider loads these sources server-side and emits real Token007 RTC credentials. Before launch, verify the App ID, App Certificate, strict host/co-host authentication setting, and recording storage configuration in the Agora Console.
