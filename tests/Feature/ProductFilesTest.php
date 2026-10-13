<?php

use App\Enums\ProductArtifactType;
use App\Enums\ProductMediaType;
use App\Enums\ProductOrigin;
use App\Enums\ProductReleaseStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductMedia;
use App\Models\ProductOffer;
use App\Models\ProductRelease;
use App\Models\ProductSubmission;
use App\Models\Technology;
use App\Models\User;
use App\Services\Products\Files\FileRetention;
use App\Services\Products\Files\ProductFiles;
use App\Services\Products\Workflow\ProductWorkflow;
use App\Services\Products\Workflow\SubmissionReadiness;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\Support\ProductFileFixtures as Files;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Files::fakeStorage();
});
function fileAuthor(): User
{
    return User::factory()->create()->assignRole(['customer', 'author']);
}
function fileProduct(?User $owner = null, bool $official = false): Product
{
    $product = Product::factory()->create([
        'owner_id' => $official ? null : ($owner ?? fileAuthor())->id,
        'origin' => $official ? ProductOrigin::Templaara : ProductOrigin::Author,
        'category_id' => Category::factory()->create()->id,
        'short_description' => 'Inventory and billing software for retail and wholesale businesses.',
        'description' => 'Manage billing, purchases, stock and customer dues in one reliable business workspace.',
        'business_problem' => 'Replace manual stock tracking and fragmented billing records.',
        'target_audience' => ['Retail businesses'], 'key_benefits' => ['Faster billing and accurate stock visibility'], 'demo_url' => 'https://demo.example.com',
    ]);
    $product->industries()->attach(Industry::factory()->create());
    $product->technologies()->attach(Technology::factory()->create());
    ProductFeature::factory()->for($product)->create();
    ProductOffer::factory()->for($product)->create();

    return $product;
}
function fileRelease(Product $product, ?User $actor = null): ProductRelease
{
    return app(ProductFiles::class)->saveRelease($actor ?? $product->owner, $product, ['version' => '1.0.0', 'changelog' => 'Initial software release.']);
}
function fileMediaUpload(Product $product, string $type = 'cover', ?UploadedFile $file = null): array
{
    return ['type' => $type, 'files' => [$file ?? UploadedFile::fake()->image('image.jpg', 640, 360)]];
}

test('author uploads cover and screenshots with safe metadata ordering and unique cover', function () {
    $product = fileProduct();
    $this->actingAs($product->owner)->post(route('products.files.media.store', $product), fileMediaUpload($product))->assertRedirect();
    $cover = $product->media()->firstOrFail();
    expect($cover->getAttribute('type'))->toBe(ProductMediaType::Cover)->and($cover->getAttribute('width'))->toBe(640);
    Storage::disk($cover->disk)->assertExists($cover->path);
    expect(basename($cover->path))->not->toBe('image.jpg');
    $this->post(route('products.files.media.store', $product), ['type' => 'screenshot', 'files' => [UploadedFile::fake()->image('first.webp', 640, 360), UploadedFile::fake()->image('second.png', 640, 360)]])->assertRedirect();
    $screen = $product->media()->where('type', 'screenshot')->firstOrFail();
    $this->patch(route('products.files.media.update', [$product, $screen]), ['sort_order' => 20, 'alt_text' => 'Retail billing workspace'])->assertRedirect();
    expect($screen->fresh()->getAttribute('sort_order'))->toBe(20)->and($screen->fresh()->getAttribute('alt_text'))->toBe('Retail billing workspace');
    $this->get(route('author.products.show', $product))->assertInertia(fn (Assert $page) => $page->has('product.media', 3)->missing('product.media.0.path')->missing('product.media.0.disk'));
});

test('media upload denies guests customers reviewers and other authors', function () {
    $product = fileProduct();
    $url = route('products.files.media.store', $product);
    $this->post($url, fileMediaUpload($product))->assertRedirect(route('login'));
    foreach ([fileAuthor(), User::factory()->create()->assignRole('customer'), User::factory()->create()->assignRole('reviewer')] as $actor) {
        $this->actingAs($actor)->post($url, fileMediaUpload($product))->assertForbidden();
    }
    expect($product->media()->count())->toBe(0);
});

