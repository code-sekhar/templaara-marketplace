# Phase 2: Business catalog foundation

Templaara now manages Categories (what software does), Industries (the business sector), and Technologies (secondary implementation metadata). The existing Laravel 13/Vue 3/Inertia architecture, Fortify authentication and Phase 1 permission matrix are retained. No packages were added. No Product or other marketplace domain is implemented.

## Schema and models

Migration: `2026_10_05_100000_create_business_catalog_tables.php`.

All three tables have bigint `id`, `name` (255), unique `slug` (200), nullable text `description`, integer `sort_order` default 0, boolean `is_active` default true, and timestamps. An index on `(is_active, sort_order, name)` supports public filtering and ordering.

- `categories`: nullable `icon` (255) and indexed nullable `parent_id` referencing `categories.id`, with **RESTRICT ON DELETE**.
- `industries`: nullable `icon` (255).
- `technologies`: nullable `logo` (255) and `website_url` (2048). Independent of categories and industries.

PostgreSQL additionally enforces nonnegative sort orders and rejects category self-parenting with CHECK constraints. SQLite isolated tests use portable schema operations; request and service validation also enforce these rules.

`Category`, `Industry`, and `Technology` use explicit fillable attributes, boolean/integer casts, factories and shared `active()`/`ordered()` query scopes. Category has `parent()` (belongsTo) and `children()` (hasMany). No soft deletes are introduced without a business requirement; deactivation is available when a record should remain stored but disappear from public discovery.

## Routes and authorization

`routes/catalog.php` is included from existing `routes/web.php`. For each of `categories`, `industries`, `technologies`:

| Method | Path                      | Named route suffix | Required permission |
| ------ | ------------------------- | ------------------ | ------------------- |
| GET    | `/admin/{kind}`           | `index`            | `{kind}.view`       |
| GET    | `/admin/{kind}/create`    | `create`           | `{kind}.create`     |
| POST   | `/admin/{kind}`           | `store`            | `{kind}.create`     |
| GET    | `/admin/{kind}/{id}/edit` | `edit`             | `{kind}.update`     |
| PUT    | `/admin/{kind}/{id}`      | `update`           | `{kind}.update`     |
| DELETE | `/admin/{kind}/{id}`      | `destroy`          | `{kind}.delete`     |

Names are `admin.categories.index`, etc. Route-model parameters are `category`, `industry`, `technology`. All management routes require `auth`, `verified` and Laravel `can` middleware. Mutation Form Requests also authorize the required create/update permission. Existing admin grants allow full management; authors and reviewers have catalog view access only; customers have none by default. Explicit permission grants work independently of role names. UI visibility uses existing `useAuthorization()` but never authorizes a backend operation.

Three thin admin controllers delegate listing and persistence to `CatalogListing` and `SaveCatalogRecord`. Listings eager-load category parents, paginate 15 records, and support case-insensitive name/slug search (LIKE wildcards escaped), active/inactive status, sort order/name/newest sorting, and category parent/root filters. Ordering includes an ID tie-breaker. Parent selectors use lightweight ID/name collections; form selectors include active parents and the existing parent if inactive. Descendant selections are rejected by the server.

## Validation and slug behavior

Form Requests reuse a small common `CatalogRequest`, extended by the three domain requests:

- Required name, max 255 characters.
- Optional slug, max 200; lowercase ASCII letters/numbers separated by single hyphens. Manual values must be unique; updates ignore only the route-bound current record.
- Optional description, max 5000.
- Required integer sort order, 0 through 1,000,000.
- Required boolean active status.
- Optional icon/logo string, max 255.
- Optional technology website: valid HTTP/HTTPS URL, max 2048.
- Optional category parent: existing integer ID, not the current category.

Blank create slugs are generated on the backend using Laravel `Str::slug()`: `HR & Payroll` becomes `hr-payroll`. Collisions receive numeric suffixes starting at 2. Names with no transliterable slug use `catalog`. Generated bases are truncated to leave room for suffixes. On update, an omitted slug retains its existing value; explicitly blank regenerates it from the name. The frontend edit form initially keeps the existing slug.

