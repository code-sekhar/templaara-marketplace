# Phase 22 — Author professional identity and public profile

Phase 22 code, author professional profiles and public author storefront are verified and ready. Production launch remains **NO**: the external infrastructure, payment-provider and legal/tax blockers recorded in Phases 20–21 are not satisfied by this work. No Phase 23 functionality is included.

## Initial Phase 9 audit

The actual implementation was inspected before editing, including Phase 9, 20 and 21 documentation, author models/policies/services/requests/routes/pages, PostgreSQL author triggers, catalog taxonomies, product visibility/presentation, storage retention, review aggregates, earnings/support/invoice purchased identities and notification discovery.

| Audit question       | Existing implementation / decision                                                                                                                                                     |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Profile fields       | One restrictive User-owned profile; display/company names, existing individual/company enum, country, bio, slug, professional URLs, support contacts, status and explicit legacy flag. |
| Application evidence | Version-1 immutable JSONB snapshot, numbered rounds, immutable feedback/events, reviewer claim and retained decisions.                                                                 |
| Public identity      | Product detail previously showed only owner account name; no public author route/profile.                                                                                              |
| Private information  | Support contacts, legal/company name, authorized applicant identity and application feedback/history. No government KYC document subsystem exists.                                     |
| Approved maintenance | Previously frozen; extended to controlled low-risk edits with revision conflict checks.                                                                                                |
| Re-verification      | No existing reopen/reverification lifecycle. High-trust changes are blocked; contact marketplace operations for a deliberately reviewed future process.                                |
| Product ownership    | Existing author owner and admin-owned/ownerless official-product rules; unchanged.                                                                                                     |
| Purchased seller     | OrderItem freezes seller account name and author identity at purchase. Phase 22 does not change this field or its source.                                                              |
| Invoices             | InvoiceItem copies the purchased seller snapshot. No author legal/tax identity is substituted.                                                                                         |
| Support              | Existing purchased seller user binding, product/package/version and purchased support duration; unchanged.                                                                             |
| Storage              | Existing public presentation disk and staged ProductFiles/FileRetention upload infrastructure; reused for ordinary author imagery.                                                     |
| Review authority     | authors.view inspection, authors.verify claim/decision; role name alone does not grant verification.                                                                                   |
| Existing authors     | Approved application profiles and explicitly grandfathered legacy role holders; neither is reset. Legacy approval is not fabricated application verification.                          |
| Incomplete profiles  | New fields are nullable. Existing authors keep their role/workspace/product eligibility and receive completeness guidance.                                                             |
| UI conventions       | Existing Vue/Inertia/Wayfinder/TypeScript, AppLayout, MarketplaceLayout, Tailwind tokens, Button/Input, feedback/toasts and paginated history. No additional framework/library.        |

## One domain and additive schema

Existing `AuthorType`, `AuthorProfile`, `AuthorApplication`, policies and workflow remain authoritative. User still supports customer + author. No alternate authentication, vendor/seller user table, second application lifecycle or second financial ledger was created.

Forward migration: `2026_10_07_220000_extend_author_professional_identity.php`. It adds nullable professional headline (160), experience (0–60), founded year, controlled team size, region/city, explicit public-location choice, bounded language JSONB array, support availability and private confidential-work explanation; revision defaults to zero on existing profiles. `bio` is the existing professional biography rather than a duplicate text field. Company legal name remains the existing private `company_name`.

Relational `author_profile_technology` and `author_profile_category` reuse existing Technologies and business Categories as expertise. Composite primary keys, reverse indexes, restrictive taxonomy/profile FKs and timestamps prevent duplicates/destructive catalog cascades. No duplicate Laravel/React/CRM taxonomy strings are introduced. Existing inactive selections can be retained while editing; new inactive selections are rejected. Public presentation excludes inactive taxonomy entries.

