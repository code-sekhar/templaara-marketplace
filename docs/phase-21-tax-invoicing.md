# Phase 21 — Tax foundation and invoicing

Phase 21 code verification passed. Legal/regulatory review is required; production launch remains blocked by external requirements.

## Initial domain audit

Order freezes customer/company/email/phone/address/city/state/postal/country, but no tax ID/state code. OrderItem freezes product/package/version/license/seller, currency and exact unit/line price. Phase 7 enforces quantity one, zero discounts/tax and total=subtotal. Prices have no tax provenance: legacy amounts must not be retrospectively classified inclusive/exclusive. Settlement assigns an INV ULID reference, not a professional invoice aggregate. This legacy reference remains unchanged.

A checkout contains multiple official/author products and multiple authors, but one Order and settled Payment. Seller type/author identity and commission terms are immutable purchased snapshots. Phase 13 refunds selected complete purchased items, creates exact compensations and revokes access; requested/approved/processing states do not mean refund settlement. Four controlled two-decimal currencies are supported with no FX aggregation. Phase 18 SettingsRegistry is typed/allow-listed and uses advisory settings locking. Phase 16 purchase-ready/refund notifications already link to private order/refund views, avoiding redundant invoice email.

## Design decisions

Platform-issued customer invoice per Order, with line-level purchased seller snapshots. Configurable issuer mode currently permits platform only; author/intermediary modes cannot be enabled without legal model and complete seller tax identity support. Invoicing defaults disabled, tax mode NONE with no implied rate. Configure real issuer identity and reviewed policy before enabling. Legal/accounting review is mandatory.

Future checkout snapshots controlled issuer/tax policy and customer tax identifiers before reserving payment. Existing unit/line price and commission/earnings/revenue basis remain exactly the purchased Offer amount. Separate tax evidence records taxable base, components and customer gross charge. Exclusive tax adds to Order/Payment/provider grand total; inclusive tax partitions existing charge. Existing financial ledgers are never regenerated. Completed exclusive-tax refunds reimburse gross purchased charge while original revenue compensation remains purchased price; credit notes additionally reverse original tax. Tax reporting is separate from Phase 17 revenue.

Legacy Orders retain exact paid amounts and UNKNOWN historical tax provenance. Their invoices may use reviewed issue-time platform identity, clearly recorded as issue-time identity; no GST/VAT is invented. Prefix/current product/customer/issuer/rate changes cannot alter issued snapshots.

## Schema and integrity

Forward migration `2026_10_07_150000_create_tax_invoices.php` adds nullable purchase-time tax/billing columns without backfill. New tables:

| Table             | Purpose                                                                                                                                                                                                                                                                                                        |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| invoice_sequences | Locked shared annual document counter; composite kind/year key.                                                                                                                                                                                                                                                |
| invoices          | UUID, immutable unique number (max 16 characters), kind/status, restrictive Order/original Invoice/Refund references, currency, commercial subtotal, taxable base, tax, gross, issuer/customer/policy snapshots, original Order number, safe provider display, provenance and timezone-explicit issue instant. |
| invoice_items     | Exact purchased product/package/version/license/seller/quantity/price, classification/rate/jurisdiction, exact tax components and restrictive purchased/original/refunded item links.                                                                                                                          |
| invoice_events    | One durable issue event per document with an absolute timestamp.                                                                                                                                                                                                                                               |
| invoice_files     | Private disk/object identity, SHA-256, byte count and absolute generation timestamp; never serialized.                                                                                                                                                                                                         |

PostgreSQL guards require an exact paid Order/settled Payment, purchased line evidence, correct customer/policy/issuer identity, nonnegative controlled-currency amounts, reconciled tax components, aggregate totals and an accompanying issue event. Deferred checks operate on both header and line insertions. UPDATE/DELETE of issued documents, lines, audit and stored file metadata is refused. Unique indexes prevent duplicate Order invoices and Refund credit notes. Restrictive FKs retain commercial history; documents have no mutable customer-edit endpoint. Eloquent guards also forbid updates/deletion. No second payment/accounting ledger exists.

The prior Order value constraint is extended only for explicit future tax snapshots; NULL legacy snapshots retain the prior zero-tax invariant. The two existing refund binding functions are introspected and only one exact, verified predicate each is extended for future gross customer refunds. Migration refuses unexpected function definitions. Other integrity triggers remain active. Down migration deliberately refuses document destruction; deploy/rollback with reviewed forward migrations.

## Identity, numbering and lifecycle

The platform issues one invoice per paid Order, including multi-author carts. Each line retains the purchased seller name/origin; this does not legally establish marketplace principal/agent treatment. `issuer_mode=platform` is the only supported mode. Author-issued/split invoices require a future reviewed seller identity/legal model.

