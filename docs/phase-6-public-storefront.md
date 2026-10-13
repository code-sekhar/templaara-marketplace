# Phase 6: Public storefront and product discovery

Templaara now has a guest-accessible business software storefront in the existing Laravel 13.34 / Vue 3.5 / Inertia / Wayfinder application. Fortify, RBAC, catalog CRUD, ownership, moderation, media and private commercial storage remain authoritative. No dependency was added.

## Public routes

| GET route            | Name                          | Purpose                                                      |
| -------------------- | ----------------------------- | ------------------------------------------------------------ |
| `/`                  | `home`                        | Marketplace homepage; preserves the existing home route name |
| `/marketplace`       | `marketplace.home`            | Homepage alias, canonicalized to `/`                         |
| `/products`          | `marketplace.products.index`  | Searchable, filtered, paginated software catalog             |
| `/search`            | `marketplace.search`          | Catalog/search entry point                                   |
| `/products/{slug}`   | `marketplace.products.show`   | Read-only software detail                                    |
| `/categories/{slug}` | `marketplace.categories.show` | Active primary-category browsing                             |
| `/industries/{slug}` | `marketplace.industries.show` | Active industry browsing                                     |

These routes have no authentication requirement. The existing `/catalog/categories`, `/catalog/industries`, `/catalog/technologies` contracts and all workspace routes remain intact. The starter Welcome file is retained, while the root route now renders the marketplace.

Unknown, inactive or ineligible product slugs return the same 404 response. Inactive category/industry pages return 404 without revealing moderation state. Category browsing uses the product's primary category; recursive descendant aggregation is not added.

## Centralized visibility

`MarketplaceQuery::visible()` is the single public product boundary used by catalog, detail, homepage, featured and related queries. It requires:

- Product status `published`, and product active.
- An active primary category and at least one active offer.
- A current cover and at least two current screenshots, matching Phase 5 submission requirements.
- Presentation records on the configured public disk, separate from the artifact disk, with permitted JPEG/PNG/WebP MIME, positive size and minimum 320×180 dimensions.
- A published release with a release timestamp and a current nonempty source-package record on the configured private artifact disk.
- Private artifact disk configuration without public visibility, serving, or a public URL.

`publicMedia()` and `publicRelease()` also constrain eager-loaded assets/release metadata. Detail selects the latest eligible published release by release timestamp, then ID. Legacy Phase 4 publications lacking Phase 5 presentation/release dependencies stay out of the storefront; their moderation history is untouched.

Visibility uses validated, persisted file records. Phase 5 upload and submission/publication readiness already verify stored objects, and published content remains frozen. Public catalog queries deliberately do not issue a filesystem/S3 HEAD request for every image and commercial object. Out-of-band object deletion or CDN/storage outages require operational monitoring and restoration; database visibility cannot independently detect an external storage outage. Retained objects must not be removed outside the application's retention rules.

## Public serialization and privacy

`PublicProduct` builds explicit card/detail arrays rather than returning Eloquent models, editor props or submission snapshots. Public nested records omit numeric IDs, pivots and operational fields.

Cards contain only name/slug, short description, category, up to three active industries, cover URL/alt text, currency, starting price, featured flag and official marker. They never load demo credentials, full content, owner identity, release history, commercial artifacts or moderation relations.

Detail adds business description/problem, target audiences, benefits, ordered features, active industries/technologies, ordered screenshots, active offers and terms, dedicated demo credentials, safe external URLs, minimal seller name, latest published version/changelog/notes/date and SEO fields. Release/artifact relations are never serialized implicitly. Uploaded private documentation is not a public documentation link.

No owner email, roles, permissions, reviewer identity, decisions/feedback, audit events, submissions/snapshots, private disk/path/URL, package filename, checksum or artifact metadata enters public props. Authenticated storefront visitors receive only their own display name in shared `auth.user`; authenticated workspace pages retain their existing full authentication/RBAC props. Guest `auth.user` remains null.

## Homepage and browsing

The homepage introduces “Software built for your business,” a prominent business-need search, category browsing, industry browsing, curated featured software, recent publications and truthful product-discovery messaging. Empty sections do not invent products or purchase capabilities.

The catalog uses 12 products per page and retains query parameters in pagination links. Desktop filters use a sidebar; mobile filters collapse behind a button. It includes loading feedback, result counts, validation messages, clear filters and useful empty states. Category/industry pages reuse this catalog with a fixed contextual scope and business-focused heading.

Filters:

- `search`: maximum 100 characters.
- `category`, `industry`, `technology`: bounded slug strings, matched only against active catalog records.
- `currency`: INR, USD, EUR or GBP.
- `min_price`, `max_price`: nonnegative decimal strings, at most ten integer and two fractional digits; maximum must be at least minimum.
- `sort`: recommended, newest, price_asc or price_desc.
- `page`: bounded positive integer.

