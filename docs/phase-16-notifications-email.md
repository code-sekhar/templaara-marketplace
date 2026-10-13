# Phase 16 — Notification Center and transactional email

Templaara uses Laravel's native database and mail notification channels. Phases 1–15 remain authoritative: notifications consume committed domain histories, never decide payments, access, moderation, earnings, payouts, refunds or support. Fortify account/security mail is unchanged. No package was added and `.env` was not modified.

## Architecture and durable recovery

`NotificationFacts` projects authoritative identities from eight existing durable sources: paid Orders, RefundEvents, PayoutEvents, AuthorApplicationEvents, ProductLifecycleEvents (including revisions), AuthorEarningLedgerEntries, ProductReviewEvents and SupportTicketEvents. Existing after-commit events are fast wakeups through `DiscoverMarketplaceNotifications`; they are not treated as a durable message bus. Its failures are logged with safe source/class identifiers and cannot undo domain commits.

`NotificationRecovery` searches for unobserved sources from the persisted deployment epoch. For each source it atomically records its observation, one delivery per recipient/type, and an optional Laravel database notification. Recipient User locks serialize discovery against preference changes; a database composite unique key backstops duplicate listeners, reconciliation and concurrent workers. A failed discovery transaction retains an unobserved source for retry. Source observations also retain deliberate no-op decisions, such as internal notes or ownerless official products.

**Crash boundary:** if a domain transaction commits and the process dies before its listener writes anything, the original committed Order/history/ledger row remains discoverable. `notifications:reconcile` reconstructs delivery without replaying financial transactions. There is no high-water ID cursor that could skip a smaller ID committed after a larger ID. Candidate results are bounded per source (default 100, maximum 1,000), and observation identity is indexed. At higher volumes, a transactionally captured dedicated source outbox can replace these anti-joins without changing public notification contracts. Existing histories must not be pruned before recovery.

The singleton deployment epoch prevents a deployment from emailing every historical transaction. No retrospective bulk backfill is enabled. The testing-only configuration override supplies an earlier epoch for controlled fixtures; production uses the stored timestamp.

Purchase-ready is discovered only after a paid/completed Order has its recorded matching settled paid Payment and consistently provisioned usable access for all items. The existing qualification query is reused with support-period checking disabled. Missing provisioning remains recoverable rather than becoming a permanent observed no-op. Revoked/refunded or otherwise unusable access is not described as ready. This is one useful customer delivery moment; there is no duplicate payment-success plus entitlement-ready email. Provider failures are not invented or broadcast.

## Schema and PostgreSQL

Forward migration: `2026_10_06_060000_create_marketplace_notifications.php`.

| Table                       | Purpose                                                                                                                                                                                                                                                          |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `notification_epoch`        | Singleton activation timestamp; prevents historical email bursts.                                                                                                                                                                                                |
| `notifications`             | Laravel UUID primary key, notification class, User morph identity, explicit JSONB data, read timestamp and timestamps. Indexed recipient/type/read/date.                                                                                                         |
| `notification_preferences`  | Unique User/group, boolean in-app/email choices and timestamps. Eight controlled groups.                                                                                                                                                                         |
| `notification_observations` | Unique source table/row identity and observation time, including intentional no-op sources.                                                                                                                                                                      |
| `notification_deliveries`   | UUID identity, recipient FK, source/row/type unique identity, safe JSONB presentation facts, immutable channel snapshot, email state, attempts, due/queued/claim/sent times, UUID claim token and generic error code. Indexed state/due time and recipient/date. |

User references cascade **only on explicit permitted account deletion**. Existing domain retention guards/FKs still block commercial/support/moderation account deletion. Notifications/preferences alone do not make an otherwise deletable account permanently undeletable. There is no automatic pruning, unread deletion endpoint or destructive rollback; observations retain durable source processing identity.

PostgreSQL constrains controlled types/groups/sources, bounded attempts, delivery states, required sent/claim timestamps and User morph type. JSONB allow-list constraints reject unrecognized payload keys and require essential presentation fields. Triggers protect notification recipient/payload/creation identity, prohibit overwriting a non-null read timestamp, protect delivery identity/channel snapshots and retain terminal sent state. Application routes do not expose any operation for creating arbitrary system notifications.

