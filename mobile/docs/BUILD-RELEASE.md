# Build and signing

## Variants

| Variant | Debuggable | HTTP | Signing | Purpose |
|---|---|---|---|---|
| debug | yes | only loopback/emulator hosts in client validation | local development certificate | isolated HTTP integration/UI tests |
| acceptance | no; R8 enabled | HTTPS required | same local development certificate, explicitly | installable acceptance candidate; preserves test-build upgrade identity |
| release | no; R8 enabled | HTTPS required | production configuration if supplied; otherwise unsigned | store/distribution build after owner signing setup |

The acceptance certificate is not represented as a configured production/store signing identity. No private key is published. Android backup and device-transfer extraction are excluded, screen capture is disabled, and exported files use a private FileProvider with temporary grants.

## Build

Use the repository wrapper, JDK 17 and Android SDK 35. The Gradle 8.11.1 distribution checksum is pinned after comparison with the official distribution checksum.

```sh
./gradlew :app:testDebugUnitTest :app:lintDebug :app:assembleAcceptance
```

Acceptance APK: `app/build/outputs/apk/acceptance/app-acceptance.apk`. A standard debug build is not a substitute for testing the minified acceptance build.

For an owner-controlled production build, inject all four variables together through a secure local/CI secret store:

- `AGAH_RELEASE_KEYSTORE`: path to the owner's keystore outside the repository.
- `AGAH_RELEASE_STORE_PASSWORD`
- `AGAH_RELEASE_KEY_ALIAS`
- `AGAH_RELEASE_KEY_PASSWORD`

```sh
./gradlew :app:assembleRelease :app:bundleRelease
```

When these variables are absent, a release artifact is unsigned; do not rename it as an installable production APK or claim it is store-ready. Back up the owner's production key securely and retain it for updates. Store requirements, target-SDK deadlines, privacy declarations and screenshots must be checked at the actual publication date.

## Reproducing local tests

Provision the disposable Docker stack from `mobile-backend/tests/compose.yml`; its `.env` is local and excluded. Do not point tests at a production host. Fixture PHP scripts also require `WP_ENVIRONMENT_TYPE=local`.

The native integration runner consumes local fixture IDs and invokes the real app transport/forms. Its tool-directory argument is specific to the prepared test environment:

```sh
python3 mobile-backend/tests/run-android-local.py /path/to/agah-mobile-tools
```

The emulator and adb server must be ready first. Run source/backend tests sequentially when they change periods or restore data: restore tests revoke sessions and must not run concurrently with Android journeys.

For the minified variant, clear only the disposable emulator app data, then run the dedicated boundary test:

```sh
./gradlew -PagahTestBuildType=acceptance -Pandroid.testInstrumentationRunnerArguments.class=ir.idpa.agah.mobile.ReleaseBoundaryTest :app:connectedAcceptanceAndroidTest
```

This checks non-debuggability, backup exclusion, secure window and rejection of cleartext before credentials are sent. It does not claim a live deployment or validate an unconfigured production signing key.

The same boundary test can run against the exact standalone acceptance APK with the matching development-signed debug test APK. Its checks inspect the target's runtime flags, rather than a build-time test constant:

```sh
adb install -r app/build/outputs/apk/acceptance/app-acceptance.apk
adb install -r -t app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk
adb shell am instrument -w -e class ir.idpa.agah.mobile.ReleaseBoundaryTest ir.idpa.agah.mobile.test/androidx.test.runner.AndroidJUnitRunner
```

Server runtime: PHP >= 8.0; disposable integration runtime PHP 8.3. Production signing and advisory clearance are not provided by a successful build.
