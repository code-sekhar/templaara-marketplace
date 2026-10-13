# Phase 20 — Production readiness

PHASE 20 CODE STATUS: **COMPLETE**. PRODUCTION LAUNCH READY: **NO**. Automated repository verification is complete; real external launch requirements remain outstanding.

## Initial audit (before changes)

| Area                            | State   | Evidence / gap                                                                                           |
| ------------------------------- | ------- | -------------------------------------------------------------------------------------------------------- |
| environment configuration       | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| application security            | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| authentication/session security | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| authorization                   | READY   | Existing domain contracts and regression coverage.                                                       |
| HTTP security headers           | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| CSP                             | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| CSRF                            | READY   | Existing domain contracts and regression coverage.                                                       |
| rate limiting                   | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| webhook security                | READY   | Existing domain contracts and regression coverage.                                                       |
| payment configuration           | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| PostgreSQL                      | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| Redis/cache                     | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| queue workers                   | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| scheduler                       | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| object storage                  | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| private downloads               | READY   | Existing domain contracts and regression coverage.                                                       |
| logs                            | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| monitoring                      | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| health checks                   | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| error handling                  | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| backups                         | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| restore procedure               | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| deployment                      | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| rollback                        | MISSING | No dedicated implementation/runbook; external deployment not available.                                  |
| migrations                      | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| maintenance mode                | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| zero/low-downtime concerns      | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| frontend production build       | READY   | Existing domain contracts and regression coverage.                                                       |
| performance                     | PARTIAL | Configuration or domain implementation exists; explicit production validation/operational proof remains. |
| operational recovery            | READY   | Existing domain contracts and regression coverage.                                                       |

## Production contract and command

`marketplace:production-check` is read-only and exits 1 for blocking FAIL. It verifies production environment, debug off, clean HTTPS origin/key, PostgreSQL/verified SSL/schema/migrations/UTC, persistent cache/session/queues, secure HttpOnly lax cookies, SMTP configuration, live provider mappings and secrets, Phase 18 policies, notification epoch, private storage guards, runtime permissions and build manifest. PASS means a local probe/configuration passed, never that a real provider, worker, bucket policy or backup was exercised. WARN requires operator evidence. Secret values and caught exception messages are deliberately omitted.

Use explicit database `DB_SEARCH_PATH=public`, `PRODUCTION_DB_SCHEMA=public`, `DB_TIMEZONE=UTC`, `APP_DEBUG=false`, HTTPS APP_URL, `SESSION_SECURE_COOKIE=true`, database or Redis cache/sessions/queue. Set DB_SSLMODE=verify-full with a verified CA (libpq configuration/secret manager). Restrict the app role to runtime tables/sequences; use a separate migration role for DDL. Revoke untrusted CREATE on application schema. Keep normal READ COMMITTED isolation and existing advisory/row locks. Size FPM+workers below PostgreSQL connection capacity; transaction pooling requires testing session search_path/timezone assumptions, not a blind switch. Set connection/statement/lock timeouts deliberately at the deployment role/server, measuring exports and recovery before reducing them.

**UTC is the standard for NEW production deployment/session writes. Do not apply it as a historical repair.** Existing offset-free commerce retains its documented interpretation. Before moving existing data between timezone standards, stop writes and obtain a separate approved migration plan; Phase 20 rewrites no timestamps. Reporting timezone is independently configured via Phase 18 and may remain Asia/Kolkata.

## Security, CSP and proxies

ResponseSecurity adds nosniff, strict-origin referrer policy, restricted permissions, frame protection and server-generated UUID request IDs. Production HTTPS receives one-year HSTS without automatically including all subdomains or preload. Scripts use self plus per-request Vite/Blade nonce and exact Razorpay checkout origin; no wildcard/eval/inline script exception. Style-only unsafe-inline permits existing Vue/provider styles. Images allow self/data/blob and explicit HTTPS CDN origins configured by CSP_IMAGE_ORIGINS. Font assets are self-hosted. Stripe hosted checkout is an external navigation, not an iframe/script requirement. Razorpay requires checkout/API frame/connect origins; real provider sandbox CSP verification remains required. CSP_MODE supports enforced/report-only; report-only is a staged rollout, not launch enforcement proof. No violation collection platform was added.

TrustedProxies accepts only explicit IP/CIDR configuration, ignores forwarded host, and never infers wildcard trust from a platform hostname. Edge must validate Host and overwrite forwarded headers. No global cross-origin isolation policy breaks the provider popup/frame. Normal CSRF remains; only the exact existing three webhook paths are exempt.

Fortify remains authoritative: production minimum 12 characters/mixed case/numbers/symbols/uncompromised, verification, session regeneration/logout and normal remember-me remain. Existing login, license, download, review/support and payment-specific limits are retained. Additional production-only registration/reset/checkout/retry/export/admin mutation/onboarding limits fill gaps, independently keyed and bounded. Recent-password gates were not added to admin POST routes because the baseline has no confirmation page/workflow and redirecting to an unavailable GET would break operations. Verified sessions, permissions and strong passwords remain mandatory; dedicated MFA/re-auth UX is a separately scoped recommendation.

