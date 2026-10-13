# Phase 14 — Verified customer reviews and ratings

Templaara now supports one verified customer review per purchased software product, one seller response, customer withdrawal/restoration, and permission-based moderation. Phases 1–13, Fortify authentication, PostgreSQL, the existing Inertia application and commercial storage boundaries are preserved. No support tickets, notification delivery, helpful votes or public author stores were added.

## Schema and retained identities

Forward migration `2026_10_06_040000_create_verified_product_reviews.php` adds three tables; historical migrations are unchanged.

| Table                      | Fields and constraints                                                                                                                                                                                                                                                                                                                                                                                           |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `product_reviews`          | Internal ID, unique public UUID, product, customer, bound verified OrderItem, rating, optional title, plaintext body, published/hidden status, verification flag, published/edited/withdrawn/hidden timestamps, increasing event sequence, timestamps. Unique `(customer_user_id, product_id)`. Restrictive product/user/purchase FKs. Product/status/verification/withdrawal/ID index and status/created index. |
| `product_review_responses` | Internal ID, unique public UUID, unique review FK, seller actor FK, plaintext body, published/edited timestamps, review audit sequence, timestamps. Restrictive FKs.                                                                                                                                                                                                                                             |
| `product_review_events`    | Review FK, nullable actor FK for system reconciliation, unique per-review sequence, controlled event type, optional reason, occurrence timestamp. Append-only; no mutable `updated_at`. Restrictive FKs.                                                                                                                                                                                                         |

Customer reviews are distinct from the existing Phase 4 product-submission review feedback and decisions. No money, license secrets, artifact paths or financial snapshots are copied into reviews. Historical versions come from the retained bound OrderItem, never the current release label. Rebinding to another qualifying purchase updates that internal binding and its corresponding purchased-version label; review text/rating are untouched.

`Product::customerReviews()` links the domain. Reviews relate to product, customer, purchase, response and events. Models guard all mass assignment, retain records on deletion, and prevent changing identity fields. Product deletion explicitly checks retained customer reviews. Account deletion refuses customers, response authors and moderation actors with retained review history; existing commerce/author/refund retention remains in place. A future legally approved anonymization/retention process needs a reviewed forward migration rather than destructive rollback. Migration `down()` deliberately refuses to erase history.

## Verified purchase qualification

`ReviewEligibility` is the centralized application query used for writes, entry points and live public visibility. PostgreSQL has the matching `templaara_review_purchase` integrity backstop. Qualification requires:

- A customer-owned OrderItem for this Product in a completed, paid order with a paid timestamp.
- The order's settled Payment is paid, belongs to that order and has matching amount and currency.
- A provisioned active, unrevoked, unexpired Entitlement and License with matching customer, order, item, product, offer and release relationships.
- The entitlement's source artifact belongs to the purchased release; the release belongs to the product and its version matches the immutable OrderItem version.
- No completed refund claim for that particular OrderItem.
- The buyer is not the product's owner. Admin users cannot create internal ratings for Templaara Official products.

Merely having a cart, pending/failed order, role, downloaded file or forged `is_verified` flag is insufficient. Downloading or activating the license is not required. A different product purchase never qualifies this product. Qualification uses historical purchased identity and current entitlement/license validity, not whether a newly sold offer or release remains commercially available today.

For multiple qualifying purchases, choose the oldest `paid_at`, then lowest OrderItem ID. The database canonical uniqueness never changes across repeated purchases, withdrawal or refunds.

## Refund interaction and repurchase

`RefundWorkflow` calls review reconciliation inside the same completion transaction, after all selected item claims and access revocations are applied. No behavior changes at refund request, approval, processing, failure or cancellation. Another item's refund does not affect this review.

If an alternate purchase remains, rebind the existing verified review deterministically and append `verification_restored` with a system reason. If none remains, set `is_verified=false` and append `verification_lost`; retain rating, body, response and audit history. Public lists, badges and aggregates immediately exclude the review. Financial compensation, original payment/order records, author earnings, payouts, entitlement/download and license behavior remain Phase 13's responsibility.