The database unique constraint remains authoritative. Generated-slug conflicts retry up to five times in fresh transactions; manual conflicts and exhausted retries return validation errors. Category hierarchy validation traverses parent IDs to reject cycles. PostgreSQL category writes use a transaction-scoped advisory lock to serialize hierarchy changes, avoiding concurrent cycle creation. This is a small consistency safeguard rather than a tree-management subsystem.

## Safe deletion

Deleting a category with children returns an actionable validation error. Reassign/remove children first. The restrictive foreign key independently prevents unsafe deletion, including concurrent child insertion. Deletion runs in a transaction and foreign-key failures become an error in the confirmation dialog. There are no cascades from catalog metadata into future business data. Leaf categories and unreferenced industries/technologies can be deleted. Future Product foreign keys must also restrict deletion; product relations must not be cascaded away when deleting catalog records.

Icon/logo values are stored as plain optional identifiers/asset paths. There are no uploads, remote fetches, HTML rendering, or media-library abstractions in this phase. Descriptions render as ordinary escaped Vue text/input values.

## Seed data

`BusinessCatalogSeeder` inserts by unique slug with `firstOrCreate`, preserving existing admin edits, active status and sort values. Running it repeatedly does not duplicate unchanged seed identities. Renaming a seeded slug intentionally creates a new identity; running the seeder later may recreate the original seed slug.

- Categories (10): POS & Inventory, HR & Payroll, CRM, Accounting & Finance, Field Service Management, Ecommerce, Booking & Reservation, Project Management, Business Analytics, Customer Support.
- Industries (11): Retail, Wholesale & Distribution, Restaurant & Food Service, Healthcare, Education, Real Estate, Construction, Manufacturing, Logistics & Transportation, Professional Services, Hospitality.
- Technologies (10): Laravel, Vue.js, React, Angular, Node.js, Spring Boot, Flutter, PostgreSQL, MySQL, Redis.

Seed entries are active with sort order increments of 10. Seed categories are roots; admins can organize parents. `DatabaseSeeder` invokes this sample catalog seeder only in local/testing, after the existing development admin seeder. Production uses migration plus explicit curated data; sample seeding is opt-in through the command below.

```sh
php artisan migrate --no-interaction
php artisan db:seed --class=BusinessCatalogSeeder --no-interaction
```

No Phase 1 role grants were changed. Existing local admin seeding safeguards still apply when running the full DatabaseSeeder.

## Public read access

Unauthenticated read-only JSON endpoints:

- `GET /catalog/categories` (`catalog.categories`)
- `GET /catalog/industries` (`catalog.industries`)
- `GET /catalog/technologies` (`catalog.technologies`)

They return Laravel paginated JSON with `data` and pagination metadata. Defaults: 50 per page; `per_page` accepts 1–100; `page` selects the page. Only active rows are returned, sorted by sort order, name, then ID. Public fields are ID/name/slug/description and icon or logo/website. Category includes parent ID only when its parent is active. No timestamps, active flags, sort metadata or permission data are serialized. Category parent lookups are eager-loaded.

Future backend code can compose `Category::active()->ordered()`, `Industry::active()->ordered()`, or `Technology::active()->ordered()` with additional filters and pagination. This phase provides metadata, not a storefront. Categories and industries precede technology in the management navigation; technology is not primary marketplace navigation.

## Vue implementation

The existing AppLayout automatically wraps `admin/catalog/Index.vue` and `admin/catalog/Form.vue`. These shared pages support all three resources, driven by typed `kind` props; each domain retains its own controller, request, model and routes. `resources/js/lib/catalog.ts` maps generated Wayfinder route helpers and labels; `resources/js/types/catalog.ts` provides record/filter/pagination types.