## Controlled types and channels

`MarketplaceNotificationType` has 38 controlled types. Content and channel decisions live in `NotificationContent`, the enum and `NotificationPreferences`, rather than controllers.

| Group           | In-app types                                                                                                               | Email-capable types/default                                                              |
| --------------- | -------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Orders          | Purchase ready                                                                                                             | Purchase ready, enabled                                                                  |
| Refunds         | Requested, approved, processing, completed, failed, cancelled                                                              | Completed and failed, enabled                                                            |
| Author workflow | Application submitted/changes requested/approved/rejected; product submitted/changes requested/approved/rejected/published | Decisions and publication, enabled; submissions in-app only                              |
| Product updates | Submitted, changes requested, approved, rejected, published                                                                | Decisions and publication, enabled; submitted in-app only                                |
| Earnings        | Recorded and became available                                                                                              | In-app only; no per-sale email                                                           |
| Payouts         | Requested, approved, processing, paid, failed, cancelled                                                                   | Paid and failed, enabled                                                                 |
| Reviews         | New public verified review; seller response                                                                                | Optional email, disabled by default                                                      |
| Support         | New ticket, customer reply, seller/staff reply, resolved, staff/system closed, escalated/deescalated                       | New ticket, participant replies and resolved, enabled; remaining transitions in-app only |

All groups default to in-app enabled. **Purchase-ready, refund-completed and payout-paid are mandatory in-app**, regardless of group toggles. All marketplace email is configurable. Password resets, verification and other essential Fortify/account messages are outside these marketplace preferences and cannot be disabled here. An enabled email group does not cause email for in-app-only types. Earnings remain in-app-only even if their email checkbox is selected.

Preferences are snapshotted when a logical delivery is first recorded, under a recipient lock. Changes apply to future discoveries, not already recorded deliveries or retries. This defines the preference-change/dispatch race deliberately. Actual email routing uses the recipient's current stored User email at send time, never an email copied from browser input or payload.

## Domain integrations and wording

- Refund requested/approved/processing explicitly do not claim that money was returned. Only the immutable completed event says completion was confirmed. Failure/cancellation notices contain no refund reasons or provider details.
- Payout processing/approved are not called paid. Only the paid event confirms the completed payout. No bank identity, destination, customer or payment information is copied.
- Author application decisions use the application applicant. Product/revision decisions use the actual product owner, with distinct `product_*` and `update_*` types. Revision actions open the exact existing authorized revision workspace. Official ownerless products create no fake seller notification.
- Earnings use retained ledger movements, not inferred sales counts; no customer identity or commercial details are copied.
- New review notices require current public verified eligibility at discovery. Hidden, withdrawn or unqualified reviews are suppressed. A new seller response notifies the customer without copying its body. Edits/moderation changes do not generate redundant messages. Earlier historical notices are retained if visibility changes later; notification possession grants no access.
- Support uses append-only ticket events and the immutable historical seller. Customer and seller/staff replies notify the opposite participant; self-directed notices are suppressed. **Internal notes are always no-ops** and never notify participants. Staff/system closure is generic, including completed-refund closure, and reveals no refund explanation. Official tickets have no fake seller or broadcast to arbitrary staff; real staff continue using their authorized queue.
- Multi-role users receive one logical notice per domain recipient, not one per role. No global staff purchase/refund/support broadcasts were added.

## Routes, authorization and presentation

All routes require the existing authentication; private/no-store headers apply.

| Method | Route                        | Name                               |
| ------ | ---------------------------- | ---------------------------------- |
| GET    | `/notifications`             | `notifications.index`              |
| POST   | `/notifications/read-all`    | `notifications.read-all`           |
| POST   | `/notifications/{uuid}/read` | `notifications.read`               |
| GET    | `/settings/notifications`    | `notifications.preferences`        |
| PUT    | `/settings/notifications`    | `notifications.preferences.update` |

Read mutations are throttled and recipient-scoped. Guessed UUIDs return 404; admin roles do not bypass personal ownership. Single/all reads are idempotent, share a recipient lock, and do not overwrite the first read time. Opening a destination does not silently mark a notification read. Safe links still pass normal destination authorization.

