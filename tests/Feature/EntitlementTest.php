<?php

use App\Enums\EntitlementStatus;
use App\Enums\LicenseStatus;
use App\Events\Commerce\OrderPaid;
use App\Listeners\ProvisionPaidOrder;
use App\Models\DownloadAccess;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\ProductArtifact;
use App\Models\ProductRelease;
use App\Models\User;
use App\Services\Commerce\PaymentLifecycle;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Entitlements\LicenseKeys;
use App\Services\Entitlements\ReconcileEntitlements;
use App\Services\Products\Files\FileRetention;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
});
test('order paid immediately provisions one matching entitlement and encrypted base license', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::firstOrFail();
    $item = $order->items->first();
    expect($e->user_id)->toBe($order->user_id)->and($e->order_item_id)->toBe($item->id)->and($e->product_release_id)->toBe($item->product_release_id)
        ->and($e->status)->toBe(EntitlementStatus::Active)->and($e->license->status)->toBe(LicenseStatus::Active)->and($e->license->expires_at)->toBeNull();
    expect($e->artifact->product_release_id)->toBe($item->product_release_id)->and($e->license->order_item_id)->toBe($item->id);
});
test('unpaid failed and cancelled orders never grant access', function (string $state) {
    $order = EntitlementFixtures::order(false);
    if ($state === 'failed') {
        EntitlementFixtures::pay($order, 'failed');
    }
    if ($state === 'cancelled') {
        app(PaymentLifecycle::class)->cancel($order->user, $order);
    }
    expect(fn () => app(EntitlementProvisioner::class)->provision($order))->toThrow(ValidationException::class);
    expect(Entitlement::count())->toBe(0)->and(License::count())->toBe(0);
})->with(['pending', 'failed', 'cancelled']);
test('duplicate listener and provisioning preserve entitlement license and key', function () {
    $order = EntitlementFixtures::order();
    $key = License::first()->license_secret;
    $cipher = License::first()->getRawOriginal('license_secret');
    app(ProvisionPaidOrder::class)->handle(new OrderPaid($order));
    app(EntitlementProvisioner::class)->provision($order);
    OrderPaid::dispatch($order);
    expect(Entitlement::count())->toBe(1)->and(License::count())->toBe(1)->and(License::first()->license_secret)->toBe($key)->and(License::first()->getRawOriginal('license_secret'))->toBe($cipher);
});
test('reconciliation recovers a missed after commit event and is idempotent', function () {
    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    expect(Entitlement::count())->toBe(0);
    $this->artisan('commerce:reconcile-entitlements', ['--dry-run' => true])->assertSuccessful();
    expect(Entitlement::count())->toBe(0);
    $this->artisan('commerce:reconcile-entitlements', ['--order' => $order->order_number])->assertSuccessful();
    $key = License::first()->license_secret;
    $this->artisan('commerce:reconcile-entitlements')->assertSuccessful();
    expect(Entitlement::count())->toBe(1)->and(License::count())->toBe(1)->and(License::first()->license_secret)->toBe($key);
});
test('reconciliation ignores unpaid orders and reports unknown order', function () {
    $order = EntitlementFixtures::order(false);
    $this->artisan('commerce:reconcile-entitlements', ['--order' => $order->order_number])->assertSuccessful();
    $this->artisan('commerce:reconcile-entitlements', ['--order' => 'missing-order'])->assertFailed();
    expect(Entitlement::count())->toBe(0);
});
test('failed immediate provisioning preserves paid truth and reconciliation repairs it', function () {
    Log::spy();
    $order = EntitlementFixtures::order(false);
    $this->mock(EntitlementProvisioner::class, function ($mock) {
        $mock->shouldReceive('provision')->andThrow(new RuntimeException('Operational failure'));
    });
    EntitlementFixtures::pay($order);
    expect($order->fresh()->status->value)->toBe('completed')->and(Entitlement::count())->toBe(0);
    $this->actingAs($order->user)->get(route('commerce.orders.show', $order))->assertInertia(fn (Assert $p) => $p->where('order.software_access', 'pending'));
    Log::shouldHaveReceived('error')->with('Paid order software access requires reconciliation.', ['order_id' => $order->id, 'exception' => RuntimeException::class]);
    app()->forgetInstance(EntitlementProvisioner::class);
    $this->artisan('commerce:reconcile-entitlements')->assertSuccessful();
    expect(Entitlement::count())->toBe(1);
});
test('provisioning rolls back entitlement when license issuance fails', function () {
    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    $this->mock(LicenseKeys::class, fn ($mock) => $mock->shouldReceive('generate')->andThrow(new RuntimeException('Key issuance unavailable')));
    expect(fn () => app(EntitlementProvisioner::class)->provision($order))->toThrow(RuntimeException::class);
    expect(Entitlement::count())->toBe(0)->and(License::count())->toBe(0);
});
test('library authentication and empty state reuse the existing application', function () {
    $this->get('/account/library')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/account/library')->assertInertia(fn (Assert $p) => $p->component('library/Index')->has('records.data', 0));
});
test('owned library snapshot and secret require ownership and never leak in lists', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $key = $e->license->license_secret;
    $this->actingAs($order->user)->get('/account/library')->assertInertia(fn (Assert $p) => $p->has('records.data', 1)->where('records.data.0.product_name', 'Nexora Inventory & POS')->missing('records.data.0.license'));
    $response = $this->get(route('library.show', $e))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->where('purchase.license.key', $key)->where('purchase.version', '1.0.0'));
    expect($response->getOriginalContent()->getData()['page']['encryptHistory'])->toBeTrue();
    $data = json_encode($response->inertiaProps('purchase'));
    foreach ([$e->artifact->disk, $e->artifact->path, 'license_key_hash', 'license_secret', 'reviewer', 'snapshot'] as $private) {
        expect($data)->not->toContain($private);
    }
    $this->get(route('commerce.orders.show', $order))->assertInertia(fn (Assert $p) => $p->where('order.software_access', 'active'));
    $this->actingAs(User::factory()->create())->get('/account/library')->assertInertia(fn (Assert $p) => $p->has('records.data', 0));
    $this->get(route('library.show', $e))->assertForbidden();
    $this->get(route('library.download', $e))->assertForbidden();
});
test('owner role changes do not remove purchased software access', function (array $roles) {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $order->user->syncRoles($roles);
    $this->actingAs($order->user)->get(route('library.show', $e))->assertOk();
    $this->get(route('library.download', $e))->assertDownload();
})->with([[['author']], [['customer', 'author']], [['admin']], [[]]]);
test('exact historical package downloads remain available when product visibility changes', function (string $change) {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $artifact = $e->artifact;
    $bytes = Storage::disk($artifact->disk)->get($artifact->path);
    match ($change) {
        'inactive' => DB::table('products')->where('id', $e->product_id)->update(['is_active' => false]),'unpublished' => DB::table('products')->where('id', $e->product_id)->update(['status' => 'approved']),
        'renamed' => DB::table('products')->where('id', $e->product_id)->update(['name' => 'A different display name']), 'retired' => DB::table('product_artifacts')->where('id', $artifact->id)->update(['is_current' => false]),
        'new_release' => $new = (new ProductRelease)->forceFill(['product_id' => $e->product_id, 'version' => '1.1.0', 'status' => 'draft', 'changelog' => 'New unrelated release', 'created_by' => $order->user_id])
    };
    $response = $this->actingAs($order->user)->get(route('library.download', $e))->assertDownload($artifact->original_name)->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($bytes)->and(DownloadAccess::first()->product_artifact_id)->toBe($artifact->id);
    $this->get(route('library.show', $e))->assertInertia(fn (Assert $p) => $p->where('purchase.product_name', 'Nexora Inventory & POS')->where('purchase.version', '1.0.0'));
})->with(['inactive', 'unpublished', 'renamed', 'retired', 'new_release']);
test('repeated downloads are allowed and audit response preparation rather than byte completion', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $this->actingAs($order->user);
    for ($i = 0; $i < 2; $i++) {
        $this->get(route('library.download', $e))->assertDownload();
    }
    expect(DownloadAccess::count())->toBe(2)->and(DownloadAccess::first()->delivery)->toBe('stream')->and(DownloadAccess::first()->url_expires_at)->toBeNull();
});
test('missing exact package never falls back and retains access', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    Storage::disk($e->artifact->disk)->delete($e->artifact->path);
    $this->actingAs($order->user)->get(route('library.show', $e))->assertInertia(fn (Assert $p) => $p->where('purchase.download_available', false));
    $this->getJson(route('library.download', $e))->assertStatus(503)->assertJsonPath('message', 'Download temporarily unavailable. Please try again later.');
    expect(DownloadAccess::count())->toBe(0)->and($e->fresh()->status)->toBe(EntitlementStatus::Active);
});
test('inactive or expired entitlement cannot download', function (string $state) {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $e->forceFill(match ($state) {
        'revoked' => ['status' => 'revoked', 'revoked_at' => now()],'expired' => ['status' => 'expired', 'expires_at' => now()->subDay()],'timed_out' => ['expires_at' => now()->subSecond()]
    })->save();
    $this->actingAs($order->user)->get(route('library.download', $e))->assertForbidden();
    expect(DownloadAccess::count())->toBe(0);
})->with(['revoked', 'expired', 'timed_out']);
test('artifact and order identifiers alone never grant package access', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $this->actingAs($order->user);
    $this->get('/account/library/'.$e->artifact->id.'/download')->assertNotFound();
    $this->get('/account/library/'.$order->order_number.'/download')->assertNotFound();
    $this->get(route('products.files.artifacts.download', [$e->product_id, $e->product_release_id, $e->artifact->id]))->assertForbidden();
});
test('commercial file retention includes historical entitlements', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $retention = app(FileRetention::class);
    $file = $e->artifact;
    expect($retention->referenced($file))->toBeTrue();
    $retention->retire($file);
    expect(ProductArtifact::find($file->id))->not->toBeNull()->and(Storage::disk($file->disk)->exists($file->path))->toBeTrue();
    $job = $retention->schedule($file->disk, $file->path);
    $retention->cleanup($job);
    expect(Storage::disk($file->disk)->exists($file->path))->toBeTrue();
});
test('license uses secure random encrypted secrets and stable lookup fingerprint', function () {
    Log::spy();
    $order = EntitlementFixtures::order();
    $license = License::first();
    $key = $license->license_secret;
    expect($key)->toMatch('/^TMP-(?:[A-F0-9]{5}-){7}[A-F0-9]{5}$/')->and($license->getRawOriginal('license_secret'))->not->toContain($key)
        ->and($license->license_key_hash)->toBe(app(LicenseKeys::class)->fingerprint($key))->not->toBe($key)->and($license->license_key_last4)->toBe(substr($key, -4));
    expect($license->expires_at)->toBeNull()->and(json_encode($license))->not->toContain($key)->not->toContain('license_key_hash');
    expect(app(LicenseKeys::class)->generate())->not->toBe($key);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});
