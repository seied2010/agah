# Online audit and integration boundary

## Existing and reusable
WordPress/PHP ERP 0.27.5-alpha, schema 21. Shared services implement parties, catalog, sales/purchases, treasury, expenses, inventory, vouchers and reports. `Access::write` locks the company and rechecks current membership/capabilities inside a transaction. `Db::tx` supports nested savepoints. Financial state changes must go through these services.

Authentication already provides WordPress password verification, rate limiting, TOTP/recovery verification, idle expiry and company-specific MFA checks. `Ctx::boot` checks company membership independently of WordPress administrator privileges. Party public DTOs strip encrypted national identifiers.

## Existing but unsuitable without adaptation
The `agah-connect/v1` API authenticates stores by shared HMAC, not individual accounting users. Existing HTML form idempotency stores redirect URLs, not mobile operation responses. Fiscal selection currently uses a user preference shared with web; mobile must carry an explicit validated year without silently changing the web preference. Some services return database-shaped objects; mobile output needs deliberate DTO filtering.

## New components required
User-scoped mobile sessions, rotation/revocation, mobile access policies, versioned API contract, persisted operation receipts, conflict checks and native UI. Authenticated outputs/attachments require the same object-level authorization as their source document.

## Preservation
No desktop inspection or integration. No parallel accounting engine. No financial schema migration planned. New integration metadata uses a separate namespace. Live production is not used as a test database.