Production 5xx errors return safe HTML/JSON, no traces or arbitrary exception messages. Exception reporting uses class category/request ID only, avoiding raw SQL/provider/private data. Preserve useful 4xx/validation. Never log request body, headers, license keys, destinations, support messages or raw provider payloads. Request context clears after each request. Operator investigation should use safe domain references and restricted monitoring, not enable APP_DEBUG.

## Health, queues, scheduler and Redis

`/up` remains cheap anonymous Laravel liveness. `/health/ready` is bounded/read-only, no-store, throttled, returns only ready/unavailable and probes DB plus Redis connections actually configured. It never sends mail, calls providers, dispatches jobs or uploads objects. Monitor both endpoints externally; distinguish boot failure from dependency outage. Deployment production-check additionally verifies storage/queue configuration. Readiness is not full launch proof.

Actual queued work is DeliverNotificationEmail on notifications (45-second timeout, five tries, 60/300/900/1800 backoff). Domain payment/access/earning events are synchronous after-commit with scheduled recovery; no new payment job/ledger is created. Database queue is supported; Redis is optional for cache/sessions/queue, never financial truth. Redis outage affects availability/locks/rate limits but cannot mark Orders paid or rewrite PostgreSQL. Monitor pending work and recover after service returns.

Use the supplied systemd template after replacing user/path/connection; retry_after >45, graceful stop >45, worker memory/process lifetime bounded. Inspect `queue:failed`; retry only reviewed failures. Do not retry ambiguous SMTP acknowledgement blindly or delete evidence. `queue:forget UUID` only after resolution/retention approval. Restart workers with `queue:restart` on release and verify new worker starts; failed jobs persistence remains existing database-uuids.

Cron as deployment service user: `* * * * * cd /srv/templaara/current && /usr/bin/php artisan schedule:run` (replace deployment path). Existing file cleanup/hourly, payment/five-minute, entitlement/earnings/ten-minute and notification/minute recovery retain withoutOverlapping. Multi-node PRODUCTION_MULTI_NODE=true adds onOneServer using shared atomic database/Redis cache. Monitor actual scheduler run completion externally; listing schedule proves configuration only. Avoid duplicate independent crons on multiple nodes unless leadership is enabled.

## Storage and secrets

Reuse PrivateArtifactStorage and purchase authorization, exact retained artifact binding, safe attachment filename, no-store streaming and bounded HTTPS temporary URL lifetime. Production S3-compatible private/public buckets must differ; private serve/public URL are forbidden. Support attachments remain private. Bucket public-access block, encryption, separate least-privilege credentials, no anonymous listing, short signed URL, and controlled CORS are operator requirements. Never expose keys or sign before ownership/access checks. Public CDN origin must be explicitly reflected in CSP.

Back up all commercial packages, documentation, support attachments and public media; enable versioning plus encrypted separate-account replication/backup and reviewed retention. Database-only backup is insufficient; restore matching object versions with database artifact identities. No real bucket policy/versioning/backup is claimed verified here. Preserve APP_KEY and payout identity/encryption keys in an audited secret manager with recoverable encrypted off-server copy. Loss of APP_KEY can make licenses and bank instructions unrecoverable. Do not regenerate a key during deploy. Secret backups are separate from SQL/repository. Web user writes only storage/bootstrap-cache; release source read-only, .env 0640 or stricter, shared directories 0750 with appropriate group. Never chmod 777.

## Deployment, maintenance and rollback

Templates in deployment cover Nginx public-only root, dotfile/private denies, TLS redirect, fixed front controller, static immutable caching, compatible upload/body/FPM limits and supervision. They are examples, not applied hosting configuration. Confirm PHP 8.3+ (current verification PHP 8.5) and required extensions; install locked Composer dependencies --no-dev --optimize-autoloader and locked npm ci/build in a fresh immutable release. Never use composer setup in production (key generation/seed defaults). Link protected shared config/storage, preserve APP_KEY and existing data. Build using explicit reviewed HTTPS environment; verify manifest, no hot file/test routes/sourcemap secrets.

Sequence: take/verify backup and rollback point; prepare fresh release; use controlled short maintenance if schema/code are not compatible; run production-check (pending migrations expected before applying); review forward migrations and execute `migrate --force`; config:cache, route:cache, view:cache in the new release; repeat production-check; atomically switch current link; gracefully restart workers/FPM/opcache; verify health and smoke; reopen and monitor/reconcile. Do not run destructive rollback after writes. Code rollback only to a schema-compatible release; otherwise forward-fix or an explicitly planned point-in-time restore with traffic halted and provider reconciliation. Existing irreversible financial migrations retain history.

Maintenance deliberately returns safe 503 to customers AND webhooks. Keep window short; providers retry non-2xx and payments:reconcile retrieves authoritative state after reopening. No webhook bypass is introduced. Maintain workers/scheduler deliberately during compatible deploy, or stop/start them with bounded outage/recovery. Preserve pending PaymentCheckout evidence and notification delivery rows across restart. Monitor/reconcile late/extra capture; never forcepaid or blind retry/refund.

## Backup, restore and disaster recovery

Launch operational targets (not guarantees): PostgreSQL PITR/WAL archive RPO <=5 minutes; initial RTO <=60 minutes after rehearsed restore. Daily encrypted custom pg_dump plus retained WAL/PITR is recommended, with independent off-server storage and retention (e.g. 7 daily/4 weekly/12 monthly) validated against legal requirements. Daily-only backup is insufficient for payments. Object versioning/replication RPO must match accepted artifact loss window. Monitor backup completion, archive lag, decryptability and regular restore drills. Credentials go in protected libpq passfile/secret manager, never command arguments or logs.

