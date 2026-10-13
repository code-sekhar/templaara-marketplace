# Phase 3: Product core

Templaara now supports private business-software drafts in the existing Laravel 13.34.0/Vue 3.5.43/Inertia/Wayfinder application. Fortify, the four roles and the 42 Phase 1 permissions are unchanged. Phase 2 catalog management and public catalog endpoints remain intact. No dependencies were added to application manifests.

## Ownership, origin and lifecycle

`products.owner_id` identifies the author for `origin=author`. It is a restrictive user foreign key. `origin=templaara` represents Templaara Official products and has a null owner; no fake account represents the business. Admins create official products through the admin endpoint; the author endpoint derives ownership from the authenticated user and creates author-origin products. Updating a product never transfers ownership or origin. PostgreSQL CHECK constraints enforce the origin/owner pairing.

`ProductOrigin` and `ProductStatus` are string-backed PHP enums. The only status is `draft`, enforced by PostgreSQL. Neither active nor featured flags publish a draft. There are no public product routes. Adding future statuses requires an explicit schema migration and policy changes; policy matches must handle each new status rather than accidentally permitting edits/deletion.

## Schema

Migration: `database/migrations/2026_10_05_120000_create_product_core_tables.php`.

### Products

- Bigint ID, nullable restrictive `owner_id`, required restrictive `category_id`.
- Origin (20), required name (255), unique slug (200), required short description (500).
- Optional full description and business problem as text.
- Required JSONB target-audience and key-benefit lists, initialized to empty lists by creation services/factories.
- Status (30), default draft; currency (3), default INR.
- Optional HTTP/HTTPS demo/documentation URL strings (2048).
- Optional demo username/password (255), version label (100).
- Featured default false; active default true.
- Optional meta title (160) and description (320), timestamps.

Business content precedes technical metadata in the editor. Target audiences and benefits are small ordered lists of strings with no independent identity or relationships, so JSONB is appropriate. They are entered as repeatable text inputs, never raw JSON. Relational structures are not stored in these JSON lists.

### Features

`product_features`: ID, cascading product foreign key, title (255), nullable description text, integer sort order default 0, timestamps. Features are sorted by sort order then ID; owners enter order values instead of using drag/drop.

### Offers and pricing

`product_offers`: ID, cascading product foreign key, name (255), optional description text, **numeric(12,2) price**, controlled license type (30), optional support/update period integers in days, default/active booleans, sort order, timestamps.

Price belongs exclusively to offers. Currency belongs to the product, so its offers use one currency without duplicating monetary configuration. Supported initial currencies are INR/USD/EUR/GBP through `ProductCurrency`; expanding currencies requires deliberate enum/DB-constraint changes. No floating-point arithmetic is used: Eloquent's decimal cast returns prices as strings and the UI uses decimal string inputs.

Every editor save requires at least one offer and exactly one active default. Zero-priced offers are allowed. PostgreSQL enforces nonnegative price/periods/order, controlled license values, default-implies-active, and at most one default with a partial unique index. Validation enforces the minimum/default count. Product locks and transactions protect offer synchronization; existing default flags are cleared transactionally before switching the default.

`LicenseType` permits `single_business`, `multi_business`, `extended`. These are descriptive configurations only. There are no keys, customer licenses, activations, entitlements or license-check APIs. Offer names are owner-configurable; installation/customization packages are not hardcoded commercial options.

Support and update periods live on offers, where packages can differ. Blank means unspecified; zero explicitly indicates no included period. No duplicate product-level price/support/update fields are maintained.

### Catalog links

Product belongs to one primary Category. Product belongs to many Industries via `industry_product` and many Technologies via `product_technology`. Each pivot has a composite primary key for uniqueness, an index on its catalog ID, foreign keys, and timestamps. Product deletion cascades only to its pivot rows; catalog deletion is restrictive. Category/Industry/Technology and User expose inverse `products()` relationships.

## Authorization

Laravel automatically discovers `App\Policies\ProductPolicy` for Product. Routes use `can` middleware, ProductRequest authorizes mutation permissions, and SaveProduct reauthorizes actor access. There is no global admin bypass.