Lists include search, filters, responsive horizontal table overflow, empty state, pagination, conditional create/edit/delete actions, loading feedback and a reused dialog for delete confirmation/errors. Forms include validation messages, submission loading state, cancel navigation and all domain fields. Successful writes use the existing Inertia flash toast mechanism. Sidebar links are reactive and permission-aware. Existing button/input/label/dialog/spinner components are reused; native select/textarea/checkbox controls keep the forms small.

## Future Product relationships

Recommended direction, not implemented:

- Product belongs to one primary Category using a restrictive `category_id` foreign key.
- Product belongs to many Industries through a uniquely indexed product/industry pivot.
- Product belongs to many Technologies through a uniquely indexed product/technology pivot.

Deleting catalog records should be prevented while referenced. Deleting a Product may remove its own pivot rows without deleting Industries or Technologies. Future product ownership/status policies must supplement global permissions. No Product model, table or pivot is created now.

## Tests and commands

`tests/Feature/BusinessCatalogTest.php` adds 41 executed cases through Pest datasets. Coverage includes all three resources' CRUD and access boundaries, explicit permission grants, manual duplicate slugs, generated collisions, update slug stability, validation, hierarchy/cycles, FK deletion safety, URL schemes, active-only public data and parent visibility, bounded pagination, listing filters and idempotent seeding. All Phase 1 tests remain.

Run:

```sh
vendor/bin/pint --test
composer types:check
php artisan test --compact
npm run check
npm run types:check
npm run build
```

Implementation commands also included `php artisan wayfinder:generate --with-form`, Pint on changed PHP files, `npx vp fmt` on changed frontend files, `php artisan route:list --path=admin --except-vendor`, and `php artisan route:list --path=catalog --except-vendor`. Migrations were applied to the configured PostgreSQL database, and BusinessCatalogSeeder was run twice successfully.

## Files

Created:

- Migration: `database/migrations/2026_10_05_100000_create_business_catalog_tables.php`.
- Models: `app/Models/Category.php`, `Industry.php`, `Technology.php`, `app/Models/Concerns/HasCatalogScopes.php`.
- Factories: `database/factories/CategoryFactory.php`, `IndustryFactory.php`, `TechnologyFactory.php`.
- Requests: `app/Http/Requests/Catalog/CatalogRequest.php`, `CategoryRequest.php`, `IndustryRequest.php`, `TechnologyRequest.php`.
- Controllers: `app/Http/Controllers/Admin/CategoryController.php`, `IndustryController.php`, `TechnologyController.php`, `app/Http/Controllers/PublicCatalogController.php`.
- Services: `app/Services/Catalog/CatalogListing.php`, `SaveCatalogRecord.php`.
- Routes: `routes/catalog.php`.
- Seeder: `database/seeders/BusinessCatalogSeeder.php`.
- Vue pages: `resources/js/pages/admin/catalog/Index.vue`, `Form.vue`.
- Frontend support: `resources/js/lib/catalog.ts`, `resources/js/types/catalog.ts`.
- Tests: `tests/Feature/BusinessCatalogTest.php`.
- Documentation: this file.

Modified: `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `resources/js/components/AppSidebar.vue`. Wayfinder generated route/action helpers and `public/build` assets were regenerated. No package manifests, authentication code, shared auth props or permission assignments were changed.

## Quality results and existing issues

All 41 catalog cases also passed on PostgreSQL (328 assertions) using a temporary, isolated schema and transaction rollback between cases. The schema and credential-bearing temporary files were removed after verification; application records were untouched. The temporary harness explicitly configured the Laravel testing environment and in-memory cache/session drivers.

The full isolated SQLite suite passes: **77 passed, 3 skipped, 485 assertions** (80 total). Pint, frontend formatting/lint, Vue TypeScript and production build pass. PHPStan reports only the pre-existing empty `database/factories/UserFactory.php:49` `withTwoFactor(): static` method; no Phase 2 static-analysis errors remain. The existing optional `fontaine` font-fallback build warning remains. The workspace has no Git repository, so no Git diff is available.

Phase 2 ends here. Product development has not started.