Repurchase does **not** silently republish an unqualified review. The customer uses **Restore Review** (or a valid edit of the existing review) to requalify and rebind it. There is still one review and one vote. Withdrawal must be restored before editing. Restoration never removes an admin hide. Public queries independently recheck live qualification, so a stale verification flag cannot expose a revoked/expired purchase.

## Lifecycle, authorization and input

New qualified reviews publish immediately. Two independent visibility controls retain clear ownership:

- Moderation `status`: `published` or `hidden`, controlled by `reviews.manage`.
- Customer `withdrawn_at`: withdrawn/restored by the review's customer.

Public visibility additionally requires retained verification and current qualification. A customer may edit rating/title/body while qualified and not withdrawn, including editing a hidden review without unhiding it. Customers may withdraw their own review even after qualification loss. Restoration requires a qualifying purchase. Admin restore clears only the moderation restriction; it never clears withdrawal or manufactures purchase qualification.

`ReviewWorkflow` is the transaction boundary. It derives all actor identities from authenticated users and all verification from real purchases. It allow-lists customer fields, accepts no status/ownership/aggregate/verification assignment, and uses `ProductReviewPolicy` for mutations. Ownership IDOR returns 404. No general Gate bypass gives admins authority to rewrite another customer's review.

Validation: rating integer 1–5; title optional plaintext up to 120 characters; body required trimmed plaintext 20–5,000 characters; response required trimmed plaintext 2–3,000 characters. HTML markup is rejected, malformed arrays produce validation errors, and Vue renders escaped interpolation with preserved line breaks, never `v-html`. Hiding requires a 5–2,000 character reason; restoration and all actions create durable events. Mutation routes use an authenticated per-user 30/minute `review-write` limiter and private/no-store response headers.

## Seller response and official products

The current author owner with the existing product-view permission can respond to a currently public, qualified review of their product. The unique review FK permits one response. Its original author may edit it while still the authorized seller; another seller/admin cannot take over an existing response. Seller response authority never permits customer rating/text changes or moderation.

An admin with `reviews.manage` may respond to an ownerless `origin=templaara` product; the public seller label is **Templaara**, without a fake user account. Its admin actor remains internal. Hidden, withdrawn or unqualified reviews hide their responses and disappear from the author workspace. Reviewers gain no new role permissions in this phase. Existing `reviews.view` grants read-only administration; `reviews.manage` grants moderation. Official response additionally requires the existing admin role deliberately.

## Audit, database integrity and concurrency

Events are `created`, `edited`, `withdrawn`, `restored_by_customer`, `hidden_by_admin`, `restored_by_admin`, `verification_lost`, `verification_restored`, `response_created`, `response_edited`. Each mutation increments the review sequence and inserts the corresponding append-only event in the same transaction. The event records actor/time and moderation/system reason, but does not copy sensitive payloads or every historical body version.

PostgreSQL checks controlled states, rating/body limits and timestamp consistency. Triggers protect identities, bind real customer/product purchases, require current qualification for verification/content edits, retain rows, constrain response ownership, enforce event actor/state/permission consistency and forbid event edits/deletes. Deferred constraint triggers require complete sequenced audit and a matching response event at commit. Verification is checked again at commit. Canonical and response uniqueness are database-enforced.

Normal lock order is buyer User → Product → canonical Review → Response. Refund completion already locks the buyer and product before reconciliation. The shared buyer row serializes creation, moderation, customer mutations, responses and completed refunds; transactions retry deadlocks up to three times. Conflicts produce useful validation errors instead of sensitive SQL output. Customer edits only update customer content, so racing moderation cannot unhide a review. Withdrawal and hide remain independent regardless of winner order.

The standalone worker runner exercises six real independent-process races against committed PostgreSQL fixtures: duplicate creation, creation versus completed refund, hide versus edit, duplicate response, two completions against alternate-purchase rebinding, and withdrawal versus hide. It verifies canonical counts, public qualification, final states and integrity rather than only response codes.

## Routes and workspaces

