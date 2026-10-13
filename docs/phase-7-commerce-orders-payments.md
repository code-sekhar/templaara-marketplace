# Phase 7 — Commerce, orders and payments

Phase 7 adds authenticated carts, billing checkout, immutable orders, payment attempts, verified payment events, customer order pages and an admin inspection workspace. Fortify, RBAC, product moderation, private storage and the public storefront remain in place. No payment provider or external email service was installed.

## Cart and checkout

Guests can continue browsing and viewing demos. Adding packages and checkout use existing Fortify authentication. There is no guest cart or second authentication system. Any authenticated user, including customer + author, admin or reviewer, can purchase; role names do not replace order ownership checks.

Each user has one `carts` row with a unique user FK, nullable controlled currency, server-generated UUID checkout key and timestamps. `cart_items` stores one selection per product with product/offer references, observed decimal price/currency/name and a SHA-256 fingerprint of commercial terms. Quantity has no independent business meaning in this phase: the UI has no quantity control, and each order item has quantity exactly one. The cart is bounded to 50 distinct products. Switching packages replaces that product's selection.

A nonempty cart contains only INR, USD, EUR or GBP offers in a single currency. Adding another currency returns a clear validation error. Removing the last item resets currency. Product/offer deletion nulls ephemeral cart references so unavailable selections remain removable; it never deletes commercial history.

`CartService` resolves each cart through the existing `MarketplaceQuery`: published active product, active category, valid current public presentation media, qualifying published release with a current private source package, and an active selected offer belonging to that product. Cards and checkout receive explicit view data rather than private artifact models. Every cart display rechecks availability and compares observed prices and terms. A changed price, package scope/name/license/support/update term, product name/currency or release requires explicit confirmation by selecting the package again. Changed currency requires removal and re-addition. No silent price acceptance occurs.

`Checkout` rechecks the authoritative database quote inside a transaction, validates the server cart UUID, locks commercial dependencies, binds the exact qualifying published release and creates order items plus the first payment attempt atomically. Browser-supplied price, currency, identity, status, totals, order items and card details are prohibited. An unavailable gateway leaves no order or payment behind. Duplicate submissions with the same owner/key return the existing order, including after success; a changed/stale key cannot create a new order.

Billing is validated separately with sensible string limits: customer name, optional company, billing email, optional phone, address lines, city, state/region, postal code and ISO 3166-1 alpha-2 country. Country codes are uppercased and checked against controlled values. Billing email defaults to the authenticated account but may differ; checkout never changes the account email. Billing is an immutable order snapshot.

## Schema, money and snapshots

All new tables have timestamps and foreign keys. PostgreSQL remains the production database.

| Table            | Purpose and significant fields                                                                                                                                                                                                                           |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `carts`          | One cart per user; currency and UUID checkout key                                                                                                                                                                                                        |
| `cart_items`     | Product/offer selection, observed price/currency/name, terms fingerprint; unique cart/product                                                                                                                                                            |
| `orders`         | Unique `TMP-{year}-{ULID}` order number; unique checkout UUID; owner; order/payment states; currency; subtotal/discount/tax/grand total; billing snapshot; placed/paid/cancelled times; optional invoice number and settled payment reference            |
| `order_items`    | Restrictive product/offer/release references; product name/slug, offer name/description, license type, unit price, quantity, line total, currency, support/update days, exact release version, origin/seller name; source cart selection and fingerprint |
| `payments`       | Order, provider and provider references, attempt UUID, status, amount/currency, safe failure code/message and paid time                                                                                                                                  |
| `payment_events` | Payment, provider/event identity, outcome, payload SHA-256, processed/ignored disposition and processed time; no raw payload                                                                                                                             |

Order-item names, prices, package descriptions, license/support/update terms, version and seller/origin are snapshots. Historical pages use them directly and do not join current product/offer data for commercial display. Restrictive references retain the exact release for future entitlements; version is also copied. Changed/unpublished products and renamed/repriced packages cannot rewrite an existing order.

Money uses PostgreSQL NUMERIC and decimal-string model casts. `Money` uses the already installed Brick Math dependency for exact addition and cent precision; it rejects fractional-cent rounding. Order totals use NUMERIC(14,2), observed offer values NUMERIC(12,2). The frontend receives decimal strings and uses Phase 6's BigInt-based formatter. There is no currency conversion. Subtotal equals grand total; discount and tax are exactly `0.00`. Those fields reserve future modules without inventing a tax policy.

Invoice references are unique `INV-{year}-{ULID}` values allocated only on verified successful payment. They are a reference foundation, not a fiscal tax invoice/PDF implementation. Number generation never uses `count() + 1`.