`author_portfolio_items`: internal ID, generated unique public UUID, restrictive profile ownership, title (160), summary (500), optional details (5000), controlled personal/client/company/open_source type, HTTPS project/case-study/repository links, optional client name/completion year, public/featured flags, bounded sort order, revision and timestamps. `author_portfolio_technology` links existing Technologies and cascades only with the portfolio item. Client attribution is author-provided, not independently verified. There is no portfolio ownership verification claim.

`author_profile_media`: generated public UUID, restrictive profile FK, optional portfolio FK, purpose avatar/cover/portfolio, backend-only disk/path/MIME/size/dimensions, current flag and timezone-aware timestamps. PostgreSQL current-image partial unique indexes allow only one active avatar/cover per profile and one preview per item. One preview per portfolio item is the deliberate Phase 22 limit; multi-image galleries are deferred. Profile avatar means individual portrait or company logo using the same storage concept. Cover is optional, never a readiness requirement.

PostgreSQL additionally enforces experience/year/team/language bounds, portfolio type/order, immutable portfolio identity, portfolio/media ownership, submitted portfolio freeze, and unique deferred portfolio ordering. The existing author guard is extended using its inspected function definition, with exact replacement assertions that fail migration on an unexpected baseline. Existing transition, role, audit, feedback, claim, ownership and retention checks remain. Snapshot versions 1 and 2 are accepted; existing historical snapshots are not rewritten. Version 2 additionally binds professional scalar values, selected taxonomy names/IDs and portfolio identity/title/summary/revision to submitted persisted evidence.

Rollback refuses destructive removal of professional evidence. Use forward corrections. No financial tables, purchased snapshots, historical timestamps or existing author approvals are migrated/reset.

## Professional information and readiness

Both individual and company profiles collect a concise headline, existing biography, years of professional experience, catalog-backed expertise/technologies, controlled languages and safe professional links. Founded year/team size are optional company context. Region/city remain private unless `public_location` is explicitly enabled. Experience is never inferred from account age. Public experience is profile-provided; the verification badge does not certify each subsequent professional claim.

Text is length-bounded and rendered as escaped text, never executable HTML. Links require public HTTPS with no credentials/custom port; private/reserved IP addresses, localhost/internal domains and unsafe schemes are rejected. GitHub/LinkedIn profile fields restrict host identity. No server-side remote fetching/proxying, OAuth or iframe embedding is added. Legacy unsafe/HTTP links are omitted publicly; new saves must use safe links. Existing reserved slugs are retained rather than destructively renamed; new manual slugs reject reserved words and generation avoids them.

Drafts remain incomplete-saveable. `AuthorProfessional` centralizes new-application readiness in addition to existing identity/country/biography/support-email/company requirements:

- Headline of at least five characters.
- Explicit experience, including zero.
- At least one business expertise or technology selection.
- At least one portfolio item **or** a confidential-work explanation of at least 30 characters and an existing accepted professional evidence link.

A portfolio-based application does not require an unnecessary external link. The confidential alternative retains Phase 9's accepted website/portfolio/GitHub professional-presence rule. LinkedIn alone is not that evidence under the existing policy. No public client disclosure, avatar, cover, government ID, tax ID, bank details or media package is required.

Version-2 submission/resubmission freezes professional scalar data, chosen taxonomy names/IDs, portfolio content/visibility/ordering, image UUIDs/URLs and identity images inside the existing application snapshot. Review uses submitted evidence, not a mutable live profile. New correction rounds preserve old snapshots and feedback. In-flight version-1 applications continue under their original readiness rules. Approval still uses additive assignRole and existing verified-email/self-review/reviewer-claim checks.

## Edit policy, conflicts and completeness

Draft/changes_requested profiles remain editable; submitted/under_review/rejected profiles remain frozen. Approved authors may edit biography/headline, professional background, skills, links, languages, voluntary location, non-binding availability, imagery and portfolio without losing approval. Profile display name, legal/company name, author type, country, slug and private support identity are locked after approval. This phase **prevents direct high-trust mutation**; it does not invent a second reverification lifecycle or silently revoke author access.