A price range without a currency defaults to INR. Cross-currency conversion is never performed. Without a currency filter, price sorts group by currency and sort amounts within each group; the UI explains this. Zero-price offers are supported without a separate free/paid mode.

Recommended ordering uses explicit featured status followed by publication timestamp and ID. Newest uses release publication time, not draft creation time. Price sorting uses the numeric database minimum active offer and an ID tie-breaker. Search gives name matches precedence. No popularity, rating, sales or discount data is fabricated.

## Search and indexes

PostgreSQL uses native `to_tsvector('simple', ...)` / `plainto_tsquery` over name, short description, business problem and target-audience JSONB text. Name-prefix matches also support partial leading searches. Escaped related-category/active-industry name and feature-title matches supplement the business document. Parameters are bound; `%`, `_` and backslashes cannot become user-controlled LIKE operators. PostgreSQL search is token-based, not a typo-tolerant or arbitrary substring search engine. SQLite isolated tests use an escaped LIKE fallback for the same public business fields.

Migration `2026_10_05_180000_index_public_marketplace_queries.php` adds:

- `(status, is_active, is_featured, id)` on products.
- `(product_id, is_active, price)` on product offers.
- PostgreSQL GIN business-document expression index.
- PostgreSQL `lower(name) text_pattern_ops` prefix index.

The migration freezes its search expression rather than importing mutable service code. Changing the indexed document later requires a new migration. Existing FK/pivot/media/release indexes serve related filters and visibility predicates. Related-field LIKE checks remain a potential optimization target as catalog size grows; future search can maintain a denormalized business document or move behind the same query boundary to a search engine. No extension, external search service or Redis cache was installed.

## Pricing and detail presentation

Starting price is the minimum active offer, independently of the default package. Inactive cheaper offers cannot affect cards, filters or sorting. Numeric/decimal database comparisons and decimal-string serialization preserve money precision. Vue's `formatPrice()` formats the integer part with BigInt and pads the decimal part directly; it does not convert prices to floating point. INR/USD/EUR/GBP symbols and grouping are supported.

The detail page presents hero/business summary, screenshot gallery, problem solved, best-fit audiences, benefits, features, packages, demo, industries, support/update terms, technical information, current release and related software in that order. The default offer is marked explicitly. License scope is descriptive; customer licenses are not issued.

Purchasing buttons are omitted. “Explore packages” and truthful future-purchasing copy do not route to checkout. `origin=templaara` produces the Templaara Official badge; author products receive minimal display-name attribution, without invented verification. Admins continue selecting featured status through the existing authorized draft editor; published-content editing rules are unchanged.

Related products are public-only, exclude the current product, prefer the same category, then overlapping active industries, and use deterministic publication/ID ordering. At most four are returned. Homepage sections each return at most six products. Active catalog selectors are bounded to 100 lightweight name/slug records. Listings eagerly load only category, active industries and cover; detail loads the required business relationships and one public release. Commercial readiness uses EXISTS queries, not artifact hydration.

## Media, demos and SEO

Laravel Storage generates public presentation URLs; Vue never builds object paths. The gallery uses existing starter-kit Dialog/Button components, ordered thumbnails, alt text, image lazy loading, Previous/Next controls, keyboard arrows, Escape and focus handling. No gallery dependency was added.

Only validated HTTP/HTTPS external URLs are serialized, including defense against unsafe legacy values. Demo, preview and public documentation links use `target="_blank"` with `rel="noopener noreferrer"`. Preview is a safe external link, never an arbitrary iframe. Dedicated demonstration username/password may be displayed and copied; no real account credential fields are loaded.

Product meta_title/meta_description have name/short-description fallbacks. Home/catalog/category/industry metadata uses sensible business descriptions and canonical URLs. Shared SeoHead manages Inertia navigation; Blade also supplies initial HTML metadata when SSR is not running.

SoftwareApplication JSON-LD is rendered in initial HTML and maintained by the Inertia Head on navigation. Its allow-list includes known name, description, business application category, version, public image and canonical URL. HEX-escaped JSON prevents closing-script injection. There are no ratings, reviews, sales, availability, discounts or commercial Offer assertions while checkout is unavailable.

## Tests and verification

`MarketplaceTest` adds 59 cases covering guest routes, every unpublished state, inactive/invalid visibility dependencies, slug detail, business fields, public gallery/order, strict nested allow-lists, private artifact metadata/paths and email exclusion, signed-in public auth minimization, business search/name ranking, wildcard/SQL payload safety, active category/industry/technology filters, exact starting-price/range/sort behavior, publication-time sorting, contextual pages, featured/official/related records, pagination, validation, external URL safety, safe JSON-LD, bounded eager queries, private download authorization and author self-feature denial.