Restore only to a new disposable database using pg_restore --exit-on-error --no-owner. Guard target identity, inspect schema/migration coherence plus immutable critical row hashes/counts and read-only application checks. Never restore to public application schema. Local generated-fixture drill result is recorded separately below; no production backup success claim. Maintain a separate migration/user grants plan after restore.

DR: declare incident; halt unsafe writes; preserve provider delivery and DB evidence; restore PostgreSQL to an approved point; restore exact object versions; restore secrets; validate schema and apply only approved forward migrations; start supervised queue/scheduler; run payments:reconcile, commerce:reconcile-entitlements --dry-run then scoped recovery, commerce:reconcile-author-earnings --dry-run then scoped recovery, commerce:reconcile-refunds (inspection), notifications:reconcile; inspect financial invariants/analytics independently by currency; smoke test; reopen with monitoring. Existing commands remain the only reconciliation systems. SMTP uncertain retry requires explicit operator acceptance.

## Monitoring, launch and external verification

Alert on readiness failure/5xx, queue oldest age/failed jobs, scheduler missed run, payment uncertain/review/late_success/additional_payment, provider failures and reconciler nonzero exits, missing access/earning compensation, uncertain mail, database lock/connection pressure, storage errors, backup/PITR lag. Use safe counts/codes/request IDs; keep financial/admin monitoring access restricted and redact secret payloads. No monitoring stack or external provider was installed.

Post-deploy non-destructive smoke: homepage/catalog/product, normal Fortify login and verified admin, settings/reports views, safe 403/404, health, actual worker and schedule heartbeat, signed URL authorization configuration, provider live configuration/webhook reachability. Do not charge automatically. Sandbox operators must verify Phase 19's Stripe Basil events and Razorpay capture/callback/webhook, duplicates, cancel/close, delayed/failure recovery and exact access/earning/notice/analytics results before live traffic.

BLOCKING: real host/TLS, PostgreSQL TLS/UTC/search_path/grants, supervised queues/shared locks/scheduler, private bucket and object recovery, real mail, PITR/backup+deployment restore drill, monitoring/alerts, real Stripe AND Razorpay sandbox, live account/webhook configuration, explicit refund/payout/contact/legal/support policy and complete automated verification. RECOMMENDED: MFA/re-auth UX, malware scanning of archives, capacity/load tuning and regular DR rehearsal. POST-LAUNCH: alert tuning and verified-volume performance improvements; no unrelated business feature is part of this phase.

External Stripe sandbox, Razorpay sandbox, production payment, mail, S3 and production backup: NOT RUN. Production launch ready: NO. Final automated evidence and external blockers are recorded below.

## Concrete operator setup

Use a reviewed secret manager or protected shared environment file; the repository's real `.env` was not changed. In a new production release configure:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-reviewed-marketplace-domain
COMMERCE_GATEWAY=external
COMMERCE_DEVELOPMENT_ENABLED=false
DB_CONNECTION=pgsql
DB_SEARCH_PATH=public
PRODUCTION_DB_SCHEMA=public
DB_TIMEZONE=UTC
DB_SSLMODE=verify-full
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
NOTIFICATION_QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_TIMEOUT=30
PRODUCT_MEDIA_DISK=product_s3_public
PRODUCT_ARTIFACT_DISK=product_s3_private
SUPPORT_ATTACHMENT_DISK=product_s3_private
CSP_MODE=enforced
PRODUCTION_MULTI_NODE=false
```

Replace the illustrative domain; supply existing APP_KEY, database host/name/runtime credentials, SMTP host/port/username/password/from identity, and PRODUCT_STORAGE_ACCESS_KEY_ID/SECRET_ACCESS_KEY/REGION/PRIVATE_BUCKET/PUBLIC_BUCKET/ENDPOINT/PUBLIC_URL through protected configuration. Never copy fake verification keys into deployment. Configure STRIPE_ENABLED/MODE/PUBLIC_KEY/SECRET_KEY/WEBHOOK_SECRET and RAZORPAY_ENABLED/MODE/KEY_ID/KEY_SECRET/WEBHOOK_SECRET according to Phase 19; production modes must be live. Enabled currencies need coherent COMMERCE_PROVIDER_INR/USD/EUR/GBP mappings and explicit Phase 18 refund/payout policies. Disable currencies that have no operational provider. Public bucket URLs must use HTTPS and their exact CDN origin must appear in CSP_IMAGE_ORIGINS.

The existing S3 disks are `product_s3_public` and `product_s3_private`; inspect config/filesystems.php before deploying. Use separate public/private buckets, prevent public ACLs on private objects, and verify short signed URLs with a real authorized test purchase only after sandbox checkout succeeds. A configured disk passing the command does not prove remote policy or access.

For a local Nginx-to-PHP-FPM deployment without an HTTP proxy, leave TRUSTED_PROXIES empty: HTTPS is supplied through the fixed FastCGI parameter. For a separate load balancer, set only its explicit private IPs/CIDRs, restrict origin ingress to those addresses, and overwrite X-Forwarded-For/Proto/Port at the trusted edge. Do not accept a browser's arbitrary forwarded chain. If using Cloudflare, maintain its reviewed current address ranges and restrict ingress; do not trust `*`, a hostname, forwarded Host, or CF headers without an explicitly validated edge configuration. HTTPS URLs are rooted at configured APP_URL; client IP/rate-limit identity uses the explicitly trusted chain. TLS termination and Cloudflare origin controls require external smoke tests.

If Redis is selected, configure authenticated/TLS private-network access, an application prefix, persistence/eviction appropriate to queues and locks, and sufficient memory. Default/cache connections now have three-second connect/read budgets. Readiness uses a separate PostgreSQL connection with three-second connect/statement and one-second lock budgets, retaining the configured schema and timezone; commercial transaction/export budgets remain unchanged. Multiple dependency probes run sequentially with finite per-probe budgets. PostgreSQL/DNS/network outage behavior still needs deployment monitoring.

Install the supplied systemd worker and FPM templates with reviewed service paths/users; run `systemctl daemon-reload`, enable/start the selected units, and inspect restricted service logs. Configure the scheduler cron above as the same deployment user. Verify a harmless notification through the real mail queue before launch, including failed-job retention. Use the shared storage/cache paths consistently across releases; restart workers and reload FPM after switching releases. Do not run a development Vite server in production.

A guarded operational deployment checklist:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan marketplace:production-check
php artisan migrate:status
# After backup, migration review and deployment authorization:
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan marketplace:production-check
php artisan queue:restart
php artisan route:list --except-vendor
php artisan event:list
php artisan schedule:list
```