Profile writes require the current revision for existing rows. Item update/delete require item revision; reorder and image replacement require current profile revision. Stale/missing revision produces a useful validation error. User → Profile locking matches the existing workflow, with transactional authorization against freshly loaded state. Submission and profile/portfolio/media changes serialize. Portfolio limit checks and ordering normalization run under that same lock; PostgreSQL deferred uniqueness protects committed ordering. New records use server-generated ownership/UUIDs; client ownership/status/roles/snapshot/actor fields are never accepted.

`AuthorProfileCompleteness` computes percentage/completed/missing sections from identity, headline, biography, experience, expertise, professional links, work evidence and communication. No percentage is writable or persisted. Cosmetic imagery is not required to reach completeness. Zero/partial/full are deterministic; completeness does not assign verification or restrict grandfathered product eligibility.

## Media, privacy and cleanup

Hard limits in `config/author_professional.php`: 12 portfolio items, one current preview per item, avatar and optional cover, 4 MB/image, PNG/JPEG/WebP, dimensions 64–8000 per axis. Languages, project types and team sizes are controlled. These are author-specific ceilings; no Phase 18 product policy is silently repurposed as author policy.

Uploads use the existing staged ProductFiles store and FileRetention failure/orphan handling, MIME/extension/image/size/dimension checks and generated object keys; client filenames are not object identities. Public and private artifact disks cannot be confused. SVG/executable images are rejected. No remote-image fetch occurs.

**Ordinary uploaded profile/portfolio images are public presentation assets, even when an item is not listed publicly.** Hidden portfolio text/metadata is omitted from public author responses, but public image URLs are not a confidential-document vault. UI explicitly warns about this. Do not upload NDA material/private verification documents. Existing confidential explanations/contact/legal identity remain private application/profile data; there is no new government-document upload module.

Replacement/deletion retires old image rows. `authors:cleanup-media --limit=100` removes bounded retired images older than 24 hours only when no retained application snapshot references their UUID. Referenced historical evidence remains. The existing generic staged cleanup additionally recognizes attached AuthorProfileMedia so recovery cannot delete an attached author image. Cleanup restricts configured disk and generated authors/ prefix. New media timestamps and cutoff comparisons are timezone-explicit; old author/commerce timestamps are unchanged. The daily cleanup uses withoutOverlapping and existing optional shared scheduler leadership. Storage backlog/retention monitoring remains an operator responsibility.

## Presentation and routes

Public `PublicAuthor` is an explicit allow-list. Only approved profiles whose User still has the author role resolve publicly. Legacy approved profiles are allowed to present their existing identity, but **do not receive an invented Verified Author badge**. The badge requires an actual approved application, and means only completion of Templaara's author-application process: not government/tax certification, a security audit, legal endorsement or a product-quality guarantee.

Public responses contain professional display name/slug, headline/biography/experience, optional deliberate location, safe links, controlled communication/company context, active taxonomies, ordinary imagery and public portfolio. No support/account email, phone, legal company identity, confidential explanation, private feedback/application history, tax identity, customer billing, banking/payouts/commission/earnings, license data or support conversations is serialized. Self DTO contains only the actor's own profile/workspace evidence; authorized reviewer DTO retains existing private review fields. Models are not serialized directly publicly.

