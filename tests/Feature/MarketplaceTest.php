<?php

use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductOffer;
use App\Models\Technology;
use App\Models\User;
use App\Services\Products\Workflow\ProductWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProductFileFixtures;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    ProductFileFixtures::fakeStorage();
});

function marketplaceProduct(array $attributes = [], bool $official = false): Product
{
    $owner = User::factory()->create()->assignRole('author');
    $admin = User::factory()->create()->assignRole('admin');
    $product = Product::factory()->create([
        'owner_id' => $official ? null : $owner->id,
        'origin' => $official ? 'templaara' : 'author',
        'name' => 'Nexora Inventory & POS',
        'short_description' => 'Inventory and billing software for retail and wholesale businesses.',
        'description' => 'Manage billing, purchases, stock and customer dues in one reliable business workspace.',
        'business_problem' => 'Replace manual stock tracking and fragmented billing records.',
        'target_audience' => ['Retail businesses', 'Wholesale distributors'],
        'key_benefits' => ['Faster billing and accurate stock visibility'],
        'demo_url' => 'https://demo.example.com', 'demo_username' => 'demo-public', 'demo_password' => 'demo-public-password',
        'documentation_url' => 'https://docs.example.com', 'preview_video_url' => 'https://video.example.com/watch',
        ...$attributes,
    ]);
    $product->industries()->attach(Industry::factory()->create(['name' => 'Retail']));
    $product->technologies()->attach(Technology::factory()->create(['name' => 'Laravel']));
    ProductFeature::factory()->for($product)->create(['title' => 'Purchase tracking']);
    ProductOffer::factory()->for($product)->create();
    ProductFileFixtures::prepare($product, $official ? $admin : $owner);
    $workflow = app(ProductWorkflow::class);
    $round = $workflow->transition($official ? $admin : $owner, $product, ProductWorkflowAction::Submit);
    $workflow->transition($admin, $product, ProductWorkflowAction::StartReview, $round->id);
    $workflow->transition($admin, $product, ProductWorkflowAction::Approve, $round->id);
    $workflow->transition($admin, $product, ProductWorkflowAction::Publish);

    return $product->fresh();
}
function marketplaceSlugs($response): array
{
    return collect($response->assertOk()->inertiaProps('records.data'))->pluck('slug')->all();
}

test('guests can access storefront routes without authentication', function (string $path) {
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
})->with(['/', '/marketplace', '/products', '/search']);

test('guest browses a valid published product by slug with business content', function () {
    $product = marketplaceProduct();
    $this->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page->component('marketplace/Index')->has('records.data', 1)->where('records.data.0.slug', $product->slug));
    $this->get('/products/'.$product->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('marketplace/Show')
        ->where('product.business_problem', $product->business_problem)->where('product.target_audience', $product->target_audience)
        ->where('product.key_benefits', $product->key_benefits)->has('product.features', 1)->has('product.screenshots', 2)
        ->where('product.demo_url', 'https://demo.example.com')->where('product.demo_username', 'demo-public')
        ->where('product.demo_password', 'demo-public-password')->where('product.release.version', '1.0.0'));
});

test('every nonpublished lifecycle state returns a public 404 and is excluded', function (ProductStatus $status) {
    $product = marketplaceProduct();
    DB::table('products')->where('id', $product->id)->update(['status' => $status->value]);
    $this->get('/products/'.$product->slug)->assertNotFound();
    $this->get('/products')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
})->with(array_filter(ProductStatus::cases(), fn ($status) => $status !== ProductStatus::Published));

test('invalid visibility dependencies fail closed everywhere', function (string $dependency) {
    $product = marketplaceProduct(['is_featured' => true]);
    match ($dependency) {
        'product' => $product->update(['is_active' => false]),
        'category' => $product->category->update(['is_active' => false]),
        'offer' => $product->offers()->update(['is_active' => false, 'is_default' => false]),
        'cover' => $product->cover()->update(['is_current' => false]),
        'gallery' => $product->screenshots()->first()->forceFill(['is_current' => false])->save(),
        'release' => DB::table('product_releases')->where('product_id', $product->id)->delete(), // Artifact FK cleanup is safe; not a workspace mutation.
        'package' => $product->releases()->first()->artifacts()->update(['is_current' => false]),
        'disk' => config()->set('product_files.media_disk', config('product_files.artifact_disk')),
    };
    $this->get('/products/'.$product->slug)->assertNotFound();
    $this->get('/products')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('featured', 0)->has('recent', 0));
})->with(['product', 'category', 'offer', 'cover', 'gallery', 'package', 'disk']);

