# Android online changes

## 0.4.0-ui-rc1 — presentation redesign

- Unified Material 3 visual system, complete light/dark palettes, Persian typography and persisted appearance selection.
- Branded login, company/year selection, adaptive workspace navigation, dashboard hierarchy, metric/module cards and responsive monthly trend.
- Bounded tablet forms, wrapping actions, stacked narrow-screen financial fields, empty/error/loading states and document-status presentation.
- Accessible full-row selection, descriptive headings/states, suitable keyboards, inline validation using existing ExactValues, focus-preserving form layout and confirmation before leaving unsubmitted forms.
- JVM Compose raster/layout regression tests and semantic contrast checks. See UI-VERIFICATION.md for actual executed results and limits.
- API, financial calculations, permissions, backend schema and desktop code unchanged. Version code 5 preserves upgrade identity.

## 0.3.0-rc1 — acceptance candidate

- Arbitrary dashboard period within the chosen fiscal year, core-backed period cards, current inventory value explicitly labelled across periods, six-month trend and management alerts.
- Thirteen shared-core financial ratios, explanations, formulas and explicit reasons for undefined values; no independent mobile financial formulas.
- Current invoice outstanding values, complete three-statement PDF/CSV, correct party closing-balance export/display and clearer report selection.
- URL captures remain authoritative over query parameters; CSV formula protection includes whitespace/BOM prefixes.
- Resume revalidation updates permissions; removed company access clears cached company views. Form submission reflects refreshed write permissions.
- HTTPS validation also applies to restored server configuration. Non-debuggable minified acceptance variant and explicit production-signing configuration.
- Explicit cloud backup/device-transfer exclusions, adaptive Persian icon, scrollable dialogs and visible diagnostic request IDs.
- Added actual web-form/API interoperability, real TOTP/recovery replay and security-change invalidation checks.

- Validate complete committed receipts before clearing uncertain-operation state; fiscal-year switches clear obsolete report ranges.
- Support existing web expense images through a scoped read endpoint and optional expense payment through existing core posting services.
- Correct companion PHP minimum to 8.0, matching installed dependencies.

Shared ERP 0.27.7-alpha adds only a read-only period dashboard service on top of the paired 0.27.6 fixes. Existing financial posting calculations and schemas remain unchanged.

## 0.2.0-test60

- Real Android financial round trips, allocations, PDF documents and private attachments.
- Precise values, Persian timezone/leap-date checks, scoped lookups, server searches and stock/party histories.
- Fixed stale read sockets, uncertain write handling and private-version ETags.
- Fixed dashboard ledger balances counting previous fiscal years again.
- Published paired APK, companion API and online ERP packages at commit `5e8d2f9`.

## 0.1.1-test30

- Hotfix: confirmed commits cannot return to an editable write form after a failed follow-up read.
- Durable cancellation/restore receipt tombstones and owner/server receipt isolation.

## 0.1.0-test30

- Initial native online test checkpoint and separate companion API. Not a completed release.
