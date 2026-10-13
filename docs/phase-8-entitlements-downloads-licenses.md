# Phase 8 — Purchase entitlements, library, downloads and licenses

## Scope

Verified paid orders now provision a customer's software library, a base license and access to the exact purchased source package. Fortify, RBAC, the storefront, checkout and payment verification remain in the existing application. No dependencies were added. The development payment gateway remains opt-in, restricted to local/testing environments; a real production payment provider is still a separate integration.

## Database and immutable commercial identity

Migration: `2026_10_05_200000_create_purchase_entitlements.php`.

| Table               | Principal fields and constraints                                                                                                                                                                     |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `entitlements`      | Unique public UUID; user, order, unique order item, product, offer, release and exact source artifact references; status, granted/revoked/expiration times; timestamps.                              |
| `licenses`          | Unique entitlement reference; user/item/product/release references; encrypted secret, unique normalized SHA-256 key hash, last four characters; status, issued/revoked/expiration times; timestamps. |
| `download_accesses` | Entitlement/user/item/release/artifact references; delivery method (`stream` or `temporary_url`), access time, optional URL expiration; timestamps.                                                  |

All commercial foreign keys restrict deletion. Indexes support owner/status library queries, order inspection and access history. Entitlement and license states are backed enums: `active`, `revoked`, `expired`. New purchases are active with no expiration. Support/update periods remain commercial snapshots and do not expire a software license or grant newer versions.

PostgreSQL triggers validate cross-table ownership, paid/completed settlement and exact item/product/offer/release/artifact identity. License and audit inserts must agree with their entitlement. Commercial identity cannot be changed after issuance; historical rows cannot be deleted; audit records cannot be changed. State/check constraints reject unknown values. Model guards provide the corresponding application boundary, including on SQLite tests; PostgreSQL is the production integrity authority. The migration deliberately rejects destructive rollback of commercial history.

## Provisioning and recovery

`EntitlementProvisioner` uses a transaction, row locks and three transaction attempts. Lock ordering starts with User, then Order, followed by entitlement and exact release/artifact. Unique item/entitlement constraints are the final duplicate protection. It requires a completed, paid order with its recorded settled payment paid for the matching amount and currency.

For each immutable OrderItem, the service uses that item's release ID and version. It selects the source package referenced by approved submission history, including retained retired files. For legacy releases without approved artifact references it accepts exactly one source package for that same release. Missing or ambiguous metadata fails closed for operational review. It never searches for the latest release or substitutes an `is_current` artifact. The selected artifact ID becomes immutable entitlement identity. An existing entitlement preserves that binding and its original license key.

Entitlement and license creation are atomic. Repeated calls are safe and can repair a missing license without rotating existing keys. Return counts report newly created entitlements and licenses only.

The automatically discovered synchronous `ProvisionPaidOrder` listener handles the existing after-commit `OrderPaid` event immediately. Failures are logged without private paths, keys or exception messages and do not undo verified payment truth. This is not a transactional outbox. Recovery is deliberately independent of event delivery:

```sh
php artisan commerce:reconcile-entitlements --dry-run
php artisan commerce:reconcile-entitlements
php artisan commerce:reconcile-entitlements --order=ORDER_NUMBER
```

Reconciliation scans completed paid orders missing entitlements or licenses in bounded chunks. It reports orders, new records and errors, exits unsuccessfully on failures and rejects an unknown selected order. It cannot grant unpaid purchases. The command is scheduled every ten minutes with overlap protection; production must run Laravel's scheduler. No queue/Redis requirement or arbitrary admin grant button was introduced.

## Routes, UI and authorization

| Route                                         | Behavior                                                                           |
| --------------------------------------------- | ---------------------------------------------------------------------------------- |
| `GET /account/library`                        | Authenticated owner-only library, 15 records per page.                             |
| `GET /account/library/{entitlement}`          | Owner purchase/license detail, public UUID binding.                                |
| `GET /account/library/{entitlement}/download` | Owner-authorized delivery, UUID binding, 30 requests/minute throttle.              |
| `GET /admin/entitlements`                     | Authenticated, verified, `orders.view` permission; paginated read-only inspection. |

`EntitlementPolicy` makes purchase ownership independent of role changes. Customers, authors, reviewers and admins can access their own purchases; an admin does not automatically receive another customer's license secret or download access. UUID syntax rejects malformed references with 404; another owner's valid purchase is denied. Every download independently authorizes the owner and validates the commercial chain through `PurchaseAccess`.

