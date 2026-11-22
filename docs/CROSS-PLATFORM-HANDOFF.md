# Cross-platform update scope

User authorized updating desktop FIRST, then Android to the already released web checkpoint ERP 0.28.0-alpha1, and requested periodic progress. Desktop completed and published in ec20504 (full offline Windows ZIP + small update ZIP). Root AGENTS.md and PLATFORM-POLICY.md implement the user's future instruction: announce platform impact and obtain a new instruction before future desktop/app changes.

Desktop uses identical ERP source and original verified Windows binaries, retains offline MU-plugin and state separation, adds an updater under the existing launcher mutex. Executed Linux-side: 13 isolated offline HTTP checks, draft/persistence after re-init, PowerShell parse of both scripts, plugin-source ZIP parity, original seed SHA256. No Windows/physical peripheral execution claimed.

Android 0.5.0-business-rc1 / code6 adds queued draft-only scans, exact quantity merging, stock hints, top add action, mixed issuance settlement, four PDF layouts. Companion 0.4.0-rc1 requires core 0.28.0-alpha1 and advertises feature flags; old APIs cannot silently return a standard PDF for a requested custom layout. The shared core views render custom mobile PDFs; financial posting uses existing InvoiceService/TreasuryService only. Core/desktop source has not changed after desktop publication.

Verification: 22 new HTTP checks, 14 existing financial and 23 existing security checks; 21 JVM unit/native-render/interaction tests. R8 acceptance build on frozen source passed; lint has zero errors. Signature and HTTPS/backup flags verified. No latest APK device acceptance claimed. Private build key, Docker .env and test state stay out of Git/ZIP. Use package-business-release.py only after build and evidence updates. App key matches prior dev certificate, no production store signing.

Remaining employer business phase is tracked in BUSINESS-PHASE.md; do not claim it completed by these ports. Preserve desktop independence: Android connects to online web only, never local desktop. Actual Windows updater, Android camera/HID, printer and destination-server acceptance remain necessary.