test('legacy publication without a published Phase 5 release remains private', function () {
    Product::factory()->create(['status' => 'published']);
    $this->get('/products')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
});

test('public product responses use strict allow lists and exclude all moderation and commercial internals', function () {
    $product = marketplaceProduct();
    $artifact = $product->releases()->first()->artifacts()->first();
    $response = $this->get('/products/'.$product->slug)->assertOk();
    $props = $response->inertiaProps();
    $json = json_encode($props);
    foreach ([$artifact->path, $artifact->disk, $artifact->checksum, $artifact->original_name, $product->owner->email] as $secret) {
        expect($json)->not->toContain($secret);
    }
    $keys = function (array $value) use (&$keys): array {
        $result = array_keys($value);
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $result = [...$result, ...$keys($nested)];
            }
        }

        return $result;
    };
    expect($keys($props['product']))->not->toContain('id', 'owner_id', 'email', 'disk', 'path', 'checksum', 'artifacts', 'submissions', 'snapshot', 'reviewed_by', 'feedback', 'roles', 'permissions', 'status');
    expect($props['product']['seller'])->toBe($product->owner->name);
    expect(array_keys($props['product']['cover']))->toBe(['url', 'alt']);
});

test('signed in storefront props do not disclose emails or authorization internals', function () {
    $user = User::factory()->create()->assignRole('author');
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user', ['name' => $user->name]));
});

test('product cards exclude detail only data', function () {
    marketplaceProduct();
    $this->get('/products')->assertInertia(fn (Assert $page) => $page->missing('records.data.0.demo_password')->missing('records.data.0.demo_username')->missing('records.data.0.release')->missing('records.data.0.seller')->missing('records.data.0.description')->missing('records.data.0.id'));
});

test('business search finds meaningful public fields', function (string $term) {
    $product = marketplaceProduct();
    expect(marketplaceSlugs($this->get('/products?search='.urlencode($term))->assertOk()))->toBe([$product->slug]);
})->with(['Nexora', 'fragmented', 'distributors', 'Retail', 'Purchase tracking']);

test('search input escapes wildcards and never interprets SQL payloads', function (string $term) {
    marketplaceProduct();
    expect(marketplaceSlugs($this->get('/products?search='.urlencode($term))->assertOk()))->toBe([]);
})->with(['%', '_', "' OR 1=1 --", '\\']);

test('name matches rank ahead of other business field matches', function () {
    $secondary = marketplaceProduct(['name' => 'Wholesale Companion', 'business_problem' => 'Nexora helps replace manual tracking and billing records.']);
    $primary = marketplaceProduct();
    expect(marketplaceSlugs($this->get('/products?search=Nexora')))->toBe([$primary->slug, $secondary->slug]);
});

test('catalog filters use active related slugs', function (string $kind, string $relation) {
    $match = marketplaceProduct();
    $other = marketplaceProduct();
    $record = $relation === 'category' ? $match->category : $match->{$relation}->first();
    expect(marketplaceSlugs($this->get('/products?'.$kind.'='.$record->slug)))->toBe([$match->slug]);
    if ($relation !== 'category') {
        $record->update(['is_active' => false]);
        expect(marketplaceSlugs($this->get('/products?'.$kind.'='.$record->slug)))->toBe([]);
    }
})->with([['category', 'category'], ['industry', 'industries'], ['technology', 'technologies']]);