PostgreSQL checks enforce controlled currencies, matching order/payment states, nonnegative totals, zero tax/discount, quantity one, line-total equality, timestamp/settlement consistency and valid event fingerprints. Reference triggers enforce item product/offer/release membership, item currency, attempt amount/currency and matching paid settlement. Partial unique indexes permit at most one pending attempt and one paid attempt per order. Model guards plus PostgreSQL triggers forbid snapshot edits and deletion of orders/items/payments/events; completed/cancelled orders and closed payment attempts are immutable. Additional indexes support owner/date listings, state/currency filtering and referenced product/offer/release lookups.

## Lifecycles and verified payments

| Action                                | Order status / payment status | Payment attempt                        |
| ------------------------------------- | ----------------------------- | -------------------------------------- |
| Place order                           | `pending_payment` / `pending` | New `pending` attempt                  |
| Verified success                      | `completed` / `paid`          | `paid`, with timestamp                 |
| Verified failure                      | `payment_failed` / `failed`   | `failed`, retained                     |
| Retry unchanged eligible failed order | `pending_payment` / `pending` | New attempt; earlier failure preserved |
| Cancel unpaid order                   | `cancelled` / `cancelled`     | Pending attempt closed as `cancelled`  |

`PaymentLifecycle` centralizes settlement, failure, retry and cancellation. It matches provider/payment reference, provider order reference, order, exact amount and currency before any event/state mutation. A frontend success flag cannot settle an order. Late callbacks for failed/cancelled/already-paid attempts are retained as ignored events and cannot change a terminal state. Approval/payment does not create downloads or license keys.

Retry is available only to the order owner, and only failed unpaid orders can create a new attempt. It verifies current eligibility and the original commercial fingerprint. Changed terms require cancellation and a new checkout. Repeated retry requests while an attempt is pending return the same order without another attempt.

Verified success removes only purchased cart rows whose original cart-item ID and terms fingerprint still match. New/changed selections remain. Failure preserves the cart. Cancellation preserves the cart and rotates its checkout key when it belongs to the cancelled order, allowing a new checkout. Completed orders cannot be cancelled; refunds are outside this phase.

## Gateway and webhook boundary

`App\Contracts\PaymentGateway` defines availability, intent creation and raw webhook verification returning a `VerifiedPayment` DTO. `Checkout` and `PaymentLifecycle` work with that boundary. The only adapter currently bound is `DevelopmentGateway`; no Stripe/Razorpay credentials or network calls were invented.

Development simulation requires **all** of: runtime `local` or `testing`, `COMMERCE_GATEWAY=development`, `COMMERCE_DEVELOPMENT_ENABLED=true`, and an application key. Configuration defaults to disabled, including `.env.example`. Production rejects simulation and development webhooks even if both switches are enabled. The user's `.env` was not changed. To enable locally, set the two explicit commerce switches in your own local environment and clear Laravel's configuration cache if necessary:

```dotenv
APP_ENV=local
COMMERCE_GATEWAY=development
COMMERCE_DEVELOPMENT_ENABLED=true
```

The simulator uses an authenticated, CSRF-protected POST owned by the customer. The browser selects only paid/failed; the server builds and signs the amount, currency and references. It is labelled “no real charge.” Unknown gateway configuration disables checkout safely.

The raw webhook route is CSRF-exempt because verification uses a provider signature. It is development-gated and throttled; unknown providers return 404. The adapter checks a domain-separated HMAC-SHA256 signature with the application key before parsing, limits bodies to 64 KiB and validates controlled fields. No unsigned production “mark paid” endpoint exists. Raw bodies are hashed, not persisted. There is no card/bank credential storage. Provider reference details are shown only in the authorized admin workspace.

A unique `(provider, provider_event_id)` identifies events. Repeated identical evidence is idempotent; reused identity with a different payment/hash is rejected. PostgreSQL advisory transaction locks serialize concurrent claims of the same event identity, including across orders. Unknown/mismatched references return useful validation errors without recording a false success.

A future real adapter must replace the binding/routing guard deliberately, implement its actual signature/replay rules, validate externally confirmed monetary evidence, and use provider idempotency. The current local intent creation performs no I/O. Real network intent creation should use a durable reservation/outbox or otherwise bounded strategy rather than a slow network call while holding database locks. Provider selection, callback reconciliation, timestamp tolerances and operational webhook monitoring are deferred.

## Concurrency and authorization

All commerce writers acquire User → Cart → Order → Payment locks in that order as applicable. The user lock serializes cart creation and operations. Checkout also locks products in sorted ID order, then categories, selected offers and published releases before resolving a fresh quote. Retries lock and revalidate product/category/offer/release dependencies. Sensitive operations use transactions and bounded deadlock retries. UUID uniqueness and PostgreSQL partial indexes provide further database defenses.