Billing name/company/email/phone/address/country/tax ID/GSTIN/place-of-supply state are purchase snapshots, independent of account profile. Later profile edits cannot rewrite invoices. New billing fields are private and explicitly validated; account credentials and full license keys are never included.

Number allocation locks a shared UTC-calendar-year counter inside the issuance transaction. Both kinds share the counter so changing/reusing prefixes cannot create cross-kind collisions. Display format is `PREFIX-YY-NNNNNN`; uppercase controlled prefixes are at most six characters. Historical assigned numbers never change. Gaps are acceptable; this is not MAX+1. Operational capacity is 999,998 documents per calendar year; an exhausted counter refuses issuance for review. Fiscal-year/statutory-series treatment requires legal approval. Existing `Order.invoice_number` ULID settlement reference remains unchanged and is not used as the professional document number.

Only `issued` invoices/credit notes exist. No edit, delete, void/reissue or government registration endpoint exists. Eligible invoices require exact completed/paid Order, recorded settled paid Payment, matching amount/currency and coherent purchased lines. Unpaid/cancelled orders cannot issue. Payment events, recovery and duplicate clicks converge on one document.

## Settings and exact tax policy

Phase 18 controls the `invoicing` group using existing `settings.view/settings.manage`, typed allow-list validation, audit/version checks and advisory coherence locks. Invoicing defaults OFF, tax mode NONE, rate unset, policy review false. Settings contain legal/trading name, issuer address/country/contact/tax registration, GSTIN/state, service code, invoice/credit prefixes, footer, inclusive flag, explicit rate/mode, reviewed-policy acknowledgment and explicit international countries/type. No provider/SMTP/storage secret belongs in settings. Nullable rates are persisted as JSON null, preserving PostgreSQL JSONB NOT NULL semantics.

Enabled issuance requires complete issuer identity. GST/international collection requires explicit reviewed policy and rate; there is no automatic 18% assumption. Incomplete configurations fail closed with useful validation. The acknowledgment is an operator control, not proof of legal review. `invoices:reconcile` can issue old paid documents only when issuance is configured; no automatic historical backfill runs on migration.

Brick Math exact decimal strings are used throughout. Supported INR/USD/EUR/GBP are two-decimal currencies; no FX conversion or combined monetary sum exists. Unsupported precision, negatives, unsupported classification and overflow are rejected. Each purchased line rounds HALF_UP to two decimal places; document totals sum these rounded lines, never round an aggregate alternative.

For exclusive tax, base = purchased price; tax = rounded(base × rate/100); customer total = base + tax. For inclusive tax, base = rounded(price/(1+rate/100)); tax = price − base; customer total = purchased price. The original Order subtotal is the commercial purchased subtotal; for inclusive pricing `tax_total` is informational embedded tax, not an additional charge. The invoice explicitly records commercial subtotal, taxable amount, tax and paid total separately.

Indian GST-ready policy requires a coherent structurally valid issuer GSTIN/state. Declared customer place of supply must be a controlled 01–38 state code. Same state creates CGST/SGST; different state creates IGST. CGST is rounded half the line tax and SGST receives the exact remainder, so components always sum exactly; rate components retain four decimal places where needed. Odd-penny allocation is deliberate and needs regulatory/accounting approval. GSTIN validation is structural, not government verification/checksum/registration validation. Declared place of supply is not automated legal nexus determination. HSN/SAC is a configurable 4–8-digit service code, not an inferred classification.

International policy applies only to explicitly listed countries with a reviewed rate/type (VAT/SALES_TAX/GST/OTHER). Other countries receive NONE with `outside_configured_policy` evidence; currency alone never selects tax. NONE is not a legal exemption determination. Production check warns when enabled foreign currencies lack a reviewed international country policy. No reverse charge, exemptions, threshold/nexus, automatic tax authority lookup or global compliance engine is implemented.

## Future checkout and financial boundaries

`POST /checkout/quote` reads authoritative cart prices under the existing settings coherence lock. It returns only exact totals/components and an opaque confirmation hash; issuer configuration is not exposed. Browser-entered totals/rates/issuer/policy/invoice identity are prohibited. The hash binds authoritative prices/policy versions, cart checkout identity, country, place of supply and GSTIN. The editor debounces quoting and ignores stale responses. Changing billing/cart/tax policy invalidates confirmation; checkout requires a refreshed quote before reserving an enabled invoice purchase.

Checkout snapshots policy/issuer and line calculations in the same commercial transaction BEFORE Payment creation. Order grand total = Payment amount = provider expected minor units. Stripe/Razorpay external creation remains outside the commercial transaction under Phase 19 orchestration. No callback, invoice or recovery can mark a payment paid. Existing retries reuse immutable purchased totals even if settings/currencies later change.