test('unsafe images are rejected', function (string $case) {
    $product = fileProduct();
    $file = match ($case) {
        'svg' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        'forged' => UploadedFile::fake()->createWithContent('image.jpg', '<?php echo "unsafe";'),
        'extension' => UploadedFile::fake()->image('image.gif', 640, 360),
        'small' => UploadedFile::fake()->image('image.jpg', 20, 20),
        default => UploadedFile::fake()->image('image.jpg', 640, 360)->size(5121),
    };
    $this->actingAs($product->owner)->post(route('products.files.media.store', $product), fileMediaUpload($product, file: $file))->assertSessionHasErrors('file');
    expect($product->media()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBeEmpty();
})->with(['svg', 'forged', 'extension', 'small', 'oversized']);

test('screenshot limits and cross product child binding are enforced without orphaned staged files', function () {
    $product = fileProduct();
    config()->set('product_files.screenshots_max', 2);
    $files = app(ProductFiles::class);
    $files->uploadMedia($product->owner, $product, ProductMediaType::Screenshot, [UploadedFile::fake()->image('one.jpg', 640, 360), UploadedFile::fake()->image('two.jpg', 640, 360)]);
    $this->actingAs($product->owner)->post(route('products.files.media.store', $product), fileMediaUpload($product, 'screenshot'))->assertSessionHasErrors('files');
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
    $other = fileProduct($product->owner);
    $screen = $product->media()->firstOrFail();
    $this->delete(route('products.files.media.destroy', [$other, $screen]))->assertNotFound();
    $this->patch(route('products.files.media.update', [$other, $screen]), ['sort_order' => 0])->assertNotFound();
});

test('unsubmitted replacements and removals clean old images and keep one cover', function () {
    $product = fileProduct();
    $files = app(ProductFiles::class);
    $files->uploadMedia($product->owner, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('first.jpg', 640, 360)]);
    $old = $product->media()->firstOrFail();
    $files->uploadMedia($product->owner, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('second.jpg', 640, 360)]);
    Storage::disk($old->disk)->assertMissing($old->path);
    expect($product->media()->where('type', 'cover')->where('is_current', true)->count())->toBe(1);
    $cover = $product->media()->firstOrFail();
    $files->removeMedia($product->owner, $product, $cover);
    Storage::disk($cover->disk)->assertMissing($cover->path);
});

test('versions require semantic format and are unique only within their product', function (string $version) {
    $product = fileProduct();
    $this->actingAs($product->owner)->post(route('products.files.releases.store', $product), ['version' => $version, 'changelog' => 'Initial release'])->assertSessionHasErrors('version');
})->with(['1', '1.0', 'v1.0.0', '01.0.0', '1.0.0-beta', '1.0.0.1']);

test('release create and update derive creator and reject duplicate versions', function () {
    $product = fileProduct();
    $author = $product->owner;
    $this->actingAs($author)->post(route('products.files.releases.store', $product), ['version' => '1.0.0', 'changelog' => 'Initial business release'])->assertRedirect();
    $release = $product->releases()->firstOrFail();
    expect($release->getAttribute('created_by'))->toBe($author->id);
    $this->post(route('products.files.releases.store', $product), ['version' => '1.0.0', 'changelog' => 'Duplicate'])->assertSessionHasErrors('version');
    $this->patch(route('products.files.releases.update', [$product, $release]), ['version' => '1.0.1', 'changelog' => 'Corrected billing'])->assertRedirect();
    $other = fileProduct($author);
    $this->post(route('products.files.releases.store', $other), ['version' => '1.0.0', 'changelog' => 'Other software'])->assertRedirect();
    $this->post(route('products.files.releases.store', $other), ['version' => '2.0.0', 'changelog' => 'Forged creator', 'created_by' => 999])->assertSessionHasErrors('created_by');
});