test('starting prices use minimum active offers instead of default or inactive cheaper offers', function () {
    $product = marketplaceProduct();
    ProductOffer::factory()->additional()->for($product)->create(['price' => '2999.99']);
    ProductOffer::factory()->additional()->for($product)->create(['price' => '0.00', 'is_active' => false]);
    $this->get('/products')->assertInertia(fn (Assert $page) => $page->where('records.data.0.starting_price', '2999.99'));
    $this->get('/products/'.$product->slug)->assertInertia(fn (Assert $page) => $page->has('product.offers', 2)->where('product.offers.0.is_default', true));
});

test('price ranges compare starting prices within their currency exactly', function () {
    $product = marketplaceProduct();
    ProductOffer::factory()->additional()->for($product)->create(['price' => '9.99']);
    marketplaceProduct(['currency' => 'USD']);
    expect(marketplaceSlugs($this->get('/products?min_price=9.99&max_price=9.99')))->toBe([$product->slug]);
    expect(marketplaceSlugs($this->get('/products?min_price=10.00')))->toBe([]);
});

test('price sorting compares decimal values numerically', function (string $sort, bool $ascending) {
    $low = marketplaceProduct();
    $low->offers()->update(['price' => '9.99']);
    $high = marketplaceProduct();
    $high->offers()->update(['price' => '100.01']);
    expect(marketplaceSlugs($this->get('/products?sort='.$sort)))->toBe($ascending ? [$low->slug, $high->slug] : [$high->slug, $low->slug]);
})->with([['price_asc', true], ['price_desc', false]]);

test('newest sorting uses publication time rather than draft creation time', function () {
    $old = marketplaceProduct();
    $this->travel(1)->hours();
    $new = marketplaceProduct();
    expect(marketplaceSlugs($this->get('/products?sort=newest')))->toBe([$new->slug, $old->slug]);
});

test('category and industry browsing only expose public products', function (string $kind, string $relation) {
    $product = marketplaceProduct();
    $record = $relation === 'category' ? $product->category : $product->{$relation}->first();
    Product::factory()->create(['category_id' => $product->category_id]);
    expect(marketplaceSlugs($this->get('/'.$kind.'/'.$record->slug)->assertOk()))->toBe([$product->slug]);
    $record->update(['is_active' => false]);
    $this->get('/'.$kind.'/'.$record->slug)->assertNotFound();
})->with([['categories', 'category'], ['industries', 'industries']]);

test('featured products are deliberately curated and official badges reflect origin', function () {
    $featured = marketplaceProduct(['is_featured' => true], true);
    marketplaceProduct();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('featured', 1)->where('featured.0.slug', $featured->slug)->where('featured.0.official', true));
});

test('related products exclude current and hidden products in a deterministic order', function () {
    $product = marketplaceProduct();
    $related = marketplaceProduct(['category_id' => $product->category_id]);
    Product::factory()->create(['category_id' => $product->category_id, 'status' => 'draft']);
    $this->get('/products/'.$product->slug)->assertInertia(fn (Assert $page) => $page->has('related', 1)->where('related.0.slug', $related->slug));
});

test('public media URLs are generated by the configured filesystem and ordered', function () {
    $product = marketplaceProduct();
    $last = $product->screenshots()->first();
    $last->forceFill(['sort_order' => 10, 'alt_text' => 'Stock dashboard'])->save();
    $this->get('/products/'.$product->slug)->assertInertia(fn (Assert $page) => $page->where('product.screenshots.1.alt', 'Stock dashboard')
        ->where('product.screenshots.1.url', Storage::disk($last->disk)->url($last->path)));
});

test('SEO uses product metadata with safe fallbacks and initial server HTML', function () {
    $product = marketplaceProduct(['meta_title' => 'Retail operations software', 'meta_description' => 'A clear description for business owners.']);
    $this->get('/products/'.$product->slug)->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Retail operations software')
        ->where('seo.description', 'A clear description for business owners.')->where('seo.canonical', route('marketplace.products.show', $product->slug)))
        ->assertSee('<meta name="description"', false)->assertSee('Retail operations software');
});

test('unsafe legacy external URLs are not published to storefront props', function () {
    $product = marketplaceProduct();
    DB::table('products')->where('id', $product->id)->update(['demo_url' => 'javascript:alert(1)', 'documentation_url' => 'data:text/html,secret', 'preview_video_url' => 'ftp://example.com']);
    $this->get('/products/'.$product->slug)->assertInertia(fn (Assert $page) => $page->where('product.demo_url', null)->where('product.documentation_url', null)->where('product.preview_video_url', null));
});