`OrderItem.unit_price`, `line_total`, commissionable amount, commission and author amounts remain the Phase 11 purchased-price basis. Exclusive tax belongs in the separate tax snapshot and customer gross charge, not a rewritten seller earning. Phase 17 gross/retained metrics remain their original purchased-price definition. This is an engineering compatibility decision pending marketplace legal/accounting approval, not a determination that the marketplace owes the collected tax or is merchant of record.

Legacy Orders keep NULL tax provenance, exact historical paid/earning/refund values and access. Optional legacy invoices explicitly say tax was not historically determined, zero tax allocated; they never label historical pricing inclusive/exclusive based on current settings. Current issue-time issuer identity is recorded with `legacy_undetermined` provenance. No old Payment/Order timestamp is rewritten.

## Refunds and credit notes

Only completed Phase 13 refunds create credit notes. Requested/approved/processing/failed/cancelled do not reverse invoice tax. Current whole-selected-item semantics remain: a future taxed item is refunded its original gross customer charge; credit lines copy original taxable base/rate/classification/components/service code exactly. Later policy changes cannot change credits. Original invoice number/PDF remain unchanged; credit references its original invoice and Refund. Multiple distinct selected-item refunds can each have a separate credit; unique Refund/refund-item links prevent duplicates. Partial proportional-amount refunds are deferred.

Original `RefundCompensation.gross_amount` remains the original purchased commercial line value, so existing commission/author reversals and revenue-cohort adjustments remain exact. `Refund.amount`/`RefundItem.amount` represent customer gross reimbursement for future taxed purchases. Tax reversal is represented by credit-note tax, independent of the compensation ledger. Existing entitlement/license revocation and payout offset behavior are unchanged. Refunds still use manual external-settlement confirmation; no automatic provider refund is added.

## PDF and private UI

Dompdf renders an escaped Blade A4 template with DejaVu Sans, platform branding, document/order/provider identity, purchased seller/package/version/license, issuer/customer billing/tax identity, price/base/components/gross, contact/footer, and original invoice/refund reference for credits. PHP/JavaScript execution and remote resource fetching are disabled; allowed filesystem roots are restricted. Dates are explicitly UTC. Creation metadata and PDF identifier are fixed to document identity/time. Financial rendering uses only immutable snapshots.

First download renders and stores private PDF bytes and SHA-256/length metadata while locking only the document row. Subsequent downloads return those retained bytes and verify checksum; corruption fails closed. Missing bytes are not silently replaced by a different renderer/template. Private disk defaults to the existing commercial artifact disk, optionally overridden by `INVOICE_PRIVATE_DISK`; production uses a correctly private S3-compatible disk. PDFs are returned by authenticated application download, not public links. No disk/path/checksum object metadata is serialized. Rendering does not hold marketplace financial/provider locks.

| Route                              | Access                                                   |
| ---------------------------------- | -------------------------------------------------------- |
| GET /account/invoices              | Authenticated purchaser; only own document summaries.    |
| GET /account/invoices/{uuid}       | Purchaser ownership policy.                              |
| GET /account/invoices/{uuid}/pdf   | Same ownership, private/no-store, bounded download rate. |
| GET /admin/invoices                | Verified user with orders.view.                          |
| GET /admin/invoices/{uuid}         | orders.view inspection policy.                           |
| GET /admin/invoices/{uuid}/pdf     | Same inspection, private/no-store and rate limit.        |
| POST /checkout/quote               | Authenticated, CSRF-protected, separately bounded rate.  |
| GET /admin/reports/tax and /export | reports.view, existing filters/export protections.       |

Vue Index/Show pages reuse Inertia/Wayfinder/TypeScript and existing layout/components. Navigation provides My Invoices and authorized admin inspection; Order detail links actual documents and PDFs. Lists paginate with deterministic issue-time/id ordering, search, controlled currency/kind/tax classification and validated UTC date ranges, retaining query parameters. Credits are visible to the original purchaser. Encrypted Inertia history protects detail billing views. Other customers, guests and unrelated authors cannot inspect/download documents. Authors see only preexisting earnings/purchased seller projections; tax settings and customer tax identity are not exposed through author analytics. reports.view alone never grants private billing access. CSRF, CSP/nonces, proxy/error handling, webhook exemptions, private artifacts and prior Phase 20 surface limits are preserved.

## Audit, notifications and recovery