| Method     | Route                                      | Boundary                                                                                                                 |
| ---------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| GET/PUT    | /author/onboarding                         | Existing targeted email verification, own draft/corrections; readiness only on submit.                                   |
| POST       | /author/onboarding/submit                  | Existing centralized application transition and frozen new round.                                                        |
| GET/PUT    | /author/profile                            | Existing author role; self-only approved/legacy maintenance, locked identity; private no-store/encrypted detail history. |
| POST       | /author/portfolio                          | Own editable profile, bounded portfolio creation.                                                                        |
| PUT/DELETE | /author/portfolio/{public_uuid}            | Own item, allowed state, revision checks.                                                                                |
| POST       | /author/portfolio/reorder                  | Exact complete owned UUID set and current profile revision.                                                              |
| POST       | /author/profile/media                      | Own avatar/cover, revision, validated upload.                                                                            |
| POST       | /author/portfolio/{public_uuid}/media      | Own preview, ownership and revision checks.                                                                              |
| GET        | /admin/authors                             | authors.view; paginated search/status/type/country directory.                                                            |
| GET/POST   | Existing /admin/author-applications routes | Existing inspection/claim/changes/approve/reject authority and reviewer restrictions.                                    |
| GET        | /authors/{slug}                            | Guest-safe approved public profile; unavailable profiles return 404.                                                     |

New portfolio/media mutations are throttled 30/minute; approved profile saves are likewise bounded. Normal web CSRF remains. Phase 20 proxy/security headers/CSP/nonces/error boundaries are unchanged.

The public author page uses existing MarketplaceLayout and SEO title/description/canonical with safe professional fields. No unsupported structured data, fake ratings/sales/response-time or Top Seller badge. Currently public product count is real. Products are paginated 12 and reuse the complete existing MarketplaceQuery visibility boundary, including active catalog, public media, published release and offers. Existing product-card qualified review aggregates are reused; no duplicate author rating algorithm is introduced. Official products keep Templaara Official identity; eligible author products gain a compact linked professional author card. Sellers without a professional profile retain baseline seller attribution and workspace eligibility.

## UX, accessibility and performance

Onboarding/profile editor uses section navigation, restrained light cards, desktop sidebar, clear status/completeness, public/private explanations, saved progress, corrections and separate submission. It deliberately uses sections rather than an additional wizard state machine. Portfolio cards provide create/edit/hide/show/reorder/delete and preview replacement. Author dashboard adds profile-completion guidance without replacing authoritative earnings or product counts. Review shows professional experience/taxonomies/portfolio cards from frozen snapshots, existing feedback/history and unchanged decision controls. Directory integrates with existing sidebar.

Labels, native controls, escaped text, error announcements/field associations, semantic headings, image alt text, safe external-link attributes, descriptive ordering controls and textual statuses support accessibility. Public profile/editor/review/portfolio layouts collapse on mobile. Hidden project metadata is explained separately from ordinary public assets. Loading/disabled states appear during actual saves/uploads; no unnecessary skeleton/state library.

Queries are bounded: 12 portfolio items, 100 taxonomy selector options, 15 directory rows, ten application history rounds and 12 public products. Eager loading prevents per-item media/technology queries. Directory uses bounded recent application loading rather than a per-author application query. Existing SQL public product/rating/visibility aggregates remain authoritative; no all-table financial iteration or caching/search service is added. Representative final measured results and visual evidence are recorded below; browser navigation timing is not a production SLA.

## Frozen financial and integration boundaries

OrderItem still freezes purchased account seller identity. InvoiceItem still copies it. Profile edits/images do not rewrite existing OrderItem, InvoiceItem, AuthorEarning, payout, refund or support binding. Existing customer + author roles, owned products, product submission rights and grandfathered incomplete approved authors remain valid. Unapproved applicants receive no author-product permission or publication shortcut.

Existing application transition events/audit/Phase 16 durable status notifications are reused. Cosmetic edits/reorders do not generate noisy moderation events. High-trust edits are prevented, so no invented reverification notification exists. Financial analytics and all Phase 21 invoice/tax/credit truth are untouched. No external identity provider, tax authority, provider payment, deployment or new financial workflow is invoked.

## Verification commands

