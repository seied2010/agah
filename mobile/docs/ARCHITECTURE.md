# Online Android architecture

`Compose UI → ViewModel → AgahRepository → HTTPS REST API → existing ERP services → shared MariaDB`

The desktop product is excluded. There is no local financial database, offline write queue or second accounting engine. Amounts are exact strings in units of one hundredth rial; quantities are exact strings scaled by 10,000. The native UI converts display units with BigDecimal. Server services own tax, rounding, balances, stock valuation, final document numbering and closed-period checks.

## Authentication
Passwords are verified by the existing WordPress authentication chain and its rate limiter. Existing ERP TOTP/recovery verification is reused. An access token identifies a short-lived real WordPress session; an independent mobile session record additionally binds password/MFA versions, revocation and device metadata. REST validates both, then installs an in-process cookie for the existing `Auth::assertCompany` check. No cookie is sent to the client. Access is memory-only; refresh credentials are encrypted with Android Keystore AES-GCM and bound to the server address. Refresh rotates the mobile session and old tokens stop working. Password/MFA changes invalidate mobile proofs.

## Authorization
Every company request calls `Ctx::boot`. A WordPress administrator does not automatically gain company access. Mobile policy defaults to read only; per-user policy can narrow company policy. Existing role/document capabilities remain mandatory. A write rechecks current membership, mobile policy and token revocation under the existing company write lock.

## Financial writes and ambiguous network outcomes
The client creates an operation UUID before sending a financial request and persists only the receipt coordinates (server, user, company, year and UUID). The payload remains in memory. The server stores a fingerprint and minimal committed result in the same transaction as the core service write. Reusing the key with different input fails. ETags and core revisions protect edits.

A failed network response does not imply failure to commit. Receipt lookup determines the outcome. Cancellation creates a tombstone under the same company lock, preventing delayed HTTP requests from executing. A confirmed write leaves its form before any optional GET refresh; refresh failure cannot re-enable that completed form. Only a valid committed receipt confirms success. Unrecognized proxy errors keep the outcome uncertain.

## Files and reports
Private attachments use existing `FileService` validation and target-level authorization. The mobile transport currently caps files at 2 MiB. Downloads include a SHA-256 digest checked before creating an app-private, temporary FileProvider URI. No public attachment URL is created. PDF generation runs on the server with mPDF; CSV guards spreadsheet formulas. Exports explicitly represent the selected report page and filters.

## Backup/restore integration
Metadata tables use `agmobile_`, separate from the core `agah_` table catalog. Financial backups remain unchanged. ERP 0.27.6 adds two transaction-local restore hooks; the companion invalidates mobile sessions, marks old receipts as restored tombstones and disables writes while preserving existing `off` policies. Old receipts cannot claim that a restored-away operation still exists or execute again. This is an additive integration hook, not a new financial restore implementation.

## Build and release
Kotlin 2.1.20, Compose, AGP 8.9.2, Gradle 8.11.1, compile/target SDK 35, minimum API 26. Debug builds permit local emulator hosts for tests; release traffic requires HTTPS. Installable packages exclude test fixtures and signing/SDK secrets. Test source contains only deliberately disposable local fixture credentials. Development APKs are explicitly labelled as test builds.