Immutable `invoice_events` are the durable InvoiceIssued/CreditNoteIssued foundation. Existing OrderPaid/RefundCompleted events invoke discovered `IssuePurchaseInvoice` listeners. Issuance failures log only safe recovery codes/order references, never billing identifiers/provider data, and do not replay payment/provisioning. No duplicate notification/email is added: existing purchase/refund notifications link the private order/refund experience containing issued documents.

`php artisan invoices:reconcile --limit=100` processes bounded batches (1–500), oldest missing paid documents then missing completed-refund credits. Repeated runs are idempotent; errors are isolated and counts contain no secrets. Disabled invoicing does not strand already-enabled purchase snapshots behind older disabled purchases. There are no provider calls/payment state mutations. PDF generation is lazy and separate from document recovery.

Scheduler runs every ten minutes with withoutOverlapping; Phase 20 optional onOneServer leadership applies. Existing payment, entitlement, earning and notification recovery schedules remain unchanged. Shared cache affects scheduler leadership only; financial uniqueness/locks are PostgreSQL-backed. Monitor nonzero errors, missing eligible documents, exhausted numbering and corrupt/missing PDFs.

## Tax analytics, privacy and performance

The ninth Phase 17 report is deliberately separate. It groups immutable document lines by currency, tax type, jurisdiction and rate, over document ISSUE dates in the existing configured reporting timezone. It shows invoiced taxable base, tax invoiced, tax credited and net tax (invoiced − credited). Credits are activity in their issue period, not a rewrite of original invoice/sale dates. Existing product/author/category/origin filters are reused. No billing address/email/GSTIN, provider raw data, storage identity, license secret, author payout destination or private support content is projected/exported.

CSV inherits explicit columns, reports.view, server filters, deterministic ordered chunks, UTF-8, formula-injection protection and PostgreSQL read-only repeatable-read snapshot. Memory remains bounded. Tax aggregates run in SQL, not whole-table PHP loops. Listing projections use summary fields only and bounded pagination. Existing indexes plus new currency/issue-time, issue-time/id and classification/invoice indexes support the new document query paths; no speculative changes to existing commercial indexes/search engines are made. Query-plan findings and counts are recorded in final verification below.

## Operations, configuration and limits

Deploy the forward migration after backup/restore checks; `php artisan migrate --no-interaction` does not issue/reinterpret historical invoices. Configure issuer through the authorized settings UI, choose reviewed policies/identity/country/state/classification/rounding/series, then enable invoicing. Set `INVOICE_PRIVATE_DISK=product_s3_private` (or a secured equivalent) in deployment configuration; real .env was not modified. Verify object IAM/private visibility/encryption/versioning/retention and restore PDF metadata AND corresponding object versions. Provision private framework render/font cache with appropriate process ownership (not public web storage).

Run `marketplace:production-check` for read-only invoice configuration/private-storage guards and international-policy warning. It never issues invoices, uploads bytes or calls providers. Supervise existing scheduler/queues, monitor `invoices:reconcile` and storage corruption. Legal review must approve platform principal/agent treatment, supplier responsibility, rates, place of supply, GST registration, statutory fields, numbering/fiscal periods, document/PII retention, credits and customer-facing wording BEFORE collection/launch. A checkbox is not legal sign-off.

Deferred: tax authority validation, GST e-invoicing/IRN/QR/e-way systems, filing/returns, automated global tax obligations, reverse charge/exemptions/thresholds, author-issued invoices/stores, split merchant settlement, arbitrary partial refunds, subscriptions, FX, chargebacks, automatic provider refunds and accounting integrations (Tally/Zoho/QuickBooks/Xero). No Phase 22 is started. Real payment sandbox, deployment S3/mail/TLS/monitoring/backup/restore and legal readiness remain external launch blockers from Phase 20.

## Verification commands

`php artisan test --compact tests/Feature/PhaseTwentyOneTest.php`; `php tests/Support/verify-phase21-suite.php --payments-only`; `php tests/Support/verify-phase21-suite.php` (21–10 + Commerce + Entitlements); `php tests/Support/verify-phase21-concurrency.php`; `php artisan test --compact`; guarded HTTPS/browser/PDF harness; `vendor/bin/pint`; `vendor/bin/pint --test`; `npm run check`; `npm run types:check`; `npm run build`; `composer audit`; `npm audit`; `vendor/bin/phpstan analyse --no-progress`; isolated production config/route/view caches; migration/status/routes/events/schedule and controlled production-check.

Every verification migration/fixture runs in a guarded disposable schema, with exact current_schema/search_path assertions. Existing application public schema is used only for the explicitly requested additive migration and read-only counts/safety audit. Disposable schemas, private browser credentials/TLS/storage/cache/test outputs containing identity are cleaned up; actual .env digest and original commercial counts must match. User development servers are preserved.

