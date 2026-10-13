# Developer portal integration

The frontend lives in `kulsah-forge-hub-dev`. All Laravel code and migrations live in this `kulsah_backend` repository.

## Local setup

The local additive migration has been applied. On another environment run:

```shell
php artisan migrate --path=database/migrations/2026_10_12_000001_create_developer_portal_tables.php
```

The frontend API base is `VITE_API_BASE_URL=http://localhost:8000/api/v1/`. Use the same hostname for the frontend and API so the Sanctum CSRF cookie can be read. Keep the existing stateful domain and CORS configuration. The frontend initializes `/sanctum/csrf-cookie`, sends cookies and the decoded `X-XSRF-TOKEN` header, and retains the existing bearer-token session contract. Explicit invalid bearer tokens cannot fall back to a cookie session.

## API resources

All routes below are under `/api/v1/developer`, require an activated authenticated user, and are limited to 120 requests per minute. Mutations with sensitive codes and invitation creation have additional limits.

| Resource | Operations |
| --- | --- |
| `workspaces` | List owned and joined workspaces |
| `dashboard` | Real application/token counts, seven-day charts, request activity, account regions |
| `apps` / `apps/{client}` | List, create, inspect, update, revoke; existing secret-rotation endpoint remains |
| `tokens` / `tokens/{token}` | Paginated OAuth tokens; revoke access and refresh tokens |
| `logs` / `logs/{log}` | Searchable paginated request metadata; optional `client_id` filter |
| `authorizations` / `authorizations/{token}` | Personal OAuth grants; revoke all grants for the selected application |
| `team` | Owner, members, roles, pending invitations |
| `team/invitations`, `team/accept`, `team/members/{member}` | Create/cancel email-bound single-use invitations; accept; change role; remove member |
| `profile`, `preferences` | Save personal name/username/location and notification preferences |
| `two-factor/setup`, `two-factor/confirm`, `two-factor` | Password-confirmed authenticator enrollment, confirmation/recovery codes, verified disable |
| `sessions`, `sessions/others`, `sessions/{session}` | Real bearer/browser sessions and revocation |
| `keys`, `keys/{key}` | Expiring developer API keys, restricted read/write abilities, revoke; plaintext returned once |
| `notifications`, `notifications/read` | Persisted workspace notifications, unread count, mark read |
| `webhooks`, `webhooks/{webhook}` | Persisted endpoint subscriptions, enable/disable, update, delete |
| `webhooks/{webhook}/test`, `webhooks/{webhook}/deliveries` | Queue a signed test; inspect delivery attempts/results |
| `billing` | Configured developer plan and recorded usage; paid billing is not configured |

`X-Workspace-Id` selects an owned/joined workspace. Without it, resources use the signed-in user's workspace. Every workspace endpoint validates membership. Owners/admins manage members; only owners grant/remove admin roles. Developers can mutate apps/tokens/webhooks. Viewers have read-only workspace resource access. Personal preferences, profile, sessions, API keys, and authorizations always belong to the signed-in user, regardless of selected workspace.

Invitations store a SHA-256 token hash, expire after seven days, and can be accepted only by the matching email account. The UI returns a link for the owner to share; no invitation email is sent automatically.

## Security and logging

Authenticator enrollment uses six-digit SHA-1 TOTP with a 30-second step and one-step clock tolerance. Secrets are encrypted; successful counters cannot be reused. Eight hashed recovery codes are returned once, each usable once. Enrolled accounts require the second factor on API password login, browser login, and social login. Existing accounts remain unchanged until confirmed enrollment.

New access/refresh tokens share a unique device-pair identifier in their internal token names, so device revocation also invalidates its refresh token. Older access tokens without that pairing identifier can only be individually revoked; use ?Log out other devices? to revoke all older access/refresh sessions.

Request logs store path/method, actor/client IDs, status, latency, IP, and account country when present. Request bodies, authorization headers, passwords, OTPs, client secrets and response bodies are not stored. Recording begins at integration activation; pre-existing history is not fabricated. A daily `developer:prune-logs` schedule retains 30 days of logs and completed webhook delivery history.

## Webhook delivery

Run the existing Laravel queue worker/Horizon. Requests to subscribed application-change and token-revocation events queue persisted delivery records. Test delivery sends an explicit `webhook.test` event. HTTPS port 443 and publicly resolved IPv4 endpoints are required. Delivery re-resolves and pins the public address; redirects and private/reserved addresses are rejected. TLS verification stays enabled. Delivery retries up to three times with backoff; IDs can be used for receiver-side deduplication.

Headers: `X-Kulsah-Delivery`, `X-Kulsah-Event`, and `X-Kulsah-Signature: t=<unix timestamp>,v1=<hex signature>`. Verify HMAC-SHA256 using your signing secret over the exact bytes of `timestamp + "." + raw request body`; reject timestamps older than your chosen tolerance and compare signatures in constant time. The signing secret is returned only at creation.

## Validation

The dedicated `phpunit.developer.xml` uses PostgreSQL on localhost port 15432 with database `kulsah_developer_testing`, independent of application data. Start a disposable database:

```shell
docker run --detach --rm --name kulsah-developer-test-db --publish 127.0.0.1:15432:5432 --env POSTGRES_PASSWORD=developer_testing_only --env POSTGRES_DB=kulsah_developer_testing postgres:15
```

Wait until `docker exec kulsah-developer-test-db pg_isready -U postgres` reports ready, then use the host PHP runner with installed development dependencies:

```shell
php vendor/bin/phpunit --configuration phpunit.developer.xml
docker stop kulsah-developer-test-db
```

The suite covers authentication, workspace isolation/RBAC, ownership, persistence, native client PKCE flows, OTP/recovery enforcement, token revocation, API-key restrictions, signed deliveries, and account anonymization/revocation.