| Workspace  | Routes                                                                                                                                                                                               |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Customer   | `GET /account/reviews`; `GET/POST /account/reviews/products/{product:slug}`; `GET/PUT /account/reviews/{review:public_uuid}`; `POST .../{review}/withdraw`, `restore`.                               |
| Author     | `GET /author/reviews`, `GET .../{review}`, `POST .../{review}/respond`, `edit_response`. Author role and current ownership required; queue contains only public qualified reviews of owned products. |
| Moderation | `GET /admin/reviews`, `GET .../{review}` with `reviews.view`; `POST .../{review}/hide`, `unhide`, `respond`, `edit_response` with `reviews.manage` plus policy checks.                               |
| Public     | Existing product detail route, with `review_sort=newest                                                                                                                                              | highest_rating | lowest_rating`and`reviews_page`. Existing catalog/marketplace routes and visibility remain unchanged. |

Customer history is owner-scoped. Admin list filters search, product slug, customer/author name, status, rating, date range and answered/unanswered; author filters cannot expand ownership. Lists paginate 15; audit detail paginates 20. Existing sidebar adds **My Reviews**, **Customer Reviews** and **Review Moderation** through the current authorization helpers. There is no duplicate application or sidebar. Entry links appear in qualifying library purchases, paid order items and product detail. Existing canonical reviews lead to private history even when no longer qualified.

`reviews/Index.vue` and `reviews/Form.vue` reuse the application layout and shared components, with pending controls, validation errors, existing flash toast convention, empty state, confirmations and pagination. Form presents customer, seller or moderation actions according to server-provided capabilities. Backend policy/qualification always remain authoritative.

## Storefront, aggregation and privacy

`ProductRatingSummary::publicQuery()` defines the eligible public review boundary. Both detail summaries and lightweight product-card subqueries use it. Ratings use integer sums/counts and Brick Math decimal division with half-up two-decimal rounding; currencies and product prices play no part. Distribution includes counts for each star 1–5. Zero reviews produce `average=null`, `count=0`; cards show no fake stars. No rating sorting, sales/popularity statistics or fabricated review schema were added.

Product detail shows a summary, distribution and ten reviews per page, sorting newest/highest/lowest with deterministic published-time and ID tie-breaks. Pagination retains sorting. Product cards load only count/sum correlated queries, not review bodies/history/purchases; public detail eagerly loads only the relationships needed for its paginated review projection. Library/order entry checks are batched by product to avoid per-item qualification queries.

`ReviewPresentation` explicitly allow-lists public UUID, rating/title/body, privacy-safe reviewer name (first name plus last initial; email-like display names use a generic label), verified badge, purchased version, published/edited dates and safe response text/seller/date. Public data excludes emails, customer/user/purchase IDs, order numbers, amounts, billing, payment/provider identifiers, license keys, artifact paths/disks/checksums, refund reasons/references, financial adjustments and moderation audit. Review and response text are user-authored; intentionally typed personal information in that text is not silently rewritten.

The customer and moderator can see retained review state and safe paginated audit reasons; product sellers do not receive private financial/moderation history. Moderators operate on UUIDs and capabilities without exposing purchaser contact or commercial internals. No owner email, private download URL or license secret is needed to read or moderate a review.

## Events and deferred features

Seven `App\Events\Reviews` domain events implement `ShouldDispatchAfterCommit`: `ProductReviewCreated`, `ProductReviewUpdated`, `ProductReviewWithdrawn`, `ProductReviewHidden`, `ProductReviewRestored`, `ProductReviewResponseCreated`, `ProductReviewResponseUpdated`. Each contains only a readonly internal review ID. Rolled-back work dispatches nothing. Verification loss/rebinding remains an append-only system audit event inside the refund transaction. No delivery listener, email provider, notification table or message sending was added.

Notifications, helpful votes, public author stores, review reports/appeals, full historical content snapshots, support tickets and Phase 15 are deferred. PostgreSQL remains authoritative; SQLite provides portable validation/policy/UI regression coverage, with PostgreSQL-only integrity cases explicitly skipped.

## File inventory

Created:

- `app/Enums/ProductReviewStatus.php`; `app/Models/ProductReview.php`, `ProductReviewResponse.php`, `ProductReviewEvent.php`; `app/Policies/ProductReviewPolicy.php`.
- `app/Services/Reviews/ReviewEligibility.php`, `ReviewWorkflow.php`, `ReviewPresentation.php`, `ProductRatingSummary.php`.
- `app/Http/Controllers/Reviews/ReviewController.php`; `routes/reviews.php`; the forward migration listed above.
- Seven `app/Events/Reviews/ProductReview*.php` events listed above.
- `resources/js/components/reviews/ReviewContent.vue`, `ProductReviews.vue`; `resources/js/pages/reviews/Index.vue`, `Form.vue`; `resources/js/types/reviews.ts`.
- `tests/Feature/PhaseFourteenTest.php`; `tests/Support/ReviewFixtures.php`, `verify-phase14-suite.php`, `verify-phase14-concurrency.php`; this document.
- Generated Wayfinder `resources/js/routes/{account,author,admin}/reviews/index.ts` and `resources/js/actions/App/Http/Controllers/Reviews/{index.ts,ReviewController.ts}`.

Modified:

- `routes/web.php`, `app/Providers/AppServiceProvider.php`, `app/Models/Product.php`, `app/Services/Products/Files/DeleteProduct.php`, `app/Http/Requests/Settings/ProfileDeleteRequest.php`.
- `app/Services/Refunds/RefundWorkflow.php`, `app/Services/Marketplace/MarketplaceQuery.php`, `PublicProduct.php`, `app/Http/Controllers/MarketplaceController.php`.
- `app/Services/Entitlements/LibraryPresentation.php`, `app/Http/Controllers/Account/LibraryController.php`, `app/Services/Commerce/OrderPresentation.php`.
- `resources/js/components/AppSidebar.vue`, `resources/js/components/marketplace/ProductCard.vue`, `resources/js/pages/marketplace/Show.vue`, `resources/js/pages/library/Index.vue`, `Show.vue`, `resources/js/pages/orders/Show.vue`.
- `resources/js/types/marketplace.ts`, `library.ts`, `commerce.ts`, `config/inertia.php`; generated Wayfinder parent barrel exports/build output where regenerated.

No packages, RBAC grants, production credentials, `.env` values, old migrations or unrelated authentication code were changed. Browser verification scripts and screenshots live outside application manifests in the private external Phase 14 verification cache; disposable credentials/configuration/storage are removed afterward.

## Commands and verification

```sh
php artisan migrate --no-interaction
php artisan route:list --except-vendor
php artisan event:list
php artisan schedule:list
php artisan test --compact
php tests/Support/verify-phase14-suite.php --reviews-only
php tests/Support/verify-phase14-suite.php
php tests/Support/verify-phase14-concurrency.php
vendor/bin/pint
vendor/bin/pint --test
npm run check:fix
npm run check
npm run types:check
npm run build
vendor/bin/phpstan analyse --no-progress
```

The PostgreSQL runners generate a random private schema and verify both `current_schema()` and the **exact** `SHOW search_path` before migrations/fixtures/worker writes. Regression tests run in disposable transaction-scoped copies, without `RefreshDatabase` against the application schema. Worker fixtures commit only inside their disposable schema. The external browser router applies the same two assertions on every request, uses private cached configuration and isolated public/private storage, and never edits `.env`. Cleanup only removes validated schema/cache/storage/symlink scopes.

### Final completed results