The PostgreSQL real-commit race found that Eloquent's default offset-free date format interpreted new invoice times in the local database session offset. Invoice's NEW issue timestamp now uses an explicit offset; audit/PDF timestamps are timezone-explicit, and four session timezones exercise exact instant equality plus deferred checks. Historical commerce timezone behavior is untouched. Real-commit checks also cover the generic deferred-trigger record shape and prevent rollback-only tests from masking commit errors.

Final verification and completion report are recorded below only after authoritative runs finish.

## Exact file inventory

Created (30):

- `app/Http/Controllers/Invoicing/InvoiceController.php`
- `app/Listeners/IssuePurchaseInvoice.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceItem.php`
- `app/Policies/InvoicePolicy.php`
- `app/Services/Invoicing/CheckoutTax.php`
- `app/Services/Invoicing/InvoiceNumber.php`
- `app/Services/Invoicing/InvoicePdf.php`
- `app/Services/Invoicing/InvoicePresentation.php`
- `app/Services/Invoicing/InvoiceRecovery.php`
- `app/Services/Invoicing/IssueInvoice.php`
- `app/Services/Invoicing/TaxCalculator.php`
- `app/Services/Invoicing/TaxIdentity.php`
- `app/Services/Invoicing/TaxPolicy.php`
- `config/invoicing.php`
- `database/migrations/2026_10_07_150000_create_tax_invoices.php`
- `docs/phase-21-tax-invoicing.md`
- `resources/js/actions/App/Http/Controllers/Invoicing/InvoiceController.ts`
- `resources/js/actions/App/Http/Controllers/Invoicing/index.ts`
- `resources/js/lib/invoicing.ts`
- `resources/js/pages/invoices/Index.vue`
- `resources/js/pages/invoices/Show.vue`
- `resources/js/routes/account/invoices/index.ts`
- `resources/js/routes/admin/invoices/index.ts`
- `resources/views/invoicing/document.blade.php`
- `routes/invoicing.php`
- `tests/Feature/PhaseTwentyOneTest.php`
- `tests/Support/InvoiceFixtures.php`
- `tests/Support/verify-phase21-concurrency.php`
- `tests/Support/verify-phase21-suite.php`

Modified (27):

- `app/Http/Controllers/Commerce/CheckoutController.php`
- `app/Http/Requests/Commerce/CheckoutRequest.php`
- `app/Models/Order.php`
- `app/Models/OrderItem.php`
- `app/Services/Analytics/AnalyticsReports.php`
- `app/Services/Commerce/Checkout.php`
- `app/Services/Commerce/OrderPresentation.php`
- `app/Services/Operations/ProductionReadiness.php`
- `app/Services/Refunds/RefundWorkflow.php`
- `app/Services/Settings/SettingsRegistry.php`
- `app/Services/Settings/UpdateMarketplaceSettings.php`
- `composer.json`
- `composer.lock`
- `resources/js/actions/App/Http/Controllers/Commerce/CheckoutController.ts`
- `resources/js/actions/App/Http/Controllers/index.ts`
- `resources/js/components/AppSidebar.vue`
- `resources/js/pages/analytics/Report.vue`
- `resources/js/pages/commerce/Checkout.vue`
- `resources/js/pages/marketplace-settings/Index.vue`
- `resources/js/pages/orders/Show.vue`
- `resources/js/routes/account/index.ts`
- `resources/js/routes/admin/index.ts`
- `resources/js/routes/commerce/checkout/index.ts`
- `resources/js/types/commerce.ts`
- `routes/commerce.php`
- `routes/console.php`
- `routes/web.php`

## Final verification evidence