test('public pages order summaries and admin inspection omit license secrets', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $key = $e->license->license_secret;
    foreach (['/', '/products', '/products/'.$order->items->first()->product_slug] as $url) {
        $response = $this->get($url)->assertOk();
        expect($response->getContent())->not->toContain($key);
    }
    $this->actingAs($order->user)->get('/admin/entitlements')->assertForbidden();
    $admin = User::factory()->create()->assignRole('admin');
    $response = $this->actingAs($admin)->get('/admin/entitlements')->assertInertia(fn (Assert $p) => $p->has('records.data', 1)->where('records.data.0.license_status', 'active'));
    expect($response->getContent())->not->toContain($key)->not->toContain($e->license->license_key_hash);
    $this->get(route('library.show', $e))->assertForbidden();
    $this->get(route('library.download', $e))->assertForbidden();
});
test('entitlement license and access commercial identity cannot be overwritten or deleted', function () {
    EntitlementFixtures::order();
    $e = Entitlement::first();
    expect(fn () => $e->forceFill(['user_id' => User::factory()->create()->id])->save())->toThrow(LogicException::class);
    expect(fn () => $e->license->forceFill(['license_secret' => 'FORGED'])->save())->toThrow(LogicException::class);
    expect(fn () => $e->delete())->toThrow(LogicException::class);
    expect(fn () => $e->license->delete())->toThrow(LogicException::class);
});
test('postgresql protects cross table entitlement integrity and immutable retention', function (string $change) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific entitlement integrity.');
    }
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $license = $e->license;
    $attributes = $e->getAttributes();
    unset($attributes['id']);
    $attributes['public_id'] = (string) Str::uuid();
    $other = User::factory()->create();
    expect(fn () => match ($change) {
        'duplicate' => DB::table('entitlements')->insert($attributes), 'wrong_user' => DB::table('entitlements')->insert([...$attributes, 'user_id' => $other->id]),
        'wrong_order' => DB::table('entitlements')->insert([...$attributes, 'order_id' => EntitlementFixtures::order(false)->id]),
        'link_update' => DB::table('entitlements')->where('id', $e->id)->update(['user_id' => $other->id]),
        'delete' => DB::table('entitlements')->where('id', $e->id)->delete(),
        'artifact_delete' => DB::table('product_artifacts')->where('id', $e->artifact->id)->delete(),
        'license_duplicate' => DB::table('licenses')->insert(array_diff_key($license->getAttributes(), ['id' => true])),
        'license_update' => DB::table('licenses')->where('id', $license->id)->update(['license_secret' => 'forged']),
        'license_delete' => DB::table('licenses')->where('id', $license->id)->delete(),
    })->toThrow(QueryException::class);
})->with(['duplicate', 'wrong_user', 'wrong_order', 'link_update', 'delete', 'artifact_delete', 'license_duplicate', 'license_update', 'license_delete']);

