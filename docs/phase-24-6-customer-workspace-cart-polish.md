# Phase 24.6 — customer workspace and cart final polish

## Audit and scope

Phase 24.1–24.5 documentation, current Vue pages, shared shell, CSS, routes, controllers, read models, policies and existing tests were inspected. No Git metadata is present. The supplied prior screenshots show inconsistent filter positioning and independent cart control wrapping; the implementation refines those existing surfaces. This phase does not rebuild the storefront or add commercial features.

## Feature preservation inventory

Every entry below exists both BEFORE and AFTER. No filter, route, option, action, column, pagination control or permission gate is removed. Presentation changes are described separately. Filters remain server-validated; the existing URL, replacement/history and withQueryString pagination semantics are retained. There was no customer sort control on these lists; none is fabricated.

| Page         | Retained fields, filters and results                                                                                                                                                                                                                                                                                               | Retained actions and permissions                                                                                                                                                                                                          |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard    | Verified paid purchases, current active licenses, open support tickets, issued invoices; recent product titles, order reference, original currency amount and paid date; support number/subject/status/activity date; truthful empty states                                                                                        | Browse Software; order and support details; library, orders/payments, invoices, reviews, notifications and account settings shortcuts; existing authorized author/admin workspace links                                                   |
| Invoices     | Search invoice/order number; currency INR/USD/EUR/GBP; invoice/credit-note document kind; NONE/CGST_SGST/IGST/VAT/SALES_TAX/GST/OTHER tax type; from/to dates; number, order, kind, issued date, exact total/currency; existing links pagination                                                                                   | Apply, clear, remove individual filter; document detail and private PDF; owner-only customer documents; admin inspection role retained                                                                                                    |
| Support      | Search ticket/subject, product slug, status awaiting seller/customer/resolved/closed, low/normal/high priority, all eight categories, unread, escalation, from/until dates; admin customer/seller filters; ticket number/product/version/subject/status/priority/escalation/participant/unread/last message; previous/more tickets | Open Support Ticket; detail/messages/attachments and lifecycle actions remain on existing detail/create routes; all purchase eligibility, private/internal-note rules and owner/seller/admin boundaries retained; author metrics retained |
| Reviews      | Search, product slug, 1–5 rating, published/hidden/withdrawn/unqualified status, answered/unanswered seller response, from/until dates; admin customer/author filters; product/rating/status/withdrawn/qualifying purchase/body/published/edited date/response state; previous/next                                                | View/Edit, author View/Respond, admin Moderate; create/edit/withdraw/restore remain on existing authorized routes and purchased-software entry points; verified-purchase rules retained                                                   |
| Refunds      | Reference/order/customer search; requested/approved/processing/completed/failed/cancelled status; currency; all six reasons; from/until; Reference/Order, admin Customer, Amount, Status/Reason, Requested columns; existing links pagination; admin currency-separated report fields                                              | Detail and existing authorized cancellation/operations routes; clear/apply/remove chip; external-manual-refund wording retained; no provider refund implied                                                                               |
| Library      | Cover, product title, offer, purchased version, license type, granted date, software/license status, order number, completed refund reference; admin customer/active installation limits/activation domain/status/dates/prepared-download count; pagination                                                                        | Existing support/review entry URLs and labels, View Purchase & License, active-access Download, Browse Software empty-state CTA; purchased-release binding and server download authorization retained                                     |
| Orders       | Search order/billing name; completed/pending payment/payment failed/cancelled order status; pending/paid/failed/cancelled payment status; currency; from/to; Order, admin Customer, Date, Status, Payment, Total columns; previous/page/next                                                                                       | Apply/Clear/chip removal; order details and its unchanged payment/retry/cancel/refund/invoice links and permission gates                                                                                                                  |
| Cart         | All items, original confirmed amounts, category/title/cover, every offer, selected confirmed package/license/activations/support/updates; subtotal, cart total/currency, price-change issue/previous price                                                                                                                         | Existing server package update/changed-price confirmation/remove; busy/disabled/error states; continue browsing, empty CTA and valid checkout; one-per-product, same-currency, fingerprint and billing-dependent tax rules retained       |
| Shared shell | All sidebar groups/entries/selected states/role gates, safe avatar or initials, account menu, notifications, logout, breadcrumbs                                                                                                                                                                                                   | Mobile Sheet, Escape/focus restoration, keyboard access and original navigation routes                                                                                                                                                    |

Original model bindings and dynamic href expressions were compared mechanically against pre-edit source snapshots; all are retained. Conditional role controls, options and table columns were reviewed manually. Browser and regression evidence is recorded below. Existing tables retain bounded internal scrolling on phones; no column is deleted or hidden to fit the viewport.

## Presentation decisions

Customer list headings share compact spacing and explanatory descriptions. Existing PageHeader is refined through customer-scoped CSS; author/admin layouts and projections remain intact. Customer topbar supplies Workspace / page breadcrumbs for the audited list routes when none was provided, while preserving explicit breadcrumbs on other routes.