| Check                                         | Final authoritative result                                                                                                                                                                                                                                        |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 21 SQLite                               | 132 total; 128 passed; 4 explicitly PostgreSQL-only trigger skips; 296 assertions; zero failures.                                                                                                                                                                 |
| Phase 21 isolated PostgreSQL                  | 132 total; 132 passed; zero skips; 304 assertions; zero failures.                                                                                                                                                                                                 |
| Full PostgreSQL 21–10, Commerce, Entitlements | 1,438 total; 1,437 passed; one established Entitlement fixture skip; 4,573 assertions; zero failures.                                                                                                                                                             |
| Full Laravel                                  | 1,799 total; 1,620 passed; 179 skipped (175 baseline + 4 PostgreSQL-only new checks); 6,741 assertions; zero failures.                                                                                                                                            |
| Independent-process PostgreSQL concurrency    | All seven required scenarios passed on separate connections, including settlement/recovery and the completed-refund race.                                                                                                                                         |
| Additional guarded mixed-seller verification  | Official + author lines at 0.03 each: commercial subtotal 0.06, per-line tax sum 0.02, paid total 0.08. Selected author-item credit 0.04 with original tax 0.01; original invoice remains 0.08; original author gross/commission/net remain 0.03/0.01/0.02. PASS. |
| Browser                                       | Ten fresh HTTPS/enforced-CSP flows: eight required flows, taxed Razorpay callback/invoice and author earnings billing-PII/privacy checks. Controlled fake providers only; no JavaScript/CSP errors.                                                               |
| PDF                                           | Signature/nonempty, extracted invoice/credit and Order numbers, issuer/customer snapshots and exact totals, secret-marker exclusion, private access and retained checksum/byte identity passed; original PDF unchanged after credit/profile/settings changes.     |
| Pint and Pint --test                          | PASS.                                                                                                                                                                                                                                                             |
| Frontend check, TypeScript, production build  | PASS.                                                                                                                                                                                                                                                             |
| Composer / npm audits                         | Zero advisories/abandoned packages; npm zero findings at all severities.                                                                                                                                                                                          |
| Secret scan                                   | 907 source/runbook files; zero credential candidates; real .env excluded from scan output and checked by digest.                                                                                                                                                  |
| PHPStan                                       | Only the existing UserFactory::withTwoFactor() missing-return error; no Phase 21 errors.                                                                                                                                                                          |
| Production caches                             | Config, route and view cache PASS in isolated paths; temporary caches removed.                                                                                                                                                                                    |
| Migration / inspection                        | Additive migration applied; migrate:status, route:list --except-vendor, event:list and schedule:list inspected successfully.                                                                                                                                      |
| Controlled production-check                   | Correctly refuses local configuration lacking actual external production policies/storage/UTC/operational proof; invoice defaults disabled and no writes performed. User development hot mode is retained, so its manifest check is not production-ready.         |
| Cleanup                                       | Disposable schemas/fixtures/credentials/TLS/storage/caches removed; public/public and unchanged real .env/original commercial counts confirmed.                                                                                                                   |

Query plans on the small three-document disposable fixture used the invoice issue-time index, invoice-item composite unique index and purchased/product primary keys for tax aggregates (0.125 ms). Owned-summary zero-state plan used the existing Order owner index and a tiny invoice sequential scan (0.047 ms). These are measured examples, not production load/SLA evidence. No additional speculative commercial index was introduced; larger launch-data workload planning remains an operational exercise.

The first intermediate full PostgreSQL run failed one incorrect two-item quote expectation; the first intermediate Laravel run encountered a stale already-loaded model during an in-flight edit. Those runs are not final pass evidence. New real-commit trigger/timestamp failures were fixed, not skipped. A final recovery audit found disabled legacy refunds could occupy a small batch; eligible-credit selection now excludes those blockers and a limit-one regression passes on both drivers. The final full suites above ran after that correction.

Known unrelated tooling: Composer's existing post-update Boost hook returns an error because Boost has not been set up. The dependency installation/lock/autoload completed, Dompdf execution and audits pass; no unrelated Boost setup was performed. PHP 8.5 also emits Composer-tool deprecation notices. Optional fontaine build warning remains. Historical application/PostgreSQL timezone mismatch is unchanged. Invoice/audit/file operational timestamps are new timezone-explicit instants.

Installed packages: dompdf/dompdf 3.1.6, dompdf/php-font-lib 1.0.2, dompdf/php-svg-lib 1.0.2, masterminds/html5 2.11.0, sabberworm/php-css-parser 9.5.0. No frontend package or prior application dependency was upgraded.

## Completion report

