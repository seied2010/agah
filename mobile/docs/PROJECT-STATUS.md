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

## Completed (approximately 30% of the full contract; test checkpoint)
- Read contract and audited online authentication, context, transactions and service interfaces.
- Located online ERP 0.27.5-alpha and copied its source into `source/agah-erp` for reviewable integration.
- Downloaded Android command-line and Gradle tooling; SDK provisioning started.
- Added isolated companion API with user sessions, MFA integration, rotation, revocation, server mobile policy, exact-scaled numeric input, idempotent transactions and ETags. Existing financial core is unchanged.
- Added native Compose source for login, company/year selection, dashboard, entity lists/detail/forms and basic reports. Not yet Android-verified.
- HTTP integration: 22 checks passed (authorization, company/year isolation, duplicate concurrent writes, conflict handling, rotation/revocation).
- Financial HTTP integration: 14 checks passed (catalog, sales/purchase issue, receipt/payment with allocations, expense posting, balanced voucher finalization and report reads).
- Native debug APK compiled successfully; three exact-value unit tests passed; APK signature verified with Android apksigner.
- Added bundled Persian font and native barcode scanning source; server-side private attachments and PDF/CSV export endpoints.
- PDF and CSV HTTP output verified. Cancellation tombstones tested: cancellation returns cancelled; delayed write returns 409; receipt stays cancelled.
- Android emulator provisioned in software mode; boot/install verification is still in progress.

## Remaining
- Secure user API, mobile policy management, operation deduplication and conflict checks.
- Android application, all required modules, reports, attachments and barcode scanning.
- Local integration, concurrency, security and Android UI tests.
- APK, deployment/user documentation and final acceptance evidence.

## Verification
PHP syntax checks passed. HTTP integration checks passed on isolated WordPress/PHP 8.3/MariaDB. Gradle assembleDebug/testDebugUnitTest passed; 3 tests, 0 failures. APK uses a development signing key; it is an early test build, not a completed release. No live deployment performed. Composer dependency advisory audit was blocked by access to packagist.org; dependency security review remains outstanding.

## Known limitations
Production access and release signing have not been validated. A production signing key must not be committed.