test('pagination is bounded and retains search filters and sorting', function () {
    $product = marketplaceProduct();
    // Duplicate public metadata and relation identities solely to exercise pagination without 13 review rounds.
    for ($i = 0; $i < 12; $i++) {
        $copy = $product->replicate();
        $copy->slug = 'pagination-'.$i;
        $copy->save();
        foreach ($product->media as $media) {
            $row = $media->replicate();
            $row->product_id = $copy->id;
            $row->path .= '-'.$i;
            $row->save();
        }
        $offer = $product->offers->first()->replicate();
        $offer->product_id = $copy->id;
        $offer->save();
        $release = $product->releases->first()->replicate();
        $release->product_id = $copy->id;
        $release->save();
        $artifact = $product->releases->first()->artifacts->first()->replicate();
        $artifact->product_release_id = $release->id;
        $artifact->path .= '-'.$i;
        $artifact->save();
    }
    $this->get('/products?search=Nexora&category='.$product->category->slug.'&sort=newest')->assertInertia(fn (Assert $page) => $page->has('records.data', 12)->where('records.total', 13)
        ->where('records.next_page_url', fn ($url) => str_contains($url, 'search=Nexora') && str_contains($url, 'sort=newest') && str_contains($url, 'category=')));
});

test('invalid filters return useful errors instead of expensive or arbitrary queries', function (array $filters, string $field) {
    $this->getJson('/products?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([[['search' => str_repeat('x', 101)], 'search'], [['sort' => 'popularity'], 'sort'], [['min_price' => '-1'], 'min_price'], [['min_price' => '100.01', 'max_price' => '99.99'], 'max_price'], [['currency' => 'AAA'], 'currency'], [['page' => '10001'], 'page']]);

test('storefront changes preserve private artifact authorization for guests and customers', function () {
    $product = marketplaceProduct();
    $release = $product->releases()->first();
    $artifact = $release->artifacts()->first();
    $url = route('products.files.artifacts.download', [$product, $release, $artifact]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create()->assignRole('customer'))->get($url)->assertForbidden();
});

test('authors cannot self-feature through the existing product editor', function () {
    $author = User::factory()->create()->assignRole('author');
    $this->actingAs($author)->post(route('author.products.store'), ['name' => 'Unauthorized featured product', 'is_featured' => true])->assertSessionHasErrors('is_featured');
});

test('structured data contains known software facts and cannot inject script markup', function () {
    $product = marketplaceProduct(['name' => 'Business Software </script><script>alert(1)</script>']);
    $response = $this->get('/products/'.$product->slug)->assertOk();
    $json = $response->inertiaProps('seo.structuredData');
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($data['@type'])->toBe('SoftwareApplication')->and($data['name'])->toBe($product->name)
        ->and(array_keys($data))->not->toContain('aggregateRating', 'offers', 'review', 'sales')
        ->and($json)->not->toContain('</script>');
    $response->assertSee('type="application/ld+json"', false);
});

test('catalog listing uses bounded eager queries without loading artifacts or moderation', function () {
    marketplaceProduct();
    marketplaceProduct();
    DB::enableQueryLog();
    $this->get('/products')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->count())->toBeLessThan(15);
    // EXISTS clauses enforce release/package readiness without hydrating commercial or audit rows.
    foreach ($queries as $query) {
        expect($query)->not->toMatch('/^select \* from ["`]?product_(artifacts|submissions|review_feedback|lifecycle_events)["`]?/i');
    }
});

test('missing published release state is excluded even with presentation files', function () {
    $product = Product::factory()->create(['owner_id' => User::factory()->create()->assignRole('author')->id]);
    ProductOffer::factory()->for($product)->create();
    ProductFileFixtures::prepare($product);
    DB::table('products')->where('id', $product->id)->update(['status' => 'published']);
    $this->get('/products/'.$product->slug)->assertNotFound();
    $this->get('/products')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
});