The existing Phase 4 publication test now additionally asserts approved products remain 404 and published ready products resolve through the Phase 6 detail page. Its old assertion that no public route existed was replaced; workflow, permission and audit assertions remain.

Commands executed:

```sh
php artisan wayfinder:generate --with-form
php artisan migrate --no-interaction
php artisan route:list --except-vendor
php artisan route:list --path=products --except-vendor
php artisan test --compact --filter=MarketplaceTest
php artisan test --compact
vendor/bin/pint
vendor/bin/pint --test
composer types:check
npm run check:fix
npm run check
npm run types:check
npm run build
```

PostgreSQL verification migrated a random isolated schema, copied the marketplace suite into a disposable transactional test harness, exercised the real PostgreSQL search/money/index paths, and removed its schema/configuration afterward. Use a dedicated disposable PostgreSQL testing database/schema for repeat runs; never run RefreshDatabase against the application database.

Browser verification used existing Playwright/Chrome outside application manifests, an isolated PostgreSQL schema and disposable public/private storage. Guest homepage → search → category/industry filters → detail → gallery → packages/demo checks passed on desktop and 390px mobile. It checked keyboard gallery navigation, query persistence, public metadata/JSON-LD replacement, no authentication redirect, no horizontal overflow, private information absence, valid image loading and no JavaScript errors. Demo URL/safe link behavior was verified; the example external demo's availability is not an application assertion. Temporary server, credentials, schema, public link and objects were removed.

Final results:

| Check                                 | Result                                                          |
| ------------------------------------- | --------------------------------------------------------------- |
| Full Laravel suite, Phases 1–6        | **286 passed, 5 skipped, 2006 assertions**                      |
| Isolated PostgreSQL marketplace suite | **59 passed, 635 assertions**                                   |
| Migration                             | Applied on PostgreSQL; subsequent run has nothing pending       |
| Browser                               | Guest desktop/mobile flow passed; no JS errors or broken images |
| Pint                                  | Passed                                                          |
| Frontend lint/format                  | Passed                                                          |
| Vue TypeScript                        | Passed                                                          |
| Production build                      | Passed                                                          |
| PHPStan                               | Only pre-existing UserFactory::withTwoFactor() missing return   |

The five existing skips cover disabled Fortify features and PostgreSQL-only constraints from earlier phases. The optional fontaine build warning remains. Composer's installed tooling emits an unrelated PHP 8.5 deprecation notice. This workspace has no Git repository for baseline diff review.

## File inventory

Created:

- `app/Http/Controllers/MarketplaceController.php`.
- `app/Services/Marketplace/MarketplaceQuery.php`, `PublicProduct.php`.
- `routes/marketplace.php`.
- `database/migrations/2026_10_05_180000_index_public_marketplace_queries.php`.
- `resources/js/layouts/MarketplaceLayout.vue`.
- `resources/js/pages/marketplace/Home.vue`, `Index.vue`, `Show.vue`.
- `resources/js/components/marketplace/ProductCard.vue`, `ScreenshotGallery.vue`, `SeoHead.vue`.
- `resources/js/types/marketplace.ts`, `resources/js/lib/marketplace.ts`.
- `tests/Feature/MarketplaceTest.php`.
- This document.

Modified:

- `routes/web.php`: includes public storefront routes while preserving home name and workspace includes.
- `app/Http/Middleware/HandleInertiaRequests.php`: minimal auth props on storefront routes only.
- `app/Models/Product.php`: preview-video property type documentation only.
- `resources/js/app.ts`: chooses MarketplaceLayout for storefront pages.
- `resources/views/app.blade.php`: safe initial SEO and structured-data fallback.
- `tests/Feature/ProductWorkflowTest.php`: publication visibility expectation described above.

Wayfinder generated `resources/js/routes/marketplace/**` and `resources/js/actions/App/Http/Controllers/MarketplaceController.ts`, refreshed existing home-route/action helpers, and the production build regenerated `public/build` assets. No new seed data or factory was needed; tests/browser fixtures use existing factories and the real review/publication pipeline. No products were automatically published into application data.

## Deferred commercial integration

Future checkout can connect active offer selection to an authenticated order workflow after rechecking visibility, currency and server-authoritative prices. Customer commercial access must use future entitlement authorization and private artifact delivery; storefront media/public release metadata confer no package-download permission.

Cart, checkout, orders, payments, customer downloads/licenses, earnings, commissions/payouts, customer reviews and support tickets remain absent. Rich author stores, search ranking analytics, published revisions and storage/CDN integrity monitoring remain separate future work. Phase 6 ends here; no Phase 7 implementation begins.