- `manageAll`: admin role plus `products.view`; gates the complete admin workspace.
- `viewAny`: `products.view` plus author/admin membership.
- `view`: view permission plus admin management or author ownership.
- `create`: create permission plus author/admin membership.
- `createOfficial`: admin management plus create permission.
- `update`/`delete`: corresponding permission and admin management or author ownership, with an explicit draft-status match.

Ownership requires author membership, author origin, and matching authenticated user ID. Multiple roles remain supported. A customer+author user can manage their author drafts. Reviewer membership or an isolated update permission never bypasses ownership. There is no author verification requirement in this phase.

Requests prohibit owner ID, origin and status fields. Non-admin actors cannot submit `is_featured`. Product fillable fields exclude ownership, origin, status and featured; the service assigns them through controlled code. Nested child IDs must belong to the current product, are distinct, and are queried through that product's relation again during persistence. Foreign child IDs and nested extra keys are rejected. Backend checks remain authoritative; Vue permissions and per-record policy flags only control UI visibility.

## Routes and pages

Both `/author/products` and `/admin/products` expose seven named management routes:

| Method | Path relative to workspace | Action    |
| ------ | -------------------------- | --------- |
| GET    | `/`                        | `index`   |
| GET    | `/create`                  | `create`  |
| POST   | `/`                        | `store`   |
| GET    | `/{product}`               | `show`    |
| GET    | `/{product}/edit`          | `edit`    |
| PUT    | `/{product}`               | `update`  |
| DELETE | `/{product}`               | `destroy` |

Names use `author.products.*` and `admin.products.*`. All require auth/verified and the appropriate policy ability. Admin routes additionally require manageAll. Show presents a read-only editor to an authorized owner/admin; it is not a customer-facing product page.

Author listings are strictly scoped to the authenticated owner and author origin, with search/category/status filters and 15-row pagination. Supplied owner IDs cannot change that scope. Admin listings include all products, owner name/origin, and an additional origin filter. Both use escaped case-insensitive name/slug searches and deterministic updated-at/ID ordering. Only category and owner ID/name relationships are eager-loaded for listing. Demo credentials, full descriptions and nested child records are absent from listing props.

The shared `products/Index.vue` and `products/Form.vue` automatically use the existing AppLayout. They reuse starter-kit input/button/label/spinner/dialog components, flash toasts, Wayfinder helpers and `useAuthorization`. ProductField is a small labeled/error/help wrapper, not a new design system. Navigation retains one AppSidebar with Platform, Catalog and Products groups. Admins see All Products; authors see My Products; multi-role admins/authors can see both.

Editor sections: Basic Information, Business Details, Features, Industries, Pricing/Offers, Demo, Technical Information, Support/Updates and SEO. They support repeatable audience/benefit/feature/offer rows, multiple industries/technologies, explicit order inputs, validation summaries and field errors, default offer selection, loading/disabled state, cancellation and success feedback. Lists include empty states, filters, pagination and delete confirmation. Successful saves refresh persistent child IDs so repeated edits update existing offers/features instead of replacing their identities.

Demo credentials are intentionally demo information, stored as plain text rather than user-account credentials. Only authorized editor/inspection responses include them. No public product serialization exists. The form tells owners to use dedicated shareable demo credentials.

## Draft validation

Useful minimum: name, primary category, short description, currency and at least one fully configured offer. Full description, business details, features, industry/technology tags, demo/technical/support/SEO fields remain optional. Publication/submission requirements are deliberately absent.

Limits:

- Name 255; short description 500; full description 30,000; business problem 10,000.
- Audiences: at most 20 strings; benefits: at most 30 strings, each up to 500.
- Features: at most 50, title 255, description 5000, order 0–1,000,000.
- Offers: 1–20, name 255, description 5000, controlled license, required default/active booleans, bounded order.
- Price: nonnegative plain numeric decimal, maximum 9,999,999,999.99, at most two fractional places. Scientific notation, excessive precision and negative amounts are rejected.
- Support/update periods: optional integers from 0 through 36,500 days.
- URLs: HTTP/HTTPS only, max 2048; demo username/password 255; version label 100.
- SEO title 160, SEO description 320.
- Catalog ID lists: bounded arrays of distinct existing IDs; new selections must be active. An existing inactive category or linked industry/technology remains selectable and can be retained safely.
- Slug: optional lowercase ASCII letters/numbers separated by single hyphens, max 200, unique excluding only the bound current record on update.

