# Mobile API v1

Base: `https://<WordPress-root>/wp-json/agah-mobile/v1`.

All endpoints require TLS except explicitly local test environments. All protected endpoints require `Authorization: Bearer <access_token>`. Financial writes additionally require an unpredictable `Idempotency-Key` (20–100 ASCII letters, digits or hyphens). Edits and transitions require the exact `If-Match` returned by the detail endpoint. Never put credentials in URLs.

Success: `{data: ..., api_version: "1", request_id: "<16 hex>"}`. Application rejection: `{error:{message,fields},request_id}` with an appropriate HTTP status. Authentication permission rejection uses WordPress `{code:"agah_mobile_auth",message,data:{status}}`. An unknown response, proxy error or missing committed receipt is not proof that a write failed.

All money fields and quantities are strings. Money scale is 100 per rial (1,000 per toman); quantity scale is 10,000. IDs and other integers are also serialized as strings. Input flags are JSON booleans. Financial JSON numbers, scientific notation, mixed numeric strings and values outside the core limit are rejected. Dates on the wire are ISO `YYYY-MM-DD`; the client displays/selects Persian dates.

| Endpoint | Methods | Contract |
|---|---|---|
| `/auth/login` | POST | username, raw password, optional factor, installation device_id, device_name |
| `/auth/refresh` | POST | refresh_token; one-use rotation |
| `/auth/logout` | POST | revoke current mobile session |
| `/auth/devices` | GET | current user's unrevoked device sessions |
| `/auth/devices/{id}` | DELETE | revoke current user's session; no other user's devices |
| `/companies` | GET | active memberships with mobile access |
| `/companies/{c}/context` | GET | safe company fields, fiscal years, effective mode, capabilities and scales |
| `/companies/{c}/dashboard` | GET | year_id required; optional as_of inside year; core summary, six-calendar-month trend and notifications |
| `/companies/{c}/{entity}` | GET, POST | paged list / core-backed creation |
| `/companies/{c}/{entity}/{id}` | GET, PUT | authorized detail plus ETag / permitted versioned edit |
| `/companies/{c}/{entity}/{id}/{action}` | POST | allowed issue/approve/finalize/reopen/void transition |
| `/companies/{c}/operations/{key}` | GET, DELETE | committed/cancelled/restored/not_found receipt / cancellation tombstone |
| `/companies/{c}/lookups/{source}` | GET | paged authorized lookup, q/page |
| `/companies/{c}/lookups/{source}/{id}` | GET | scoped safe selection label; no private identifier fields |
| `/companies/{c}/barcode` | GET | exact barcode match via code |
| `/companies/{c}/reports/{report}` | GET | fiscal/date-filtered server report |
| `/companies/{c}/reports/{report}/export` | GET | format=pdf/csv; selected report page |
| `/companies/{c}/{entity}/{id}/export` | GET | PDF for invoices/vouchers/treasury/expenses; view and resource export permissions |
| `/companies/{c}/attachments/{target}/{id}` | GET, POST | private file metadata / name and content_base64 upload |
| `/companies/{c}/files/{id}` | GET | authorized bytes, MIME, name and SHA-256 |

Entities: parties, items, invoices, treasury, expenses, vouchers and stock. Financial entities require `year_id`; submitted document dates must belong to it. Direct edits of posted treasury, expense and stock documents are disallowed; use the permitted core reversal/void workflow. Invoice kind determines its capability. Stock inventory is explicitly current across periods, not a fabricated historical balance.

Read filters include page, q, from, to, status, party_id, account_id, detail_id, ledger mode and voucher number range where supported by the underlying service. Fiscal bounds clamp report dates. Sales/purchase/expense financial reports include issued records. Treasury reports include issued records in the date range. Lookups never grant write authority.

Report names: ledger, parties, statements, sales, purchases, receipts, payments, expenses, inventory, party_statement and kardex. Kardex requires warehouse_id and item_id and currently reflects the shared core's all-period movement history.

The strict request field whitelist is `mobile-backend/agah-mobile-api/src/Payload.php`. The source of financial validation is the existing ERP service, not the mobile client. The machine-readable route and DTO contract is [openapi.json](openapi.json), regenerated with `python3 mobile-backend/tests/generate-openapi.py`. Core business-required fields and allowed transitions remain authoritative service validations.