test('reconciliation repairs a missing base license without duplicating entitlement', function () {
    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    app(EntitlementProvisioner::class)->provision($order);
    // Simulate legacy partial provisioning before licenses existed; PostgreSQL commercial deletion remains protected.
    if (DB::getDriverName() === 'pgsql') {
        $this->markTestSkipped('Partial legacy fixture uses SQLite only; PostgreSQL forbids deleting commercial licenses.');
    }
    DB::table('licenses')->delete();
    $this->artisan('commerce:reconcile-entitlements')->assertSuccessful();
    expect(Entitlement::count())->toBe(1)->and(License::count())->toBe(1);
});
test('s3 compatible delivery issues short lived https attachments and records issuance', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $disk = Storage::disk($e->artifact->disk);
    $received = null;
    $disk->buildTemporaryUrlsUsing(function ($path, $expiration, $options) use (&$received) {
        $received = compact('expiration', 'options');

        return 'https://private.example.test/authorized-object?signature=opaque';
    });
    config()->set('filesystems.disks.'.$e->artifact->disk.'.driver', 's3');
    config()->set('entitlements.temporary_urls', true);
    config()->set('entitlements.url_minutes', 5);
    $this->actingAs($order->user)->get(route('library.download', $e))->assertRedirect('https://private.example.test/authorized-object?signature=opaque');
    expect($received['expiration']->diffInSeconds(now(), true))->toBeLessThanOrEqual(300)->and($received['options']['ResponseContentDisposition'])->toContain('attachment');
    expect(DownloadAccess::first()->delivery)->toBe('temporary_url')->and(DownloadAccess::first()->url_expires_at)->not->toBeNull();
});
test('unsafe private storage or temporary delivery fails closed', function (string $problem) {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $disk = $e->artifact->disk;
    match ($problem) {
        'public' => config()->set('filesystems.disks.'.$disk.'.visibility', 'public'),'served' => config()->set('filesystems.disks.'.$disk.'.serve', true),
        'public_url' => config()->set('filesystems.disks.'.$disk.'.url', 'https://public.example.com'), 'public_root' => config()->set('filesystems.disks.'.$disk.'.root', public_path()),
        'http_url' => (function () use ($disk) {
            config()->set('filesystems.disks.'.$disk.'.driver', 's3');
            config()->set('entitlements.temporary_urls', true);
            Storage::disk($disk)->buildTemporaryUrlsUsing(fn () => 'http://private.example.com/insecure');
        })()
    };
    $this->actingAs($order->user)->getJson(route('library.download', $e))->assertStatus(503);
    expect(DownloadAccess::count())->toBe(0);
})->with(['public', 'served', 'public_url', 'public_root', 'http_url']);
test('existing paid order can recover its retired approved package', function () {
    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    $artifact = ProductArtifact::where('product_release_id', $order->items->first()->product_release_id)->first();
    $artifact->forceFill(['is_current' => false])->save();
    $this->artisan('commerce:reconcile-entitlements')->assertSuccessful();
    expect(Entitlement::first()->product_artifact_id)->toBe($artifact->id);
});
test('library listings paginate without license plaintext and customer ownership remains scoped', function () {
    $order = EntitlementFixtures::order();
    $e = Entitlement::first();
    $this->actingAs($order->user)->get('/account/library?page=2')->assertInertia(fn (Assert $p) => $p->has('records.data', 0));
    $this->get(route('commerce.orders.show', $order))->assertDontSee($e->license->license_secret);
    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect($order->user->fresh())->not->toBeNull();
});
test('admin entitlement inspection is permission based and never reveals owner secret', function () {
    $order = EntitlementFixtures::order();
    $viewer = User::factory()->create()->givePermissionTo('orders.view');
    $this->actingAs($viewer)->get('/admin/entitlements')->assertOk();
    $this->get(route('library.show', Entitlement::first()))->assertForbidden();
});
test('license lookup normalizes presentation separators and casing without using the hash as secret', function () {
    $keys = app(LicenseKeys::class);
    $key = $keys->generate();
    $hash = $keys->fingerprint($key);
    expect($keys->fingerprint(strtolower(str_replace('-', '', $key))))->toBe($hash)->and($keys->fingerprint($hash))->not->toBe($hash);
});
test('entitlement and license mass assignment cannot accept forged commercial linkage', function (string $model) {
    $record = new $model;
    expect(fn () => $record->fill(['user_id' => 999, 'status' => 'active']))->toThrow(MassAssignmentException::class);
})->with([Entitlement::class, License::class, DownloadAccess::class]);