Commands above are a deployment runbook, not a claim that a real host was configured. Maintenance uses `php artisan down --retry=60` and `php artisan up` only during an approved deployment window; do not put a bypass token in public logs. Stop incoming writes before any incompatible migration/restore. Run approved reconciliation after reopening, using Phase 19 provider evidence and existing domain recovery commands.

For encrypted backups, use a restricted libpq service/passfile outside the repository, a dedicated read-only backup role, `pg_dump --format=custom --no-owner --no-acl` into a protected staging directory, then authenticated encryption and verified off-server transfer. Configure continuous WAL archiving/PITR separately; test decryption and retained WAL. Record backup timestamp, database identity, migration version and matching object-backup versions without credentials. Restore with `pg_restore --exit-on-error --no-owner --no-acl` **only to a newly provisioned, explicitly approved disposable database**. Check target identity before executing; the local verification runner accepts no caller-supplied restore target and generates both schemas itself. Never point it at an existing application schema or commercial database.

## Restore drill evidence and limitations

The local role cannot create databases. `tests/Support/verify-phase20-restore.php` therefore creates two guarded disposable schemas, verifies exact current_schema/search_path before fixture writes, migrates/seeds only the source fixture, creates a controlled paid order, takes a custom pg_dump, restores only into the generated target, and compares exact ordered row hashes/counts. All eleven tables match:

| Category                   | Tables                                         |
| -------------------------- | ---------------------------------------------- |
| Purchased financial truth  | orders, order_items, payments                  |
| Provider operations/replay | payment_checkouts, payment_events              |
| Access                     | entitlements, licenses                         |
| Author financial truth     | author_earnings, author_earning_ledger_entries |
| Delivery operations        | notification_deliveries                        |
| Schema version             | migrations                                     |

Result: **PASS**, eleven exact count/hash matches; paid/access state also checked. Both generated schemas and dump/restore scratch files are removed in finally cleanup. No financial trigger is disabled, no real commercial data is copied or overwritten. Schema-name substitution is limited to the generated fixture identities. This proves the representative schema/data dump round trip, not roles/grants/extensions, whole-database PITR, object restore, large-data capacity, production network, encrypted backup automation or achievable RPO/RTO. Deployment-environment full database restore remains **NOT RUN** and launch blocking.

## Performance and load measurements

Measured locally on temporary HTTPS, enforced CSP, production assets, guarded disposable PostgreSQL data, two visible products and synthetic purchases. The PHP built-in server is single-worker; full regression also consumed CPU during these measurements. Twenty requests per page at four-request concurrency were measured. These are launch diagnostics, not production capacity or an SLA.

| Page             | p95 ms | Maximum queries | PHP allocated peak bytes reported by harness |
| ---------------- | -----: | --------------: | -------------------------------------------: |
| Homepage         |   1165 |              13 |                                      4194304 |
| Catalog          |    689 |              13 |                                      4194304 |
| Product          |   1544 |              18 |                                      4194304 |
| Login page       |    116 |               6 |                                      4194304 |
| Readiness        |    128 |               4 |                                      4194304 |
| Customer library |    326 |              17 |                                      4194304 |
| Admin dashboard  |   8300 |              65 |                                      4194304 |
| Sales report     |    225 |              20 |                                      4194304 |
| Settings         |    263 |              35 |                                      4194304 |

Follow-up five requests at concurrency one: admin dashboard maximum/p95 1984 ms, 65 queries; payment status 43 ms, 14 queries. Ten isolated checkout reservations, excluding cart setup and **all provider calls**, took 15.89–27.77 ms, consistently 28 queries, 35651584 allocated peak bytes. Pending purchased snapshots and operational attempts persisted; no fake settlement occurred. Memory numbers are PHP allocator measurements in different harness processes, not worker RSS or comparable production FPM capacity.

