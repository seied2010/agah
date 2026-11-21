# Remaining verification after RC1

Published candidate source/APK/API/core: commit d04d119, branch feature/android-online. Later documentation commits refine evidence; APK is unchanged.

Latest device run was stopped after the fresh API30 software emulator installed both APKs but produced no completed test results; shared-storage transport failed and ADB screenshot timed out. Do not claim a latest-source instrumentation pass. Earlier 12-test results belong to earlier source.

Next steps on a working emulator/device:
1. Run mobile-backend/tests/run-android-local.py with the isolated Docker fixture backend. Keep restore tests separate because they revoke sessions. Expected functional classes are OnlineJourney, FinancialRoundTrip, PostCommitFailure, PersianDates and ReceiptIsolation; exclude ReleaseBoundary.
2. Run OnlineJourney at 320dp with font scale 1.5 and at 800x600dp in dark mode; inspect screenshots.
3. Install the exact non-debuggable acceptance APK and the debug instrumentation APK (same development certificate). Run ReleaseBoundaryTest directly via am instrument. Check that the test ran, not skipped.
4. Verify camera/file sharing on a physical device and TLS/roles on destination server. Production signing, dependency-advisory clearance and commercial PDF-library license review remain outstanding.

Do not expand features or modify desktop code. Financial writes continue to use existing core posting services. Current verified evidence: 155 backend checks, 9 unit tests, lint zero errors/14 warnings, acceptance build and signature/manifest, Persian PDF visual inspection. See ACCEPTANCE-RC1.md.