`LibraryPresentation` explicitly serializes allow-listed fields. Purchased product/package names, license type, version, support/update periods, price/currency and order identity come from OrderItem snapshots. A current safe public cover is only presentation. Changes to names, offer metadata, public visibility or product activity cannot alter historical access.

`resources/js/pages/library/Index.vue` and `Show.vue` reuse AppLayout, existing UI components and Wayfinder. They provide responsive purchase cards, pagination, empty-state Browse Software action, owner detail, license copying, checksum and availability. Native download anchors handle binary delivery. My Library links appear in the existing sidebar/storefront navigation. Admin inspection shows customer/order/product/package/version/status/license status/access count/granted date, never the license secret.

Order presentation adds an allow-listed software access state. A fully provisioned paid order links to My Library; a provisioning failure/lag says payment is confirmed and access is being prepared. Payment success never falsely becomes payment failure. Owner license detail uses private/no-store responses and Inertia encrypted history to reduce browser-history secret retention.

## Secure package delivery and retention

The authorization chain is authenticated owner → active, unrevoked, unexpired entitlement → matching paid/completed immutable order/item/settlement → exact purchased release → exact private source artifact. Product publication/activity/current release are not authorization inputs. No artifact-ID route, order-number download shortcut or client-supplied file locator exists.

`PrivateArtifactStorage` extracts and reuses Phase 5's private storage checks for both uploads and delivery. Local roots must remain outside the served public directory; S3 commercial storage must be private and separate from presentation storage. Paths and disks never appear in library/public props. Private objects must remain private under the deployment's bucket policy.

Default delivery streams through Laravel after storage existence/read preparation. Filenames are reduced to a sanitized basename. Responses use attachment disposition, `application/octet-stream`, `nosniff`, and private/no-store caching. Authorization happens on every request. Repeated downloads are allowed; the per-minute abuse throttle is not a contractual download cap.

Optional S3-compatible temporary redirects require adapter support and explicit configuration:

```dotenv
ENTITLEMENT_TEMPORARY_URLS=false
ENTITLEMENT_URL_MINUTES=5
```

Lifetime is clamped to 1–10 minutes and HTTPS is mandatory. Temporary responses specify attachment/content type; no presigned URL is persisted or shared through Inertia. A presigned redirect necessarily carries the temporary object locator to its authorized recipient; local/proxied streaming remains the default when that disclosure is undesirable. No permanent private URL or public bucket delivery is created. S3 behavior was tested using an isolated adapter fake; a real cloud bucket was not exercised.

`download_accesses` records successful response preparation or temporary URL issuance, not confirmed byte completion. It stores who, which immutable purchase/release/artifact, when and delivery type. It intentionally omits raw or hashed IP/user-agent data. Auditing failure prevents delivery. Mid-stream transport failure does not retroactively prove file completion.

Missing bytes, unsafe configuration or temporary storage failure produces a controlled unavailable state/503, with safe operational logging. The entitlement remains intact. There is no fallback to another artifact or newer release. An available owner detail can expose the retained package's SHA-256 checksum for verification; this checksum is not an authorization secret.

Centralized `FileRetention` protects artifacts referenced by any historical entitlement, including revoked/expired records. Existing commercial account deletion protection remains and restrictive references reinforce it. No scattered storage-deletion exceptions were introduced.

## License foundation and threat model

`LicenseKeys` generates 160 bits from `random_bytes(20)` and formats a `TMP-` key with eight five-character hexadecimal groups. Keys never derive from IDs or timestamps. Normalized uppercase alphanumeric keys use a SHA-256 lookup fingerprint and last-four display suffix.

The recoverable secret uses Laravel's encrypted cast. Plaintext is absent from database storage, URLs, logs, public/shared props, library lists and admin inspection. Only the authorized owner detail decrypts it. A database-only compromise therefore lacks the decryptable secret, and the high-entropy key resists fingerprint guessing. Compromise of both application encryption keys and database permits decryption: protect APP_KEY, encrypted backups and operational access. Future key rotation must retain the ability to decrypt existing purchases. Browser/device compromise remains outside encryption-at-rest protection; owners should treat displayed keys as private.

There is one license per entitlement. Revocation/expiration fields are foundation only, with no arbitrary mutation UI. No activation, validation, deactivation, device/domain binding, regeneration or subscription API exists.

## File inventory