Measured internal smoke budgets for this fixture/server: p95 public/auth/library/report/settings <=2500 ms at four concurrent requests, dashboard <=10000 ms at four / <=2500 ms at one, checkout reservation <=100 ms at one, status <=250 ms at one. All meet these initial diagnostic budgets. Dashboard's many fixed authoritative aggregate queries and serial-server queueing are the clear capacity risk. No product-table iteration or speculative index/cache redesign was introduced. Listings remain eager-loaded/paginated and query counts are fixed for this small fixture. Full deployment-size data and FPM/PG load must be measured before choosing concurrency targets; this exercise does not prove absence of every possible scale-dependent N+1.

## Dependency and secret audits

Scoped npm overrides resolve shell-quote to 1.12.0 and tinypool to 2.2.0 without a direct Laravel/Vue/toolchain major upgrade. Initial npm audit reported five critical development-tool findings; final npm audit reports **zero vulnerabilities** across all severities. Composer audit reports **zero advisories and zero abandoned packages**. Composer's installed CLI emits PHP 8.5 deprecation notices; these are tooling notices, not security findings. Build passes with the existing optional fontaine warning.

Source/config/routes/resources/tests/docs/deployment/example scan searched private key blocks, provider secret patterns, AWS key IDs and APP_KEY-like values without printing matches. No confirmed repository credential was found. `.env.example:55` is the existing MAIL_PASSWORD `null` placeholder, not a credential. A literal-assignment scan additionally flagged app/Models/User.php:57, which is the `hashed` cast declaration, not a password. The real `.env`, dependencies and runtime/build binaries were excluded from output/scanning; real `.env` integrity is checked by baseline digest privately. This is a scoped heuristic scan, not a guarantee against every secret format or absent Git history. Secrets observed through normal local configuration are never emitted.

## Verification inventory

Created: ProductionCheck; ReadinessController; ResponseSecurity, TrustedProxies and ProductionSurfaceLimits; Operations/ProductionReadiness and Readiness; config/production.php; PhaseTwentyTest; guarded verify-phase20-suite and verify-phase20-restore runners; Nginx/FPM/systemd examples; this document; generated Wayfinder readiness action and health route.

Modified: bootstrap/app.php; AppServiceProvider; DeliverNotificationEmail; config/database.php and session.php; routes/web.php and console.php; app.blade.php; .env.example; package.json and package-lock.json; generated controller index. Pint also normalized existing verify-phase18-concurrency.php formatting without changing its scenarios. **No Phase 20 migration/index, new direct package, auth replacement, financial ledger or provider integration was added.**

The final production asset regression found that a Vue page consolidated into a shared Vite chunk no longer had an individual manifest entry. Blade now loads only configured CSS/app entry points; Inertia's existing resolver loads page chunks. The affected 70-case onboarding suite passes (56 passed, 14 existing skips, 346 assertions). Production CSP nonces remain on executable entry/theme scripts.

Automated coverage includes production config blockers, sanitized read-only probes, proxy/CIDR handling, nonces/security headers/HSTS, no wildcard/eval scripts, useful client errors and safe server errors, request context cleanup, private storage guards and surface-specific limits. Prior phase suites retain full payment/ownership/access/privacy behavior. Browser and performance harnesses live outside application source; production routes contain no test-only endpoints.

Final totals and cleanup evidence are recorded below. External launch requirements remain independent of automated passes.

## References