test('recovery and downloads bind purchased release even when a newer public package exists', function (bool $missingOriginal) {
    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    $item = $order->items->first();
    $original = ProductArtifact::where('product_release_id', $item->product_release_id)->firstOrFail();
    $originalBytes = Storage::disk($original->disk)->get($original->path);
    $new = (new ProductRelease)->forceFill(['product_id' => $item->product_id, 'created_by' => $order->user_id, 'version' => '1.1.0', 'status' => 'published', 'changelog' => 'New version fixture', 'released_at' => now()->addSecond()]);
    $new->save();
    $path = 'verification/new-version-'.Str::uuid().'.zip';
    Storage::disk($original->disk)->put($path, 'New release bytes');
    (new ProductArtifact)->forceFill(['product_release_id' => $new->id, 'type' => 'source_package', 'disk' => $original->disk, 'path' => $path, 'original_name' => 'new-version.zip', 'mime_type' => 'application/zip', 'size_bytes' => 17, 'checksum' => hash('sha256', 'New release bytes'), 'is_current' => true])->save();
    app(ReconcileEntitlements::class)->run();
    $e = Entitlement::firstOrFail();
    expect($e->product_release_id)->toBe($item->product_release_id)->and($e->product_artifact_id)->toBe($original->id);
    $this->actingAs($order->user)->get('/products/'.$item->product_slug)->assertInertia(fn (Assert $p) => $p->where('product.release.version', '1.1.0'));
    if ($missingOriginal) {
        Storage::disk($original->disk)->delete($original->path);
        $this->getJson(route('library.download', $e))->assertStatus(503);
    } else {
        expect($this->get(route('library.download', $e))->assertDownload('software.zip')->streamedContent())->toBe($originalBytes);
    }
})->with([false, true]);

test('reconciliation reports provisioning errors without altering payment truth', function () {
    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    $this->mock(EntitlementProvisioner::class, fn ($mock) => $mock->shouldReceive('provision')->andThrow(new RuntimeException('Unavailable')));
    $this->artisan('commerce:reconcile-entitlements')->assertFailed();
    expect($order->fresh()->status->value)->toBe('completed')->and(Entitlement::count())->toBe(0);
});
