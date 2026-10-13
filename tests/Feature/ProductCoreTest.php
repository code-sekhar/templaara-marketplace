<?php

use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductOffer;
use App\Models\Technology;
use App\Models\User;
use App\Services\Products\SaveProduct;
use Database\Seeders\BusinessCatalogSeeder;
use Database\Seeders\DevelopmentProductSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Category::factory()->create(['name' => 'POS & Inventory']);
});

function productCorePayload(array $extra = []): array
{
    return [...[
        'name' => 'Nexora Inventory & POS',
        'category_id' => Category::firstOrFail()->id,
        'short_description' => 'Inventory, billing and sales management for retail businesses.',
        'currency' => 'INR',
        'offers' => [[
            'name' => 'Software License', 'price' => '4999.00', 'license_type' => 'single_business',
            'is_default' => true, 'is_active' => true, 'sort_order' => 0,
        ]],
    ], ...$extra];
}

function productCoreAuthor(): User
{
    return User::factory()->create()->assignRole(['customer', 'author']);
}

test('author creates a useful draft with server derived ownership', function () {
    $author = productCoreAuthor();
    $this->actingAs($author)->post(route('author.products.store'), productCorePayload())->assertRedirect();
    $product = Product::firstOrFail();
    expect($product->owner_id)->toBe($author->id)->and($product->origin)->toBe(ProductOrigin::Author)
        ->and($product->status)->toBe(ProductStatus::Draft)->and($product->slug)->toBe('nexora-inventory-pos')
        ->and($product->is_featured)->toBeFalse()->and($product->offers()->firstOrFail()->price)->toBe('4999.00');
    $this->get(route('author.products.index'))->assertInertia(fn (Assert $page) => $page
        ->component('products/Index')->where('records.total', 1)->where('context', 'author'));
    $this->get(route('author.products.show', $product))->assertInertia(fn (Assert $page) => $page
        ->component('products/Form')->where('readOnly', true)->where('product.id', $product->id));
    $this->get(route('author.products.edit', $product))->assertOk();
});

test('guests cannot manage products', function () {
    $this->get(route('author.products.index'))->assertRedirect(route('login'));
    $this->post(route('author.products.store'), productCorePayload())->assertRedirect(route('login'));
    $this->get(route('admin.products.index'))->assertRedirect(route('login'));
});

test('customers and reviewers cannot manage products even with unrelated product permissions', function (string $role) {
    $user = User::factory()->create()->assignRole($role);
    $product = Product::factory()->create();
    $this->actingAs($user)->get(route('author.products.index'))->assertForbidden();
    $this->get(route('author.products.create'))->assertForbidden();
    $this->get(route('author.products.show', $product))->assertForbidden();
    $this->get(route('author.products.edit', $product))->assertForbidden();
    $this->post(route('author.products.store'), productCorePayload())->assertForbidden();
    $this->put(route('author.products.update', $product), productCorePayload())->assertForbidden();
    $this->delete(route('author.products.destroy', $product))->assertForbidden();
    $this->get(route('admin.products.index'))->assertForbidden();
    $this->post(route('admin.products.store'), productCorePayload())->assertForbidden();
})->with(['customer', 'reviewer']);

test('author cannot access or mutate another authors product by any management route', function () {
    $author = productCoreAuthor();
    $other = Product::factory()->create(['owner_id' => productCoreAuthor()->id]);
    $this->actingAs($author)->get(route('author.products.show', $other))->assertForbidden();
    $this->get(route('author.products.edit', $other))->assertForbidden();
    $this->put(route('author.products.update', $other), productCorePayload())->assertForbidden();
    $this->delete(route('author.products.destroy', $other))->assertForbidden();
    $this->get(route('admin.products.edit', $other))->assertForbidden();
    $this->put(route('admin.products.update', $other), productCorePayload())->assertForbidden();
    $this->delete(route('admin.products.destroy', $other))->assertForbidden();
    $this->get(route('author.products.index', ['owner_id' => $other->owner_id]))->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
    $this->assertDatabaseHas('products', ['id' => $other->id]);
});