- [Laravel deployment](https://laravel.com/framework/docs/deployment)
- [Laravel requests and trusted proxies](https://laravel.com/framework/docs/requests)
- [shell-quote advisory](https://github.com/advisories/GHSA-pqg4-j6r4-53mv)
- [tinypool advisory](https://github.com/advisories/GHSA-85c8-ppgw-ccpr)
- [additional tooling advisory](https://github.com/advisories/GHSA-5gmw-xhrv-c9v3)

## Detailed completion report

|   # | Area                    | Delivered / evidence / external limitation                                                                                                                                                               |
| --: | ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
|   1 | Files created           | Seven PHP classes, production config, 93-case test file, two guarded verification runners, three deployment templates, this runbook and generated readiness Wayfinder files; exact inventory above.      |
|   2 | Files modified          | Bootstrap, provider, notification job, database/session configs, web/console routes, Blade, environment example, npm manifests and generated controller index; existing Phase 18 runner formatting only. |
|   3 | Dependencies            | Scoped shell-quote/tinypool overrides; no new direct dependency or unrelated major upgrade.                                                                                                              |
|   4 | Migrations              | None; existing migrations inspected and no pending migration required locally.                                                                                                                           |
|   5 | Readiness audit         | Initial 30-area audit above; repository hardening/runbooks delivered, external operational evidence remains missing.                                                                                     |
|   6 | Environment             | Read-only validated production contract, reviewed secrets outside repository, real .env unchanged.                                                                                                       |
|   7 | Production-check        | PASS/WARN/FAIL table and blocking exit; controlled local production-like configuration correctly fails missing real policies/storage/infrastructure.                                                     |
|   8 | HTTPS                   | Secure origin rooted at APP_URL; temporary proxy HTTPS automated pass, real domain/TLS not verified.                                                                                                     |
|   9 | Proxies                 | Explicit bounded IP/CIDR only; no forwarded Host/wildcard/automatic platform trust; edge ingress/header overwrite required.                                                                              |
|  10 | Headers                 | nosniff, referrer, frame, permissions, request ID; HSTS only production HTTPS.                                                                                                                           |
|  11 | CSP                     | Enforced nonce scripts, exact Razorpay sources, explicit image origins; style-only unsafe-inline justified; report-only staged mode documented.                                                          |
|  12 | Cookies/session         | Production secure default, HttpOnly/lax/exact domain checks; database/Redis sessions, existing fixation/logout behavior preserved.                                                                       |
|  13 | Authentication          | Existing Fortify and strong production passwords preserved; MFA/re-auth UX deliberately deferred.                                                                                                        |
|  14 | Authorization           | Existing ownership/permissions remain authoritative; cross-user orders/downloads denied in browser and regressions.                                                                                      |
|  15 | Limits                  | Separate production registration/reset/checkout/retry/export/admin/onboarding limits; existing provider/download/support/login limits retained.                                                          |
|  16 | Errors                  | Safe 500/503 HTML/JSON; useful 403/404/419/422 preserved; maintenance verified.                                                                                                                          |
|  17 | Logging                 | Safe exception class and request ID only; SMTP failure scrubbed in production; no raw payload logging added.                                                                                             |
|  18 | Correlation IDs         | Server-generated UUID, incoming ID ignored, context cleaned; no caller content/header injection.                                                                                                         |
|  19 | Liveness                | Existing anonymous /up preserved.                                                                                                                                                                        |
|  20 | Readiness               | Minimal no-store ready/unavailable, read-only isolated timeout-bounded PG probe and selected Redis pings; no provider/mail/storage write.                                                                |
|  21 | PostgreSQL              | Existing integrity/locking preserved; explicit optional schema/timeouts; deployment TLS/grants/capacity required.                                                                                        |
|  22 | Timezone                | New production UTC standard; historical mismatch documented and untouched, reporting timezone independent.                                                                                               |
|  23 | Redis                   | Optional shared cache/session/queue, connection/read budgets; no financial truth depends on Redis; real Redis not tested.                                                                                |
|  24 | Workers                 | Supplied systemd example for notifications/default, 45-second timeout, bounded memory/lifetime, restart/graceful stop instructions.                                                                      |
|  25 | Failed jobs             | Existing durable database UUID failed jobs preserved; reviewed retries, SMTP ambiguity and retention documented.                                                                                         |
|  26 | Scheduler               | Six existing schedules preserved; optional shared-lock onOneServer leadership, no new financial scheduler.                                                                                               |
|  27 | Storage                 | Existing public/private S3-compatible disks and guards; production configuration/policy/versioning proof external.                                                                                       |
|  28 | Private artifacts       | Exact purchase binding, owner access, no-store, safe stream/signing retained; owned download checksum matches, guest/other/refunded denial pass.                                                         |
|  29 | Object recovery         | Existing domain cleanup/recovery retained; matching database/object versions required in DR.                                                                                                             |
|  30 | Nginx                   | Public root/fixed front controller/TLS/static policy/private denies template; not installed on actual infrastructure.                                                                                    |
|  31 | PHP-FPM/supervision     | Pool/body/memory/lifetime examples and worker service; connection-capacity sizing remains operator work.                                                                                                 |
|  32 | Deployment              | Locked release build, reviewed backup/forward migrate/cache/atomic switch/restart/smoke sequence.                                                                                                        |
|  33 | Production caches       | config/route/view cache pass with isolated paths, removed afterward.                                                                                                                                     |
|  34 | Migration strategy      | Separate migration role, backup first, forward compatible schema changes; no destructive migration run.                                                                                                  |
|  35 | Rollback                | Schema-compatible code rollback or reviewed forward fix/PITR with traffic halted; no blind financial rollback.                                                                                           |
|  36 | Maintenance             | Safe retry UX, webhook retry and reconciliation policy; isolated actual maintenance mode browser pass.                                                                                                   |
|  37 | Stripe                  | Existing after-commit/idempotent adapter preserved; automated contract pass, real sandbox/live account readiness unverified.                                                                             |
|  38 | Razorpay                | Existing receipt recovery/callback boundary preserved; mocked SDK+CSP pass, real sandbox unverified.                                                                                                     |
|  39 | Webhooks                | Exact existing CSRF exclusions only, signed normalized events/replay/domain settlement preserved.                                                                                                        |
|  40 | Reconciliation          | Existing payment/access/earning/refund/notification recovery commands remain authoritative.                                                                                                              |
|  41 | Monitoring              | Restricted health/error/queue/payment/access/backup metrics runbook; no real platform installed.                                                                                                         |
|  42 | Alerts                  | Failure/lag/uncertainty/connection/backup triggers documented; real paging/escalation not tested.                                                                                                        |
|  43 | Backup                  | Encrypted off-server dump plus PITR/object versions/secrets recovery instructions; automation external.                                                                                                  |
|  44 | Restore                 | Guarded two-schema local drill passes exact counts/hashes on 11 tables.                                                                                                                                  |
|  45 | Restore limits          | No CREATEDB privilege; schema round trip is not full deployment DB/PITR/object/roles/grants restore.                                                                                                     |
|  46 | RPO/RTO                 | <=5-minute DB RPO / <=60-minute initial RTO operational targets, not achieved guarantees.                                                                                                                |
|  47 | Disaster recovery       | Halt writes, restore approved DB/object/key state, inspect invariants, recover authoritative workflows, smoke and monitor.                                                                               |
|  48 | Integrity               | Constraints/triggers retained; commercial baseline counts and real env digest checked; no public fixture writes.                                                                                         |
|  49 | Performance             | Ten representative targets measured; dashboard fixed aggregates are the clear latency risk.                                                                                                              |
|  50 | Load                    | Local four-request concurrency and isolated reservations measured; fixture budgets and limitations recorded, no production SLA claimed.                                                                  |
|  51 | Frontend                | Production manifest/font/assets/CSP pass; consolidated-page manifest failure fixed through configured app entry.                                                                                         |
|  52 | Composer audit          | Zero advisories / abandoned packages; installed CLI PHP 8.5 deprecation notices only.                                                                                                                    |
|  53 | npm audit               | Zero vulnerabilities, including dev tooling after scoped patches.                                                                                                                                        |
|  54 | Secret scan             | No confirmed source credential; only null mail placeholder and hashed cast false positives; scope limitations documented.                                                                                |
|  55 | Permissions             | Read-only releases, protected environment, least-privilege storage/cache/log/runtime writes, no chmod 777; actual host permissions external.                                                             |
|  56 | Smoke                   | Non-destructive public/auth/admin/health/cache/worker/scheduler/provider reachability checklist documented.                                                                                              |
|  57 | Launch checklist        | Explicit blocking infrastructure/provider/backup/storage/mail/legal evidence; recommended MFA/capacity/DR follow-up separated.                                                                           |
|  58 | Tests                   | 93 meaningful Phase 20 cases plus existing domain/regression suites; final exact totals below.                                                                                                           |
|  59 | Phase 20 PostgreSQL     | 93 passed, 183 assertions, zero skips/failures in guarded schema.                                                                                                                                        |
|  60 | Full PostgreSQL         | Final run covers 20–10, Commerce and Entitlements; exact totals below; only established Entitlement fixture skip allowed.                                                                                |
|  61 | Full Laravel            | 1667 total, 1492 passed, 175 existing skips, 6445 assertions, zero failures after asset fix.                                                                                                             |
|  62 | Concurrency/recovery    | Eight payment races plus committed/unlocked reservation proof; seven notification scenarios/32 assertions; seven settings races, all pass.                                                               |
|  63 | Browser                 | Eight fresh HTTPS/enforced-CSP flows cover all requested surfaces/errors/downloads/mobile/provider fakes; zero JS/CSP errors.                                                                            |
|  64 | Quality                 | Pint/test, frontend format/lint/types/build pass; final checks recorded below.                                                                                                                           |
|  65 | Cache inspection        | Isolated production config/route/view caches pass; migration/status/routes/events/schedules inspected.                                                                                                   |
|  66 | PHPStan                 | Only pre-existing UserFactory::withTwoFactor() missing return, line 49; not suppressed/fixed.                                                                                                            |
|  67 | Automated Stripe        | PASS: Http::fake/signatures/concurrency/domain provisioning and HTTPS handoff, not real provider.                                                                                                        |
|  68 | Automated Razorpay      | PASS: Http::fake/signatures/concurrency/domain provisioning and mocked checkout, not real provider.                                                                                                      |
|  69 | Stripe sandbox          | NOT RUN; no real user-provided sandbox credentials used.                                                                                                                                                 |
|  70 | Razorpay sandbox        | NOT RUN; no real user-provided sandbox credentials used.                                                                                                                                                 |
|  71 | External mail           | NOT RUN.                                                                                                                                                                                                 |
|  72 | External object storage | NOT RUN.                                                                                                                                                                                                 |
|  73 | Deployment restore      | NOT RUN; local schema drill only.                                                                                                                                                                        |
|  74 | Cleanup                 | Owned test infrastructure/fixtures/credentials/storage/caches/TLS scratch removed; exact final safety evidence below.                                                                                    |
|  75 | Known issues            | UserFactory PHPStan, optional fontaine, Composer CLI deprecations, untouched historical timezone mismatch; local dashboard capacity needs deployment-size measurement.                                   |
|  76 | External blockers       | Real host/TLS/PG/optional Redis/workers/scheduler/private storage/mail/backup+restore/monitoring/provider sandboxes/live accounts+webhooks/secrets/policies.                                             |
|  77 | Final status            | Code and launch are separate; code verification complete, evidence below, production launch remains NO.                                                                                                  |

## Final verification record

| Check                                           | Final result                                                                                                                                                                                                                             |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Targeted Phase 20 SQLite                        | 93 total / 93 passed / 0 skipped / 183 assertions / 0 failures                                                                                                                                                                           |
| Targeted isolated Phase 20 PostgreSQL           | 93 total / 93 passed / 0 skipped / 183 assertions / 0 failures                                                                                                                                                                           |
| Full PostgreSQL 20–10 + Commerce + Entitlements | 1306 total / 1305 passed / 1 documented Entitlement fixture skip / 4269 assertions / 0 failures                                                                                                                                          |
| Full Laravel                                    | 1667 total / 1492 passed / 175 skipped / 6445 assertions / 0 failures                                                                                                                                                                    |
| Payment concurrency                             | 8 independent-process races PASS; committed reservation and unlocked financial rows before external call confirmed                                                                                                                       |
| Notification concurrency                        | 7 scenarios, 32 assertions, mail failure handling PASS                                                                                                                                                                                   |
| Settings concurrency                            | 7 independent-process scenarios PASS                                                                                                                                                                                                     |
| Disposable restore                              | PASS, 11 exact table count/hash matches                                                                                                                                                                                                  |
| HTTPS/enforced-CSP browser                      | 8 fresh flows PASS; authentication, public/admin/settings/report/library, errors, actual maintenance, provider fakes, authorized exact download, unauthorized/guest/refunded denial, mobile; no JavaScript/CSP errors                    |
| Pint / Pint test                                | PASS                                                                                                                                                                                                                                     |
| Frontend format/lint                            | PASS, 161 formatted files / 134 linted files, zero warnings/errors                                                                                                                                                                       |
| TypeScript / production build                   | PASS; existing optional fontaine warning                                                                                                                                                                                                 |
| Composer / npm audits                           | Zero advisories/abandoned packages / zero vulnerabilities                                                                                                                                                                                |
| PHPStan                                         | One existing UserFactory::withTwoFactor() return.missing, line 49; no new errors                                                                                                                                                         |
| Production config/route/view caches             | PASS with isolated paths; temporary caches removed                                                                                                                                                                                       |
| Migration/status/routes/events/schedule         | Inspected; no pending local migrations; all six existing schedules retained                                                                                                                                                              |
| Controlled production-check                     | Correctly rejects local origin/TLS/cache/mail/schema/timezone/payout-policy/S3 gaps; no secret output, no provider/mail/storage/commercial writes                                                                                        |
| Secret scan                                     | No confirmed source credentials; two inspected false positives; real .env unchanged                                                                                                                                                      |
| Final cleanup/integrity                         | PASS: no Phase 20 temporary schemas; current_schema() = public; SHOW search_path = public; real .env digest unchanged; original commercial counts preserved; owned browser/TLS/cache/storage/credentials removed; user servers preserved |

Executed verification commands: `php artisan test --compact tests/Feature/PhaseTwentyTest.php`; `php tests/Support/verify-phase20-suite.php --payments-only`; `php tests/Support/verify-phase20-suite.php`; `php artisan test --compact`; `php artisan test --compact tests/Feature/AuthorOnboardingTest.php`; `php tests/Support/verify-phase20-restore.php`; `php tests/Support/verify-phase19-concurrency.php`; `php tests/Support/verify-phase16-concurrency.php`; `php tests/Support/verify-phase18-concurrency.php`; temporary guarded browser/performance runners; `vendor/bin/pint`; `vendor/bin/pint --test`; `npm run check`; `npm run types:check`; `npm run build`; `composer audit --format=json`; `npm audit --json`; `vendor/bin/phpstan analyse --no-progress`; isolated `config:cache`, `route:cache`, `view:cache`; `php artisan migrate --no-interaction`; `migrate:status`; `route:list --except-vendor`; `event:list`; `schedule:list`; controlled `marketplace:production-check`; read-only application baseline audit. No user development server was stopped.

The first resumed full Laravel run reported three production-manifest failures (1489 passed, 175 skipped); that failed result was not used as completion evidence. After the Blade entry correction the entire suite was rerun and passed as above. The prior first resumed PostgreSQL full run passed 1305 + one skip; final run reflects the separate bounded readiness connection. Intermediate browser hydration assertion timing was corrected by waiting for the actual heading; final fresh run exercises all flows, not a partial retry. No database failure was weakened/skipped.

### External launch blockers

All of the following require operator evidence before public launch:

1. Actual hosting/VPS, reviewed Nginx/FPM service configuration, production domain/TLS and trusted-edge ingress/header checks.
2. Production PostgreSQL verified TLS/grants/search_path/UTC plan, capacity, backup/PITR and historical-timezone migration decision if migrating existing data.
3. Production persistent cache/shared locks; real private Redis configuration if Redis is selected.
4. Supervised queue workers and scheduler heartbeat/recovery on actual hosting.
5. Real public/private object storage policies, signed URL authorization, versioning and object recovery.
6. External SMTP delivery, verified sender/domain and failed/uncertain delivery operation.
7. Automated encrypted off-server database/object/secret backup, deployment-environment whole-database restore drill and demonstrated RPO/RTO.
8. Monitoring, alert delivery/escalation and operational incident ownership.
9. Real Stripe and Razorpay sandbox payments, captured/failed/cancelled/replayed/delayed flows, account readiness and production live webhooks/secrets.
10. Reviewed public terms/privacy/legal/contact/support/refund information, explicit refund/payout policies and enabled currency/provider configuration.
11. Deployment-size capacity smoke/load and tuning informed by the measured admin dashboard latency; local fixture budgets are not a production SLA.

Automated Stripe contract: **PASS**. Automated Razorpay contract: **PASS**. Real Stripe sandbox: **NOT RUN**. Real Razorpay sandbox: **NOT RUN**. Production mail/object storage/TLS-hosting/monitoring/payment/backup-restore: **NOT RUN**.

No Phase 21 work, automatic provider refunds, new checkout features, extra financial ledger, storefront redesign or external deployment was undertaken.

PHASE 20 CODE STATUS: COMPLETE

PRODUCTION LAUNCH READY: NO
