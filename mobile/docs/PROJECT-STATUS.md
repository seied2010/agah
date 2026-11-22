# Android project status

Contract: supplied Android specification v2.0. Delivery is one complete release; internal checkpoints are not releases.

## Presentation update — 0.4.0-ui-rc1

A focused Android UI/UX redesign follows the RC1 candidate. Backend/API/core/desktop code is unchanged. Fifteen JVM unit/layout/render tests passed, including narrow/large-font and tablet layouts, password controls and semantic contrast. See UI-RELEASE-FA.md, UI-VERIFICATION.md and UI-PREVIEWS.md. These results do not replace the outstanding complete-device acceptance of RC1 or production signing.

## Scope and decisions
- Only the online ERP is in scope. Desktop source and database are excluded.
- Native Kotlin/Compose client; online only, no financial database or offline write queue.
- Reuse existing PHP accounting services, permissions, company locks and transactions.
- Separate versioned mobile API. Store HMAC credentials are not user credentials.
- Default company mobile policy: read only; managers can explicitly allow writes.
- Financial values travel as decimal strings. Financial calculations remain on the server.
- Session and operation metadata must not introduce `agah_` tables: portable backup verifies that table namespace against its schema.

## Current checkpoint — approximately 80%; acceptance candidate

Implementation includes native online modules, exact values, role/company read-write controls, durable uncertain-operation handling, dashboard period cards, thirteen core financial ratios, private files including existing web expense images, optional direct expense payments, PDF/CSV and actual web/API interoperability. Existing financial posting services remain authoritative. No desktop files were inspected or changed.

## Verification

Latest source: 155 backend controls passed; 9 Android unit tests passed; lint has zero errors and 14 warnings; non-debuggable minified acceptance APK built and development signature verified. Actual Persian invoice/voucher PDFs were rendered and inspected. See ACCEPTANCE-RC1.md and evidence/summary.json for scope and limitations.

Twelve Android instrumentation tests passed on earlier source. Latest device tests remain blocked: a clean software emulator installed both APKs, but shared-storage transport failed and no completed test result was returned; screenshot capture also timed out. The stalled test run was stopped explicitly. Do not count historical results as latest-source acceptance.

## Remaining

Complete latest instrumentation, device-size/font-scale/dark acceptance and non-debuggable runtime boundary check. Physical camera/device and destination-server acceptance remain operational checks. Production signing is unconfigured; online dependency advisory access is blocked. mPDF license compatibility with the owner's commercial distribution needs review; owner source license was not changed.

## Delivery

RC1 pairs ERP 0.27.7-alpha, companion API 0.3.0-rc1 (PHP >=8.0), and Android 0.3.0-rc1-acceptance. Development certificate matches previous checkpoints. This is a reviewable candidate, not a claim of final completion or store readiness.