`UniqueSlug` is shared with Phase 2 persistence. It transliterates the name, bounds the generated base, and appends `-2`, `-3`, etc. Product fallback is `product`. Omitted update slugs retain their value; explicitly blank regenerates. Unique constraints remain authoritative and generated collisions retry in fresh transactions up to five times. Manual conflicts return validation errors. Catalog slug behavior is otherwise preserved.

SaveProduct atomically persists the product, catalog links, features and offers. Updates lock the product row and reauthorize after locking. Explicitly supplied empty feature/tag arrays clear those relations; omitted arrays retain them. Offers are the editor's complete offer configuration on each save. Child rows omitted from a supplied collection are removed; supplied owned IDs retain identity/timestamps.

## Deletion and integrity

Authors can delete only their own draft products; admins can delete appropriate drafts. Features/offers/pivots cascade from the product, never into shared catalog data or users. Delete locks and reauthorizes the product inside a transaction. Future submitted/published deletion needs explicit retention and policy decisions; no such states exist yet.

Phase 2 catalog controllers now detect product references and return explicit reassignment errors before deletion. Restrictive FKs independently protect categories and pivots, including races. Catalog rows are never silently detached.

Author account deletion is blocked with a validation error while the account owns products, before logout or deletion occurs; the owner FK also protects against orphaning. Official products do not depend on a user's lifetime. A future operational ownership-transfer/account-closure process is outside this phase.

## Factories and development seed

ProductFactory creates an author-origin draft with a User/Category factory and business content; callers should assign author roles to ownership users when testing management. `official()` produces an ownerless official draft. ProductFeatureFactory and ProductOfferFactory support related fixtures; `additional()` creates a nondefault installation offer. Factories are fixture tools and may create an offerless Product for targeted tests; interactive saves enforce useful-draft rules.

DevelopmentProductSeeder is restricted to local/testing. It creates one ownerless official draft, Nexora Inventory & POS, only when the active POS & Inventory catalog category exists. It links active Retail/Wholesale industries and Laravel/Vue.js/PostgreSQL technologies, creates two features and one INR 4999.00 default offer with 180-day support and 365-day updates. Existing sample slugs are left untouched, including edited nested data. Missing catalog data never blocks application functionality.

DatabaseSeeder invokes it after local/testing BusinessCatalogSeeder. Standalone setup:

```sh
php artisan migrate --no-interaction
php artisan db:seed --class=BusinessCatalogSeeder --no-interaction
php artisan db:seed --class=DevelopmentProductSeeder --no-interaction
```

Running the complete DatabaseSeeder still honors Phase 1 local admin safeguards. Sample slug identity follows Phase 2 seeding conventions; changing it can allow a later run to recreate the original sample.

## Indexes and performance

Products index owner/status/ID, category/status, origin/status/ID, active/status and status; slug is unique. Features index product/order. Offers index product/active/order and have a PostgreSQL partial unique default index. Pivots index catalog IDs as well as their composite product/catalog keys. No search engine or speculative caching is introduced. Admin/author lists paginate and avoid loading nested/editor data; editor selectors are lightweight catalog collections appropriate to the current scale.

## Verification and commands

`tests/Feature/ProductCoreTest.php` adds 48 executed cases. They cover author/admin flows, multi-role owners, every relevant ownership boundary, forbidden fields, model fillable protection, relationships/features/offers, slug collisions, nested validation/scoped child IDs, exact money inputs/default offers, inactive catalog retention, draft deletion cascades, catalog restrictive FKs, account deletion, listing scope/filtering/pagination, credential omission, development seed safety, service authorization and transactional rollback on relation failure. Phase 1/2 tests remain unchanged.

Commands executed:

```sh
php artisan wayfinder:generate --with-form
php artisan migrate --no-interaction
php artisan db:seed --class=DevelopmentProductSeeder --no-interaction
vendor/bin/pint --test
composer types:check
php artisan test --compact
npm run check
npm run types:check
npm run build
php artisan route:list --path=products --except-vendor
```

Pint and `npx vp fmt` were also run on changed files. The product seeder was run twice successfully. PostgreSQL verification used a temporary isolated schema, the same ProductCore cases, and per-test transaction rollback. The schema and temporary credential files were removed afterward; application data was untouched by those tests.

Browser verification used Playwright installed outside the application in `/tmp`, with existing Chrome and a temporary local Artisan server. Both author and admin flows passed create, multiple features/offers, default switching, editing twice, persistence after reload, search, origin display and confirmed deletion with no JavaScript errors. Temporary fixture accounts/products were removed after verification. These tooling dependencies do not change application manifests.

Results: **125 passed, 3 skipped, 786 assertions** in the full Laravel suite; **48 passed, 301 assertions** in isolated PostgreSQL Product tests. PostgreSQL migration and repeated sample seeding passed. Pint, frontend lint/formatting, TypeScript and production build passed. PHPStan reports only the known pre-existing `UserFactory::withTwoFactor(): static` missing-return issue; no Phase 3 errors remain. The optional fontaine font-fallback build warning remains. The workspace still has no Git repository for diff/baseline review.

## File inventory

Created:

- `app/Enums/ProductOrigin.php`, `ProductStatus.php`, `ProductCurrency.php`, `LicenseType.php`.
- `app/Models/Product.php`, `ProductFeature.php`, `ProductOffer.php`.
- `app/Policies/ProductPolicy.php`.
- `app/Support/UniqueSlug.php`.
- `app/Http/Requests/Products/ProductRequest.php`.
- `app/Services/Products/SaveProduct.php`, `ProductEditor.php`.
- `app/Http/Controllers/Admin/AdminProductController.php`.
- `app/Http/Controllers/Author/AuthorProductController.php`.
- `routes/products.php`.
- `database/migrations/2026_10_05_120000_create_product_core_tables.php`.
- `database/factories/ProductFactory.php`, `ProductFeatureFactory.php`, `ProductOfferFactory.php`.
- `database/seeders/DevelopmentProductSeeder.php`.
- `resources/js/pages/products/Index.vue`, `Form.vue`.
- `resources/js/components/products/ProductField.vue`.
- `resources/js/lib/products.ts`, `resources/js/types/product.ts`.
- `tests/Feature/ProductCoreTest.php`.
- This documentation.

Modified:

- `app/Models/User.php`, `Category.php`, `Industry.php`, `Technology.php` (inverse relationships).
- `app/Http/Controllers/Admin/CategoryController.php`, `IndustryController.php`, `TechnologyController.php` (explicit reference checks).
- `app/Http/Requests/Settings/ProfileDeleteRequest.php` (account ownership protection).
- `app/Services/Catalog/SaveCatalogRecord.php` (shared slug helper).
- `routes/web.php` (product routes include).
- `database/seeders/DatabaseSeeder.php` (local/testing sample products).
- `resources/js/components/AppSidebar.vue`, `NavMain.vue` (permission-aware grouped navigation).
- Generated Wayfinder route/action helpers and production build assets.

## Future boundaries and technical decisions

Submission/review will require separate transition actions, new statuses and policy/migration changes. Draft validation must not be reused as complete submission validation. Future publication should deliberately serialize safe public business fields and dedicated demo information rather than exposing the entire Product model.

Media, versioned artifacts and secure download authorization belong in separate related structures when introduced. The current version label and documentation URL are descriptive text only. No screenshots/uploads, ZIPs, product versions, download endpoints, publication, checkout, orders, payments, licenses, commissions, payouts, reviews or support tickets were implemented.

Offers retain stable identities to support future references, but commercial offers are still editable draft data. Future orders will need immutable price/currency/license/support snapshots; draft offer edits must not rewrite historical purchases. Future submitted/published products will need retention/soft-delete decisions. Currency support is intentionally a small controlled list, not a payment integration or multi-currency conversion system.

Phase 3 ends here. Submission/review development has not started.