`OrderPolicy` permits own order inspection, retry and cancellation. `orders.view` permits admin inspection; admin cancellation additionally requires `orders.manage`. Admin mutation is restricted to unpaid cancellation, with no editable snapshot/status form. The route number is a human-readable identifier and never authorizes access. Customer listings always constrain `user_id` and paginate 15 rows. Admin/customer filters validate search, order/payment status, currency and date ranges, retaining query parameters in pagination.

Accounts with any retained orders cannot use the existing account-deletion action; a clear validation message explains retention. User/order/item/payment/product/offer/release restrictive foreign keys prevent commercial cascades. Future anonymization, legal retention periods and fiscal invoice policy require separate design. Existing product moderation and file retention rules remain authoritative.

As in Phase 6, visibility relies on validated stored file metadata and storage visibility, without per-item S3 HEAD calls. Out-of-band object loss still needs storage/CDN monitoring; this phase does not alter the file boundary or grant paid customers access to commercial artifacts.

## Routes and UI

| Method     | Route                                              | Access                                              |
| ---------- | -------------------------------------------------- | --------------------------------------------------- |
| GET        | `/cart`                                            | Authenticated own cart                              |
| POST       | `/cart/items`                                      | Add/confirm/switch own package                      |
| DELETE     | `/cart/items/{item}`                               | Remove own selection                                |
| GET / POST | `/checkout`                                        | Own billing checkout / place order                  |
| GET        | `/account/orders`                                  | Own paginated orders                                |
| GET        | `/account/orders/{order_number}`                   | Own order or `orders.view`                          |
| POST       | `/account/orders/{order_number}/retry`             | Owner; failed unpaid only                           |
| POST       | `/account/orders/{order_number}/cancel`            | Owner; unpaid only                                  |
| GET        | `/admin/orders` and `/admin/orders/{order_number}` | Authenticated, verified, `orders.view`              |
| POST       | `/admin/orders/{order_number}/cancel`              | Also `orders.manage`; unpaid only                   |
| POST       | `/payments/development/{attempt_uuid}/simulate`    | Owner, auth, CSRF, development gate                 |
| POST       | `/payments/{provider}/webhook`                     | Adapter verification, development gate and throttle |

The existing product detail adds per-offer Add to Cart buttons; guests receive a sign-in CTA. Public offer serialization adds only the selection identifier `offer_id`. It does not expose artifact data. The storefront header shares an owner-scoped cart count and links Cart/My Orders. AppSidebar reuses the existing architecture for My Orders and permission-controlled All Orders.

`commerce/Cart.vue` shows covers, chosen packages, licenses/support/update terms, prices, stale issues, package changes, removal, exact server total and checkout. `commerce/Checkout.vue` uses the storefront layout with billing, validation, processing states and summary. `orders/Index.vue` and `orders/Show.vue` use AppLayout for customer/admin workspaces. They show filters, pagination, empty/error states, snapshot detail, retained payment attempts, success/failure, guarded retry and cancellation confirmation. Completed orders show “Order confirmed” and an invoice reference. There is no download button or fake delivery claim. Wayfinder supplies routes/actions, and responsive views reuse existing UI controls.

## Events and Phase 8 boundary

`OrderPlaced`, `PaymentSucceeded`, `PaymentFailed` and `OrderPaid` implement Laravel `ShouldDispatchAfterCommit`. Duplicate confirmation does not re-emit payment success or settlement. These are extension points; no external notification provider, entitlement, download, license key or activation listener is registered.

Phase 8 can consume `OrderPaid` and provision idempotent entitlements bound to the immutable order item and exact release. It must retain independent private-artifact authorization and handle retries/durable event delivery; ordinary after-commit application events are not a transactional outbox. Later refunds and entitlement revocation need explicit workflows. No Phase 8 work is included here.

## Verification

Run from the repository root:

```bash
php artisan migrate
php artisan route:list --except-vendor
php artisan test --compact
php tests/Support/verify-commerce-suite.php
php tests/Support/verify-commerce-postgres.php
vendor/bin/pint --test
npm run check
npm run types:check
npm run build
composer types:check
```

The default existing test configuration uses in-memory SQLite; production is still PostgreSQL. The two committed PostgreSQL verification scripts create random isolated schemas using the configured pgsql connection, apply migrations only there, use private temporary configuration files and drop only their own schemas/files in `finally`. They require PostgreSQL schema-creation permission and never refresh/truncate application data. The isolated HTTP harness resets the rate-limiter singleton after changing test connections, avoiding a stale database-cache connection losing the enclosing test transaction. No production credentials are committed or printed.

`CommerceTest` contains 100 cases including authentication/multi-role purchasing, offer membership, eligibility, money/identity forgery, currencies, stale commercial terms, billing, checkout UUIDs, snapshots and package descriptions, exact release binding, payment failures/retries, verified callbacks/replay protection, mismatched evidence, disabled/production gateways, ownership, admin permissions, cancellation, exact decimal boundaries, after-commit event counts, cart clearing and retained history. Nine checks exercise PostgreSQL-only raw mutation/deletion/FK protections. Paid-order tests explicitly verify private artifact paths/disks/checksums/filenames are absent and downloading remains forbidden.

