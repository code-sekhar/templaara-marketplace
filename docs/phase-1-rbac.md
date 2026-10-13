# Templaara foundation and RBAC

Laravel 13.34.0, Vue 3.5.43, Inertia 3, TypeScript and Pest. Existing Fortify authentication is retained. Registration was disabled in the supplied starter kit; it is now enabled through Fortify's registration action and view. Password and profile validation reuse the existing concerns. User creation and customer assignment are atomic. Submitted role/permission fields are ignored.

## Setup

Keep the existing PostgreSQL connection in `.env`. Run:

```sh
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder
```

Run the RBAC seeder during deployment before accepting registrations. It creates four web-guard roles and 42 permissions, resets the permission cache and synchronizes each role's grants. Re-running it restores the defined role matrix without removing user role assignments or direct grants. Add future permissions and role grants in this seeder. Deploy seed changes with application code.

The published Spatie migration creates `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, and `role_has_permissions`, with unique keys, composite pivot keys, indexes and cascading role/permission foreign keys. It uses Laravel schema operations compatible with PostgreSQL. Entity tables have timestamps; package pivot tables follow Spatie conventions. Polymorphic model references cannot use conventional foreign keys. No marketplace tables or duplicate role columns exist. Soft deletion can be added with future domain requirements; it is not an authorization mechanism.

## Local initial admin

Set `APP_ENV=local`. Set `TEMPLAARA_ADMIN_NAME`, `TEMPLAARA_ADMIN_EMAIL` and `TEMPLAARA_ADMIN_PASSWORD` in your uncommitted `.env`, then run:

```sh
php artisan db:seed
```

Use a unique email and a password of at least 12 characters with upper/lowercase letters, numbers and symbols. DatabaseSeeder always seeds RBAC; it invokes DevelopmentAdminSeeder only in local/testing. With no credentials, no user is created. Incomplete or weak credentials fail validation. Existing emails cause an error rather than overwriting credentials or promoting an existing account. Clear the admin email/password variables after successful creation. Future RBAC reseeding can use RolesAndPermissionsSeeder alone. Directly invoking DevelopmentAdminSeeder outside local/testing is rejected. Production admin provisioning must be a separate controlled operational process; no default production account/password is supplied.

## Role matrix

- **admin:** all 42 defined permissions, including product publishing and finance.
- **reviewer:** authors.view, categories.view, industries.view, technologies.view, products.view, products.review, products.approve, products.soft-reject, products.reject. No publishing, finance, user management or settings management.
- **author:** catalog view permissions plus products.view/create/update/delete/submit. These are capability grants; future policies must additionally enforce ownership and submission status.
- **customer:** no global permissions. Future purchase, download, review and support authorization must enforce ownership.

Users can hold any combination of roles. Use `$user->assignRole('author')` to retain existing roles; `syncRoles` intentionally replaces them. No implicit admin bypass exists: admin permissions are explicitly seeded.

Permissions cover users (2), authors (3), categories/industries/technologies (4 each), products (10), orders/reviews/support (2 each), commissions/payouts/refunds (2 each), settings (2), reports (1). The complete names are in RolesAndPermissionsSeeder.

## Backend authorization

Aliases are registered in `bootstrap/app.php` using Spatie's singular `Middleware` namespace. Existing dashboard and settings protection remains intact. No demo or marketplace endpoints were added to application routes. The automated tests register temporary protected endpoints.

Future route middleware examples:

```php
['auth', 'role:admin']
['auth', 'role:admin|reviewer']
['auth', 'role:author']
['auth', 'role:customer']
['auth', 'permission:products.review']
['auth', 'can:products.review']
```

Prefer `can`/policies for actions and combine permissions with resource ownership checks. A capability alone must never authorize editing another author's product. Keep `auth` before role/permission middleware.

## Frontend

Inertia shares existing visible user attributes plus `roles` and effective `permissions` as name arrays; it does not serialize permission/role models or pivots. Guests receive `auth.user = null`. The existing User TypeScript contract gains both arrays. `useAuthorization()` reads reactive Inertia props and supplies `hasRole`, `hasAnyRole`, `can`, `canAny`; absent users return false/empty arrays. These checks only control UI visibility. The dashboard displays the user's name, roles and a product-review permission example.

## Verification

```sh
vendor/bin/pint --test
composer types:check
php artisan test
npm run check
npm run types:check
npm run build
```

The existing test suite uses SQLite in memory for isolated tests; application database configuration stays PostgreSQL. PostgreSQL migration checks should also be run against an isolated PostgreSQL database in CI. Never run `migrate:fresh` against the application database to test RBAC.

## Completion record

Installed `spatie/laravel-permission` 8.3.0 and its dependency `spatie/laravel-package-tools` 1.93.3, consistent with [Spatie's Laravel 13 compatibility documentation](https://spatie.be/docs/laravel-permission/v8/prerequisites).

Created:

- `app/Actions/Fortify/CreateNewUser.php`
- `config/permission.php`
- `config/templaara.php`
- `database/migrations/2026_10_05_082400_create_permission_tables.php`
- `database/seeders/RolesAndPermissionsSeeder.php`
- `database/seeders/DevelopmentAdminSeeder.php`
- `resources/js/composables/useAuthorization.ts`
- `resources/js/pages/auth/Register.vue`
- `tests/Feature/AuthorizationTest.php`
- `tests/Feature/DevelopmentAdminSeederTest.php`
- `docs/phase-1-rbac.md`

Modified:

- `composer.json` and `composer.lock`
- `app/Models/User.php`
- `app/Providers/FortifyServiceProvider.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `bootstrap/app.php`
- `config/fortify.php`
- `database/seeders/DatabaseSeeder.php`
- `.env.example`
- `resources/js/types/auth.ts`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/auth/Login.vue`
- `resources/js/pages/Welcome.vue`

Wayfinder regenerated route/action helpers; the frontend build regenerated `public/build` assets. No existing tests were deleted. Fifteen tests were added across the two new feature test files, covering registration and validation, all role boundaries, multiple roles, effective Inertia permissions, repeatable role seeding, and admin seeding safeguards.

Executed: project/dependency/authentication inspection, `composer require spatie/laravel-permission --no-interaction`, `php artisan vendor:publish --tag=permission-config --tag=permission-migrations --no-interaction`, `php artisan wayfinder:generate --with-form`, Pint formatting on changed PHP files, `npx vp fmt` on changed frontend/documentation files, `vendor/bin/pint --test`, `composer types:check`, `php artisan test --compact`, `npm run check`, `npm run types:check`, `npm run build`, `php artisan migrate --no-interaction`, and `php artisan db:seed --class=RolesAndPermissionsSeeder --no-interaction`.

Results: PostgreSQL migration and RBAC seeding succeeded. Pest: 36 passed, 3 skipped, 157 assertions (39 total). Pint, frontend formatting/lint, Vue TypeScript and production build passed. PHPStan reports one pre-existing error at `database/factories/UserFactory.php:49`: the empty `withTwoFactor(): static` method lacks a return. This was left unchanged. Composer package installation/discovery succeeded, but its existing post-update `boost:update` hook exited with an error because Boost has not been installed/configured. The build reports an optional `fontaine` font-fallback warning. The workspace has no Git repository, so changes could not be compared with a Git baseline and Pint's `--dirty` mode is unavailable.

Phase 1 ends here. Product, order, payment, cart, submission, and real role-specific dashboards remain future work.
