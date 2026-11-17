# Android project status

Contract: supplied Android specification v2.0. Delivery is one complete release; internal checkpoints are not releases.

## Scope and decisions
- Only the online ERP is in scope. Desktop source and database are excluded.
- Native Kotlin/Compose client; online only, no financial database or offline write queue.
- Reuse existing PHP accounting services, permissions, company locks and transactions.
- Separate versioned mobile API. Store HMAC credentials are not user credentials.
- Default company mobile policy: read only; managers can explicitly allow writes.
- Financial values travel as decimal strings. Financial calculations remain on the server.
- Session and operation metadata must not introduce `agah_` tables: portable backup verifies that table namespace against its schema.

## Current checkpoint — approximately 60%; implementation continues
- Native Kotlin/Compose login, company/year selection, module lists/details/forms, role and policy gating, reports, attachments, barcode scanner, Persian calendar and exact-value formatting.
- Shared core sales/purchase drafts and issue, receipt/payment allocations, expense posting, balanced voucher transitions and stock movement APIs. No offline financial database or write queue.
- Owner/server-isolated durable operation receipts. Reads can reconnect; financial writes cannot automatically reconnect/replay. Unrecognized proxy errors remain uncertain. Session revocation can retry safely.
- Confirmed commits leave the form before optional reads; failed follow-up reads cannot reopen an already committed form.
- Server search and scoped lookup labels, stock/party histories, document PDF, report PDF/CSV, all three financial statements.
- Core dashboard trends/alerts/date selection and controlled widget visibility. Fixed a reproduced prior-year carry-forward double-count in dashboard ledger balances by scoping the existing query to its fiscal year.
- Private party fields remain hidden while opaque ETags include their version. Session issuance rechecks password/MFA state under the existing user lock.
- Companion metadata remains outside the portable-backup financial namespace. Restore revokes related sessions, disables mobile writes and preserves operation tombstones as restored.
- Machine-readable OpenAPI route/DTO contract plus Persian installation, security, permissions, operation, backup/restore and update guidance.

## Verification evidence
- 8 native unit tests passed (exact numbers, API response classification, form/settlement serialization).
- All 12 Android instrumentation tests passed, including real login/company/year/rotated form/logout, real Android financial commits/settlement/PDF, timezone/leap-day checks and uncertain-write/owner isolation regressions (0 skipped, 0 failures).
- HTTP integration and financial suites passed; financial suite has 14 checks.
- API security: 23 checks passed, including role restrictions, strict DTOs, private-file integrity/IDOR, cancellation tombstones and real PDF/CSV.
- Stock/period concurrency: 9 checks passed. Server searches/lookups: 10 passed.
- Document PDF/integrity/company scope: 8 checks passed. Dashboard/complete statements: 7 passed. Private-version ETags: 3 passed.
- Dashboard year-boundary regression reproduced before the fix (200000 → 210000) and passed after (200000 → 200000); the fixture rolls back all its changes.
- Prior company-restore integration: 5 checks passed through actual REST callbacks and portable restoration.
- Bounded loopback load smoke: 40 requests, concurrency 5, 0 errors; p50 201.9 ms, p95 316.2 ms. This is not production capacity evidence.
- All verification uses disposable WordPress/PHP 8.3/MariaDB and an Android 11 software emulator. No live deployment performed.

## Remaining before final acceptance
- Additional native module UX and device-size/font-scale verification; broader interruption and server-change visibility checks.
- Complete dashboard range/financial indicators and remaining presentation details against the supplied contract.
- Final release build/signing decision, installation verification, acceptance matrix and complete delivery report.
- Online dependency-advisory verification: both Packagist and OSV endpoints were blocked by the environment proxy. No dependency-security clearance is claimed.

## Delivery status
The test60 APK uses the existing development certificate, not a validated production signing setup. API test60 requires ERP 0.27.6-alpha or later. Its paired ERP package includes only additive restore hooks, read-search support and the scoped dashboard-balance correction; financial posting calculations and schemas are unchanged. Desktop files were not inspected or modified. Test outputs are checkpoints, not final completion.