```sh
php artisan migrate --no-interaction
php artisan migrate:status
php artisan route:list --except-vendor
php artisan event:list
php artisan schedule:list
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/PhaseTwentyTwoTest.php tests/Feature/AuthorOnboardingTest.php
php tests/Support/verify-phase22-suite.php --phase22-only
php tests/Support/verify-phase22-suite.php
php tests/Support/verify-phase22-concurrency.php
php artisan test --compact
vendor/bin/pint
vendor/bin/pint --test
npm run check
npm run types:check
npm run build
composer audit
npm audit
vendor/bin/phpstan analyse --no-progress
```

PostgreSQL runners assert exact disposable schema/search_path before migration and each test/worker. Full regression covers Phases 22–10, Phase 9 onboarding, commerce, entitlements and storefront. Independent connections exercise simultaneous profile edits, submit/edit, portfolio reorder/edit, slug allocation, approval/resubmission, media replacement and historical seller reads. Four new PostgreSQL-only constraint cases skip only on SQLite and run on PostgreSQL. Prior intentional Entitlement PostgreSQL fixture skip remains.

Browser harness is external/private, uses fresh disposable fixtures, production assets, controlled HTTPS proxy and enforced CSP. It adds no application test routes or browser dependency. Real hosting/provider/object-storage/identity verification is not claimed. Screenshots may be intentionally retained privately as visual evidence, not application data.

## Verification results

All results below are from the final application code, including revision enforcement, reserved-identifier handling, ownership/privacy checks and current-media constraints. Only documentation and verification cleanup followed these passes.

| Verification                         | Final result                                                                                                                                                                                 |
| ------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 22 SQLite                      | 119 total: **115 passed, 4 PostgreSQL-only skipped, 405 assertions, 0 failures**.                                                                                                            |
| Phase 22 isolated PostgreSQL         | **119 passed, 0 skipped, 409 assertions, 0 failures**.                                                                                                                                       |
| Full PostgreSQL regression           | 1,686 total: **1,685 passed, 1 established Entitlement fixture skip, 5,982 assertions, 0 failures**. Phases 22–10, Phase 9 author onboarding, Commerce, Entitlements and Marketplace.        |
| Full Laravel regression              | 1,918 total: **1,735 passed, 183 skipped, 7,149 assertions, 0 failures**. SQLite excludes established PostgreSQL-only checks; these run in the PostgreSQL regression.                        |
| Independent-process PostgreSQL races | **7 scenarios, 24 assertions, PASS**: profile/identity updates, submit/edit, reorder/edit, slug allocation, approval/resubmission, image replacement, immutable seller history/profile edit. |
| Fresh HTTPS/CSP browser              | **10 flows PASS**, desktop/tablet/mobile, no JavaScript or enforced-CSP errors, no broken images or horizontal overflow.                                                                     |
| Pint / Pint --test                   | PASS; formatting only.                                                                                                                                                                       |
| Frontend check                       | PASS: 172 formatted files, 142 linted files, no warnings/errors.                                                                                                                             |
| TypeScript / production build        | PASS; build 3.29 seconds.                                                                                                                                                                    |
| Composer / npm audit                 | **0 advisories / 0 vulnerabilities**. No dependencies changed.                                                                                                                               |
| PHPStan                              | Only the known pre-existing UserFactory::withTwoFactor() missing-return issue; no Phase 22 errors.                                                                                           |
| Isolated production caches           | config:cache, route:cache and view:cache PASS; temporary paths removed.                                                                                                                      |
| Migrations / command inspection      | Forward migration applied; migrate:status, route:list, event:list, schedule:list PASS. Existing recovery schedules retained plus author media cleanup.                                       |

Meaningful backend coverage includes professional draft/readiness rules; company/individual fields; bounded experience/languages/taxonomies; unsafe links/XSS; portfolio CRUD/revision/ownership/limit/ordering/visibility; image format/dimensions/storage/replacement; immutable submitted professional evidence and retained feedback; controlled approved maintenance; high-trust freezes; grandfathered legacy eligibility; completeness; public approved-role visibility and accurate verification marker; product visibility/author card; directory permissions/filter pagination; historical purchase/invoice/earnings preservation; rate/CSRF boundaries; PostgreSQL direct-bypass integrity.