The controller explicitly serializes UUID, category, title, short message, validated internal action, occurrence/read times. It never exposes Laravel's raw model/data envelope or delivery/source/recipient internals. Center pagination is 20 records, deterministic created-time/UUID descending, with all/unread filtering and query retention. Shared props expose only an authoritative unread count, not recent raw notifications.

`NotificationAction` permits only known account/author destinations and exact authorized product-update workspace paths. External/admin redirects, encoded separators, query redirects and unknown routes are rejected. Generated links use existing application routes; no new destination grants exist.

Vue pages/components:

- `notifications/Index.vue`: responsive card/list center, empty state, category labels, absolute local dates, unread styling, safe action links and explicit single/all read buttons.
- `settings/Notifications.vue`: existing settings layout, eight channel groups, mandatory-in-app explanation, validation and save state.
- `NotificationBell.vue`: existing sidebar header, alternate app header and marketplace shell; zero hides badge, 1–99 exact, 100+ `99+`, accessible label.
- `types/notifications.ts` and shared-prop typing; generated Wayfinder routes/actions.

No polling loop, WebSocket or parallel frontend state store was introduced. Counts refresh through normal Inertia navigation.

## Email delivery, failure and idempotency

`MarketplaceNotification` uses Laravel `MailMessage` and the existing responsive notification template: consistent subject, short plain summary, sign-in instruction and a safe application action. It does not copy support/review bodies, attachments, license secrets, billing information, provider payloads, refund reasons or bank details. URLs use configured `APP_URL`; private signed/object URLs are never generated. A stable logical-delivery Message-ID assists transport diagnosis; it is not claimed to enforce SMTP deduplication.

`DeliverNotificationEmail` is queued on a dedicated asynchronous connection/queue. Discovery never contacts a mail transport. Queue submission is separate and recoverable; a timestamp reservation limits duplicate queue entries while allowing recovery of the crash-before-enqueue window after five minutes. Actual delivery claims a pending row transactionally with a UUID token, commits that claim, then sends mail **outside every database transaction**. Duplicate jobs cannot independently claim a sending/sent row. Success records sent timestamp; a worker also refuses to run mail within a database transaction.

Tries: **5**. Backoff: **60, 300, 900, 1,800 seconds**. Job timeout: **45 seconds**. SMTP timeout defaults to **30 seconds** through `MAIL_TIMEOUT`; database queue retry-after defaults to **90 seconds**, longer than the worker timeout. Exhausted queue attempts become failed. Delivery errors store only generic codes and never undo domain truth.

**External mail cannot be guaranteed exactly once.** A transport may accept a message and then fail before acknowledgement. Bounded transport-error retries can therefore result in an external duplicate; the logical in-app notification and delivery row remain exactly one. A process killed while sending leaves an ambiguous claim; after five minutes it becomes `uncertain` and is **not automatically resent**. An operator checks provider logs/stable Message-ID before explicitly authorizing retry. The phase does not hide this SMTP acknowledgement limitation.

## Recovery and production configuration

```sh
php artisan notifications:reconcile
php artisan notifications:reconcile --limit=1000
# Only after checking provider delivery for a failed/uncertain UUID:
php artisan notifications:reconcile --retry=<delivery-uuid>
```

The command recovers durable discoveries, queues pending email and identifies stale claims. It never replays payment/refund/payout transactions. Failed/uncertain retry is explicit, scoped to one UUID; sent work cannot be reset through this command. Configure an application scheduler to run `schedule:run` each minute; the reconciliation schedule uses `withoutOverlapping`. Existing schedules are preserved.

Required production settings belong in secret management/uncommitted environment configuration:

- `APP_URL`: canonical HTTPS application origin, never a request-controlled host.
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, provider-supported scheme; `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; `MAIL_TIMEOUT=30`.
- `NOTIFICATION_QUEUE_CONNECTION=database` by default. A configured Redis/SQS/Beanstalk connection can be selected; sync/deferred/background/null connections are refused for this email dispatcher. No Redis or external infrastructure is assumed/installed.
- `NOTIFICATION_QUEUE=notifications` and database queue/failed-job configuration. Existing jobs/failed_jobs tables are reused.

Example database worker (adapt process manager and deployment paths):

```sh
php artisan queue:work database --queue=notifications --tries=5 --timeout=45 --sleep=3
php artisan queue:failed
```

Use supervised systemd/Supervisor/container workers, restart them on deployment and monitor queue health, failed jobs, delivery `failed`/`uncertain` counts and reconciliation errors. Recovery redispatches durable pending work independently of failed queue jobs. Avoid a blind `queue:retry all` for ambiguous mail. Use a shared scheduler lock backend for multi-instance scheduling; database uniqueness and delivery claims remain the final concurrency backstop. Local `log` or `array` mail transports do not deliver external mail. Automated/browser verification uses only controlled local transports.

## Inventory

Created:

- `app/Enums/MarketplaceNotificationType.php`.
- `app/Services/Notifications/{NotificationFacts,NotificationContent,NotificationPreferences,NotificationRecovery}.php`.
- `app/Notifications/{MarketplaceNotification,NotificationAction}.php`.
- `app/Jobs/DeliverNotificationEmail.php`, `app/Listeners/DiscoverMarketplaceNotifications.php`.
- `app/Http/Controllers/Notifications/NotificationController.php`, `routes/notifications.php`, `config/notifications.php`.
- One forward migration named above.
- Vue pages/component/types named above.
- `tests/Feature/PhaseSixteenTest.php`, `tests/Support/verify-phase16-suite.php`, `verify-phase16-concurrency.php`.
- This document and generated notification Wayfinder route/action helpers.

Modified:

- `AppServiceProvider.php` (committed-event wakeup), `HandleInertiaRequests.php` (unread count), `routes/web.php`, `routes/console.php`.
- `config/mail.php` (bounded SMTP timeout), `config/inertia.php` (sensitive page devtools exclusions), `.env.example` (nonsecret configuration examples).
- Existing app/sidebar/marketplace headers and settings navigation; shared TypeScript props; generated helper parent exports and production build output.

No existing domain workflow was rewritten. No financial/access tables were reset, no role grants changed, no real credentials were added, and no existing test was removed.

## Verification

```sh
php artisan migrate --no-interaction
php artisan migrate:status
php artisan route:list --except-vendor
php artisan event:list
php artisan schedule:list
php artisan test --compact
php artisan test --compact --filter=PhaseSixteenTest
php tests/Support/verify-phase16-suite.php --notifications-only
php tests/Support/verify-phase16-suite.php
php tests/Support/verify-phase16-concurrency.php
vendor/bin/pint
vendor/bin/pint --test
npm run check:fix
npm run check
npm run types:check
npm run build
vendor/bin/phpstan analyse --no-progress
```

The PostgreSQL runners verify both exact `current_schema()` and exact `SHOW search_path` before migration/fixture/worker writes. Random disposable schemas and private cached configuration/storage are cleaned in finally blocks. The browser router checks scope on every request. No RefreshDatabase runs against application public schema, no commercial table is truncated, and no integrity trigger is globally disabled. Browser tools remain outside application manifests.

Completed results and the 41-point completion report are recorded below.

## Deferred

No Phase 17, marketing/newsletters, SMS, WhatsApp, push, live polling/WebSockets, AI, bulk campaigns, notification billing or helpdesk integration. Optional recent-notification dropdown, destructive pruning and richer delivery operations UI are deferred. Notification storage is not authorization or a replacement for domain history.

## Completion report (41 requested points)

| #   | Deliverable               | Implementation/result                                                                                                                                                                                                                       |
| --- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Files created             | Complete inventory above: registry, projector/content/preferences/recovery, native notification, queue job/listener, controller/routes/config/migration, Vue pages/bell/types, tests/runners/documentation and generated Wayfinder helpers. |
| 2   | Files modified            | Existing provider/shared props/routes/console, mail timeout/Inertia/example configuration, application headers/settings navigation, shared typing and generated/build output.                                                               |
| 3   | Migrations                | One forward migration; no historical domain migration edited. Applied locally and recorded as run.                                                                                                                                          |
| 4   | Notification schema       | Native UUID database notifications, preferences, immutable logical deliveries, source observations and activation epoch.                                                                                                                    |
| 5   | Delivery architecture     | Native Laravel database/mail channels; explicit presentation facts, recipient/type registry, independent asynchronous email.                                                                                                                |
| 6   | Durable delivery/recovery | Retained committed domain rows recover the event-listener crash gap; discovery/observation/delivery/in-app write atomic, email enqueue independently recoverable.                                                                           |
| 7   | Types                     | 38 controlled marketplace types; no browser-selected arbitrary notification type.                                                                                                                                                           |
| 8   | Channels                  | Centralized policy; in-app default, conservative high-value email, optional review email, no earning-sale email.                                                                                                                            |
| 9   | Customer                  | Purchase-ready, refund states, support replies/resolution/closure and seller review response.                                                                                                                                               |
| 10  | Author                    | Applicant/product/update decisions, ledger earning movements, payout states, eligible reviews and historical-seller support activity.                                                                                                       |
| 11  | Staff                     | No arbitrary broadcasts; official products/tickets do not invent owners; staff use existing operational workspaces.                                                                                                                         |
| 12  | Refunds                   | Requested/approved/processing do not claim settlement; only completed confirms completion. No private reasons copied.                                                                                                                       |
| 13  | Payouts                   | Paid only after paid truth; processing/approved remain unpaid wording; no destination copied.                                                                                                                                               |
| 14  | Support                   | Opposite participant only, safe summary, no body/files/notes, generic refund-driven closure.                                                                                                                                                |
| 15  | Reviews                   | Public-qualified seller notice and customer response notice; hidden/unqualified suppression; no response body copied.                                                                                                                       |
| 16  | Product workflow          | Initial/revision types distinct; owner recipient; revision links open exact authorized workspace.                                                                                                                                           |
| 17  | Preferences               | Eight server-validated User-scoped groups in existing settings layout.                                                                                                                                                                      |
| 18  | Mandatory policy          | Purchase-ready/refund-completed/payout-paid mandatory in-app; marketplace email configurable; Fortify/security unaffected.                                                                                                                  |
| 19  | Center                    | Paginated deterministic owned cards, all/unread, empty state, dates, safe actions, mark-read states.                                                                                                                                        |
| 20  | Bell                      | Authoritative shared unread count in existing authenticated shells; zero hidden, exact 1–99, capped 99+.                                                                                                                                    |
| 21  | Email                     | Native responsive MailMessage, short professional content and configured application links; no private content.                                                                                                                             |
| 22  | Queue                     | Dedicated asynchronous database default; existing jobs/failed_jobs; no Redis/infrastructure assumption.                                                                                                                                     |
| 23  | Retry/backoff             | Five attempts; 60/300/900/1,800-second backoff; 45-second job, 30-second SMTP timeout; exhausted/uncertain states.                                                                                                                          |
| 24  | Idempotency               | Unique source/row/type/recipient, recipient lock, immutable channel snapshot, row claim token; stable Message-ID.                                                                                                                           |
| 25  | Privacy                   | Explicit payload/DTO allow-lists, minimal summaries, no account/provider/license/file/billing/bank/private-note data.                                                                                                                       |
| 26  | Ownership/IDOR            | Personal scope even for admins; guessed UUID 404; destination retains independent authorization.                                                                                                                                            |
| 27  | Multi-role                | Domain context selects one User, never role fan-out.                                                                                                                                                                                        |
| 28  | PostgreSQL                | Controlled values/JSON keys/required fields, uniqueness, FK/indexes and immutable payload/recipient/read/sent guards.                                                                                                                       |
| 29  | Reconciliation            | Scheduled every minute with overlap prevention; bounded discovery, pending dispatch and explicit failed/uncertain UUID retry.                                                                                                               |
| 30  | Events/listeners          | Existing committed events act as wakeups; failed wakeup falls back to source recovery; no financial workflow rewrite.                                                                                                                       |
| 31  | Tests                     | 121 Phase 16 cases, including nine PostgreSQL-only integrity cases. Existing tests retained.                                                                                                                                                |
| 32  | PostgreSQL tests          | Isolated Phase 16: **121 passed, 342 assertions**. Full regression result below.                                                                                                                                                            |
| 33  | Concurrency/delivery      | **Seven independent-worker scenarios, 32 assertions passed**, including a controlled local transport failure.                                                                                                                               |
| 34  | Browser                   | **All six requested flows passed**, plus mobile/privacy/JavaScript and local-only mail checks.                                                                                                                                              |
| 35  | Full regression           | Laravel **994 passed, 166 skipped, 5,093 assertions**; includes all prior phases. PostgreSQL result below.                                                                                                                                  |
| 36  | Formatting/frontend       | Pint, formatting/lint, TypeScript and production build passed.                                                                                                                                                                              |
| 37  | PHPStan                   | One known pre-existing UserFactory::withTwoFactor missing-return error; no Phase 16 errors.                                                                                                                                                 |
| 38  | Production                | APP_URL/mail/from/SMTP timeout, asynchronous queue, supervised worker, failed jobs, scheduler and recovery documented above.                                                                                                                |
| 39  | Known issues              | Pre-existing PHPStan missing return and optional fontaine warning; SMTP acknowledgement ambiguity explicitly handled/documented.                                                                                                            |
| 40  | Deferred                  | No Phase 17, SMS/WhatsApp/push/marketing/WebSockets/AI/helpdesk/billing or automatic destructive pruning.                                                                                                                                   |
| 41  | Decisions                 | Durable existing histories as recovery boundary, deployment epoch, immutable preference snapshot, recipient-scoped native notifications, conservative financial wording, no staff broadcasts and explicit ambiguous-send handling.          |

### Exact verification results

| Check                             | Result                                                                                                                                                                                                                                                                                                                                                                                      |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full Laravel                      | 1,160 total: **994 passed, 166 skipped, 5,093 assertions**, 223.151 seconds.                                                                                                                                                                                                                                                                                                                |
| Isolated Phase 16 PostgreSQL      | **121 passed, zero skipped, 342 assertions**, 53.871 seconds.                                                                                                                                                                                                                                                                                                                               |
| Full PostgreSQL                   | 799 total: **798 passed, one legacy skip, 2,900 assertions**, 336.194 seconds. Phase 16/15/14/13/12/11/10, Commerce and Entitlement suites.                                                                                                                                                                                                                                                 |
| Concurrency/delivery              | **Seven scenarios, 32 assertions passed**, plus controlled local transport-failure verification; no external email.                                                                                                                                                                                                                                                                         |
| Browser                           | **All six requested flows passed**: customer purchase/badge/owned action/read; author update changes/approval/publication; support participant notices/internal-note exclusion; accurate refund states/completion; payout paid truth/privacy; preference suppression with mandatory in-app preservation. Mobile and no JavaScript errors; queued mail drained through array transport only. |
| Pint                              | Final `--test` passed.                                                                                                                                                                                                                                                                                                                                                                      |
| Frontend formatting/lint          | 148 formatted; 125 linted clean.                                                                                                                                                                                                                                                                                                                                                            |
| TypeScript                        | Passed.                                                                                                                                                                                                                                                                                                                                                                                     |
| Production build                  | Passed in **3.02 seconds**; optional pre-existing fontaine warning.                                                                                                                                                                                                                                                                                                                         |
| PHPStan                           | Exactly the pre-existing `database/factories/UserFactory.php:49` missing return in `withTwoFactor()`. No new errors/suppressions.                                                                                                                                                                                                                                                           |
| Migration/routes/events/schedules | Migration applied successfully; **198 non-vendor routes**; committed-event integration and reconciliation schedule listed successfully.                                                                                                                                                                                                                                                     |

The legacy PostgreSQL skip is the existing SQLite-only partial Entitlement fixture, which attempts deletion of retained commercial licenses. Portable skips include existing PostgreSQL-only cases and the pre-existing legacy skip. They are not Phase 16 failures.

Browser retries preserved domain rules: the harness was corrected to wait for actual mutations, treat Add Feedback Item as a local UI control, and complete its author payout before attempting a refund that was correctly blocked by Phase 13's unpaid-payout safeguard. No existing financial validation was weakened.

Final cleanup verified `current_schema()=public` and exact `SHOW search_path=public`, with no Phase 16 temporary schemas. Disposable schemas, cached configuration, random login credentials, private/public fixture storage, public symlinks and error/screenshot artifacts were removed; reusable external verification scripts remain outside application manifests. The user-started npm dev server was preserved. Application commercial/support/review/notification counts remain zero, the existing commission policy remains one and notification epoch is one; application users/catalog/products were not replaced or reset. No real external email was sent during verification.

Phase 16 is complete. Stop here; Phase 17 has not begun.