Compact FilterToolbar is opt-in on customer lists and also applies inside its teleported mobile Sheet. Every input/select has a visible label, date bounds remain a labelled fieldset, and Apply/Clear have a full-width action row. Four desktop columns reduce awkward gaps; tablet uses two columns and phone drawers one. No filter is removed to achieve compactness. Existing chip removal and clear behavior remain.

Support/review/document rows use consistent white surfaces with restrained borders and shadows. Refund zero state replaces the empty table frame only when there are no results; all populated table columns remain. Library shows the real purchase information before support/review and purchase/download actions. Its existing projection does not supply support/update durations on the list; those remain on the existing purchase detail page rather than inventing terms or adding queries.

Cart previews use object-contain in video-ratio frames. Package selector, Update and Remove share a deliberate grid; smaller widths place the selector above both actions. Confirmed terms come from the confirmed offer ID. An unsaved proposed offer is visible in the selector and explicit unsaved notice; it cannot alter item price, confirmed terms, subtotal or cart total. Existing server update must accept it. No JavaScript financial calculation, discount, fee, shipping or payment method is added.

## Source inventory

Created: this document; `tests/Feature/PhaseTwentyFourCustomerWorkspaceTest.php`; `tests/Support/verify-phase246-suite.php`.

Modified: `resources/js/pages/Dashboard.vue`; `resources/js/pages/invoices/Index.vue`; `resources/js/pages/support/Index.vue`; `resources/js/pages/reviews/Index.vue`; `resources/js/pages/refunds/Index.vue`; `resources/js/pages/library/Index.vue`; `resources/js/pages/orders/Index.vue`; `resources/js/pages/commerce/Cart.vue`; `resources/js/components/AppSidebarHeader.vue`; `resources/js/components/design/FilterToolbar.vue`; `resources/css/app.css`.

No application backend, route, policy, schema, ledger, tax/payment/invoice service, dependency, original logo or real environment file changed. Production assets are rebuilt by the existing command.

## Verification and findings

Focused SQLite and isolated PostgreSQL each passed 86 cases, zero skips, 865 assertions and zero failures. The new 40-case suite verifies every retained filter value and reset, rejects array input for every visible customer filter, exercises populated orders/documents/reviews/support/refunds/library, verifies owner isolation, PDF no-store/denial and unchanged PDF bytes. Existing focused commerce coverage proves stale-price acknowledgement and authoritative cart terms. Full Laravel passed 2,035 total: 1,852 passed, 183 established skips, 8,390 assertions, zero failures. Full isolated PostgreSQL passed 1,803 total: 1,802 passed, one established Entitlement fixture skip, 7,223 assertions, zero failures. No new skip was added.

Eight independent-process payment concurrency scenarios passed, including duplicate webhooks/reconciliation/callback/retry/settings/provisioning/creation leasing and committed-reservation/unlocked-row verification. Seven refund scenarios passed with 31 assertions. Full prior-phase regression covers retained review withdrawal/restore, support eligibility/private attachments, pagination, expired/refunded download denial, checkout/tax/payment and private invoice workflows; these domain actions were not rewritten.

The broad HTTPS/enforced-CSP browser run passed 13 flows with 72 logical captures. Final customer/cart matrix passed 128 captures: eight pages × populated/empty × eight widths. No page overflow was detected. Final behavior checks passed all visible mobile filter controls, search URL/apply/reset, Escape/focus restoration, account/sidebar keyboard behavior, unsaved exact cart prices/totals, accepted package update, remove, checkout entry, revoked download denial and private PDF no-store/owner denial. Six additional captures record mobile drawers and unsaved cart. After narrowing native date-input padding/font size to preserve the complete entered date, all five filter pages were rechecked at every width: 40 captures, no overflow or JavaScript/CSP errors. Original demo-artwork library verification passed eight additional widths, image loading/object-contain, active actions and refunded download suppression. No real provider call/payment or owner-data purchase/review occurred.

Actual screenshots reviewed: `qa-dashboard.png`, `qa-invoices.png`, `qa-support.png`, `qa-reviews.png`, `qa-refunds.png`, `qa-library.png`, `qa-orders.png`, `qa-cart.png` contact sheets cover all eight widths. Individual invoice/support 375px, cart 1024px, library-artwork 375/1440px, all-page empty-state 375/1440px contact sheets, and mobile drawer/entered-date screenshots supplement them. Raw ISO invoice dates were a real mobile readability defect; only their display format changed, retaining the original timestamp in semantic time/title attributes. Generic fixture cover images were black placeholders; real demo artwork was additionally verified through canonical disposable cart/quote/checkout/settlement/refund fixtures, without replacing media.