Final browser flows: individual onboarding with experience/skills/portfolio/avatar/preview; company/confidential-work evidence; requested changes and preserved-history resubmission; frozen reviewer evidence and approval; approved low-risk edits and portfolio editing; product author card to public author/products; guest/customer/other-author private boundaries; incomplete legacy compatibility; mobile editor/portfolio/review/directory/public product; HTTPS/security headers/enforced CSP. Production assets and existing authentication were used; no real payment or deployment was performed.

Visual QA inspected final onboarding, review, public-profile desktop, profile/public mobile viewport and tablet captures. Checked hierarchy/spacing, long company name, empty imagery, portfolio cards, badges, status and completeness, responsive controls and overflow. Generated plain image fixtures deliberately test image rendering rather than representing real software. Final screenshots are intentionally retained in a private verification directory as visual evidence; application fixtures are removed.

### Bounded performance measurements

After browser flows, the isolated approved author was expanded to **12 portfolio items**, and the directory to **18 profiles** (15-row pagination). Twelve repeated reads measured server kernel handling rather than browser network-idle waiting:

| Surface                                        | Server handling range | Queries                                               |
| ---------------------------------------------- | --------------------- | ----------------------------------------------------- |
| Public author profile                          | 167.51–182.03 ms      | 22–23 (cold/warm), without per-portfolio-item queries |
| Approved profile / integrated portfolio editor | 41.46–65.72 ms        | 27                                                    |
| Admin author directory                         | 38.18–40.50 ms        | 19                                                    |
| Admin application review                       | 38.65–43.84 ms        | 26                                                    |

Measured peak memory was 4 MiB for these reads. The verification's internal local budgets were <1 second server handling and <=40 queries; all passed. Full browser navigation includes network-idle waits and is not the server latency. These are representative local read checks, not production capacity/load certification or an SLA. No speculative indexes, all-table PHP iteration or new cache/search platform were introduced.

### Cleanup and safety

All Phase 22 disposable schemas, browser accounts, generated public/private fixture storage, provider/config credentials, temporary HTTPS certificate/key and isolated cache files were removed. Only private screenshots and sanitized result evidence are intentionally retained. Only this run's verification backend/proxy were stopped; user development servers were preserved. Read-only final audit confirmed **current_schema() = public**, **SHOW search_path = public**, **zero Phase 22 schemas/fixture users/portfolio/media rows in the application**, unchanged real `.env` digest, unchanged package manifests/locks and original commercial counts. No application seed/reset/truncate or real provider call occurred.

### Production requirements

Production launch remains **NO**. Phase 20 external hosting/domain/TLS, production PostgreSQL/Redis, live workers/scheduler, private object storage/mail, backup automation and deployment restore drill, monitoring/alerts, real Stripe/Razorpay sandbox/account/webhook/secrets readiness and Phase 21 qualified tax/legal review remain external requirements. Ordinary public author media needs the existing deliberately configured public storage/CSP origin and cleanup monitoring. A verified marketplace application is not government identity, tax, legal or security certification. No external identity/portfolio verification was performed.

**PHASE 22 CODE STATUS: COMPLETE**  
**AUTHOR PROFESSIONAL PROFILE: READY**  
**PUBLIC AUTHOR STOREFRONT: READY**  
**PRODUCTION LAUNCH READY: NO**

## Deferred and known limitations

No author-issued tax invoicing, split settlement, government KYC/Aadhaar/PAN/GST authority API, external paid identity checks, OAuth, portfolio ownership validation, author subscriptions, messaging/social/followers/gamification or ranking manipulation. High-trust change/reverification and dedicated suspension/anonymization remain future deliberately audited moderation work; do not delete financial history. Portfolio currently has one preview per item. No public verified badge for grandfathered authors without an actual approved application.

