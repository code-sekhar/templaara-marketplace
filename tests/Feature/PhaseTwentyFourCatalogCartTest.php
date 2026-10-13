<?php

use App\Models\Cart;
use App\Models\Technology;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Marketplace\MarketplaceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Tests\Support\CommerceFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    ProductFileFixtures::fakeStorage();
});

test('multi-select technologies match any active choice without duplicated products', function (): void {
    $a = CommerceFixtures::product();
    $b = CommerceFixtures::product();
    $first = $a->technologies->first();
    $second = $b->technologies->first();
    $a->technologies()->attach($second);
    $filters = ['technologies' => [$first->slug, $second->slug]];
    $query = app(MarketplaceQuery::class)->browse($filters);
    expect($query->pluck('products.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
    expect($query->paginate(1)->total())->toBe(2);
    $first->update(['is_active' => false]);
    expect(app(MarketplaceQuery::class)->browse(['technologies' => [$first->slug]])->count())->toBe(0);
    expect(app(MarketplaceQuery::class)->browse(['technologies' => ['unknown-technology']])->count())->toBe(0);
});

test('multi-select industry and technology groups intersect with category currency and prices', function (): void {
    $a = CommerceFixtures::product(['currency' => 'INR']);
    $b = CommerceFixtures::product(['currency' => 'USD']);
    $industry = $a->industries->first();
    $technology = $a->technologies->first();
    $filters = ['category' => $a->category->slug, 'industries' => [$industry->slug], 'technologies' => [$technology->slug], 'currency' => 'INR', 'min_price' => '0', 'max_price' => '999999', 'view' => 'list'];
    expect(app(MarketplaceQuery::class)->browse($filters)->pluck('products.id')->all())->toBe([$a->id]);
    $filters['technologies'] = [$b->technologies->first()->slug];
    expect(app(MarketplaceQuery::class)->browse($filters)->count())->toBe(0);
});

test('active public taxonomy excludes inactive technologies', function (): void {
    $active = Technology::factory()->create();
    $inactive = Technology::factory()->create(['is_active' => false]);
    $facets = app(MarketplaceQuery::class)->facets();
    expect(array_column($facets['technologies'], 'slug'))->toContain($active->slug)->not->toContain($inactive->slug);
});

test('array selectors normalize duplicates and retain valid presentation parameters', function (): void {
    $filters = app(MarketplaceQuery::class)->filters(Request::create('/products', 'GET', ['technologies' => ['vue', 'laravel', 'vue'], 'industries' => ['retail', 'retail'], 'view' => 'list', 'sort' => 'price_asc', 'min_price' => '1.01', 'arbitrary' => 'ignored']));
    expect($filters['technologies'])->toBe(['laravel', 'vue'])->and($filters['industries'])->toBe(['retail'])->and($filters['view'])->toBe('list')->and($filters['currency'])->toBe('INR')->and($filters)->not->toHaveKey('arbitrary');
});

test('malicious or structurally invalid multi-select inputs are bounded', function (array $data, string $field): void {
    $this->getJson('/products?'.http_build_query($data))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['technologies' => 'laravel'], 'technologies'],
    [['technologies' => [['laravel']]], 'technologies.0'],
    [['technologies' => ["vue' OR 1=1"]], 'technologies.0'],
    [['industries' => ['<script>']], 'industries.0'],
    [['technologies' => array_fill(0, 21, 'vue')], 'technologies'],
    [['category' => ['crm']], 'category'],
]);

test('legacy scalar selectors continue working alongside bounded multiple selectors', function (): void {
    $product = CommerceFixtures::product();
    $tech = $product->technologies->first();
    expect(app(MarketplaceQuery::class)->browse(['technology' => $tech->slug])->pluck('products.id')->all())->toBe([$product->id]);
    expect(app(MarketplaceQuery::class)->browse(['technology' => $tech->slug, 'technologies' => ['unknown']])->count())->toBe(0);
});

test('category and industry context cannot be broadened by array selectors', function (): void {
    $a = CommerceFixtures::product();
    $b = CommerceFixtures::product();
    $this->get('/industries/'.$a->industries->first()->slug.'?'.http_build_query(['industries' => [$b->industries->first()->slug]]))->assertOk()->assertInertia(fn ($p) => $p->where('records.total', 0));
});

test('detail content remains escaped public data with authoritative packages', function (): void {
    $p = CommerceFixtures::product(['description' => "First paragraph.\n\n<script>not executable</script>\n\n- Actual recorded feature"]);
    $this->get('/products/'.$p->slug)->assertOk()->assertInertia(fn ($page) => $page->where('product.description', $p->description)->where('product.offers.0.activation_limit', 1)->missing('product.owner.email')->missing('product.artifacts')->missing('product.submissions'));
});

test('cart projection includes safe category and validated license terms and keeps exact totals', function (): void {
    $p = CommerceFixtures::product();
    $u = User::factory()->create()->assignRole('customer');
    $service = app(CartService::class);
    $offer = $p->offers()->first();
    $service->add($u, $p->slug, $offer->id);
    $cart = Cart::where('user_id', $u->id)->firstOrFail();
    $data = $service->presentation($service->resolve($cart));
    expect($data['items'][0]['category'])->toBe($p->category->only(['name', 'slug']))->and($data['items'][0]['offers'][0]['activation_limit'])->toBe(1)->and($data['total'])->toBe($offer->price)->and($data['valid'])->toBeTrue();
    $offer->update(['price' => '123.45']);
    $changed = $service->presentation($service->resolve($cart));
    expect($changed['valid'])->toBeFalse()->and($changed['items'][0]['issue'])->toContain('Price changed');
    $service->add($u, $p->slug, $offer->id);
    expect($service->resolve($cart)['valid'])->toBeTrue();
});