| Check                               | Exact result                                                                                                                                                                                                                                                                                                              |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full Laravel suite                  | **905 total: 777 passed, 128 skipped, 4,447 assertions**, no failures; 167.077 seconds. Existing SQLite/optional-feature skips remain, with 17 additional PostgreSQL-only Phase 14 skips.                                                                                                                                 |
| Isolated PostgreSQL Phase 14        | **91 passed, 0 skipped, 372 assertions**, no failures; 56.263 seconds.                                                                                                                                                                                                                                                    |
| Full isolated PostgreSQL regression | **544 total: 543 passed, 1 skipped, 2,216 assertions**, no failures; 202.621 seconds. Includes Phase 14, Phase 13, Phase 12, Phase 11, Phase 10, Commerce and Entitlement suites. The existing skipped legacy Entitlement fixture deletes commercial licenses and is SQLite-only.                                         |
| Independent PostgreSQL concurrency  | **6 scenarios, 30 assertions, all passed** on the final rerun.                                                                                                                                                                                                                                                            |
| Browser                             | Previously completed full real UI purchase/payment → review → response → edit → hide/restore → refund → repurchase/explicit restore → withdrawal/restore; mobile and guest privacy passed, **no JavaScript errors**. No browser-affecting implementation changes were made afterward, so the completed result was reused. |
| Pint                                | `vendor/bin/pint --test` passed.                                                                                                                                                                                                                                                                                          |
| Frontend check                      | Final documentation formatting applied; `npm run check` passed without warnings or lint errors.                                                                                                                                                                                                                           |
| TypeScript                          | `npm run types:check` passed.                                                                                                                                                                                                                                                                                             |
| Production build                    | Passed, 2.64 seconds; existing optional fontaine fallback warning remains.                                                                                                                                                                                                                                                |
| PHPStan                             | **Not clean:** exactly one known pre-existing error, `database/factories/UserFactory.php:49`, missing return in `withTwoFactor()`. No Phase 14 errors.                                                                                                                                                                    |
| Migration                           | `php artisan migrate --no-interaction` applied the Phase 14 forward migration successfully; subsequent status verification records it as run.                                                                                                                                                                             |
| Routes/events/schedules             | All three list commands completed; 168 non-vendor routes, existing scheduled jobs preserved.                                                                                                                                                                                                                              |
| Application data and cleanup        | Final `current_schema()=public`, exact `SHOW search_path=public`. No Phase 14 temporary schemas remain. Browser credentials/configuration/storage/symlink/error screenshot removed; only reusable external verification scripts remain. User-started npm dev server preserved.                                            |

The final public application counts are zero for orders, OrderItems, payments, entitlements, licenses, refunds/items/compensations/events/claims, author earnings/ledger, payouts/allocations/events, customer reviews, responses and review events. The existing commission policy count remains one. Synthetic fixtures were confined to disposable schemas. No application schema reset/truncation or application financial fixtures occurred.

The expanded test file has 91 dataset cases. It covers qualification/failed payment/identity consistency, protected payload fields, canonical repeat purchases, customer IDOR and lifecycle, separate moderation and withdrawal, all non-completed refund states, complete refund and alternate purchase/repurchase, own-product responses and official admin behavior, public serialization and rating/count/distribution, deterministic sorting and pagination, entry points, retention, safe events/rollback, throttling, and PostgreSQL direct-write integrity. Full previous-phase suites provide the refund/payout/accounting/update/license/download regressions. The earlier failures were malformed-input validation defects and test isolation/deferred-trigger defects resolved before these final runs; the results above are completed runs, not partial progress.

## Acceptance confirmation

All Phase 14 acceptance checks are confirmed by the implementation and completed tests/browser/worker results:

- Only reconciled verified paid purchasers qualify; self-review and admin internal official-product ratings are blocked.
- One canonical review and one response are database-enforced. Repeat purchases never add another vote; repurchase restores the retained review explicitly.
- Completed refunds remove qualification when no alternate remains; an alternate safely rebinds verification. Pending, approved, processing, failed and cancelled refunds do not remove qualification.
- Sellers respond only to their owned product's public reviews and cannot modify customer reviews. Moderation requires existing permissions.
- Customer withdrawal and moderation hiding remain independent. Hidden, withdrawn or currently unqualified reviews are absent from public rows and aggregates.
- Counts, exact decimal average and star distribution contain only eligible reviews. Zero-review cards show no fake rating. Review sorting/pagination is deterministic and retains query parameters.
- Reviewer attribution is privacy-safe; public review projections exclude contact, order/payment, license, artifact and private refund data.
- Phase 13 refunds, Phase 12 payouts, Phase 11 accounting and Phase 10 update/activation behavior continue passing alongside commerce and entitlement/download regressions.

## Completion report (33 requested points)

