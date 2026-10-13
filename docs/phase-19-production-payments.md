# Phase 19 — Production payments

Templaara extends its existing Laravel PaymentGateway, immutable commerce records and PaymentLifecycle with Stripe Checkout and Razorpay Orders. Fortify, RBAC, settings, purchased snapshots, entitlements, licenses, earnings, notifications, manual refunds and provider-neutral analytics remain authoritative. No SDK, frontend payment package, second ledger or Phase 20 functionality was introduced. Actual `.env` is not edited.

## Architecture and committed reservation

`PaymentGatewayManager` implements the original contract. It delegates local simulation to the existing DevelopmentGateway and reserves external attempts without network I/O. `ExternalPaymentGateway` defines external create, recover, fetch, raw webhook verification/normalization and browser projections. `StripeGateway` and `RazorpayGateway` implement that boundary through Laravel HTTP.

The checkout sequence is:

1. Authenticate the cart owner and validate billing and the server checkout UUID.
2. Acquire existing settings/User/Cart/domain locks; recheck enabled currency, cart eligibility and exact purchased commercial terms.
3. Commit Order, immutable OrderItems, immutable pending Payment and an operational PaymentCheckout reservation atomically.
4. Claim and commit the operational creation lease in a short transaction.
5. Call the provider outside both transactions.
6. Bind the verified provider reference in another short transaction, guarded by the current lease token.
7. Redirect to an authenticated, owner-only payment-status page.

CheckoutController and OrderController perform external preparation only after Checkout/PaymentLifecycle returns from the reservation transaction. Explicit customer preparation and the reconciliation command use the same service. An independent PostgreSQL connection verifies committed records and takes User/Cart/Order/Payment `FOR UPDATE NOWAIT` locks during mocked provider creation; it proves no marketplace financial locks remain held during HTTP I/O. The lease itself is also committed before HTTP.

A timeout does not undo purchases, fabricate failure/success, or recreate commercial records. The pending attempt remains recoverable. Provider controllers never provision access or calculate prices, commissions, refunds or payouts.

## Provider selection and configuration

`COMMERCE_GATEWAY` remains disabled by default. `external` uses explicit currency mapping: INR → Razorpay; USD/EUR/GBP → Stripe. `COMMERCE_PROVIDER_INR`, `_USD`, `_EUR`, `_GBP` override the mapping. Razorpay is deliberately restricted to INR initially; Stripe supports all four internal currencies. There is no fallback to development on configuration failure.

Availability requires enabled configuration, a controlled `test`/`live` mode, matching key prefixes and required secrets. Production permits live external configuration only. AppServiceProvider rejects production startup with `COMMERCE_GATEWAY=development`; the unchanged DevelopmentGateway independently requires local/testing, both existing development switches and an application key. Unknown/disabled configuration leaves checkout unavailable.

Environment-only placeholders in `.env.example`:

- `STRIPE_ENABLED`, `STRIPE_MODE`, `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`.
- `RAZORPAY_ENABLED`, `RAZORPAY_MODE`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`.
- Provider mappings and the existing `COMMERCE_GATEWAY` switch.

No secret enters MarketplaceSetting, commerce snapshots, client props, notifications, reports, CSV or application audit data. Stripe's public key confirms configured mode but hosted Checkout needs no key in Vue. Razorpay's key ID is exposed only in the owner checkout handoff. Operational mode changes should drain or investigate attempts from the previous provider account/mode; credentials and webhook secrets must be configured consistently for that account.

## Operational persistence and financial identity

The forward migration `2026_10_06_090000_create_payment_checkouts.php` adds only `payment_checkouts`:

| Field                           | Meaning                                                                             |
| ------------------------------- | ----------------------------------------------------------------------------------- |
| `payment_id`                    | Unique restrictive FK to the existing immutable Payment attempt                     |
| `provider`                      | Controlled Stripe/Razorpay identity                                                 |
| `provider_order_id`             | Write-once verified Stripe session/Razorpay order reference                         |
| `provider_payment_id`           | Write-once captured PaymentIntent/Razorpay payment reference                        |
| `checkout_url`                  | Validated Stripe hosted checkout URL; never shared in reports/admin troubleshooting |
| `state`                         | reserved, creating, ready, uncertain, settled, failed, review                       |
| `claim_token`, `claimed_at`     | Creation lease identity and absolute acquisition instant                            |
| `attempts`, `available_at`      | Creation/recovery count and absolute next eligible time                             |
| `reconciled_at`, `problem_code` | Last operational check and controlled safe diagnostic                               |
| `created_at`, `updated_at`      | Timezone-aware operational timestamps                                               |

No money is duplicated here. Existing Payment retains its immutable amount/currency/provider/order/attempt UUID and terminal financial state. Its external adapter reservation references are internal `attempt-{UUID}` and the public order number; actual external identifiers belong to PaymentCheckout. This avoids weakening the Phase 7 financial immutability trigger merely to update asynchronous provider references.

PostgreSQL checks restrict provider/state/counters. A trigger requires the exact reserved Payment/provider identity, retains operational history and prohibits rebinding non-null external identifiers or moving the record to another Payment/provider. Unique provider/order and provider/payment references prevent cross-attempt binding. The `(state, available_at, id)` index supports bounded recovery work; the unique Payment FK supports owner/admin projections. Original partial unique pending/paid attempt indexes and all commerce/reference/entitlement/earning/refund integrity guards remain intact.

## Exact money

`MinorUnits` accepts only INR/USD/EUR/GBP and plain nonnegative decimal strings with at most twelve integer and two fractional digits. Brick Math converts to/from integer minor units exactly, without float, rounding or FX. Provider amounts must be actual integers within internal NUMERIC(14,2) limits; strings/floats/negative/overflow/unsupported currencies fail safely.

External checkout rejects zero/below-minimum charges before committing a commercial order: Razorpay INR 1.00; Stripe 0.50 for INR/USD/EUR and GBP 0.30. Providers may enforce account/payment-method-specific constraints in addition; rejected/ambiguous creation remains unpaid and recoverable. A separate no-charge fulfillment policy is deferred; development simulation remains unchanged. Internal money capacity does not promise every payment method accepts that maximum.

## Stripe

Stripe uses hosted Checkout `mode=payment`, immutable total/currency, one order-level line item and only public order number plus attempt UUID metadata/client reference. No billing details, license secrets, artifacts or seller ledger data are sent as metadata. HTTP pins `Stripe-Version: 2025-03-31.basil`.

Creation uses `Idempotency-Key: templaara-{immutable attempt UUID}`. Recovery repeats the same request/key within a conservative 23-hour window. Beyond that window, ambiguous creation enters manual investigation rather than risking recreation after provider key retention expires. Responses must match amount, currency, mode, internal metadata and client reference. Hosted URLs require HTTPS and exact `checkout.stripe.com` host.

Raw-body `Stripe-Signature` verification uses the configured webhook secret, SHA-256, constant-time comparison, multiple v1 signatures and ±300-second system-clock tolerance. Keep production clocks synchronized. Event and object mode must match configuration. Accepted events:

- `checkout.session.completed`: paid only when complete and payment_status=paid; unpaid completion stays pending.
- `checkout.session.async_payment_succeeded`: complete/paid required.
- `checkout.session.async_payment_failed`: unpaid required; genuine failure closes the attempt.
- `checkout.session.expired`: expired status required; closes the attempt.

Contradictory failure/success evidence cannot settle. Unsupported signed events acknowledge without changing commerce. Retrieval normalizes authoritative session state through the same settlement boundary. For a completed/unpaid asynchronous session, a second bounded GET verifies its exact bound PaymentIntent, amount/currency and received amount: succeeded confirms payment, canceled or a completed failed payment requiring a new method closes the attempt, and processing remains pending. A missed asynchronous failure webhook therefore does not leave the customer permanently unable to retry.

Success/cancel URLs contain a UX return hint only. Neither query string marks paid or fails an attempt. A cancel return can resume the same unresolved checkout.

Sources: [Stripe idempotent requests](https://docs.stripe.com/api/idempotent_requests), [signature verification](https://docs.stripe.com/webhooks/signature), [Checkout retrieval](https://docs.stripe.com/api/checkout/sessions/retrieve), [Basil API version](https://docs.stripe.com/changelog/basil), [currency constraints](https://docs.stripe.com/currencies).

## Razorpay

Razorpay Orders receives authoritative integer amount, INR, partial_payment=false, receipt=immutable attempt UUID and safe order/attempt notes. The 36-character UUID fits the documented receipt bound. Each genuinely failed internal attempt gets a different receipt/provider order on retry.

There is **no invented idempotency header and no automatic POST retry**. After an ambiguous response, recovery issues a bounded GET `/orders?receipt={UUID}&count=2`. Exactly one result must have the exact receipt and immutable amount/currency. Zero/multiple/mismatched results enter investigation; the system does not blindly create again. This supports response-lost recovery without creating another commercial Order.

The SDK handoff includes only key ID, provider order ID, authoritative integer amount/currency and safe marketplace display name. Vue lazily loads Razorpay's official checkout script; no secret is rendered.

Authenticated callback verifies HMAC-SHA256 over the **stored** order reference plus supplied payment reference, using the key secret. It then retrieves that exact payment server-side; provider payment/order identity and amount/currency must match. Authorization alone is pending; only captured=true/status=captured can settle. Callback is CSRF-protected and enforces exact Order ownership.

Raw webhooks verify HMAC-SHA256 with the separate webhook secret before parsing. `X-Razorpay-Event-Id` is validated and used for durable replay identity. `payment.captured`, `payment.failed`, `order.paid` are supported; captured/failed event and payment state must be coherent. Other signed event types acknowledge without settlement. Reconciliation retrieves the exact order and bounded order payments; captured takes priority over earlier failures, while authorization alone never grants access.

Sources: [create order and unique receipt](https://razorpay.com/docs/api/orders/create/), [receipt-filtered order lookup](https://razorpay.com/docs/api/orders/fetch-all/), [order payments](https://razorpay.com/docs/api/orders/fetch-payments/), [raw webhook validation and event identity](https://razorpay.com/docs/webhooks/validate-test/).

## Settlement, replay and concurrency

ProviderResult carries only provider/event/object/payment identity, controlled state, exact decimal amount/currency, payload hash and Stripe's internal metadata. Raw bodies, card/payment-method data and provider responses are never persisted. Existing PaymentEvent records paid/failed outcome, provider event ID, SHA-256 fingerprint, processed/ignored disposition and internal processing time; it does not retain the raw provider event type or occurrence timestamp.

ExternalPayments resolves the exact bound provider object, acquires the existing provider/event advisory lock, then User → Cart → Order → Payment → operational checkout locks. It verifies exact Payment/Order/provider/amount/currency and Stripe internal metadata before calling unchanged `PaymentLifecycle::process`. The original domain boundary checks immutable internal references/economics and valid transitions again. Event uniqueness/fingerprint checks, row locks and existing partial indexes make duplicate success one logical paid Payment/OrderPaid transition. Webhook, callback and reconciliation share this path.

Mismatches quarantine operational state (`evidence_mismatch`) while leaving commerce unpaid. Unknown/unbound references and transient provider failures return retryable responses. A reused event identity with different contents cannot overwrite history. Known identical duplicates acknowledge successfully. Different genuine event IDs for the same completed purchase are retained as ignored events.

Only an accepted pending attempt on a pending Order settles. Closed failed/cancelled attempts and terminal Orders are never reopened. Late success is retained/flagged as `late_success`; additional captured identity is `additional_payment`. These require operator investigation and, where appropriate, externally confirmed manual refund handling. There is no automatic charge cancellation/refund or force-paid escape hatch.

Retry retains the same Order and immutable items, rechecks original product/package/release terms and creates a new attempt only after genuine provider failure/expiry. Changed terms require a new checkout. Repeated retry while pending returns the same attempt. Local cancellation of unresolved external attempts is blocked, and the corresponding UI action is hidden; a browser close cannot rule out later capture.

## Recovery, leases and timezone fix

`payments:reconcile --limit=100` permits 1–500 records and selects deterministic eligible operational IDs using the indexed state/available-time projection. It never scans all commerce history or externally processes DevelopmentGateway. Reserved/uncertain creation is prepared/recovered first; known provider objects are retrieved authoritatively and passed through the same settlement service. Pending results never settle.

Creation leases last three minutes. Row locking admits one token; active leases cannot be stolen, expired leases recover, and only the current token may bind a create response. Lease ownership never authorizes payment. Ready-object GETs may run concurrently, but settlement remains idempotent. `withoutOverlapping` limits scheduled overlap; database invariants remain authoritative across independently started workers.

Creation ambiguity backs off exponentially, capped at 60 minutes. Ready/pending retrieval schedules another check in five minutes; retrieval errors back off ten minutes. Ambiguous unresolved creation, mismatches and late/extra success enter `review` rather than guessing financial truth. Operators must inspect those cases; no manual provider-reference editing/mark-paid endpoint is exposed.

**The interrupted PostgreSQL failure was fixed in the new operational domain.** All PaymentCheckout timestamps use TIMESTAMPTZ; the model writes `Y-m-d H:i:sP`, and bulk operational writes plus eligibility bindings use the same offset-explicit format. Matching the separator also preserves SQLite's lexical comparisons. Comparisons use absolute immutable Carbon instants. Exact epoch tests run with application UTC and PostgreSQL session Asia/Kolkata and America/New_York; active/future leases stay inactive and expired leases recover. No database/global/application timezone is changed. Historical Order/Payment timestamps and the previously documented legacy reporting mismatch are untouched.

The command runs every five minutes alongside existing entitlement/earning/notification recovery schedules. Production requires Laravel's scheduler and its existing queue worker infrastructure.

## HTTP, routes and UX

Provider HTTP uses fixed HTTPS origins, no redirects, a 3-second connect timeout and 12-second request timeout. GET has at most two attempts; POST has no automatic transport retry. Exceptions/response bodies are replaced by a generic safe error. Structured recovery logs contain only provider, internal attempt UUID and controlled result code—never credentials, authorization headers or payloads.

| Method/path                                      | Access/behavior                                                                        |
| ------------------------------------------------ | -------------------------------------------------------------------------------------- |
| POST `/webhooks/stripe`                          | Anonymous session access; signed raw Stripe event; throttle 240/min, 64-KiB body bound |
| POST `/webhooks/razorpay`                        | Anonymous session access; signed raw Razorpay event; same bounds                       |
| GET `/checkout/payment/{order_number}/status`    | Authenticated exact owner; private/no-store, explicit safe props                       |
| POST `/checkout/payment/{order_number}/start`    | Owner, CSRF, throttle 10/min; prepare/resume current pending attempt                   |
| POST `/checkout/payment/{order_number}/razorpay` | Owner, CSRF, throttle 30/min; verify callback and retrieve provider payment            |

Only the **exact** external webhook paths and existing development webhook are request-forgery exemptions. Normal checkout/mutations keep Laravel 13's origin/token protection. Invalid signatures reject with 401, signed malformed JSON with 400, oversized bodies with 413, transient verification/settlement failures with safe 503 JSON, accepted/known duplicates with 200. Production web-server body limits should accompany the application bound, and webhook throughput should be monitored/tuned from actual traffic.

`commerce/PaymentStatus.vue` uses the existing MarketplaceLayout, shared Button/Link/Head, Wayfinder and TypeScript. It displays server pending/confirmed/failed state, exact formatted price, provider handoff and Orders/Library links. Polling is limited to thirty checks 2.5 seconds apart; pending users can safely leave. GET/polling never queries a provider or changes financial state. The existing Order page links owner payment status and shows safe operational references only for authorized admin inspection.

## Privacy and integrations

Customer status props are an explicit allow-list of public order number, financial status, stored total/currency and necessary handoff fields. No billing/provider customer identity, event history, checksum, secrets or private artifacts appear there. Customer Order attempts expose display provider name and existing safe payment status; external references/state/diagnostics appear only in the existing `orders.view` admin workspace. Authors have no new provider access and cannot inspect another buyer's order. Existing analytics/CSV serializers receive no new operational fields. There is no force-paid route/button.

OrderPaid and existing after-commit listeners remain the only normal downstream integration. EntitlementProvisioner, License, EarningProvisioner and NotificationRecovery retain independent idempotent recovery. Provider controllers never call them directly. Separate-process verification asserts one logical OrderPaid and one entitlement/license/author earning; purchase notification recovery records one logical delivery per source/recipient. Listener failures after financial commit remain recoverable through the existing commands.

Phase 13 manual externally confirmed refund works for both providers, compensates existing earnings/access and retains original paid Payment/Order history. **Automatic Stripe/Razorpay refund APIs are deferred.** Phase 17 recognizes both providers using the same recorded settled-payment proof and immutable purchased totals, not current prices or a provider-specific revenue table. Phase 18 enabled currencies apply when reserving new commerce; subsequently disabling a currency does not invalidate already valid in-flight/reserved/historical orders or settlement.

## Verification and production operation

Run:

```bash
php artisan migrate --no-interaction
php artisan migrate:status
php artisan route:list --except-vendor
php artisan event:list
php artisan schedule:list
php artisan test --compact
php tests/Support/verify-phase19-suite.php --payments-only
php tests/Support/verify-phase19-suite.php
php tests/Support/verify-phase19-concurrency.php
vendor/bin/pint
vendor/bin/pint --test
npm run check
npm run types:check
npm run build
vendor/bin/phpstan analyse --no-progress
```

PhaseNineteenTest covers exact conversion/configuration, provider reservation and privacy, genuine/lost/failed creation, supported signatures/events, mismatches/metadata/object references, replay, callback forgery, server returns, bounded reconciliation/backoff, timezone-safe creation/leases, historical settings, provider-neutral analytics, manual refunds, retained paid truth and notification deduplication. PostgreSQL-specific tests are skipped only on SQLite and run in the isolated PostgreSQL suite.

The guarded suite creates a random disposable schema and checks **both exact current_schema and exact SHOW search_path** before migration and every transactional fixture test. The full PostgreSQL suite covers Phases 19–10, Commerce and Entitlements. It never refreshes public, truncates commercial data, disables integrity triggers or rewrites history. Every runner cleans its schema, protected config and storage in finally.

Independent-process scenarios: duplicate Stripe webhook; Stripe webhook/retrieval; duplicate Razorpay webhook; Razorpay callback/webhook; success/repeated retry; settlement/currency settings change; simultaneous downstream provisioning; simultaneous creation lease. Each worker has its own PostgreSQL connection and barrier synchronization. A separate visibility connection proves reservation commit and absence of financial row locks at the provider boundary.

Browser verification uses a protected external disposable-schema harness with Http::fake and generated signed events, plus intercepted Stripe hosted return and Razorpay SDK responses. These controls are **outside application routes/manifests** and cannot enable fake production settlement. Flows cover signed Stripe success, pending cancel/resume, authoritative Razorpay callback/webhook, forged callback, missed-webhook reconciliation, permission/secret projections, real request-forgery boundaries, mobile checkout/status/orders/library and JavaScript errors.

Real provider sandbox payments are **NOT RUN**: no actual provider credentials or provider networks were used. To verify manually, configure your own test-mode infrastructure secrets, register the exact webhook endpoints with the documented events (Stripe endpoint version matching Basil), open a controlled purchase, verify capture plus Order/Library, resend events, test cancellation/delayed return, run reconciliation, and inspect safe admin references. Confirm provider/account/currency/payment-method availability before switching to live mode. Preserve existing external-confirmed refund operations and scheduler/queue recovery. No real payment or external email is claimed by the automated harness.

### Final results

Final verification on 7 October 2026:

| Suite                               | Total | Passed |                          Skipped | Assertions | Failures |
| ----------------------------------- | ----: | -----: | -------------------------------: | ---------: | -------: |
| Phase 19 / SQLite                   |   137 |    134 |                3 PostgreSQL-only |        389 |        0 |
| Phase 19 / isolated PostgreSQL      |   137 |    137 |                                0 |        402 |        0 |
| Complete Laravel regression         | 1,574 |  1,399 |       175 platform/fixture skips |      6,262 |        0 |
| Full required PostgreSQL regression | 1,213 |  1,212 | 1 documented Entitlement fixture |      4,086 |        0 |

Eight independent-process races and seven controlled browser flows passed. Automated provider contract verification: **PASS**. Real Stripe sandbox: **NOT RUN**. Real Razorpay sandbox: **NOT RUN**.

Pint and Pint test passed. Frontend check (formatting and lint), TypeScript and production build passed. PHPStan reports only the pre-existing missing return in `database/factories/UserFactory.php:49`. The build retains the pre-existing optional fontaine warning. Forward migration, migration status, route list, event list and schedule list all succeeded.

Cleanup confirmed: all Phase 19 disposable schemas, generated provider/browser credentials, protected test configuration, synthetic browser storage and 28 newly created fake-storage roots were removed. No test config cache remains. `current_schema() = public` and `SHOW search_path = public`; original commercial counts match the pre-run baseline and `.env` hash is unchanged. Application external-attempt count remains zero. The forward migration is the only production-database schema change. User-started PHP/Vite development servers remain running. No Phase 20 work was started.

**Phase 19 is complete.** Production readiness still requires operator configuration and the explicitly unperformed real-provider sandbox/deployment checks; automated verification makes no external payment claim.

## File inventory

Created (22):

- `app/Contracts/ExternalPaymentGateway.php`
- `app/Http/Controllers/Commerce/ExternalPaymentController.php`
- `app/Http/Middleware/PrivatePaymentResponse.php`
- `app/Models/PaymentCheckout.php`
- `app/Services/Commerce/ExternalPayments.php`
- `app/Services/Commerce/Gateways/MinorUnits.php`
- `app/Services/Commerce/Gateways/ProviderCheckout.php`
- `app/Services/Commerce/Gateways/ProviderHttp.php`
- `app/Services/Commerce/Gateways/ProviderResult.php`
- `app/Services/Commerce/Gateways/ProviderUnavailable.php`
- `app/Services/Commerce/Gateways/RazorpayGateway.php`
- `app/Services/Commerce/Gateways/StripeGateway.php`
- `app/Services/Commerce/PaymentGatewayManager.php`
- `database/migrations/2026_10_06_090000_create_payment_checkouts.php`
- `docs/phase-19-production-payments.md`
- `resources/js/actions/App/Http/Controllers/Commerce/ExternalPaymentController.ts`
- `resources/js/pages/commerce/PaymentStatus.vue`
- `resources/js/routes/commerce/webhooks/index.ts`
- `tests/Feature/PhaseNineteenTest.php`
- `tests/Support/ExternalPaymentFixtures.php`
- `tests/Support/verify-phase19-concurrency.php`
- `tests/Support/verify-phase19-suite.php`

Modified (22):

- `.env.example`
- `app/Http/Controllers/Commerce/CheckoutController.php`
- `app/Http/Controllers/Commerce/OrderController.php`
- `app/Models/Payment.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Commerce/Checkout.php`
- `app/Services/Commerce/OrderPresentation.php`
- `app/Services/Commerce/PaymentLifecycle.php`
- `bootstrap/app.php`
- `config/commerce.php`
- `resources/js/actions/App/Http/Controllers/Commerce/CheckoutController.ts`
- `resources/js/actions/App/Http/Controllers/Commerce/OrderController.ts`
- `resources/js/actions/App/Http/Controllers/Commerce/index.ts`
- `resources/js/pages/orders/Show.vue`
- `resources/js/routes/admin/orders/index.ts`
- `resources/js/routes/commerce/checkout/index.ts`
- `resources/js/routes/commerce/index.ts`
- `resources/js/routes/commerce/orders/index.ts`
- `resources/js/routes/commerce/payments/index.ts`
- `resources/js/types/commerce.ts`
- `routes/commerce.php`
- `routes/console.php`

No dependency manifest, role/permission seeder, historical migration, accounting ledger or authentication rewrite. Generated Wayfinder files follow the existing build convention.

## Known limits and deferred work

The pre-existing UserFactory::withTwoFactor missing return remains the only permitted PHPStan issue. The optional fontaine build warning and historical app/database timezone mismatch remain unrelated. Gateway account/mode changes and ambiguous/late/extra capture require deliberate operational investigation; no force-paid or automatic refund is provided. Remote provider outages can delay checkout/confirmation without rewriting commercial truth. Provider webhook secret rotation requires coordinated delivery handling; no arbitrary old-secret database store is introduced. Real sandbox/deployment verification is explicitly separate from automated provider contract testing.

Automatic provider refunds, disputes/chargebacks, recurring billing, FX, tax expansion, new accounting/payment providers and Phase 20 are deferred.

## Completion report

The inventory above lists application and generated files; the following report records each requested delivery boundary.

| #   | Item                         | Delivered behavior / verification                                                                                                                                                                   |
| --- | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Files created                | External contract, operational model/migration, gateway manager/adapters/DTOs, ExternalPayments service, controller/middleware, status page, tests and guarded verification runners; see inventory. |
| 2   | Files modified               | Existing checkout/lifecycle/presentation/controllers/routes/config/provider binding and Order UI/types; authentication and historical financial migrations preserved.                               |
| 3   | Dependencies                 | None added; Laravel HTTP and existing Brick Math are reused.                                                                                                                                        |
| 4   | Migration                    | Forward `2026_10_06_090000_create_payment_checkouts.php`; no commercial history rewrite.                                                                                                            |
| 5   | Gateway architecture         | Original PaymentGateway contract retained, external provider contract and operational service added.                                                                                                |
| 6   | External-call boundary       | Commercial reservation and creation lease commit before HTTP; independent connection sees committed rows and acquires financial row locks.                                                          |
| 7   | Provider selection           | Explicit currency mapping; INR Razorpay, USD/EUR/GBP Stripe by default, controlled overrides.                                                                                                       |
| 8   | DevelopmentGateway           | Local/testing only; production development configuration blocks startup; no fallback.                                                                                                               |
| 9   | Stripe                       | Hosted Checkout, pinned Basil API, exact metadata/economics and authoritative retrieval.                                                                                                            |
| 10  | Stripe idempotency           | Immutable attempt-derived supported key; lost response recovers using same key within conservative retention window.                                                                                |
| 11  | Razorpay                     | INR Orders, exact amount/receipt, captured-payment verification and bounded retrieval.                                                                                                              |
| 12  | Razorpay recovery            | Receipt lookup recovers exactly one matching order; ambiguity stops, no invented header/blind POST retry.                                                                                           |
| 13  | Secrets                      | Infrastructure environment config only; placeholders documented, actual `.env` untouched.                                                                                                           |
| 14  | Checkout                     | Existing immutable Order/items/Payment reservation followed by external preparation and status handoff.                                                                                             |
| 15  | Minor units                  | Exact decimal-string/integer conversion for INR/USD/EUR/GBP, precision and overflow validation, no floats.                                                                                          |
| 16  | Operational attempts         | One PaymentCheckout per external immutable Payment; state, references and bounded recovery only.                                                                                                    |
| 17  | Provider references          | Write-once unique external order/payment identity; internal Payment identity remains immutable.                                                                                                     |
| 18  | Stripe webhook               | Exact anonymous signed endpoint with raw-body verification, event/mode validation and durable replay handling.                                                                                      |
| 19  | Razorpay webhook             | Exact raw-body HMAC endpoint, stable event identity and coherent order/payment evidence.                                                                                                            |
| 20  | Browser callback             | Razorpay verifies signature against stored order, then retrieves authoritative payment; Stripe returns do not settle.                                                                               |
| 21  | Signatures                   | Constant-time HMAC checks; Stripe clock tolerance/multiple v1 handling; invalid signatures cause no settlement.                                                                                     |
| 22  | Normalized events            | Allow-listed evidence only; raw provider payloads are not persisted. Provider occurrence timestamps are not stored.                                                                                 |
| 23  | Settlement                   | Both adapters enter existing PaymentLifecycle through ExternalPayments; no direct provisioning.                                                                                                     |
| 24  | Validation                   | Exact internal Order/Payment/attempt/provider/external reference/amount/currency/success state required.                                                                                            |
| 25  | Replay                       | Durable PaymentEvent identity/hash, advisory/domain locks and existing unique financial/provisioning invariants.                                                                                    |
| 26  | Multiple attempts            | Pending retry reuses attempt; genuine failed/expired attempt can retry same unchanged purchased Order; late capture quarantined.                                                                    |
| 27  | Status page                  | Owner-only safe server props; bounded polling and resume/Orders/Library guidance.                                                                                                                   |
| 28  | Reconciliation               | Bounded deterministic 1–500 operational records, authoritative retrieval and same settlement boundary.                                                                                              |
| 29  | Lease/recovery               | Three-minute token ownership, expired recovery, active lease protection, safe backoff/manual investigation.                                                                                         |
| 30  | Timezone                     | All new operational timestamps TIMESTAMPTZ with offset-explicit writes/bindings; exact UTC/Asia-Kolkata/New-York regression. Historical commerce untouched.                                         |
| 31  | Scheduler                    | Reconcile every five minutes without scheduled overlap; existing recovery schedules retained.                                                                                                       |
| 32  | HTTP policy                  | Fixed HTTPS origins, no redirects, 3-second connect/12-second request timeout; bounded GET retry, no automatic POST retry.                                                                          |
| 33  | Privacy/logging              | Controlled diagnostic codes and internal attempt IDs only; no provider secrets/body/billing/license/artifact leakage.                                                                               |
| 34  | Admin visibility             | Existing orders.view workspace gets necessary safe operational references/status, no provider secrets or force-paid action.                                                                         |
| 35  | Customer UX                  | Hosted Stripe or lazy Razorpay handoff, safe pending/confirmed/failed state, mobile Orders/Library.                                                                                                 |
| 36  | Access integration           | Existing OrderPaid/EntitlementProvisioner/License path, exactly-once logical access.                                                                                                                |
| 37  | Earnings                     | Existing immutable EarningProvisioner/ledger, no provider-specific accounting table.                                                                                                                |
| 38  | Notifications                | Existing recovery/delivery path, one logical purchase outcome; no external email sent by verification.                                                                                              |
| 39  | Refund compatibility         | Both paid providers support existing manually confirmed Phase 13 refund compensation while retaining payment history.                                                                               |
| 40  | Analytics                    | Phase 17 settled-payment definition counts immutable paid sales once across provider/retry attempts.                                                                                                |
| 41  | Settings                     | New reservation requires enabled currency; later disabling preserves valid in-flight/historical settlement.                                                                                         |
| 42  | PostgreSQL integrity         | Controlled states/providers, restrictive exact Payment binding, immutable references, unique identities, indexed recovery.                                                                          |
| 43  | Tests                        | 137 meaningful Phase 19 cases plus prior-phase suites; exact final totals below.                                                                                                                    |
| 44  | Phase 19 PostgreSQL          | Isolated exact-schema suite; final result in verification table.                                                                                                                                    |
| 45  | Full PostgreSQL              | Phases 19–10, Commerce and Entitlements; final result in verification table.                                                                                                                        |
| 46  | Full Laravel                 | Complete suite, including prior phases; final result in verification table.                                                                                                                         |
| 47  | Concurrency                  | Eight independent-process PostgreSQL races passed, including committed/unlocked provider boundary proof.                                                                                            |
| 48  | Browser                      | Seven controlled flows passed: signatures, forged callback, return/cancel, reconciliation, privacy/CSRF/mobile, no JavaScript errors.                                                               |
| 49  | Quality                      | Pint, frontend check, TypeScript and production build; final outcomes below.                                                                                                                        |
| 50  | PHPStan                      | Only existing UserFactory::withTwoFactor missing return at line 49 remains.                                                                                                                         |
| 51  | Automated provider contracts | PASS: Http::fake, exact payloads, signatures, recovery and normalized settlement; no external credential claims.                                                                                    |
| 52  | Real Stripe sandbox          | NOT RUN; no real user-provided sandbox credentials used.                                                                                                                                            |
| 53  | Real Razorpay sandbox        | NOT RUN; no real user-provided sandbox credentials used.                                                                                                                                            |
| 54  | Cleanup/safety               | Disposable schemas/config/credentials/storage removed; final public/public and original counts/.env checks below.                                                                                   |
| 55  | Production setup             | Configure external/live secrets and exact webhook endpoints/API version; run scheduler/queue, monitor pending/manual-review cases and verify provider account availability.                         |
| 56  | Known issues                 | Existing factory PHPStan issue, optional fontaine warning and historical timezone mismatch; operational ambiguity requires deliberate investigation.                                                |
| 57  | Deferred                     | Automatic provider refunds, disputes, recurring billing, FX/tax expansion and Phase 20.                                                                                                             |
| 58  | Decisions                    | Separate operational recovery from immutable financial truth; after-commit HTTP, exact evidence, durable replay and conservative ambiguity handling.                                                |