test('private ZIP and PDF documentation are streamed with server checksums and no raw paths', function () {
    $product = fileProduct();
    $release = fileRelease($product);
    $zip = Files::zip();
    $checksum = hash_file('sha256', $zip->getPathname());
    $this->actingAs($product->owner)->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => $zip])->assertRedirect();
    $package = $release->artifacts()->firstOrFail();
    expect($package->getAttribute('checksum'))->toBe($checksum)->and($package->disk)->toBe('product_private');
    Storage::disk('public')->assertMissing($package->path);
    Storage::disk($package->disk)->assertExists($package->path);
    $this->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'documentation', 'file' => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF")])->assertRedirect();
    $response = $this->get(route('products.files.artifacts.download', [$product, $release, $package]))->assertOk()->assertDownload('software.zip')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(hash('sha256', $response->streamedContent()))->toBe($checksum);
    $this->get(route('author.products.show', $product))->assertInertia(fn (Assert $page) => $page->has('product.releases.0.artifacts', 2)->missing('product.releases.0.artifacts.0.path')->missing('product.releases.0.artifacts.0.disk'));
    $this->get('/storage/'.$package->path)->assertForbidden();
});

test('package MIME extension corruption size and forged checksum fail validation', function (string $case) {
    $product = fileProduct();
    $release = fileRelease($product);
    $payload = ['type' => 'source_package', 'file' => Files::zip()];
    if ($case === 'invalid') {
        $payload['file'] = UploadedFile::fake()->createWithContent('software.zip', 'not a zip');
    }
    if ($case === 'executable') {
        $payload['file'] = UploadedFile::fake()->createWithContent('software.exe', 'executable');
    }
    if ($case === 'checksum') {
        $payload['checksum'] = str_repeat('a', 64);
    }
    if ($case === 'size') {
        config()->set('product_files.package_max_kb', 0);
    }
    $this->actingAs($product->owner)->post(route('products.files.artifacts.store', [$product, $release]), $payload)->assertSessionHasErrors($case === 'checksum' ? 'checksum' : 'file');
    expect($release->artifacts()->count())->toBe(0);
})->with(['invalid', 'executable', 'checksum', 'size']);

test('artifact access denies customers other authors and mismatched child identities', function () {
    $product = fileProduct();
    Files::prepare($product);
    $release = $product->releases()->firstOrFail();
    $package = $release->artifacts()->firstOrFail();
    $url = route('products.files.artifacts.download', [$product, $release, $package]);
    $this->get($url)->assertRedirect(route('login'));
    foreach ([fileAuthor(), User::factory()->create()->assignRole('customer'), User::factory()->create()->assignRole('reviewer')] as $actor) {
        $this->actingAs($actor)->get($url)->assertForbidden();
    }
    $other = fileProduct($product->owner);
    $this->actingAs($product->owner)->get(route('products.files.artifacts.download', [$other, $release, $package]))->assertNotFound();
});

test('new submissions require complete media and private release files', function (string $missing) {
    $product = fileProduct();
    Files::prepare($product);
    $files = app(ProductFiles::class);
    $release = $product->releases()->firstOrFail();
    if ($missing === 'cover') {
        $files->removeMedia($product->owner, $product, $product->media()->where('type', 'cover')->firstOrFail());
    }
    if ($missing === 'screenshots') {
        $files->removeMedia($product->owner, $product, $product->media()->where('type', 'screenshot')->firstOrFail());
    }
    if ($missing === 'release') {
        foreach ($release->artifacts as $file) {
            $files->removeArtifact($product->owner, $product, $release, $file);
        }$release->delete();
    }
    if ($missing === 'package') {
        $files->removeArtifact($product->owner, $product, $release, $release->artifacts()->firstOrFail());
    }
    $field = match ($missing) {
        'cover' => 'media.cover','screenshots' => 'media.screenshots','release' => 'release',default => 'release.package'
    };
    $this->actingAs($product->owner)->post(route('author.products.submit', $product))->assertSessionHasErrors($field);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft)->and($product->submissions()->count())->toBe(0);
})->with(['cover', 'screenshots', 'release', 'package']);

