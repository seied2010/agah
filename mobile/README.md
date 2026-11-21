# Agah native online Android

Kotlin/Compose client for the existing online ERP. Accounting rules, transactions, permissions and data remain on WordPress. There is no WebView, offline financial database or delayed write queue.

- App: `mobile/app`
- Companion API: `mobile-backend/agah-mobile-api`
- Shared online ERP: `source/agah-erp`
- API: `docs/API-CONTRACT.md` and `docs/openapi.json`
- Persian operation guide: `docs/OPERATIONS-FA.md`
- Build/signing: `docs/BUILD-RELEASE.md`

Use JDK 17, Android SDK 35/build-tools 35.0.0, and the pinned Gradle wrapper. Configure the SDK in an uncommitted `local.properties`. A first build needs dependency downloads. Runtime requires a working HTTPS accounting server.

For a fresh development checkout, create a local test key (not a production identity):

```sh
mkdir -p .local
keytool -genkeypair -keystore .local/debug.keystore -storepass android -alias androiddebugkey -keypass android -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Android Debug,O=Android,C=US"
./gradlew :app:testDebugUnitTest :app:assembleDebug
```

A newly generated key cannot update an APK signed with a different key. Never commit keystores, local settings, tokens, actual passwords or deployment environment files. See the delivery guide before replacing an installation containing an unresolved operation receipt.

Local HTTP/Android test fixtures are in `mobile-backend/tests`; their credentials are exclusively for the disposable local WordPress database. They are not included in the installable companion plugin.