Known baseline issues may remain: UserFactory::withTwoFactor() missing return in PHPStan, optional fontaine warning, existing Composer/Boost notices, historical timestamp mismatch. New media instants are explicit; no historical repair occurs. Marketplace branding remains controlled by existing settings; a fixture's inherited Laravel name is not changed in the real application.

## Exact change inventory

No dependency or package-lock changes were made. The additive migration is the only schema change. Generated Wayfinder files are included below.

Created (28):

- `app/Http/Controllers/Admin/AuthorDirectoryController.php`
- `app/Http/Controllers/Author/AuthorPortfolioController.php`
- `app/Http/Controllers/PublicAuthorController.php`
- `app/Models/AuthorPortfolioItem.php`
- `app/Models/AuthorProfileMedia.php`
- `app/Services/Authors/AuthorMedia.php`
- `app/Services/Authors/AuthorPortfolio.php`
- `app/Services/Authors/AuthorProfessional.php`
- `app/Services/Authors/AuthorProfileCompleteness.php`
- `app/Services/Authors/ProfessionalProfileInput.php`
- `app/Services/Authors/PublicAuthor.php`
- `config/author_professional.php`
- `database/migrations/2026_10_07_220000_extend_author_professional_identity.php`
- `docs/phase-22-author-professional-profile.md`
- `resources/js/actions/App/Http/Controllers/Admin/AuthorDirectoryController.ts`
- `resources/js/actions/App/Http/Controllers/Author/AuthorPortfolioController.ts`
- `resources/js/actions/App/Http/Controllers/PublicAuthorController.ts`
- `resources/js/components/authors/AuthorIdentity.vue`
- `resources/js/components/authors/PortfolioEditor.vue`
- `resources/js/pages/authors/Directory.vue`
- `resources/js/pages/marketplace/Author.vue`
- `resources/js/routes/admin/authors/index.ts`
- `resources/js/routes/author/portfolio/index.ts`
- `resources/js/routes/marketplace/authors/index.ts`
- `resources/js/types/author-professional.ts`
- `tests/Feature/PhaseTwentyTwoTest.php`
- `tests/Support/verify-phase22-concurrency.php`
- `tests/Support/verify-phase22-suite.php`

Modified (29):

- `app/Http/Controllers/Author/AuthorOnboardingController.php`
- `app/Models/AuthorProfile.php`
- `app/Policies/AuthorProfilePolicy.php`
- `app/Services/Authors/AuthorProfileInput.php`
- `app/Services/Authors/AuthorWorkflow.php`
- `app/Services/Authors/AuthorWorkspace.php`
- `app/Services/Authors/SaveAuthorProfile.php`
- `app/Services/Marketplace/PublicProduct.php`
- `app/Services/Products/Files/FileRetention.php`
- `resources/js/actions/App/Http/Controllers/Admin/index.ts`
- `resources/js/actions/App/Http/Controllers/Author/AuthorOnboardingController.ts`
- `resources/js/actions/App/Http/Controllers/Author/index.ts`
- `resources/js/actions/App/Http/Controllers/index.ts`
- `resources/js/components/AppSidebar.vue`
- `resources/js/components/authors/AuthorSnapshot.vue`
- `resources/js/pages/authors/Dashboard.vue`
- `resources/js/pages/authors/Onboarding.vue`
- `resources/js/pages/marketplace/Show.vue`
- `resources/js/routes/admin/index.ts`
- `resources/js/routes/author/index.ts`
- `resources/js/routes/author/onboarding/index.ts`
- `resources/js/routes/author/profile/index.ts`
- `resources/js/routes/marketplace/index.ts`
- `resources/js/types/authors.ts`
- `resources/js/types/marketplace.ts`
- `routes/authors.php`
- `routes/console.php`
- `tests/Feature/AuthorOnboardingTest.php`
- `tests/Support/AuthorFixtures.php`