Initial populated test fixtures lacked eligible support and then a current tax-quote hash; the application correctly refused those operations. Fixtures now purchase support with the canonical quote. The expected PDF header directive ordering was corrected. Browser harness selectors were corrected for selected-count Filters buttons and the existing Orders Clear link, and the save assertion now waits for the Inertia response before reading the updated total. These harness failures were corrected and rerun, not waived. The first matrix completed before a selector failure, and separate behavior checks were corrected and rerun to PASS. A subsequent combined pass crossed an asset rebuild and was not accepted as final evidence. The final clean combined run passed 134 captures against stable final production assets, with every width, retained filter control, URL/reset action, cart save/remove/checkout, focus/keyboard and private/refunded authorization check passing and no JavaScript/CSP errors. The padding-only date refinement additionally passed all 40 affected page/width checks.

Pint/Pint --test, frontend formatting/lint, TypeScript and production build passed. Composer audit: zero advisories; npm audit: zero vulnerabilities. PHPStan reports only the known pre-existing UserFactory::withTwoFactor() missing return (line 49); no suppression or unrelated fix was made. Isolated config/route/view cache checks passed. The final invoice/support display-only fixes were rebuilt and verified in the final matrix; backend projections and domain semantics remain unchanged.

## Performance, integrity and cleanup

Three read-only handling samples per route use production assets and disposable fixtures. Concurrent regressions and local infrastructure affect latency; these measurements are not a production SLA. Peak measured memory is 4 MiB. No backend query or projection changed; all previously measured query counts match Phase 24.5, including the admin 65-query baseline. Its capacity concern remains outside this UI phase.

| Route               | Queries    | Median handling | Phase 24.5 queries      |
| ------------------- | ---------- | --------------- | ----------------------- |
| `/cart`             | 24, 24, 24 | 38.16 ms        | 24                      |
| `/account/orders`   | 13, 13, 13 | 23.99 ms        | Not previously measured |
| `/account/invoices` | 13, 13, 13 | 24.23 ms        | Not previously measured |
| `/account/refunds`  | 12, 12, 12 | 26.51 ms        | Not previously measured |
| `/dashboard`        | 18, 18, 18 | 34.24 ms        | 18                      |
| `/account/library`  | 24, 24, 24 | 72.82 ms        | 24                      |
| `/account/support`  | 13, 13, 13 | 21.28 ms        | 13                      |
| `/account/reviews`  | 12, 12, 12 | 23.66 ms        | 12                      |
| `/admin/dashboard`  | 65, 65, 65 | 2386.47 ms      | 65                      |

Baselines were captured read-only before edits. Original row digests, environment/logo/dependency digests and historical demo media/private ZIPs/submission snapshots/content/active prices matched during verification. Cleanup completed: the Phase 24.6 disposable browser schema, public fixture symlink and private storage, generated accounts/products/orders/payments/documents/support/reviews/refunds, certificates/credentials, isolated caches, private screenshots and browser scripts were removed. Only owned verification processes and failed-run browser descendants were stopped. The owner's Laravel server (PID 119458) and Vite server (PID 119726) remain running. Known verification-specific fake test disks and newly generated files in generic fake disks were cleaned; older test files and owner assets were preserved. Sanitized test/performance/boolean integrity summaries remain in the private verification cache.

Final `current_schema() = public`, `SHOW search_path = public`, and no Phase 24.6 schema remains. Actual environment/logo/dependency digests match baseline; every original financial/support/review/notification count matches. No tracked original row is missing. Every tracked original row matches except user ID 1. All ten owner demo products remain publicly eligible, and their old media/private ZIP bytes/submission snapshots/content/active prices match.

The final user-ID-1 row hash differs from this phase's fresh baseline, after an earlier mid-phase comparison matched. Its updated_at value remains the older recorded date; the hash-only baseline cannot identify the changed field or independently explain its cause. No account value is exposed and no repair/reset/overwrite is attempted. All verification fixture writes were guarded into disposable schemas. This exception is reported explicitly, not treated as a passing row-integrity comparison.

The earlier user-ID-1 digest exception remains UNRESOLVED. This phase must not reset or edit the account, and a matching fresh baseline cannot explain the older exception.

Production launch remains NO. Real hosting/TLS, PostgreSQL/Redis operations, queue/scheduler, storage/mail, backup/restore, monitoring, provider sandbox/webhooks and legal/tax requirements from Phases 20/21 remain external blockers. No Phase 25 is started.

## Final status

PHASE 24.6 CODE STATUS: COMPLETE

EXISTING FEATURE PRESERVATION: PASS

CUSTOMER WORKSPACE CONSISTENCY: PASS

ALL FILTERS PRESERVED: PASS

CART ALIGNMENT: PASS

CART/CHECKOUT FINANCIAL INTEGRITY: PASS

RESPONSIVE VISUAL QA: PASS

USER-ID-1 DIGEST EXCEPTION: UNRESOLVED

PRODUCTION LAUNCH READY: NO