test('author cannot change ownership origin status or admin featured fields', function (string $field, mixed $value) {
    $this->actingAs(productCoreAuthor())->post(route('author.products.store'), productCorePayload([$field => $value]))->assertSessionHasErrors($field);
    expect(Product::count())->toBe(0);
})->with([
    ['owner_id', 1234], ['origin', 'templaara'], ['status', 'published'], ['is_featured', true],
]);

test('mass assignment cannot change product ownership origin status or featured flag', function () {
    $owner = productCoreAuthor();
    $product = Product::factory()->create(['owner_id' => $owner->id]);
    $product->fill(['owner_id' => null, 'origin' => 'templaara', 'status' => 'published', 'is_featured' => true])->save();
    expect($product->fresh()->owner_id)->toBe($owner->id)->and($product->fresh()->origin)->toBe(ProductOrigin::Author)
        ->and($product->fresh()->status)->toBe(ProductStatus::Draft)->and($product->fresh()->is_featured)->toBeFalse();
});

test('author can update own product with business content and catalog relationships', function () {
    $author = productCoreAuthor();
    $product = Product::factory()->create(['owner_id' => $author->id]);
    $industries = Industry::factory()->count(2)->create();
    $technologies = Technology::factory()->count(2)->create();
    $features = [
        ['title' => 'Fast billing', 'description' => 'Create bills quickly.', 'sort_order' => 20],
        ['title' => 'Stock tracking', 'sort_order' => 10],
    ];
    $offers = productCorePayload()['offers'];
    $offers[] = [...$offers[0], 'name' => 'Software + Installation', 'price' => '8999.00', 'is_default' => false, 'sort_order' => 10];
    $this->actingAs($author)->put(route('author.products.update', $product), productCorePayload([
        'name' => 'Updated Product', 'business_problem' => 'Replace manual stock tracking.',
        'target_audience' => ['Retail', 'Wholesale'], 'key_benefits' => ['Faster billing', 'Stock visibility'],
        'industry_ids' => $industries->modelKeys(), 'technology_ids' => $technologies->modelKeys(),
        'features' => $features, 'offers' => $offers,
    ]))->assertRedirect(route('author.products.edit', $product));
    $product->refresh();
    expect($product->name)->toBe('Updated Product')->and($product->category->id)->toBe(Category::firstOrFail()->id)
        ->and($product->industries)->toHaveCount(2)->and($product->technologies)->toHaveCount(2)
        ->and($product->features)->toHaveCount(2)->and($product->offers)->toHaveCount(2)
        ->and($product->features->first()->getAttribute('title'))->toBe('Stock tracking')
        ->and($product->target_audience)->toBe(['Retail', 'Wholesale'])
        ->and($product->key_benefits)->toBe(['Faster billing', 'Stock visibility']);
});