| #   | Topic                             | Result                                                                                                                                                   |
| --- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Files created                     | 30; exact paths in the inventory above.                                                                                                                  |
| 2   | Files modified                    | 27; exact paths in the inventory above. No real .env change.                                                                                             |
| 3   | Dependencies                      | Dompdf 3.1.6 and four rendering dependencies; no frontend upgrade.                                                                                       |
| 4   | Migrations                        | One additive migration; five document/counter/audit/file tables and forward-only purchase tax fields.                                                    |
| 5   | Initial domain audit              | Purchased prices/billing existed; no authoritative customer tax calculation or actual invoice document.                                                  |
| 6   | Invoice issuer model              | Platform-issued customer invoice, deliberately separate from settlement reference.                                                                       |
| 7   | Multi-seller model                | One document with immutable purchased seller identity per line.                                                                                          |
| 8   | Author invoice status             | Author-issued/split-merchant documents deferred pending legal identity and tax design.                                                                   |
| 9   | Settings                          | Controlled Phase 18 audited/versioned policy; invoicing disabled by default; no inferred rate.                                                           |
| 10  | Issuer identity                   | Reviewed legal/address/contact/tax identity frozen in purchase policy and issued document.                                                               |
| 11  | Customer billing snapshot         | Purchased name/address/contact independent of mutable account profile.                                                                                   |
| 12  | Customer tax identity             | Optional validated GSTIN/tax ID/state; private billing evidence only.                                                                                    |
| 13  | Invoice aggregate                 | Immutable invoice/credit header tied to exact Order/settled Payment or completed Refund.                                                                 |
| 14  | Invoice items                     | Purchased product/offer/seller, commercial amount, tax components and gross; original-item credit binding.                                               |
| 15  | Invoice events                    | Durable immutable issue records tied to commercial evidence.                                                                                             |
| 16  | Invoice numbering                 | Controlled prefixes and max-16-character immutable document numbers.                                                                                     |
| 17  | Number concurrency                | Shared locked UTC annual counter for invoice and credit; uniqueness and seven-process proofs.                                                            |
| 18  | Prefix changes                    | Continue the same annual sequence; do not reset counter or collide with retained numbers.                                                                |
| 19  | Invoice immutability              | Model guards and PostgreSQL UPDATE/DELETE protection, restrictive retention FKs.                                                                         |
| 20  | Invoice eligibility               | Completed paid Order and recorded settled paid Payment with exact currency/amount.                                                                       |
| 21  | Tax policy                        | Explicit configured mode/rate/jurisdiction/professional-review flag; no default 18%.                                                                     |
| 22  | Tax snapshot                      | Frozen policy/base/components/gross/reason on future purchased lines and Order.                                                                          |
| 23  | Exact money                       | Brick Math decimal strings; supported two-decimal currencies; no floating money or FX.                                                                   |
| 24  | Rounding                          | HALF_UP per line, sum rounded lines, deterministic same-state component residual.                                                                        |
| 25  | Inclusive tax                     | Partition the existing charge; no second tax addition.                                                                                                   |
| 26  | Exclusive tax                     | Add tax to customer grand total; preserve purchased commercial/revenue/earning basis.                                                                    |
| 27  | GST foundation                    | Explicit India/issuer/state configuration; GST-ready foundation, legal review required.                                                                  |
| 28  | CGST                              | Configured same-state component, rounded half of line tax.                                                                                               |
| 29  | SGST                              | Exact remaining same-state line tax after CGST.                                                                                                          |
| 30  | IGST                              | Configured different-state component.                                                                                                                    |
| 31  | GSTIN                             | Structural controlled-format/state-prefix validation; no authority verification.                                                                         |
| 32  | SAC/HSN                           | Optional reviewed digit classification; no inferred statutory classification.                                                                            |
| 33  | International tax                 | Explicit reviewed country/type/rate only; outside policy NONE with reason, not legal exemption advice.                                                   |
| 34  | Future checkout tax               | Server quote confirmation and policy coherence before commercial reservation.                                                                            |
| 35  | Order tax snapshot                | Nullable forward-only header/line evidence; no legacy backfill.                                                                                          |
| 36  | Payment/provider amount invariant | Order gross equals Payment amount and provider expected exact minor units.                                                                               |
| 37  | Stripe taxed path                 | Controlled signed provider settlement with taxed USD gross verified; no real sandbox payment.                                                            |
| 38  | Razorpay taxed path               | Controlled signed callback with taxed INR gross verified; no real sandbox payment.                                                                       |
| 39  | Legacy Orders                     | Historical paid/earning/refund truth retained; tax explicitly undetermined, not fabricated GST/VAT.                                                      |
| 40  | PDF generation                    | Escaped A4 DejaVu template; remote/PHP/JS disabled; immutable inputs.                                                                                    |
| 41  | PDF storage/security              | Private retained bytes with SHA-256/size; owner or authorized admin only, private no-store.                                                              |
| 42  | Customer UI                       | My Invoices, Order links, invoice/credit detail, private PDF, pagination/filters/empty state.                                                            |
| 43  | Admin UI                          | orders.view list/search/filter/detail/private PDF inspection.                                                                                            |
| 44  | Author privacy                    | Earnings list/detail exclude customer billing/tax identity and invoice snapshots.                                                                        |
| 45  | Refund integration                | Completed exact selected purchased items; customer refund gross includes original tax; compensation basis unchanged.                                     |
| 46  | Credit notes                      | Unique per completed Refund, original invoice/item links; original invoice remains available.                                                            |
| 47  | Tax reversal                      | Copy original tax/rate/components; never recalculate current policy.                                                                                     |
| 48  | Recovery                          | Idempotent bounded invoices:reconcile; no financial/provider mutation.                                                                                   |
| 49  | Recovery starvation fix           | Exclude disabled legacy refunds without eligible original invoice; limit-one regression passes both drivers.                                             |
| 50  | Scheduler                         | Every ten minutes, overlap lock and existing optional leadership preserved.                                                                              |
| 51  | Notifications                     | Existing durable purchase/refund links lead to documents; no redundant delivery pipeline.                                                                |
| 52  | Tax analytics                     | SQL document issue-date aggregates, grouped by currency/type/jurisdiction/rate.                                                                          |
| 53  | CSV                               | Existing authorized allow-list/filter/formula protection and bounded repeatable-read streaming.                                                          |
| 54  | Production-check                  | Read-only issuer/policy/private-storage checks and international-policy warning.                                                                         |
| 55  | PostgreSQL constraints            | Exact purchased evidence, components/totals, immutable audit, uniqueness and deferred real-commit guards.                                                |
| 56  | Timezone behavior                 | New invoice/audit/file timestamps are timezone-explicit; historical commerce timestamps untouched.                                                       |
| 57  | Tests                             | 132 Phase 21 cases plus mixed-seller/penny/selected-credit proof, independent races and browser flows.                                                   |
| 58  | Phase 21 PostgreSQL               | 132 passed, 304 assertions, zero skips/failures; offset-session regressions included.                                                                    |
| 59  | Full PostgreSQL regression        | 1,438 total: 1,437 passed, 1 established Entitlement skip, 4,573 assertions, zero failures.                                                              |
| 60  | Full Laravel regression           | 1,799 total: 1,620 passed, 179 skips, 6,741 assertions, zero failures.                                                                                   |
| 61  | Concurrency                       | All seven independent-process scenarios PASS after the recovery fix.                                                                                     |
| 62  | Browser verification              | Ten fresh controlled HTTPS/enforced-CSP flows PASS, including author privacy; fake providers only, no external hosting proof.                            |
| 63  | PDF verification                  | Extracted number/Order/issuer/customer/exact totals, authorization, privacy and retained bytes/checksum.                                                 |
| 64  | Pint/frontend/TypeScript/build    | PASS; optional baseline fontaine warning only.                                                                                                           |
| 65  | Composer/npm audit                | Zero advisories/abandoned packages; npm zero vulnerabilities.                                                                                            |
| 66  | PHPStan                           | Only known UserFactory::withTwoFactor() missing return.                                                                                                  |
| 67  | Production cache regression       | Isolated config/route/view caches PASS; temporary caches removed.                                                                                        |
| 68  | Cleanup/database safety           | Fixture schemas/credentials/TLS/storage/caches removed; public/public, .env digest and original counts preserved.                                        |
| 69  | Legal/compliance limitations      | Professional review of supplier duties, model, rates, jurisdiction, numbering, retention and credits required.                                           |
| 70  | Production requirements           | Reviewed issuer/policy, private S3/IAM/encryption/retention, workers/scheduler/monitoring and external deployment evidence.                              |
| 71  | Known issues                      | Baseline factory PHPStan, optional fontaine, existing Boost hook setup error, Composer PHP 8.5 notices and legacy timezone mismatch.                     |
| 72  | Deferred work                     | Government compliance, author-issued documents, accounting integrations, partial refunds, FX, subscriptions and automated provider refunds. No Phase 22. |
| 73  | Final status                      | Phase 21 code verified; tax legal/regulatory review REQUIRED; production launch NO.                                                                      |

Automated Stripe and Razorpay provider contract verification: PASS. Real Stripe/Razorpay sandbox: NOT RUN. Production S3, mail, TLS/hosting, operational monitoring and deployment-environment backup/restore: NOT RUN. The local controlled HTTPS proxy and fake provider callbacks are not external launch evidence.

Reference boundaries: [CBIC invoice rules](https://cbic-gst.gov.in/gst-invoice-rules.html) are a legal-review starting point, not compliance certification; [Dompdf documentation](https://github.com/dompdf/dompdf/blob/master/README.md) describes renderer capabilities/security configuration.

PHASE 21 CODE STATUS: COMPLETE

TAX LEGAL/REGULATORY REVIEW: REQUIRED

PRODUCTION LAUNCH READY: NO

External blockers: legal/tax/accounting approval of the actual marketplace issuer model/rates/place-of-supply/series/retention/credit policy; real hosting/domain/TLS; production PostgreSQL UTC/TLS/grants/history plan, Redis/worker/scheduler operations; private S3/PDF/artifact IAM/encryption/backup/restore; mail delivery; monitoring/alerts/DR; deployment-environment restore drill; real Stripe and Razorpay sandbox/account/webhook readiness; secure production secrets and public legal/support/refund operational information.
