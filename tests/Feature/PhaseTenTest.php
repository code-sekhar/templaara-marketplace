<?php

use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Models\Cart;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Product;
use App\Models\ProductLifecycleEvent;
use App\Models\ProductRelease;
use App\Models\ProductRevision;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Entitlements\LicenseKeys;
use App\Services\Products\ProductUpdates;
use App\Services\Products\RevisionContent;
use App\Support\SemanticVersion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Middleware;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommerceFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
});
function phase10Revision(?Product $product = null): array
{
    $product ??= CommerceFixtures::product();
    $actor = User::factory()->create()->assignRole('admin');
    $service = app(ProductUpdates::class);
    $revision = $service->create($actor, $product, '1.1.0');
    $service->release($actor, $revision, ['version' => '1.1.0', 'changelog' => 'Improved inventory reporting for business owners.']);
    $service->upload($actor, $revision, 'source_package', ProductFileFixtures::zip('Version 1.1.0 package'));

    return [$product, $actor, $revision, $service];
}
function phase10License(): array
{
    EntitlementFixtures::order();
    $e = Entitlement::firstOrFail();

    return [$e, ['license_key' => $e->license->license_secret, 'product' => $e->item->product->public_uuid, 'installation_id' => (string) Str::uuid(), 'installation_label' => 'Retail office', 'domain' => 'HTTPS://Example.COM/path']];
}
test('published update isolates business content offers category and media until publication', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $old = app(RevisionContent::class)->fromProduct($p);
    $liveCover = $p->cover->id;
    $data = $r->content;
    $data['name'] = 'Improved business inventory';
    $data['offers'][0]['price'] = '5999.00';
    $data['offers'][0]['activation_limit'] = 3;
    $s->save($a, $r, $data);
    $s->upload($a, $r, 'cover', UploadedFile::fake()->image('new.jpg', 640, 360));
    $s->upload($a, $r, 'screenshot', UploadedFile::fake()->image('new-screen.jpg', 640, 360));
    expect($p->fresh()->name)->toBe($old['name'])->and($p->fresh()->cover->id)->toBe($liveCover)->and($p->fresh()->offers->where('is_active', true)->first()->price)->toBe($old['offers'][0]['price']);
    $round = $s->transition($a, $r, ProductWorkflowAction::Submit);
    expect($p->fresh()->status)->toBe(ProductStatus::Published)->and($round->snapshot['name'])->toBe($data['name'])->and($round->snapshot['media'][0])->toHaveKey('checksum');
    $s->transition($a, $r, ProductWorkflowAction::StartReview, roundId: $round->id);
    $s->transition($a, $r, ProductWorkflowAction::Approve, roundId: $round->id);
    expect($p->fresh()->name)->toBe($old['name']);
    $s->transition($a, $r, ProductWorkflowAction::Publish);
    expect($p->fresh()->name)->toBe($data['name'])->and($p->fresh()->offers->where('is_active', true)->first()->price)->toBe('5999.00')->and($s->current($p)->version)->toBe('1.1.0')->and(ProductRelease::whereKey($r->base_release_id)->first()->status->value)->toBe('published');
    $this->get(route('marketplace.products.show', $p->slug))->assertOk();
    expect(ProductLifecycleEvent::where('product_revision_id', $r->id)->count())->toBe(4);
});
test('draft product cannot enter published update flow', function () {
    $a = User::factory()->create()->assignRole('admin');
    $p = Product::factory()->official()->create();
    expect(fn () => app(ProductUpdates::class)->create($a, $p, '1.1.0'))->toThrow(AuthorizationException::class);
});
test('only one open update and product version identity stays unique', function () {
    [$p,$a,$r,$s] = phase10Revision();
    expect(fn () => $s->create($a, $p, '1.2.0'))->toThrow(ValidationException::class);
    expect(ProductRevision::count())->toBe(1);
});
test('semantic version comparison uses numeric components without overflow', function (string $next, string $base, bool $valid) {
    if ($valid) {
        SemanticVersion::newer($next, $base);
        expect(true)->toBeTrue();
    } else {
        expect(fn () => SemanticVersion::newer($next, $base))->toThrow(ValidationException::class);
    }
})->with([['1.10.0', '1.9.9', true], ['2.0.0', '1.99.99', true], ['1.0.1', '1.0.0', true], ['1.0.0', '1.0.0', false], ['0.9.0', '1.0.0', false], ['1.2.3-beta', '1.0.0', false], ['01.0.0', '1.0.0', false], ['99999999999999999999.0.0', '9999999999999999999.0.0', true]]);
test('ready update submission freezes editor and concurrent duplicate actions', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $round = $s->transition($a, $r, ProductWorkflowAction::Submit);
    expect(fn () => $s->save($a, $r, $r->content))->toThrow(AuthorizationException::class);
    expect(fn () => $s->transition($a, $r, ProductWorkflowAction::Submit))->toThrow(AuthorizationException::class);
    $s->transition($a, $r, ProductWorkflowAction::StartReview);
    $other = User::factory()->create()->assignRole('reviewer');
    expect(fn () => $s->transition($other, $r, ProductWorkflowAction::StartReview))->toThrow(ValidationException::class);
    expect(fn () => $s->transition($other, $r, ProductWorkflowAction::Approve))->toThrow(ValidationException::class);
});
test('changes requested edits only proposal and resubmission retains exact old history', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $round = $s->transition($a, $r, ProductWorkflowAction::Submit);
    $frozen = $round->snapshot;
    $s->transition($a, $r, ProductWorkflowAction::StartReview);
    expect(fn () => $s->transition($a, $r, ProductWorkflowAction::RequestChanges))->toThrow(ValidationException::class);
    $s->transition($a, $r, ProductWorkflowAction::RequestChanges, ['feedback' => [['section' => 'pricing', 'message' => 'Explain the installation package services.']]]);
    $data = $r->fresh()->content;
    $data['description'] = 'Updated proposed documentation and business information for all retail operators.';
    $s->save($a, $r, $data);
    $new = $s->transition($a, $r, ProductWorkflowAction::Submit);
    expect($new->id)->not->toBe($round->id)->and($round->fresh()->snapshot)->toEqual(json_decode(json_encode($frozen), true))->and($round->feedback()->count())->toBe(1)->and($p->fresh()->description)->not->toBe($data['description']);
});
test('rejection leaves live version unchanged and allows a distinct new proposal', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $s->transition($a, $r, ProductWorkflowAction::Submit);
    $s->transition($a, $r, ProductWorkflowAction::StartReview);
    expect(fn () => $s->transition($a, $r, ProductWorkflowAction::Reject))->toThrow(ValidationException::class);
    $s->transition($a, $r, ProductWorkflowAction::Reject, ['summary' => 'This package cannot proceed through ordinary corrections.']);
    expect($s->current($p)->version)->toBe('1.0.0');
    $new = $s->create($a, $p, '1.2.0');
    expect($new->id)->not->toBe($r->id);
    expect(fn () => $s->release($a, $new, ['version' => '1.1.0', 'changelog' => 'Duplicate rejected release']))->toThrow(ValidationException::class);
});
test('unready update cannot submit without a new package', function () {
    $p = CommerceFixtures::product();
    $a = User::factory()->create()->assignRole('admin');
    $s = app(ProductUpdates::class);
    $r = $s->create($a, $p, '1.1.0');
    expect(fn () => $s->transition($a, $r, ProductWorkflowAction::Submit))->toThrow(ValidationException::class);
    expect($r->fresh()->status)->toBe(ProductStatus::Draft);
});
test('author ownership and self review remain enforced for updates', function () {
    $owner = User::factory()->create()->assignRole('author');
    $p = CommerceFixtures::product();
    $p->forceFill(['origin' => 'author', 'owner_id' => $owner->id])->save();
    $other = User::factory()->create()->assignRole('author');
    $s = app(ProductUpdates::class);
    expect(fn () => $s->create($other, $p, '1.1.0'))->toThrow(AuthorizationException::class);
    $r = $s->create($owner, $p, '1.1.0');
    $s->release($owner, $r, ['version' => '1.1.0', 'changelog' => 'Reporting enhancements.']);
    $s->upload($owner, $r, 'source_package', ProductFileFixtures::zip());
    $s->transition($owner, $r, ProductWorkflowAction::Submit);
    $owner->assignRole('admin');
    expect(fn () => $s->transition($owner, $r, ProductWorkflowAction::StartReview))->toThrow(AuthorizationException::class);
});
test('old purchase package price terms and activation remain bound while new purchases use promoted version', function () {
    $oldOrder = EntitlementFixtures::order();
    $e = Entitlement::firstOrFail();
    $item = $oldOrder->items->first();
    $oldPrice = $item->unit_price;
    $oldArtifact = $e->artifact;
    $oldHash = $oldArtifact->checksum;
    [$p,$a,$r,$s] = phase10Revision($item->product);
    $data = $r->content;
    $data['offers'][0]['price'] = '5999.00';
    $data['offers'][0]['activation_limit'] = 4;
    $s->save($a, $r, $data);
    foreach ([ProductWorkflowAction::Submit, ProductWorkflowAction::StartReview, ProductWorkflowAction::Approve, ProductWorkflowAction::Publish] as $action) {
        $s->transition($a, $r, $action);
    }
    expect($item->fresh()->unit_price)->toBe($oldPrice)->and($item->fresh()->activation_limit)->toBe(1)->and($e->fresh()->product_release_id)->toBe($item->product_release_id)->and(Storage::disk($oldArtifact->disk)->exists($oldArtifact->path))->toBeTrue();
    $this->actingAs($oldOrder->user)->get(route('library.download', $e))->assertOk()->assertDownload('software.zip');
    expect(hash('sha256', Storage::disk($oldArtifact->disk)->get($oldArtifact->path)))->toBe($oldHash);
    $buyer = User::factory()->create()->assignRole('customer');
    $offer = $p->fresh()->offers->where('is_active', true)->first();
    app(CartService::class)->add($buyer, $p->slug, $offer->id);
    $new = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->value('checkout_key'), CommerceFixtures::billing());
    EntitlementFixtures::pay($new);
    expect($new->items->first()->version)->toBe('1.1.0')->and($new->items->first()->activation_limit)->toBe(4)->and($new->items->first()->unit_price)->toBe('5999.00');
});
test('update editor rejects direct status owner feature and foreign nested fields', function (string $field) {
    [$p,$a,$r] = phase10Revision();
    $data = $r->content;
    $data[$field] = $field === 'is_featured' ? true : 'forged';
    $this->actingAs($a)->put(route('products.updates.save', [$p, $r]), $data)->assertSessionHasErrors($field);
    expect($p->fresh()->status)->toBe(ProductStatus::Published);
})->with(['status', 'owner_id', 'origin', 'is_featured']);
test('update queue and business first frozen detail are authorized', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $s->transition($a, $r, ProductWorkflowAction::Submit);
    $reviewer = User::factory()->create()->assignRole('reviewer');
    $this->actingAs($reviewer)->get(route('admin.product-updates.index'))->assertOk();
    $this->get(route('products.updates.show', [$p, $r]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('products/Update')->where('liveVersion', '1.0.0')->where('snapshot.release.version', '1.1.0'));
    $customer = User::factory()->create()->assignRole('customer');
    $this->actingAs($customer)->get(route('admin.product-updates.index'))->assertForbidden();
});
test('license HTTP API activates validates deactivates and reactivates without exposing secrets', function () {
    [$e,$data] = phase10License();
    $response = $this->postJson('/api/v1/licenses/activate', $data)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('license.purchased_version', '1.0.0');
    $uuid = $response->json('activation_id');
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk()->assertJsonPath('activation_id', $uuid);
    expect(LicenseActivation::count())->toBe(1);
    $this->postJson('/api/v1/licenses/validate', $data)->assertOk();
    $this->postJson('/api/v1/licenses/deactivate', $data)->assertOk()->assertJsonPath('code', 'DEACTIVATED');
    $this->postJson('/api/v1/licenses/validate', $data)->assertStatus(422)->assertJsonPath('code', 'ACTIVATION_INACTIVE');
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk()->assertJsonPath('activation_id', $uuid);
    expect(DB::table('license_activation_events')->count())->toBe(3)->and(LicenseActivation::first()->domain_display)->toBe('example.com');
    $json = $response->getContent();
    foreach ([$data['license_key'], $data['installation_id'], $e->user->email, 'license_key_hash', 'disk', 'path', 'user_id'] as $private) {
        expect($json)->not->toContain($private);
    }
});
test('activation key product and installation failures have JSON contracts', function (string $field, string $value, string $code) {
    [$e,$data] = phase10License();
    $data[$field] = $value;
    $this->postJson('/api/v1/licenses/activate', $data)->assertStatus(422)->assertJsonPath('code', $code);
})->with([['license_key', 'TMP-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA', 'INVALID_LICENSE'], ['product', 'b6bf3bcb-b6d3-4474-bb68-d7ec85a5a3bd', 'PRODUCT_MISMATCH']]);
test('malformed API identifiers are rejected before lookup', function (string $field, string $value) {
    [$e,$data] = phase10License();
    $data[$field] = $value;
    $this->postJson('/api/v1/licenses/activate', $data)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([['license_key', 'bad'], ['installation_id', 'machine-id'], ['product', 'mutable-slug'], ['domain', 'https://not a host.com']]);
test('active installation limit survives live offer changes and releases slot on deactivation', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $second = [...$data, 'installation_id' => (string) Str::uuid()];
    $e->item->product->offers()->update(['activation_limit' => 99]);
    $this->postJson('/api/v1/licenses/activate', $second)->assertStatus(409)->assertJsonPath('code', 'ACTIVATION_LIMIT');
    $this->postJson('/api/v1/licenses/deactivate', $data)->assertOk();
    $this->postJson('/api/v1/licenses/activate', $second)->assertOk();
});
test('license entitlement inactive or expired blocks validation', function (string $target, string $state) {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $record = $target === 'license' ? $e->license : $e;
    $record->forceFill($state === 'expired' ? ['expires_at' => now()->subDay()] : ['status' => $state, 'revoked_at' => now()])->save();
    $this->postJson('/api/v1/licenses/validate', $data)->assertStatus(422)->assertJsonPath('code', 'LICENSE_INACTIVE');
})->with([['license', 'revoked'], ['license', 'expired'], ['entitlement', 'revoked'], ['entitlement', 'expired']]);
test('inactive and unpublished products do not invalidate purchased installations', function () {
    [$e,$data] = phase10License();
    $e->item->product->forceFill(['is_active' => false, 'status' => 'draft'])->save();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $this->postJson('/api/v1/licenses/validate', $data)->assertOk();
});
test('unknown installation domain mismatch and idempotent deactivation stay scoped', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/validate', $data)->assertStatus(422)->assertJsonPath('code', 'ACTIVATION_NOT_FOUND');
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $this->postJson('/api/v1/licenses/validate', [...$data, 'domain' => 'elsewhere.example'])->assertStatus(422)->assertJsonPath('code', 'INSTALLATION_MISMATCH');
    $this->postJson('/api/v1/licenses/deactivate', [...$data, 'installation_id' => (string) Str::uuid()])->assertStatus(422);
    $this->postJson('/api/v1/licenses/deactivate', $data)->assertOk();
    $this->postJson('/api/v1/licenses/deactivate', $data)->assertOk();
    expect(DB::table('license_activation_events')->count())->toBe(2);
});
test('owner deactivation denies other owners and mismatched entitlement UUID', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $activation = LicenseActivation::first();
    $other = User::factory()->create()->assignRole('customer');
    $this->actingAs($other)->post(route('library.activations.deactivate', [$e, $activation]))->assertForbidden();
    $this->actingAs($e->user)->post(route('library.activations.deactivate', [$e, $activation]))->assertRedirect();
    expect($activation->fresh()->status)->toBe('deactivated');
});
test('library and admin inspection expose safe installation metadata and historical limits', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $this->actingAs($e->user)->get(route('library.show', $e))->assertOk()->assertInertia(fn (Assert $p) => $p->where('purchase.activation_limit', 1)->where('purchase.active_activations', 1)->where('purchase.activations.0.domain_display', 'example.com'));
    $admin = User::factory()->create()->assignRole('admin');
    Inertia\Inertia::flushShared();
    $response = $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(Middleware::class)->version(request())])->get(route('admin.entitlements.index'))->assertOk();
    $response->assertJsonMissingPath('props.purchase')->assertJsonPath('props.records.data.0.activation_limit', 1);
    expect($response->getContent())->not->toContain($data['license_key'])->not->toContain('installation_id_hash');
});
test('API rejects URL secrets GET and non JSON bodies without redirects', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate?license_key=not-a-real-key', $data)->assertStatus(422)->assertJsonPath('code', 'INVALID_REQUEST');
    $this->get('/api/v1/licenses/activate')->assertStatus(405);
    $this->post('/api/v1/licenses/activate', $data)->assertStatus(422)->assertJsonPath('code', 'INVALID_REQUEST');
});
test('API rate limits brute force and does not log supplied key or machine UUID', function () {
    Log::spy();
    [$e,$data] = phase10License();
    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    }$this->postJson('/api/v1/licenses/activate', $data)->assertStatus(429);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('update authorization blocks customers reviewers and foreign authors', function (string $role) {
    $p = CommerceFixtures::product();
    $actor = User::factory()->create()->assignRole($role);
    $this->actingAs($actor)->post(route('products.updates.create', $p), ['version' => '1.1.0'])->assertForbidden();
})->with(['customer', 'reviewer', 'author']);
test('submitted media checksum prevents approving altered presentation bytes', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $s->transition($a, $r, ProductWorkflowAction::Submit);
    $s->transition($a, $r, ProductWorkflowAction::StartReview);
    $file = $r->media()->first();
    Storage::disk($file->disk)->put($file->path, 'Changed immutable image bytes');
    expect(fn () => $s->transition($a, $r, ProductWorkflowAction::Approve))->toThrow(ValidationException::class);
    expect($r->fresh()->status)->toBe(ProductStatus::UnderReview);
});
test('missing approved package prevents partial promotion', function () {
    [$p,$a,$r,$s] = phase10Revision();
    $name = $p->name;
    foreach ([ProductWorkflowAction::Submit, ProductWorkflowAction::StartReview, ProductWorkflowAction::Approve] as $action) {
        $s->transition($a, $r, $action);
    }$file = $r->release->artifacts()->first();
    Storage::disk($file->disk)->delete($file->path);
    expect(fn () => $s->transition($a, $r, ProductWorkflowAction::Publish))->toThrow(ValidationException::class);
    expect($p->fresh()->name)->toBe($name)->and($s->current($p)->version)->toBe('1.0.0')->and($r->fresh()->status)->toBe(ProductStatus::Approved);
});
test('paid license API remains exact release bound after publication', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    [$p,$a,$r,$s] = phase10Revision($e->item->product);
    foreach ([ProductWorkflowAction::Submit, ProductWorkflowAction::StartReview, ProductWorkflowAction::Approve, ProductWorkflowAction::Publish] as $action) {
        $s->transition($a, $r, $action);
    }$this->postJson('/api/v1/licenses/validate', $data)->assertOk()->assertJsonPath('license.purchased_version', '1.0.0');
    expect(LicenseActivation::first()->entitlement_id)->toBe($e->id);
});
test('unpaid order has no key capable of activation', function () {
    EntitlementFixtures::order(false);
    expect(License::count())->toBe(0);
    $this->postJson('/api/v1/licenses/activate', ['license_key' => app(LicenseKeys::class)->generate(), 'product' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'INVALID_LICENSE');
});
test('owner cannot deactivate an activation through an unrelated entitlement', function () {
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    $activation = LicenseActivation::first();
    EntitlementFixtures::order(true, $e->user);
    $other = Entitlement::where('id', '<>', $e->id)->first();
    $this->actingAs($e->user)->post(route('library.activations.deactivate', [$other, $activation]))->assertNotFound();
    expect($activation->fresh()->status)->toBe('active');
});
test('PostgreSQL guards protect revision activation purchase and immutable history', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Production PostgreSQL integrity test.');
    }
    [$e,$data] = phase10License();
    $this->postJson('/api/v1/licenses/activate', $data)->assertOk();
    [$p,$a,$r,$s] = phase10Revision($e->item->product);
    $round = $s->transition($a, $r, ProductWorkflowAction::Submit);
    expect(fn () => DB::transaction(function () use ($case, $r, $round) {
        match ($case) {
            'activation_identity' => DB::table('license_activations')->update(['product_id' => 999999]),
            'activation_state' => DB::table('license_activations')->update(['status' => 'deactivated', 'deactivated_at' => null]),
            'activation_delete' => DB::table('license_activations')->delete(),
            'activation_history' => DB::table('license_activation_events')->update(['action' => 'deactivated']),
            'activation_audit' => DB::table('license_activations')->update(['status' => 'deactivated', 'deactivated_at' => now()]),
            'offer_limit' => DB::table('product_offers')->update(['activation_limit' => 0]),
            'order_limit' => DB::table('order_items')->update(['activation_limit' => 999]),
            'product_identity' => DB::table('products')->where('id', $r->product_id)->update(['public_uuid' => (string) Str::uuid()]),
            'revision_content' => DB::table('product_revisions')->where('id', $r->id)->update(['content' => '{}']),
            'revision_delete' => DB::table('product_revisions')->where('id', $r->id)->delete(),
            'revision_status' => DB::table('product_revisions')->where('id', $r->id)->update(['status' => 'published']),
            'revision_media' => DB::table('product_revision_media')->where('product_revision_id', $r->id)->delete(),
            'submission_snapshot' => DB::table('product_submissions')->where('id', $round->id)->update(['snapshot' => '{}']),
            'submission_revision' => DB::table('product_submissions')->where('id', $round->id)->update(['product_revision_id' => null]),
        };
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
})->with(['activation_identity', 'activation_state', 'activation_delete', 'activation_history', 'activation_audit', 'offer_limit', 'order_limit', 'product_identity', 'revision_content', 'revision_delete', 'revision_status', 'revision_media', 'submission_snapshot', 'submission_revision']);

test('production license API requires HTTPS without resolving a key', function () {
    app()->instance('env', 'production');
    $this->postJson('/api/v1/licenses/activate', [])->assertStatus(426)->assertJsonPath('code', 'HTTPS_REQUIRED');
});
