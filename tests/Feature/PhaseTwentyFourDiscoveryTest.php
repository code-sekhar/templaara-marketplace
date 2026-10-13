<?php

use App\Models\Product;
use App\Models\User;
use App\Services\Development\DemoCatalogArtwork;
use App\Services\Development\DevelopmentDemoCatalog;
use App\Services\Marketplace\MarketplaceQuery;
use Database\Seeders\BusinessCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

test('catalog presentation mode safely defaults and keeps real filters', function (): void {
    foreach (['grid', 'list', 'unknown', ['list']] as $view) {
        $this->get('/products?'.http_build_query(['view' => $view, 'search' => 'Inventory', 'sort' => 'newest']))->assertOk()->assertInertia(fn ($p) => $p->where('filters.view', $view === 'list' ? 'list' : 'grid')->where('filters.search', 'Inventory')->where('filters.sort', 'newest'));
    }
});

test('category artwork is distinct original and explicitly fictional', function (): void {
    $hashes = [];
    foreach (['Stock', 'People', 'Pipeline', 'Finance', 'Field visits', 'Orders', 'Booking', 'Projects', 'Analytics', 'Tickets'] as $section) {
        $file = app(DemoCatalogArtwork::class)->image('Fictional software', $section, 0);
        try {
            $hashes[] = hash_file('sha256', $file->getPathname());
            expect(getimagesize($file->getPathname())[0])->toBe(1280);
        } finally {
            unlink($file->getPathname());
        }
    }
    expect(array_unique($hashes))->toHaveCount(10);
});

test('registered artwork updates retain old files snapshots packages and exact active prices', function (): void {
    $catalog = app(DevelopmentDemoCatalog::class);
    $catalog->run();
    $before = DB::table('product_media')->get();
    $snapshots = DB::table('product_submissions')->pluck('snapshot', 'id')->all();
    $artifacts = DB::table('product_artifacts')->get();
    $prices = Product::with('offers')->get()->mapWithKeys(fn ($p) => [$p->id => $p->offers->where('is_active', true)->pluck('price')->sort()->values()->all()])->all();
    expect($catalog->run(false, true))->toBe(10);
    foreach ($before as $m) {
        $old = DB::table('product_media')->find($m->id);
        expect($old->path)->toBe($m->path);
        expect(Storage::disk($m->disk)->exists($m->path))->toBeTrue();
    }
    foreach ($snapshots as $id => $snapshot) {
        expect(DB::table('product_submissions')->where('id', $id)->value('snapshot'))->toBe($snapshot);
    }
    foreach ($artifacts as $a) {
        expect(DB::table('product_artifacts')->where('id', $a->id)->value('checksum'))->toBe($a->checksum);
    }
    foreach (Product::with('offers')->get() as $p) {
        expect($p->offers->where('is_active', true)->pluck('price')->sort()->values()->all())->toBe($prices[$p->id]);
    }
    expect(app(MarketplaceQuery::class)->visible()->count())->toBe(10);
    expect(DB::table('product_revisions')->count())->toBe(10);
    expect($catalog->run(false, true))->toBe(10)->and(DB::table('product_revisions')->count())->toBe(10);
    expect(DB::table('orders')->count())->toBe(0)->and(DB::table('product_reviews')->count())->toBe(0);
});

test('artwork refresh refuses production and conflicting removal before writes', function (): void {
    expect(fn () => app(DevelopmentDemoCatalog::class)->run(true, true))->toThrow(RuntimeException::class);
    app()->instance('env', 'production');
    $this->artisan('marketplace:demo-catalog', ['--refresh-artwork' => true])->assertFailed();
    expect(Product::count())->toBe(0);
});

test('public account navigation is authenticated only and preserves minimal auth projection', function (): void {
    $this->get('/')->assertInertia(fn ($p) => $p->where('marketplaceAccount', null));
    foreach (['customer', 'author', 'admin'] as $role) {
        $u = User::factory()->create()->assignRole($role);
        $this->actingAs($u)->get('/')->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn ($p) => $p->where('auth.user', ['name' => $u->name])->where('marketplaceAccount.email', $u->email)->where('marketplaceAccount.author_url', $role === 'author' ? '/author' : null)->where('marketplaceAccount.admin_url', $role === 'admin' ? '/admin/dashboard' : null)->missing('marketplaceAccount.roles')->missing('marketplaceAccount.permissions'));
    }
});