test('snapshot freezes media and packages and review access is scoped to exact round', function () {
    $product = fileProduct();
    Files::prepare($product);
    $workflow = app(ProductWorkflow::class);
    $round = $workflow->transition($product->owner, $product, ProductWorkflowAction::Submit);
    expect($round->getAttribute('snapshot_version'))->toBe(2)->and($round->snapshot['media'])->toHaveCount(3)->and($round->snapshot['release']['version'])->toBe('1.0.0');
    $package = $product->releases()->firstOrFail()->artifacts()->firstOrFail();
    expect($round->snapshot['release']['artifacts'][0]['checksum'])->toBe($package->getAttribute('checksum'))->and($round->snapshot['release']['artifacts'][0])->not->toHaveKey('path');
    $reviewer = User::factory()->create()->assignRole('reviewer');
    $url = route('admin.product-reviews.artifacts.download', [$round, $package]);
    $this->actingAs($reviewer)->get($url)->assertOk()->assertDownload('software.zip');
    $other = fileProduct();
    Files::prepare($other);
    $otherPackage = $other->releases()->firstOrFail()->artifacts()->firstOrFail();
    $this->get(route('admin.product-reviews.artifacts.download', [$round, $otherPackage]))->assertNotFound();
    $this->actingAs($product->owner)->get($url)->assertForbidden();
    $this->post(route('products.files.media.store', $product), fileMediaUpload($product))->assertForbidden();
    $workflow->transition($reviewer, $product, ProductWorkflowAction::StartReview, $round->id);
    $this->post(route('products.files.artifacts.store', [$product, $package->release]), ['type' => 'source_package', 'file' => Files::zip()])->assertForbidden();
});

test('changes requested replacements and resubmission preserve all historical files', function () {
    $product = fileProduct();
    Files::prepare($product);
    $files = app(ProductFiles::class);
    $workflow = app(ProductWorkflow::class);
    $round = $workflow->transition($product->owner, $product, ProductWorkflowAction::Submit);
    $reviewer = User::factory()->create()->assignRole('reviewer');
    $workflow->transition($reviewer, $product, ProductWorkflowAction::StartReview, $round->id);
    $workflow->transition($reviewer, $product, ProductWorkflowAction::RequestChanges, $round->id, ['feedback' => [['section' => 'general', 'message' => 'Improve the software screenshots and package.']]]);
    $release = $product->releases()->firstOrFail();
    expect($release->status)->toBe(ProductReleaseStatus::Draft);
    $oldMedia = $product->media()->where('type', 'screenshot')->firstOrFail();
    $oldPackage = $release->artifacts()->firstOrFail();
    $files->uploadMedia($product->owner, $product->fresh(), ProductMediaType::Screenshot, [UploadedFile::fake()->image('corrected.jpg', 640, 360)], $oldMedia->id);
    $files->uploadArtifact($product->owner, $product->fresh(), $release, ProductArtifactType::SourcePackage, Files::zip('Corrected release', 'corrected.zip'));
    $second = $workflow->transition($product->owner, $product, ProductWorkflowAction::Submit);
    expect($second->round_number)->toBe(2)->and($oldMedia->fresh()->is_current)->toBeFalse()->and($oldPackage->fresh()->is_current)->toBeFalse()
        ->and($round->fresh()->snapshot['release']['artifacts'][0]['checksum'])->toBe($oldPackage->getAttribute('checksum'));
    Storage::disk($oldMedia->disk)->assertExists($oldMedia->path);
    Storage::disk($oldPackage->disk)->assertExists($oldPackage->path);
    $this->actingAs($reviewer)->get(route('admin.product-reviews.artifacts.download', [$round, $oldPackage]))->assertOk();
    $newPackage = $release->artifacts()->where('is_current', true)->firstOrFail();
    $this->get(route('admin.product-reviews.artifacts.download', [$round, $newPackage]))->assertNotFound();
    $this->get(route('admin.product-reviews.artifacts.download', [$second, $oldPackage]))->assertNotFound();
    $this->actingAs($product->owner)->delete(route('author.products.destroy', $product))->assertForbidden();
});

test('draft deletion cleans media artifacts and release metadata safely', function () {
    $product = fileProduct();
    Files::prepare($product);
    $media = $product->media()->get();
    $artifacts = $product->releases()->firstOrFail()->artifacts;
    $this->actingAs($product->owner)->delete(route('author.products.destroy', $product))->assertRedirect();
    foreach ($media->concat($artifacts) as $file) {
        Storage::disk($file->disk)->assertMissing($file->path);
    }
    expect(ProductRelease::count())->toBe(0)->and(ProductMedia::count())->toBe(0)->and(DB::table('product_file_cleanup')->count())->toBe(0);
});