The multi-process PostgreSQL script uses synchronized independent PHP processes against persisted fixtures. Nine scenarios cover duplicate checkout, duplicate confirmation, simultaneous retries, old/current attempts racing, and price/offer/product/category/release dependency changes during checkout. It also records `OrderPaid` in an isolated verification table to assert one semantic settlement event. Temporary presentation/package files are removed after verification.

Results for this implementation:

- Full Laravel suite: **377 passed, 14 skipped, 2,493 assertions**. Nine skipped cases are PostgreSQL-only; the five other skips predate Phase 7.
- Isolated PostgreSQL commerce suite: **100 passed, 496 assertions**.
- Concurrent PostgreSQL verification: **9 scenarios passed, 22 assertions**.
- Pint, frontend lint, TypeScript and production build pass.
- PHPStan reports only the known empty `UserFactory::withTwoFactor()` return issue; optional fontaine and Composer/PHP deprecation warnings are pre-existing.
- Playwright used the existing external tooling and a disposable PostgreSQL schema/storage configuration. Guest login behavior, package selection, cart counts, billing, successful payment, confirmation/history/detail, failure/retry, private-data absence and 390px mobile layouts were checked without JavaScript errors. No browser tooling was added to application dependencies. Verification credentials/files/schema/server are cleaned up.

## File inventory

Created:

- `app/Enums/OrderStatus.php`, `PaymentStatus.php`.
- `app/Models/Cart.php`, `CartItem.php`, `Order.php`, `OrderItem.php`, `Payment.php`, `PaymentEvent.php`.
- `app/Policies/OrderPolicy.php` and `app/Contracts/PaymentGateway.php`.
- `app/Services/Commerce/{CartService,Checkout,Money,DevelopmentGateway,VerifiedPayment,PaymentLifecycle,OrderPresentation}.php`.
- `app/Events/Commerce/{OrderPlaced,PaymentSucceeded,PaymentFailed,OrderPaid}.php`.
- `app/Http/Middleware/DevelopmentPaymentsOnly.php`.
- `app/Http/Requests/Commerce/{CartRequest,CheckoutRequest}.php`.
- `app/Http/Controllers/Commerce/{CartController,CheckoutController,OrderController,PaymentController}.php`.
- `config/commerce.php`, `routes/commerce.php`.
- `database/migrations/2026_10_05_190000_create_commerce_foundation.php`.
- `database/migrations/2026_10_05_193000_protect_commercial_references.php`.
- `database/migrations/2026_10_05_194000_snapshot_commercial_package_description.php`.
- `resources/js/pages/commerce/{Cart,Checkout}.vue`, `resources/js/pages/orders/{Index,Show}.vue`, `resources/js/types/commerce.ts`.
- `tests/Feature/CommerceTest.php`, `tests/Support/CommerceFixtures.php`, `tests/Support/verify-commerce-suite.php`, `tests/Support/verify-commerce-postgres.php`.
- This document.

Modified:

- `app/Providers/AppServiceProvider.php`: gateway contract binding.
- `app/Http/Middleware/HandleInertiaRequests.php`: owned cart count, preserving public auth minimization.
- `app/Http/Requests/Settings/ProfileDeleteRequest.php`: order retention.
- `app/Services/Marketplace/PublicProduct.php`: deliberate offer selection ID.
- `bootstrap/app.php`: narrowly scoped payment webhook CSRF exemption.
- `routes/web.php`: includes commerce routes.
- `.env.example`: disabled commerce defaults; no actual `.env` change.
- `resources/js/app.ts`: storefront layout for cart/checkout.
- `resources/js/layouts/MarketplaceLayout.vue`, `resources/js/components/AppSidebar.vue`: cart/order navigation.
- `resources/js/pages/marketplace/Show.vue`: package/cart CTA.
- `resources/js/types/marketplace.ts`, `resources/js/types/global.d.ts`: offer selection and cart count.

Wayfinder regenerated commerce/admin-order route helpers and controller actions; the build regenerated `public/build`. No new dependencies or production commerce seeds were added. Fixtures use the real moderation/media/release pipeline rather than bypassing visibility. The three migrations were applied to the configured PostgreSQL application database; commerce example purchases were created only in isolated verification schemas.

The commerce migrations intentionally resist destructive rollback of retained commercial history. Future schema changes should be forward migrations. Real payment providers, durable outbox/reconciliation, entitlements/downloads/licenses, refunds, tax engine/fiscal invoices, coupons, commissions/payouts, customer reviews and support remain deferred. Phase 7 stops here.