test('author can delete own draft and only product dependent data is removed', function () {
    $author = productCoreAuthor();
    $product = Product::factory()->create(['owner_id' => $author->id]);
    $industry = Industry::factory()->create();
    $technology = Technology::factory()->create();
    $product->industries()->attach($industry);
    $product->technologies()->attach($technology);
    ProductFeature::factory()->for($product)->create();
    ProductOffer::factory()->for($product)->create();
    $this->actingAs($author)->delete(route('author.products.destroy', $product))->assertRedirect(route('author.products.index'));
    foreach (['products', 'product_features', 'product_offers', 'industry_product', 'product_technology'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    $this->assertDatabaseHas('categories', ['id' => $product->category_id]);
    $this->assertDatabaseHas('industries', ['id' => $industry->id]);
    $this->assertDatabaseHas('technologies', ['id' => $technology->id]);
    $this->assertDatabaseHas('users', ['id' => $author->id]);
});

test('admin can list all products create official drafts and edit author products', function () {
    $authorProduct = Product::factory()->create();
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin)->get(route('admin.products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('context', 'admin')->where('records.total', 1)->has('records.data.0.owner.name'));
    $this->get(route('admin.products.create'))->assertOk();
    $this->post(route('admin.products.store'), productCorePayload(['is_featured' => true]))->assertRedirect();
    $official = Product::where('origin', 'templaara')->firstOrFail();
    expect($official->owner_id)->toBeNull()->and($official->origin)->toBe(ProductOrigin::Templaara)
        ->and($official->status)->toBe(ProductStatus::Draft)->and($official->is_featured)->toBeTrue();
    $this->put(route('admin.products.update', $authorProduct), productCorePayload(['name' => 'Admin Edit']))->assertRedirect();
    expect($authorProduct->fresh()->name)->toBe('Admin Edit')->and($authorProduct->fresh()->origin)->toBe(ProductOrigin::Author);
    $this->delete(route('admin.products.destroy', $official))->assertRedirect();
});

test('manual duplicate slugs fail and unchanged slugs remain valid on update', function () {
    $author = productCoreAuthor();
    $existing = Product::factory()->create(['owner_id' => $author->id, 'slug' => 'crm']);
    $other = Product::factory()->create(['owner_id' => $author->id]);
    $this->actingAs($author)->post(route('author.products.store'), productCorePayload(['slug' => 'crm']))->assertSessionHasErrors('slug');
    $this->put(route('author.products.update', $other), productCorePayload(['slug' => 'crm']))->assertSessionHasErrors('slug');
    $this->put(route('author.products.update', $existing), productCorePayload(['slug' => 'crm']))->assertRedirect();
    expect($existing->fresh()->slug)->toBe('crm');
});

test('product slugs generate suffixes and preserve omitted slugs on update', function () {
    $this->actingAs(productCoreAuthor());
    foreach (['nexora-inventory-pos', 'nexora-inventory-pos-2', 'nexora-inventory-pos-3'] as $slug) {
        $this->post(route('author.products.store'), productCorePayload(['slug' => '']))->assertRedirect();
        $this->assertDatabaseHas('products', ['slug' => $slug]);
    }
    $product = Product::firstOrFail();
    $this->put(route('author.products.update', $product), productCorePayload(['name' => 'Changed Name']))->assertRedirect();
    expect($product->fresh()->slug)->toBe('nexora-inventory-pos');
    $this->put(route('author.products.update', $product), productCorePayload(['name' => 'Changed Name', 'slug' => '']))->assertRedirect();
    expect($product->fresh()->slug)->toBe('changed-name');
});

test('product URLs and catalog ids are validated', function (string $field, mixed $value, string $error) {
    $this->actingAs(productCoreAuthor())->post(route('author.products.store'), productCorePayload([$field => $value]))->assertSessionHasErrors($error);
    expect(Product::count())->toBe(0);
})->with([
    ['demo_url', 'javascript:alert(1)', 'demo_url'],
    ['documentation_url', 'ftp://example.com/docs', 'documentation_url'],
    ['industry_ids', [999999], 'industry_ids.0'],
    ['technology_ids', [999999], 'technology_ids.0'],
    ['category_id', 999999, 'category_id'],
    ['slug', 'Invalid Slug', 'slug'],
    ['currency', 'XYZ', 'currency'],
    ['target_audience', [['raw' => 'json']], 'target_audience.0'],
]);

test('offer prices reject negative excessive fractional and exponential values', function (string $price) {
    $data = productCorePayload();
    $data['offers'][0]['price'] = $price;
    $this->actingAs(productCoreAuthor())->post(route('author.products.store'), $data)->assertSessionHasErrors('offers.0.price');
})->with(['-0.01', '4.999', '1e3', '10000000000.00']);

test('offers require exactly one active default and controlled license types', function (string $scenario) {
    $data = productCorePayload();
    if ($scenario === 'none') {
        $data['offers'][0]['is_default'] = false;
    }
    if ($scenario === 'two') {
        $data['offers'][] = $data['offers'][0];
    }
    if ($scenario === 'inactive') {
        $data['offers'][0]['is_active'] = false;
    }
    if ($scenario === 'empty') {
        $data['offers'] = [];
    }
    if ($scenario === 'license') {
        $data['offers'][0]['license_type'] = 'unlimited-magic';
    }
    if ($scenario === 'support') {
        $data['offers'][0]['support_period_days'] = -1;
    }
    if ($scenario === 'updates') {
        $data['offers'][0]['updates_period_days'] = -1;
    }
    $this->actingAs(productCoreAuthor())->post(route('author.products.store'), $data)->assertSessionHasErrors();
    expect(Product::count())->toBe(0);
})->with(['none', 'two', 'inactive', 'empty', 'license', 'support', 'updates']);

test('nested rows cannot reference another products features or offers', function () {
    $author = productCoreAuthor();
    $own = Product::factory()->create(['owner_id' => $author->id]);
    $other = Product::factory()->create();
    $feature = ProductFeature::factory()->for($other)->create();
    $offer = ProductOffer::factory()->for($other)->create();
    $data = productCorePayload(['features' => [['id' => $feature->id, 'title' => 'Hijacked', 'sort_order' => 0]]]);
    $data['offers'][0]['id'] = $offer->id;
    $this->actingAs($author)->put(route('author.products.update', $own), $data)->assertSessionHasErrors(['features.0.id', 'offers.0.id']);
    expect($feature->fresh()->getAttribute('title'))->not->toBe('Hijacked')->and($offer->fresh()->product_id)->toBe($other->id);
});

test('nested extra keys and duplicate ids are rejected without partial writes', function () {
    $data = productCorePayload(['features' => [['title' => 'Stock', 'sort_order' => 0, 'product_id' => 999]]]);
    $data['offers'][0]['owner_id'] = 123;
    $this->actingAs(productCoreAuthor())->post(route('author.products.store'), $data)->assertSessionHasErrors(['features.0', 'offers.0']);
    expect(Product::count())->toBe(0)->and(ProductFeature::count())->toBe(0)->and(ProductOffer::count())->toBe(0);
});

test('editing maintains child identities switches defaults and removes omitted rows', function () {
    $author = productCoreAuthor();
    $product = Product::factory()->create(['owner_id' => $author->id]);
    $first = ProductOffer::factory()->for($product)->create();
    $second = ProductOffer::factory()->for($product)->additional()->create();
    $keep = ProductFeature::factory()->for($product)->create();
    $remove = ProductFeature::factory()->for($product)->create();
    $offer = productCorePayload()['offers'][0];
    $data = productCorePayload([
        'offers' => [[...$offer, 'id' => $first->id, 'is_default' => false], [...$offer, 'id' => $second->id, 'is_default' => true]],
        'features' => [['id' => $keep->id, 'title' => 'Kept', 'sort_order' => 30]],
    ]);
    $this->actingAs($author)->put(route('author.products.update', $product), $data)->assertRedirect();
    expect($first->fresh()->is_default)->toBeFalse()->and($second->fresh()->is_default)->toBeTrue()
        ->and($keep->fresh()->getAttribute('title'))->toBe('Kept');
    $this->assertDatabaseMissing('product_features', ['id' => $remove->id]);
    expect($product->offers()->count())->toBe(2);
});

test('new drafts require active catalog and existing inactive selections can be retained', function () {
    $author = productCoreAuthor();
    $category = Category::factory()->inactive()->create();
    $industry = Industry::factory()->inactive()->create();
    $technology = Technology::factory()->inactive()->create();
    $this->actingAs($author)->post(route('author.products.store'), productCorePayload([
        'category_id' => $category->id, 'industry_ids' => [$industry->id], 'technology_ids' => [$technology->id],
    ]))->assertSessionHasErrors(['category_id', 'industry_ids.0', 'technology_ids.0']);
    $product = Product::factory()->create(['owner_id' => $author->id, 'category_id' => $category->id]);
    $product->industries()->attach($industry);
    $product->technologies()->attach($technology);
    $this->get(route('author.products.edit', $product))->assertInertia(fn (Assert $page) => $page
        ->where('product.category_id', $category->id)->has('industries', 1)->has('technologies', 1));
    $this->put(route('author.products.update', $product), productCorePayload([
        'category_id' => $category->id, 'industry_ids' => [$industry->id], 'technology_ids' => [$technology->id],
    ]))->assertRedirect();
});

test('catalog records referenced by products cannot be deleted', function (string $kind, string $model) {
    $product = Product::factory()->create();
    $record = $kind === 'categories' ? $product->category : $model::factory()->create();
    if ($kind === 'industries') {
        $product->industries()->attach($record);
    }
    if ($kind === 'technologies') {
        $product->technologies()->attach($record);
    }
    $this->actingAs(User::factory()->create()->assignRole('admin'))->delete(route("admin.$kind.destroy", $record))->assertSessionHasErrors('delete');
    $this->assertDatabaseHas('products', ['id' => $product->id]);
    $this->assertDatabaseHas((new $model)->getTable(), ['id' => $record->id]);
    expect(fn () => DB::transaction(fn () => $record->delete()))->toThrow(QueryException::class);
})->with([
    ['categories', Category::class], ['industries', Industry::class], ['technologies', Technology::class],
]);

test('author account deletion is blocked before authentication is cleared', function () {
    $author = productCoreAuthor();
    Product::factory()->create(['owner_id' => $author->id]);
    $this->actingAs($author)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    $this->assertAuthenticatedAs($author);
    $this->assertDatabaseHas('users', ['id' => $author->id]);
});

test('listings are scoped filtered paginated and omit demo credentials', function () {
    $author = productCoreAuthor();
    $category = Category::firstOrFail();
    Product::factory()->count(17)->create(['owner_id' => $author->id, 'category_id' => $category->id]);
    Product::factory()->official()->create(['name' => 'Official Accounting', 'demo_password' => 'demo-only']);
    $this->actingAs($author)->get(route('author.products.index'))->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 15)->where('records.total', 17)->missing('records.data.0.demo_password')->missing('records.data.0.demo_username'));
    $this->get(route('author.products.index', ['search' => '%']))->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
    $this->actingAs(User::factory()->create()->assignRole('admin'))
        ->get(route('admin.products.index', ['search' => 'accounting', 'origin' => 'templaara', 'status' => 'draft']))
        ->assertInertia(fn (Assert $page) => $page->where('records.total', 1)->where('records.data.0.name', 'Official Accounting')->missing('records.data.0.demo_password'));
    $this->get(route('admin.products.index', ['category' => $category->id]))->assertInertia(fn (Assert $page) => $page->where('records.total', 17));
});

test('development product seeding is idempotent and preserves edited data', function () {
    $this->seed(BusinessCatalogSeeder::class);
    $this->seed(DevelopmentProductSeeder::class);
    $product = Product::where('slug', 'nexora-inventory-pos')->firstOrFail();
    $product->update(['name' => 'Edited sample']);
    $this->seed(DevelopmentProductSeeder::class);
    expect(Product::count())->toBe(1)->and($product->fresh()->name)->toBe('Edited sample')
        ->and($product->origin)->toBe(ProductOrigin::Templaara)->and($product->owner_id)->toBeNull()
        ->and($product->industries()->count())->toBe(2)->and($product->technologies()->count())->toBe(3)
        ->and($product->features()->count())->toBe(2)->and($product->offers()->count())->toBe(1);
});

test('development product seeding refuses production', function () {
    app()->instance('env', 'production');
    expect(fn () => (new DevelopmentProductSeeder)->run())->toThrow(RuntimeException::class);
});

test('product service enforces authorization even outside HTTP controllers', function () {
    $customer = User::factory()->create()->assignRole('customer');
    expect(fn () => app(SaveProduct::class)->create($customer, productCorePayload(), ProductOrigin::Templaara))
        ->toThrow(AuthorizationException::class);
});

test('product writes roll back completely if a relation fails database integrity', function () {
    $author = productCoreAuthor();
    $data = productCorePayload([
        'features' => [['title' => 'Stock tracking', 'sort_order' => 0]],
        'technology_ids' => [999999],
    ]);
    expect(fn () => app(SaveProduct::class)->create($author, $data, ProductOrigin::Author))->toThrow(QueryException::class);
    expect(Product::count())->toBe(0)->and(ProductFeature::count())->toBe(0)->and(ProductOffer::count())->toBe(0);
});

test('owner permissions are still required and reviewer editing grants do not bypass ownership', function () {
    $author = productCoreAuthor();
    $product = Product::factory()->create(['owner_id' => $author->id]);
    $author->removeRole('author');
    $author->givePermissionTo(['products.view', 'products.update', 'products.delete']);
    $this->actingAs($author)->put(route('author.products.update', $product), productCorePayload())->assertForbidden();
    $reviewer = User::factory()->create()->assignRole('reviewer')->givePermissionTo('products.update');
    $this->actingAs($reviewer)->put(route('author.products.update', $product), productCorePayload())->assertForbidden();
});