test('official products follow the same upload and publication requirements', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $product = fileProduct(official: true);
    Files::prepare($product, $admin);
    $workflow = app(ProductWorkflow::class);
    $round = $workflow->transition($admin, $product, ProductWorkflowAction::Submit);
    $workflow->transition($admin, $product, ProductWorkflowAction::StartReview, $round->id);
    $workflow->transition($admin, $product, ProductWorkflowAction::Approve, $round->id);
    $workflow->transition($admin, $product, ProductWorkflowAction::Publish);
    $release = $product->releases()->firstOrFail();
    expect($release->status)->toBe(ProductReleaseStatus::Published)->and($release->getAttribute('released_at'))->not->toBeNull();
    $this->actingAs($admin)->post(route('products.files.media.store', $product), fileMediaUpload($product))->assertForbidden();
});

test('legacy phase four rounds can finish without rewriting snapshots or requiring new files', function () {
    $product = fileProduct();
    $snapshot = app(SubmissionReadiness::class)->validate($product, requireFiles: false);
    $round = new ProductSubmission;
    $round->forceFill(['product_id' => $product->id, 'round_number' => 1, 'submitted_by' => $product->owner_id, 'submitted_at' => now(), 'snapshot_version' => 1, 'snapshot' => $snapshot])->save();
    $originalSnapshot = $round->fresh()->snapshot;
    $product->forceFill(['status' => ProductStatus::Submitted])->save();
    $admin = User::factory()->create()->assignRole('admin');
    $workflow = app(ProductWorkflow::class);
    $workflow->transition($admin, $product, ProductWorkflowAction::StartReview, $round->id);
    $workflow->transition($admin, $product, ProductWorkflowAction::Approve, $round->id);
    $workflow->transition($admin, $product, ProductWorkflowAction::Publish);
    expect($product->fresh()->status)->toBe(ProductStatus::Published)->and($round->fresh()->snapshot)->toBe($originalSnapshot)->and($round->fresh()->getAttribute('snapshot_version'))->toBe(1);
});

test('cleanup retries abandoned staging and never removes referenced live storage', function () {
    $retention = app(FileRetention::class);
    Storage::disk('product_private')->put('abandoned.zip', 'content');
    $job = $retention->schedule('product_private', 'abandoned.zip');
    $this->artisan('products:prune-files')->assertSuccessful();
    Storage::disk('product_private')->assertMissing('abandoned.zip');
    $product = fileProduct();
    Files::prepare($product);
    $cover = $product->media()->firstOrFail();
    $retention->schedule($cover->disk, $cover->path);
    $this->artisan('products:prune-files')->assertSuccessful();
    Storage::disk($cover->disk)->assertExists($cover->path);
});

test('insecure artifact disk configuration fails closed', function () {
    $product = fileProduct();
    $release = fileRelease($product);
    config()->set('product_files.artifact_disk', 'public');
    $this->actingAs($product->owner)->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => Files::zip()])->assertSessionHasErrors('file');
});

test('PostgreSQL file constraints enforce covers versions artifacts and immutable references', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL file integrity verification.');
    }
    $product = fileProduct();
    Files::prepare($product);
    $cover = $product->media()->where('type', 'cover')->firstOrFail();
    expect(fn () => DB::transaction(function () use ($cover) {
        $copy = $cover->replicate();
        $copy->forceFill(['path' => 'different.jpg'])->save();
    }))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('product_media')->where('id', $cover->id)->update(['type' => 'svg'])))->toThrow(QueryException::class);
    $release = $product->releases()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('product_releases')->where('id', $release->id)->update(['version' => 'bad'])))->toThrow(QueryException::class);
    $package = $release->artifacts()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('product_artifacts')->where('id', $package->id)->update(['checksum' => 'forged'])))->toThrow(QueryException::class);
    $round = app(ProductWorkflow::class)->transition($product->owner, $product, ProductWorkflowAction::Submit);
    expect(fn () => DB::transaction(fn () => DB::table('product_submission_media')->where('product_submission_id', $round->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('product_artifacts')->where('id', $package->id)->delete()))->toThrow(QueryException::class);
});

