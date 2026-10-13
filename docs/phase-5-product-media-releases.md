# Phase 5: Product media and versioned artifacts

Templaara now supports cover images, screenshot galleries, optional preview-video URLs, managed releases, private source ZIPs and uploaded documentation in the existing Laravel/Vue/Inertia/Wayfinder application. Fortify, RBAC, catalog, business content, offers and Phase 4 moderation remain authoritative. No storefront or customer downloads were added.

## Storage architecture

Native Laravel filesystem APIs handle local and S3-compatible storage. The native `league/flysystem-aws-s3-v3` adapter (3.35.3) was installed with its SDK dependencies; no media library was introduced. All application code uses Laravel Storage, not provider-specific APIs. See [Laravel filesystem documentation](https://laravel.com/docs/13.x/filesystem) for the filesystem/S3-compatible driver architecture.

`config/product_files.php` selects two independently configured disks:

- `PRODUCT_MEDIA_DISK=public`: presentation images, locally under storage/app/public. Production can select product_s3_public with a presentation bucket and optional CDN URL.
- `PRODUCT_ARTIFACT_DISK=product_private`: commercial files, locally under storage/app/product-private, outside the public root and the default served local disk. This disk has private visibility, serve=false and throwing failures. Production can select product_s3_private with a separate private bucket.

`config/filesystems.php` defines both S3-compatible disks using PRODUCT_STORAGE_ACCESS_KEY_ID, PRODUCT_STORAGE_SECRET_ACCESS_KEY, PRODUCT_STORAGE_REGION, PRODUCT_STORAGE_ENDPOINT and PRODUCT_STORAGE_PATH_STYLE. Public/private bucket names are separate; PRODUCT_STORAGE_PUBLIC_URL optionally configures the presentation/CDN base. Credentials belong in uncommitted environment configuration or production secret management.

Artifact upload fails closed for public/served disks, configured public URLs, local presentation/web roots, or a shared S3 presentation/private bucket. Production must disable anonymous/private-bucket access at the object-store policy level and grant only the application the required read/write/delete permissions. Disk visibility is not a substitute for bucket policy. No live object-store credentials were available; adapter construction with a custom endpoint was tested without a network request.

Presentation images intentionally have public asset URLs. Private paths/disks are hidden model attributes and are never returned in editor, reviewer, snapshot or listing props. Commercial downloads use authorized application streaming responses, with attachment disposition, application/octet-stream, private/no-store caching and nosniff. No public artifact URLs or customer download endpoints exist.

Local setup uses `php artisan storage:link` for the existing public disk. Paid packages must never be linked into public/storage.

## Schema and models

Three new migrations were applied:

- 2026_10_05_160000_create_product_media_and_releases.php
- 2026_10_05_163000_protect_product_release_and_file_references.php
- 2026_10_05_165000_index_product_file_reference_lookups.php

The second adds PostgreSQL release-content and cross-product reference guards without modifying already applied migrations. The third indexes media/artifact reverse-reference lookups for retention checks and release creators for account-retention checks.

### ProductMedia

product_media contains bigint ID/product FK, controlled type (cover/screenshot), disk/path, sanitized original name, actual MIME type, bigint size_bytes, image width/height, is_current, sort_order, nullable alt_text, timestamps.

Product has media(), cover() and screenshots() relationships. Current screenshots sort by sort_order then ID. Retained historical rows have is_current=false. PostgreSQL enforces positive size/dimensions, nonnegative ordering, controlled types and a partial unique index allowing at most one current cover. The path/disk pair is unique and file identity is immutable.

Zero cover images are permitted in drafts; new submissions require one. Metadata order/alt text can change on current editable media, while each review round retains its own frozen values.

### ProductRelease

product_releases contains ID/product FK, version, controlled status, required changelog, optional release_notes, nullable timezone-aware released_at, restrictive created_by user FK and timestamps. Version is unique per product; another product may use the same version. Product has releases(); Release belongs to Product/creator and has artifacts().

Versions use MAJOR.MINOR.PATCH with nonnegative integer components and no leading zeros except zero itself. Maximum length is 50. Prerelease/build labels and arbitrary descriptive strings are not supported as managed versions. The Phase 3 version_label remains descriptive and independent, and documentation_url remains available.

Release statuses are deliberately smaller than the product workflow:

| Product action        | Release result             |
| --------------------- | -------------------------- |
| Prepare               | draft                      |
| Submit / start review | in_review                  |
| Request changes       | draft                      |
| Reject                | rejected                   |
| Approve               | approved                   |
| Publish               | published, released_at set |

There are no independent release moderation endpoints. ProductWorkflow drives the submitted release inside the same transaction. The editor manages the latest-created prepared release; the schema permits multiple stable releases per product. Creating another release through the authorized backend preserves older records and versions. The current UI prepares/edits the latest release rather than introducing a separate version-management application.

Request Changes reopens the prepared release for corrections. Its live changelog/version may change while draft, but previous review snapshots preserve the original metadata and artifact identities. In-review/approved/published/rejected release content is frozen; PostgreSQL and Eloquent guard its identity/content. Published release state/time cannot be overwritten by ordinary updates. Future published updates will create a new release, not replace the existing package.

### ProductArtifact

product_artifacts contains ID/release FK, controlled type (source_package/documentation), disk/path, sanitized original name, actual MIME, positive bigint size_bytes, SHA-256 checksum, is_current and timestamps. A PostgreSQL partial unique index permits at most one current artifact of each type per release. Disk/path is unique; checksum must be 64 lowercase hexadecimal characters.

Replacing a file creates a new row and object name. The old row is retired or retained; its path/name/MIME/size/checksum/release/type cannot be overwritten. This rule applies even before submission.

### Immutable submission references and cleanup records

product_submission_media and product_submission_artifacts connect each immutable submission to the exact file rows it reviewed. They have IDs, restrictive foreign keys, timestamps and unique submission/file pairs. PostgreSQL checks that each referenced file belongs to the submission's product and prohibits updating/deleting those references. Restrictive references prevent deleting historical assets, including cascading deletion attempts.

product_file_cleanup stores disk/path, available_at and timestamps, with a unique disk/path pair and availability index. These records survive ordinary cleanup failures and support retrying abandoned uploads without an external queue service.

Product-only media/releases/artifacts cascade on eligible draft deletion. Submission references restrict historical deletion. Release creators cannot delete their account while releases reference it; existing ownership/moderation-account retention protections remain.

## Upload validation and storage names

Image uploads accept actual JPEG, PNG or WebP content and allowed extensions. Laravel validates the upload, image type, detected MIME, extension, size and dimensions; client MIME alone is insufficient. SVG, GIF and AVIF are excluded.

| Upload         | Default limit         | Dimensions / count                                    |
| -------------- | --------------------- | ----------------------------------------------------- |
| Cover          | 5120 KiB (5 MiB)      | 320×180 minimum, 8000×8000 maximum; one current cover |
| Screenshot     | 8192 KiB (8 MiB) each | 320×180 minimum, 10000×10000 maximum; at most 12      |
| Source package | 256000 KiB (250 MiB)  | ZIP only; configurable                                |
| Documentation  | 25600 KiB (25 MiB)    | PDF or ZIP; configurable                              |

Cover/screenshot/package/documentation limits are configurable through PRODUCT_COVER_MAX_KB, PRODUCT_SCREENSHOT_MAX_KB, PRODUCT_PACKAGE_MAX_KB and PRODUCT_DOCUMENTATION_MAX_KB. Documentation and preview video remain optional. Video is an HTTP/HTTPS URL, not a file upload or embedded provider script.

ZIP validation checks actual MIME/extension and structural validity with ZipArchive in read-only mode. It inspects central-directory metadata only: no extraction, decompression, execution or submitted-software runtime exists. Limits: 50,000 entries, 2 GiB total advertised expanded size, 1 GiB per entry, and compression ratio at most 1000 for entries larger than 1 MiB. Absolute paths, drive-letter paths, null bytes and parent traversal are rejected. This bounds inspection and guards obvious zip-slip/bomb structures; it is not malware scanning or proof that developer-supplied code is safe.

Original filenames are sanitized display metadata only. UUID storage names are generated server-side:

- products/{product-id}/media/{uuid}.{detected-image-extension}
- products/{product-id}/releases/{release-id}/{uuid}.{validated-archive-or-document-extension}

No raw user filename controls a storage path. Source/documentation SHA-256 is computed from the temporary uploaded file with hash_file, which streams the file. Laravel putFileAs streams uploaded content into the configured filesystem. Large artifact bodies are not loaded into PHP strings or PostgreSQL; snapshot JSONB contains metadata only.

## Filesystem and database failure handling

FileRetention centralizes file retirement, deletion eligibility and cleanup. Controllers do not call Storage::delete.

Before writing a generated object, ProductFiles creates a staged cleanup record with a one-hour grace period. It writes the object, then locks/refetches the product and reauthorizes within a database transaction before attaching validated metadata. Successful attachment removes the staged record transactionally.

If writing or database persistence fails, the service attempts immediate cleanup; failed cleanup remains recorded for retry. If a process dies after staging, the scheduled prune command removes the abandoned object after the grace period. Newly attached/live/historically retained objects are rechecked and never removed by cleanup. Outer transaction rollback registers compensating cleanup for newly written objects, and replacement rollback restores old database metadata without deleting the original file.

An unreferenced retired file queues deletion inside the metadata transaction. Physical deletion runs after commit. If storage deletion fails, the metadata transaction remains committed and the cleanup record remains retryable. Objects referenced by immutable submissions remain stored and their metadata remains retained. Ordinary author/admin edits do not prune history.

`php artisan products:prune-files` processes due cleanup records in bounded chunks and is scheduled hourly without overlapping. Production must run Laravel's scheduler. This is a compensation/retry design, not an assertion that PostgreSQL and object storage share an atomic transaction. Operational monitoring/backups and storage-provider lifecycle rules must respect retained review files.

Eligible draft deletion now uses DeleteProduct: product-row lock, delete-policy authorization, explicit refusal of any moderation history, queue eligible object cleanup, then delete product-only metadata transactionally. The restrictive moderation FKs provide an independent safeguard.

## Submission readiness and snapshots

All Phase 4 business-content/catalog/offer/demo rules remain. Every new submission additionally requires:

- One current stored cover image.
- At least two current stored screenshots.
- A prepared release with a valid managed version.
- Exactly one current private source ZIP for that release, with storage existence checks.

Errors identify the missing requirement. Draft saving still does not require these files. Uploaded documentation and preview video are optional.

New rounds use snapshot_version=2. Snapshot metadata includes media identity, filename/MIME/size/dimensions/order/alt text, preview_video_url, prepared release ID/version/changelog/notes, artifact identity/filename/MIME/size and server SHA-256. Durable reference rows attach those exact file IDs to the submission transactionally. No private disk/path or binary data is stored in exposed JSONB snapshots.

Media URLs are derived at display time from the retained file records; historical ordering/alt text/release/package metadata come from the frozen snapshot. Replacing current media/packages after Request Changes cannot change what a previous reviewer sees or downloads. Reviewer downloads verify reference membership in the requested round, rather than assuming a current release ID identifies the reviewed package.

Existing Phase 4 snapshot_version=1 rounds are not rewritten and do not retroactively require Phase 5 files to approve/publish. Their existing readiness rules are rechecked. Any subsequent new submission/resubmission uses version 2 and the expanded requirements. This compatibility path is tested.

## Authorization and routes

File operations reuse ProductPolicy update/view/review abilities and Phase 1 permissions. Authors need their own author-origin product and applicable permissions; multi-role users remain supported. Admins follow existing management rules and the same product freeze. Reviewers gain no upload/edit ownership, and customers gain no artifact access.

Mutation Form Requests authorize update and prohibit client status, ownership, disk/path, checksum, MIME/size, creator and current flags. Services reauthorize after locking and scope child media/releases/artifacts to their parent. Release/artifact operations also require a draft release. Actor IDs and file metadata come from authenticated/server context.

Editable product states are draft and changes_requested. All ordinary file mutations are denied in submitted, under_review, rejected, approved and published, for owners and admins. Policy checks are authoritative; UI visibility does not authorize operations.

Shared nested author/admin routes require auth and verified:

| Method | Path relative to /workspace/products/{product}    | Name prefix products.files. |
| ------ | ------------------------------------------------- | --------------------------- |
| POST   | /media                                            | media.store                 |
| PATCH  | /media/{media}                                    | media.update                |
| DELETE | /media/{media}                                    | media.destroy               |
| POST   | /releases                                         | releases.store              |
| PATCH  | /releases/{release}                               | releases.update             |
| POST   | /releases/{release}/artifacts                     | artifacts.store             |
| DELETE | /releases/{release}/artifacts/{artifact}          | artifacts.destroy           |
| GET    | /releases/{release}/artifacts/{artifact}/download | artifacts.download          |

Author/admin artifact downloads require ProductPolicy view plus matching product/release/artifact identities. They remain available for verification when product content is frozen.

Reviewer download: GET /admin/product-reviews/{submission}/artifacts/{artifact}/download, named admin.product-reviews.artifacts.download. It requires authentication, verification, products.review and exact submission/product/file membership. A reviewer may inspect historical rounds; they cannot use a different round to download the current corrected package. Customers and ordinary authors cannot use the review endpoint. No raw path or public signed customer URL is returned.

## Vue interface

Existing products/Form, ProductSummary and review detail are enhanced, using the same AppLayout, Inertia forms/toasts, Wayfinder and starter-kit components.

New ProductFilesEditor provides saved-draft cover preview/upload/replace/remove; multiple screenshot upload/replacement, previews, alt text/order inputs and removal; managed version/changelog/notes; source ZIP and optional PDF/ZIP documentation upload/replacement/removal; existing filename/size/checksum; authorized verification downloads; upload progress, processing state and validation summary. Files are independently saved after the product has a persistent identity. The create page tells the author to save first. Business-field saving remains the existing form operation, including optional preview-video URL.

New ProductFilesSummary displays cover/gallery/video, prepared releases, changelog/notes and file metadata in author/admin detail and frozen review detail. Review links carry the submission context. Technical package details follow business content rather than becoming the product's main identity.

No duplicate sidebars, frontend apps, state managers, media library, video uploader or custom design system were added.

## Operational configuration

The discovered local PHP defaults were upload_max_filesize=2M and post_max_size=8M. Laravel's configurable limits cannot override PHP/web-server request limits. For the 250 MiB package default, configure the serving PHP runtime with at least upload_max_filesize=300M and post_max_size=350M, plus appropriate proxy/body-size limits, request/upload timeouts and temporary-disk capacity. The maximum batch of twelve 8 MiB screenshots must also fit the request limit. These runtime limits were raised only on the disposable verification server; global PHP settings and the user's .env were not modified.

Production S3-compatible setup selects product_s3_public/product_s3_private, supplies custom endpoint/region/credentials and separate buckets, configures public presentation/CDN access, and keeps commercial bucket access private. S3 ACL behavior differs between compatible providers; verify bucket policy and application access with the deployed provider. Schedule products:prune-files through Laravel's scheduler and monitor repeated failures.

## Tests and verification

ProductFilesTest exercises media ownership, customer/reviewer denial, image MIME/extension/size/dimension limits, cover replacement/order/alt text, gallery limits, staged cleanup, SemVer/uniqueness/creator derivation, ZIP/PDF acceptance, checksum forgery, streamed private access, child/round scoping, submission requirements, frozen file mutation rules, requested corrections/resubmission retention, official products, draft deletion, legacy snapshots, missing storage, ZIP traversal/expansion, database rollback, delayed cleanup retries, and S3-compatible adapter construction. ProductWorkflowTest's ready-product fixtures now prepare real validated assets using shared ProductFileFixtures; its original workflow assertions are preserved.

Storage::fake is used with process-specific roots so SQLite/PostgreSQL test runs do not erase each other's files. PostgreSQL tests migrate an isolated random schema, wrap each case in a transaction and remove the schema/config afterward. They check current-cover uniqueness, valid versions/types, checksums, immutable file references and historical artifact FK protection alongside the full Phase 4 workflow tests.

Commands executed include:

```sh
composer require league/flysystem-aws-s3-v3:^3.0 --no-interaction --no-scripts
php artisan wayfinder:generate --with-form
php artisan migrate --no-interaction
php artisan storage:link --no-interaction
php artisan products:prune-files
php artisan test --compact --filter='ProductFilesTest|ProductWorkflowTest'
php artisan test --compact
vendor/bin/pint
vendor/bin/pint --test
composer types:check
npm run check:fix
npm run check
npm run types:check
npm run build
```

Composer scripts were suppressed during dependency installation to avoid invoking the unrelated existing Boost update hook. Normal Composer autoload generation completed; no existing dependencies were updated.

Browser verification used Playwright outside application manifests and existing Chrome against an isolated PostgreSQL schema and disposable presentation/private storage. It passed author uploads, image preview, video save, release creation, ZIP/PDF upload, checksum-confirmed author/reviewer downloads, submission, Request Changes, screenshot/package replacement, new submission round, old/new round download isolation, approval and admin publication, with no JavaScript errors. A final browser check also passed new-draft creation, retention of unsaved release edits across a media upload, release saving and published-content freeze. The temporary server, schema, presentation link, credentials and verification objects are cleaned up afterward.

Final results:

| Check                                              | Result                                                                                   |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Full Laravel suite, Phases 1–5                     | **227 passed, 5 skipped, 1363 assertions**                                               |
| Isolated PostgreSQL files + workflow suite         | **104 passed, 586 assertions**                                                           |
| New ProductFilesTest cases                         | **57** (PostgreSQL-only case skips under SQLite)                                         |
| PostgreSQL migrations                              | All three applied successfully                                                           |
| Browser                                            | Full upload/review/correction/resubmission/history sequence passed; no JavaScript errors |
| Pint                                               | Passed                                                                                   |
| Frontend lint/format, TypeScript, production build | Passed                                                                                   |
| PHPStan                                            | Only the pre-existing UserFactory::withTwoFactor() missing return                        |

Three full-suite skips concern disabled Fortify features, and two are PostgreSQL-only constraint cases exercised successfully by isolated PostgreSQL verification. The optional fontaine build warning remains.

## Files

Created:

- config/product_files.php.
- app/Enums/ProductMediaType.php, ProductArtifactType.php, ProductReleaseStatus.php.
- app/Models/ProductMedia.php, ProductRelease.php, ProductArtifact.php.
- The three migration files listed above.
- app/Services/Products/Files/ProductFiles.php, UploadValidation.php, FileRetention.php, FilePresentation.php, SubmissionFiles.php, DeleteProduct.php.
- app/Http/Requests/Products/Files/ProductFileRequest.php.
- app/Http/Controllers/ProductFileController.php.
- resources/js/components/products/ProductFilesEditor.vue, ProductFilesSummary.vue.
- resources/js/types/product-files.ts.
- tests/Support/ProductFileFixtures.php and tests/Feature/ProductFilesTest.php.
- This document.

Modified:

- composer.json/composer.lock; config/filesystems.php; .env.example.
- app/Models/Product.php; app/Http/Requests/Products/ProductRequest.php.
- app/Services/Products/ProductEditor.php.
- app/Services/Products/Workflow/SubmissionReadiness.php and ProductWorkflow.php.
- app/Http/Controllers/Admin/ProductReviewController.php, AdminProductController.php, Author/AuthorProductController.php.
- app/Http/Requests/Settings/ProfileDeleteRequest.php.
- routes/products.php and routes/console.php.
- resources/js/pages/products/Form.vue, product-reviews/Show.vue.
- resources/js/components/products/ProductSummary.vue; resources/js/types/product.ts.
- tests/Feature/ProductWorkflowTest.php readiness fixtures.
- Generated Wayfinder routes/actions and production build assets.

## Deliberate limits and deferred work

Private authorized downloads are owner/admin/reviewer verification tools only. Customer entitlements, signed customer delivery, range/resume delivery, download auditing/rate limits and commercial access control belong to future purchase/download phases.

No published-update moderation was built. The normalized release/artifact identities permit future 1.1.0 preparation alongside retained 1.0.0, but the current ProductPolicy intentionally freezes published-product mutations. A future revision workflow must explicitly authorize new prepared releases without editing published content.

Malware scanning, quarantine/verdict workflows and richer artifact integrity monitoring are recommended before production commercial distribution. Archive structural checks are not a malware verdict. Image optimization, media variants, video hosting, retention/anonymization policies and privileged archival remain future modules.

PHPStan's known empty UserFactory::withTwoFactor() return issue and the optional fontaine build warning remain unrelated technical debt. The workspace still has no Git repository for baseline/diff review. No Phase 5 storefront, cart, checkout, orders, payments, customer licensing, commissions/payouts, customer reviews or support tickets were implemented.

Development stops after Phase 5.