Created: entitlement/license enums; Entitlement, License and DownloadAccess models; entitlement migration; EntitlementPolicy; ProvisionPaidOrder listener; Account/LibraryController; `routes/library.php`; `config/entitlements.php`; EntitlementProvisioner, ReconcileEntitlements, PurchaseAccess, EntitlementDownload, LibraryPresentation and LicenseKeys services; shared PrivateArtifactStorage; library TypeScript types/pages; EntitlementTest, EntitlementFixtures and two standalone PostgreSQL verification scripts; this document.

Modified: OrderItem relationships; FileRetention; ProductFiles private guard reuse; OrderPresentation; `routes/web.php` and `console.php`; `.env.example`; commerce types and order Show page; existing AppSidebar and MarketplaceLayout navigation; generated Wayfinder routes/actions. The Phase 7 PostgreSQL suite helper now fails closed if its expected isolation setup hook is missing. No existing application `.env`, authentication flow or package manifests were changed.

## Validation and verification

```sh
php artisan migrate
php artisan route:list --except-vendor
php artisan event:list
php artisan commerce:reconcile-entitlements --dry-run
php artisan test
php tests/Support/verify-entitlement-suite.php
php tests/Support/verify-entitlement-postgres.php
php tests/Support/verify-commerce-suite.php
vendor/bin/pint --test
npm run check
npm run types:check
npm run build
vendor/bin/phpstan analyse --memory-limit=1G
```

EntitlementTest includes 56 cases covering paid-only provisioning, duplicate/recovery behavior, atomic rollback, owner IDOR protection, snapshots, exact old-release recovery/delivery after a newer release, missing packages, optional S3 lifetime, unsafe storage, retention, license encryption/hash/immutability, account deletion, permission inspection, pagination, role changes and PostgreSQL raw-write integrity. Independent PHP workers exercise duplicate events/provisioning, listener versus reconciliation, simultaneous reconcilers and repeated downloads.

Guest/storefront behavior and all earlier phases remain covered by the full regression suite. Existing external Playwright tooling verifies opt-in development checkout → paid confirmation → library → owner license → exact ZIP filename/checksum, private locator absence, second-customer denial, access after product hiding, mobile layouts and JavaScript errors. No browser dependency was added to application manifests.

### Verification isolation incident

The first PostgreSQL entitlement runner reused a string-replacement setup hook that did not match the new typed test hook. Generated fixtures reached the application schema. The pre-run baseline had zero orders; the 49 identifiable generated orders and their generated products/users were removed with a scoped cleanup, preserving original records. Integrity triggers were restored and verified enabled; application orders/entitlements returned to zero. Sequence gaps may remain. No application refresh/truncate occurred.

The runner now prepends its isolation hook and asserts the current schema before every test. Its private connection files are restricted and its schema is removed in a finally block. The old commerce runner now rejects an unmatched hook before running tests. Subsequent entitlement, concurrency, commerce and browser verification used isolated schemas. Temporary browser credentials, files and presentation link are removed after verification.

Known pre-existing issues: PHPStan's empty `UserFactory::withTwoFactor()` return; optional fontaine build warning. Neither is a Phase 8 regression.

Final verification: full Laravel suite **424 passed, 23 skipped, 2,833 assertions** (447 cases). Isolated PostgreSQL entitlement suite **55 passed, 1 skipped, 346 assertions**; the skipped test simulates a legacy missing-license state by deletion, which PostgreSQL intentionally prohibits. PostgreSQL commerce regression **100 passed, 496 assertions**. Independent concurrency verification **5 scenarios passed, 17 assertions**. Browser verification passed the complete customer flow, owner isolation, exact download, hidden-product access, mobile layout and JavaScript checks. Pint, frontend formatting/lint, TypeScript and production build passed. PHPStan reports only the pre-existing factory issue. Application reconciliation dry-run reports no pending orders; final application orders and entitlements are zero, and no integrity trigger is disabled.

## Deferred integration

Future activation APIs must validate secure server-side fingerprints and binding rules without exposing stored secrets. Future upgrade eligibility needs explicit entitlement policy and cannot infer ownership of a newer release from support/update days. Refund/revocation workflows must transition entitlement/license state transactionally while preserving records and downloads audit. Temporary URLs already issued cannot be revoked instantaneously; their short lifetime bounds that window. A durable outbox, email notifications, commercial download limits, license regeneration and richer admin tooling remain future decisions. Earnings, commissions, payouts, customer reviews and support tickets remain outside this phase.