test('all frozen product states deny every file mutation to owners and admins', function (string $state) {
    $product = fileProduct();
    Files::prepare($product);
    $release = $product->releases()->firstOrFail();
    $media = $product->media()->firstOrFail();
    $artifact = $release->artifacts()->firstOrFail();
    $product->forceFill(['status' => ProductStatus::from($state)])->save();
    foreach ([$product->owner, User::factory()->create()->assignRole('admin')] as $actor) {
        $this->actingAs($actor)->post(route('products.files.media.store', $product), fileMediaUpload($product))->assertForbidden();
        $this->delete(route('products.files.media.destroy', [$product, $media]))->assertForbidden();
        $this->patch(route('products.files.media.update', [$product, $media]), ['sort_order' => 0])->assertForbidden();
        $this->patch(route('products.files.releases.update', [$product, $release]), ['version' => '1.0.1', 'changelog' => 'Correction'])->assertForbidden();
        $this->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => Files::zip()])->assertForbidden();
        $this->delete(route('products.files.artifacts.destroy', [$product, $release, $artifact]))->assertForbidden();
    }
})->with(['submitted', 'under_review', 'approved', 'published', 'rejected']);

test('ZIP directory traversal and excessive expansion are rejected without extraction', function (string $case) {
    $path = tempnam(sys_get_temp_dir(), 'templaara-badzip-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString($case === 'path' ? '../escape.php' : 'bomb.txt', $case === 'path' ? 'content' : str_repeat('a', 2097152));
    $archive->close();
    $upload = UploadedFile::fake()->createWithContent('package.zip', file_get_contents($path));
    unlink($path);
    $product = fileProduct();
    $release = fileRelease($product);
    $this->actingAs($product->owner)->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => $upload])->assertSessionHasErrors('file');
    expect($release->artifacts()->count())->toBe(0);
})->with(['path', 'expansion']);

test('encompassing database rollback removes newly uploaded objects and restores replaced files', function () {
    $product = fileProduct();
    Files::prepare($product);
    $original = $product->cover;
    expect(fn () => DB::transaction(function () use ($product) {
        app(ProductFiles::class)->uploadMedia($product->owner, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('new.jpg', 640, 360)]);
        throw new RuntimeException('Rollback after replacement.');
    }))->toThrow(RuntimeException::class);
    expect($product->fresh()->cover->id)->toBe($original->id)->and(Storage::disk('public')->allFiles())->toHaveCount(3);
    Storage::disk('public')->assertExists($original->path);
});

test('missing stored files prevent submission and protected release content cannot be overwritten', function () {
    $product = fileProduct();
    Files::prepare($product);
    $package = $product->releases()->firstOrFail()->artifacts()->firstOrFail();
    Storage::disk($package->disk)->delete($package->path);
    $this->actingAs($product->owner)->post(route('author.products.submit', $product))->assertSessionHasErrors('release.package');
    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
    $release = $product->releases()->firstOrFail();
    $release->forceFill(['status' => ProductReleaseStatus::Approved])->save();
    expect(fn () => $release->forceFill(['changelog' => 'Overwritten'])->save())->toThrow(LogicException::class);
});

test('preview video accepts only HTTP HTTPS URLs', function () {
    $product = fileProduct();
    $this->actingAs($product->owner)->put(route('author.products.update', $product), ['preview_video_url' => 'javascript:alert(1)'])->assertSessionHasErrors('preview_video_url');
});

test('all frozen states deny ordinary media release and package mutations', function (string $status) {
    $product = fileProduct();
    Files::prepare($product);
    $release = $product->releases()->firstOrFail();
    $package = $release->artifacts()->firstOrFail();
    $cover = $product->cover;
    $product->forceFill(['status' => ProductStatus::from($status)])->save();
    $this->actingAs($product->owner)->post(route('products.files.media.store', $product), fileMediaUpload($product))->assertForbidden();
    $this->delete(route('products.files.media.destroy', [$product, $cover]))->assertForbidden();
    $this->patch(route('products.files.releases.update', [$product, $release]), ['version' => '1.0.1', 'changelog' => 'Attempted change'])->assertForbidden();
    $this->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => Files::zip()])->assertForbidden();
    $this->delete(route('products.files.artifacts.destroy', [$product, $release, $package]))->assertForbidden();
})->with(['submitted', 'under_review', 'approved', 'published', 'rejected']);