| #   | Deliverable             | Result                                                                                                                                                                                                |
| --- | ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Files created           | Complete inventory above: review models/enum/policy/services/controller/routes/events/migration, Vue components/pages/types, tests/runners, documentation and generated Wayfinder helpers.            |
| 2   | Files modified          | Complete inventory above: product retention, refund reconciliation, storefront/library/order projections, navigation/types, routing/provider and Inertia configuration.                               |
| 3   | Migrations              | One new forward migration, applied locally; historical migrations unchanged.                                                                                                                          |
| 4   | Review schema           | Canonical customer/product uniqueness, real OrderItem binding, separate moderation/withdrawal/verification and retained timestamps/audit sequence.                                                    |
| 5   | Purchase qualification  | Reconciled paid/completed order plus consistent active entitlement/license/release/artifact and no completed item refund.                                                                             |
| 6   | Multiple purchases      | Deterministic oldest paid timestamp then item ID; still one review/rating.                                                                                                                            |
| 7   | Refund interaction      | Same-transaction verification loss/rebinding at completion only; historical content retained.                                                                                                         |
| 8   | Repurchase behavior     | Explicit restoration or qualified edit of the existing review; withdrawal and admin hides respected.                                                                                                  |
| 9   | Lifecycle               | Publish immediately when qualified; moderation hide and customer withdrawal are independent visibility restrictions.                                                                                  |
| 10  | Customer edit/withdraw  | Ownership/qualification enforced; edit timestamps; retained withdrawal and controlled restore.                                                                                                        |
| 11  | Admin moderation        | Existing reviews.view/manage permissions; hide reason required; no customer-content editing authority.                                                                                                |
| 12  | Moderation audit        | Append-only sequenced actor/time/reason events with PostgreSQL mutation and deferred audit guards.                                                                                                    |
| 13  | Author response         | Current-owner policy, one response, original authorized responder edits; response hidden with review.                                                                                                 |
| 14  | Official behavior       | Real authorized admin responds publicly as Templaara; no fake owner accounts.                                                                                                                         |
| 15  | Self-review protection  | Product owners and admins rating official products cannot qualify.                                                                                                                                    |
| 16  | Rating aggregation      | Live eligible query, integer counts/sums, two-decimal exact arithmetic and full distribution.                                                                                                         |
| 17  | Storefront              | Real card summaries and paginated detail reviews; no fake zero-review rating or rating-based catalog sorting added.                                                                                   |
| 18  | Customer UI             | Library/order/product entry, private My Reviews, edit/withdraw/restore/history.                                                                                                                       |
| 19  | Author UI               | Owned-product qualified review workspace and one seller response.                                                                                                                                     |
| 20  | Admin UI                | Permission-based filtered/paginated moderation queue, detail/audit and official response.                                                                                                             |
| 21  | Privacy/security        | Explicit public allow-list, partial reviewer name, no commercial/customer secrets, escaped plaintext, IDOR and throttling.                                                                            |
| 22  | PostgreSQL integrity    | Rating/state/time checks, restrictive FKs, purchase identity, retained records, uniqueness, immutable audit and commit-time verification.                                                             |
| 23  | Events                  | Seven safe-ID after-commit events; notifications/delivery deferred.                                                                                                                                   |
| 24  | Concurrency             | Six final independent-worker scenarios passed, 30 assertions.                                                                                                                                         |
| 25  | Tests                   | 91 Phase 14 dataset cases plus previous-phase regression coverage; existing tests retained.                                                                                                           |
| 26  | PostgreSQL verification | Phase 14: 91/91 passed, 372 assertions; combined: 543 passed, one legacy skip, 2,216 assertions.                                                                                                      |
| 27  | Browser                 | Full verified purchase/review/response/moderation/refund/repurchase flow, mobile/privacy/no JavaScript errors passed; completed result reused.                                                        |
| 28  | Full regression         | Laravel 905 total, 777 passed, 128 skipped, 4,447 assertions; no failures.                                                                                                                            |
| 29  | Pint/lint/types/build   | Passed; final production build 2.64 seconds.                                                                                                                                                          |
| 30  | PHPStan                 | Only known UserFactory::withTwoFactor missing return remains; no new errors.                                                                                                                          |
| 31  | Known issues            | Pre-existing PHPStan error and optional fontaine warning retained; no unrelated cleanup.                                                                                                              |
| 32  | Deferred functionality  | No support tickets, delivery notifications, helpful votes, public author stores or Phase 15 work.                                                                                                     |
| 33  | Decisions               | Canonical retained review, orthogonal withdrawal/moderation, deterministic real purchase binding, explicit repurchase restoration, live aggregates, shared lock order and PostgreSQL audit backstops. |

Phase 14 is complete. Stop here; Phase 15 has not begun.
