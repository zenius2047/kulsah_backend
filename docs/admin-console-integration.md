# Admin console integration

The API implementation is in this Laravel repository. The frontend contains no Laravel backend folder.

## Activation applied

The approved admin-console-activation.patch has been applied. It registers /api/v1/admin routes for bearer-token authentication, adds the ConsolePlatformPolicy middleware, rejects suspended users during mobile login, and lets gift settlement use the saved gift creator share with the existing configured share as a fallback. The coin value and GHS wallet currency are preserved.

The migration database/migrations/2026_10_07_000001_create_admin_console_tables.php has been applied. This adds staff access, account status, preference columns, persistent CMS/campaign/adjustment records, and audit records. It does not rewrite existing wallet balances.

## Frontend configuration

The frontend runs at http://localhost:3000 and proxies /api to http://localhost:8000. VITE_API_BASE_URL defaults to /api/v1/admin. A deployed frontend needs an HTTPS API base URL ending in /api/v1/admin or a same-origin API proxy. Login requires a real existing user with the admin role. Demo role switching and simulated mutations have been removed.

## Implemented operations

Authenticated reads, account enforcement and creator verification, content moderation, events, attendance, subscription cancellation, wallet freezes, Kulcoin packages and gifts, staff roles and assignment to existing accounts, two-person coin adjustments with balanced ledger entries, CMS drafts and publication, in-app campaign delivery, profile/password/preferences, and real queue alerts. Withdrawal decisions are persisted as review decisions; external transfer execution is not implemented. Provider refunds and gift reversals are not implemented, and their former demo controls were removed. Notification campaigns currently deliver in-app notifications only.

## Validation

PHP syntax checks passed. The frontend TypeScript check and production build passed. Backend feature tests passed: 8 tests and 187 assertions on the isolated kulsah_testing database. Windows test access failed authentication, so Docker is used for backend checks with temporary test tooling. PHPUnit is installed only in /tmp for that validation; application dependencies are unchanged.