test('ZIP traversal and extreme compression are rejected without extraction', function (string $case) {
    $path = tempnam(sys_get_temp_dir(), 'templaara-unsafe-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString($case === 'traversal' ? '../evil.php' : 'bomb.txt', $case === 'traversal' ? 'unsafe' : str_repeat('a', 2097152));
    $archive->close();
    $file = UploadedFile::fake()->createWithContent('source.zip', file_get_contents($path));
    unlink($path);
    $product = fileProduct();
    $release = fileRelease($product);
    $this->actingAs($product->owner)->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => $file])->assertSessionHasErrors('file');
    expect($release->artifacts()->count())->toBe(0);
})->with(['traversal', 'compression']);

test('failed database persistence cleans staged files and keeps old cover intact', function () {
    $product = fileProduct();
    $files = app(ProductFiles::class);
    $files->uploadMedia($product->owner, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('original.jpg', 640, 360)]);
    $old = $product->cover;
    ProductMedia::creating(function () {
        throw new RuntimeException('Simulated database failure.');
    });
    try {
        expect(fn () => $files->uploadMedia($product->owner, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('replacement.jpg', 640, 360)]))->toThrow(RuntimeException::class);
    } finally {
        ProductMedia::flushEventListeners();
        ProductMedia::clearBootedModels();
    }
    expect($old->fresh()->is_current)->toBeTrue()->and(Storage::disk('public')->allFiles())->toHaveCount(1);
    Storage::disk($old->disk)->assertExists($old->path);
});

test('encompassing rollback cleans newly uploaded files without deleting earlier files', function () {
    $product = fileProduct();
    expect(fn () => DB::transaction(function () use ($product) {
        app(ProductFiles::class)->uploadMedia($product->owner, $product, ProductMediaType::Cover, [UploadedFile::fake()->image('cover.jpg', 640, 360)]);
        throw new RuntimeException('Rollback encompassing transaction.');
    }))->toThrow(RuntimeException::class);
    expect($product->media()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('missing artifact downloads return a controlled error', function () {
    $product = fileProduct();
    Files::prepare($product);
    $release = $product->releases()->firstOrFail();
    $artifact = $release->artifacts()->firstOrFail();
    Storage::disk($artifact->disk)->delete($artifact->path);
    $this->actingAs($product->owner)->get(route('products.files.artifacts.download', [$product, $release, $artifact]))->assertNotFound();
});

test('failed object deletion remains queued and succeeds on a later retry', function () {
    $retention = app(FileRetention::class);
    Storage::disk('product_private')->put('retry.zip', 'content');
    $job = $retention->schedule('product_private', 'retry.zip');
    $actual = Storage::disk('product_private');
    $faulty = Mockery::mock(FilesystemAdapter::class);
    $faulty->shouldReceive('delete')->once()->andThrow(new RuntimeException('Simulated storage outage.'));
    Storage::set('product_private', $faulty);
    expect($retention->cleanup($job))->toBeFalse()->and(DB::table('product_file_cleanup')->where('id', $job)->exists())->toBeTrue();
    Storage::set('product_private', $actual);
    expect($retention->cleanup($job))->toBeTrue()->and(DB::table('product_file_cleanup')->where('id', $job)->exists())->toBeFalse();
    Storage::disk('product_private')->assertMissing('retry.zip');
});

test('S3 compatible storage adapter can be configured without provider specific code', function () {
    config()->set('filesystems.disks.product_s3_private.bucket', 'templaara-test-private');
    config()->set('filesystems.disks.product_s3_private.key', 'test-key');
    config()->set('filesystems.disks.product_s3_private.secret', 'test-secret');
    config()->set('filesystems.disks.product_s3_private.endpoint', 'https://objects.example.test');
    expect(Storage::disk('product_s3_private')->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
});

test('a private disk rooted inside the web directory is rejected', function () {
    $product = fileProduct();
    $release = fileRelease($product);
    config()->set('filesystems.disks.product_private.root', public_path('paid-packages'));
    $this->actingAs($product->owner)->post(route('products.files.artifacts.store', [$product, $release]), ['type' => 'source_package', 'file' => Files::zip()])->assertSessionHasErrors('file');
    expect($release->artifacts()->count())->toBe(0);
});
