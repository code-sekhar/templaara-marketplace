<?php

use App\Models\Category;
use App\Models\Product;
use App\Services\Development\DevelopmentDemoCatalog;
use App\Services\Marketplace\MarketplaceQuery;
use Database\Seeders\BusinessCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductFileFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(BusinessCatalogSeeder::class);
    ProductFileFixtures::fakeStorage();
    $this->demoDirectory = sys_get_temp_dir().'/templaara-demo-test-'.bin2hex(random_bytes(8));
    $path = $this->demoDirectory.'/registry.json';
    app()->instance(DevelopmentDemoCatalog::class, new class($path) extends DevelopmentDemoCatalog
    {
        public function __construct(private string $path) {}

        public function registryPath(): string
        {
            return $this->path;
        }
    });
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->demoDirectory);
});

test('demo catalog refuses production without creating users or products', function (): void {
    app()->instance('env', 'production');
    $before = DB::table('users')->count();
    $this->artisan('marketplace:demo-catalog')->assertFailed();
    expect(DB::table('users')->count())->toBe($before)->and(Product::count())->toBe(0);
});

test('public navigation exposes only active category name slug and description', function (): void {
    Category::factory()->create(['is_active' => false, 'name' => 'Hidden category']);
    $this->get('/')->assertOk()->assertInertia(function ($page): void {
        $page->has('marketplaceNavigation', 10)->where('marketplaceNavigation.0', fn ($category) => count($category) === 3 && isset($category['name'], $category['slug']) && $category->has('description'));
    });
});

test('demo catalog uses normal publication rounds is idempotent and removes only registered visibility', function (): void {
    $unrelated = Product::factory()->create();
    $catalog = app(DevelopmentDemoCatalog::class);
    expect($catalog->run())->toBe(10);
    $ids = Product::where('slug', 'like', 'demo-%')->pluck('id');
    expect(app(MarketplaceQuery::class)->visible()->whereIn('products.id', $ids)->count())->toBe(10);
    expect(DB::table('product_submissions')->whereIn('product_id', $ids)->count())->toBe(10);
    expect(DB::table('product_lifecycle_events')->whereIn('product_id', $ids)->count())->toBe(40);
    expect(DB::table('orders')->count())->toBe(0)->and(DB::table('product_reviews')->count())->toBe(0);
    expect($catalog->run())->toBe(10)->and(Product::count())->toBe(11);
    expect($catalog->run(true))->toBe(10);
    expect($unrelated->fresh()->is_active)->toBeTrue();
    expect(app(MarketplaceQuery::class)->visible()->whereIn('products.id', $ids)->count())->toBe(0);
    expect(DB::table('product_submissions')->whereIn('product_id', $ids)->count())->toBe(10);
});

test('demo refuses missing active catalog prerequisites without adopting records', function (): void {
    Category::query()->update(['is_active' => false]);
    expect(fn () => app(DevelopmentDemoCatalog::class)->run())->toThrow(RuntimeException::class);
    expect(Product::count())->toBe(0);
});
