<?php

use App\Models\Cart;
use App\Models\CommissionPolicy;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Payouts\PayoutMinimums;
use App\Services\Refunds\RefundWindow;
use App\Services\Settings\MarketplaceSettings;
use App\Services\Settings\UpdateMarketplaceSettings;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures as EF;
use Tests\Support\PayoutFixtures as PF;
use Tests\Support\RefundFixtures as RF;

chdir(dirname(__DIR__, 2));
if (($argv[1] ?? null) === '--worker') {
    putenv('APP_CONFIG_CACHE='.$argv[2]);
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function guard18(string $s): void
{
    if (! preg_match('/^templaara_phase18_concurrency_[a-f0-9]{12}$/', $s) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $s || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $s) {
        throw new RuntimeException('Unsafe schema/search_path.');
    }
}
function check18(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
if (($argv[1] ?? null) === '--worker') {
    $s = config('database.connections.'.config('database.default').'.search_path');
    guard18($s);
    $job = json_decode(file_get_contents($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name = 'phase18_settings_worker'");
    file_put_contents($argv[3].'.ready', 'ready');
    try {
        app(UpdateMarketplaceSettings::class)->save(User::findOrFail($job['actor']), $job['group'], $job['input']);
        echo 'committed';
    } catch (ValidationException) {
        echo 'conflict';
    }exit;
}
$s = 'templaara_phase18_concurrency_'.bin2hex(random_bytes(6));
$root = sys_get_temp_dir().'/'.$s;
mkdir($root, 0700);
$primary = DB::connection('pgsql');
$primary->statement('CREATE SCHEMA '.$s);
try {
    $c = config('database.connections.pgsql');
    $c['url'] = null;
    $c['search_path'] = $s;
    config(['database.connections.settings_verify' => $c, 'database.default' => 'settings_verify', 'app.env' => 'testing', 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'commerce.gateway' => 'development', 'commerce.development_enabled' => true, 'refunds.window_days' => '7']);
    $app->instance('env', 'testing');
    foreach (['product_files.media_disk', 'product_files.artifact_disk'] as $key) {
        config(['filesystems.disks.'.config($key).'.root' => $root.'/'.config($key)]);
    }
    guard18($s);
    Artisan::call('migrate', ['--force' => true]);
    guard18($s);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    guard18($s);
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $admin = User::factory()->create()->assignRole('admin');
    $file = $root.'/config.php';
    file_put_contents($file, '<?php return '.var_export(config()->all(), true).';');
    chmod($file, 0600);
    $input = function (string $g, array $values): array {
        $v = app(MarketplaceSettings::class)->section($g);
        $versions = [];
        foreach ($values as $k => $_) {
            $versions[$k] = $v[$k]['version'];
        }

        return ['values' => $values, 'versions' => $versions];
    };
    $update = function (string $g, array $v) use ($input, $admin, $s): void {
        guard18($s);
        app(UpdateMarketplaceSettings::class)->save($admin, $g, $input($g, $v));
    };
    $start = function (string $g, array $v, ?array $payload = null) use ($input, $admin, $root, $file, $s): array {
        guard18($s);
        $job = $root.'/job-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($job, json_encode(['actor' => $admin->id, 'group' => $g, 'input' => $payload ?? $input($g, $v)]));
        chmod($job, 0600);
        $p = proc_open([PHP_BINARY, __FILE__, '--worker', $file, $job], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $until = microtime(true) + 8;
        while (! file_exists($job.'.ready') && microtime(true) < $until) {
            usleep(10000);
        }check18(file_exists($job.'.ready'), 'Worker failed to start.');
        $blocked = false;
        while (microtime(true) < $until) {
            if (DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name='phase18_settings_worker' AND wait_event_type='Lock'")->n > 0) {
                $blocked = true;
                break;
            }usleep(10000);
        }check18($blocked, 'Independent writer did not block on coherence lock.');

        return [$p, $pipes];
    };
    $finish = function (array $worker, string $expected): void {
        [$p,$pipes] = $worker;
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($p);
        check18($status === 0 && $out === $expected, 'Worker outcome differs: '.$out.' '.$err);
    };
    // A/B: real stale writers wait for the first transaction then fail atomically.
    foreach ([['maximum_cart_items' => 8], ['maximum_cart_items' => 9, 'enabled_currencies' => ['INR', 'USD']]] as $i => $values) {
        $stale = $input('commerce', $values);
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(753118)');
        $w = $start('commerce', $values, $stale);
        $update('commerce', $i === 0 ? ['maximum_cart_items' => 6] : ['maximum_cart_items' => 7, 'enabled_currencies' => ['INR', 'EUR']]);
        DB::commit();
        $finish($w, 'conflict');
        check18(app(MarketplaceSettings::class)->cartMaximum() === ($i === 0 ? 6 : 7), 'Lost update.');
        echo 'Scenario '.($i === 0 ? 'A' : 'B').": stale writer/atomic section passed\n";
    }
    $update('commerce', ['enabled_currencies' => ['INR', 'USD', 'EUR', 'GBP']]);
    $product = EF::product(null, '100.00', 'EUR');
    $buyer = User::factory()->create()->assignRole('customer');
    app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    $cart = Cart::where('user_id', $buyer->id)->first();
    DB::beginTransaction();
    app(MarketplaceSettings::class)->lock();
    $w = $start('commerce', ['enabled_currencies' => ['INR', 'USD']]);
    guard18($s);
    $order = app(Checkout::class)->place($buyer, $cart->checkout_key, CommerceFixtures::billing());
    DB::commit();
    $finish($w, 'committed');
    check18($order->currency->value === 'EUR', 'Checkout mixed configuration.');
    echo "Scenario C: checkout coherent across settings write passed\n";
    $p = EF::product();
    $buyer = User::factory()->create()->assignRole('customer');
    app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
    $cart = Cart::where('user_id', $buyer->id)->first();
    DB::beginTransaction();
    app(MarketplaceSettings::class)->lock();
    $w = $start('authors', ['commission_percentage' => '15.25', 'earning_hold_days' => 0]);
    guard18($s);
    $old = app(Checkout::class)->place($buyer, $cart->checkout_key, CommerceFixtures::billing());
    DB::commit();
    $finish($w, 'committed');
    usleep(1200000);
    $new = EF::order($p);
    check18($old->items->first()->getAttribute('commission_rate') === '20.00' && $new->items->first()->getAttribute('commission_rate') === '15.25', 'Policy snapshot mixed.');
    echo "Scenario D: commission purchase snapshot passed\n";
    $o = RF::order();
    DB::beginTransaction();
    app(MarketplaceSettings::class)->lock();
    $w = $start('refunds', ['refund_window_days' => 14]);
    guard18($s);
    $r = RF::request($o);
    DB::commit();
    $finish($w, 'committed');
    check18($r->getAttribute('window_days') === 7 && app(RefundWindow::class)->days() === 14, 'Refund rights changed.');
    echo "Scenario E: refund request retains purchased policy passed\n";
    $e = PF::available();
    $method = PF::method($e->author);
    DB::beginTransaction();
    app(MarketplaceSettings::class)->lock();
    $w = $start('payouts', ['minimum_INR' => '4500.00']);
    guard18($s);
    $payout = PF::request($e->author, '4000.00', $method);
    DB::commit();
    $finish($w, 'committed');
    check18($payout->amount === '4000.00' && app(PayoutMinimums::class)->forCurrency('INR') === '4500.00', 'Payout threshold mixed.');
    echo "Scenario F: payout threshold coherent passed\n";
    $settings = app(MarketplaceSettings::class);
    DB::beginTransaction();
    $settings->lock();
    $w = $start('support', ['attachments_max' => 1, 'attachment_max_kb' => 1]);
    $before = $settings->supportLimits();
    DB::commit();
    $finish($w, 'committed');
    $after = $settings->supportLimits();
    check18($before['count'] === 5 && $after['count'] === 1 && $after['size'] === 1, 'Stale cached read.');
    echo "Scenario G: uncached reader sees committed bounded section passed\n";
    echo "Seven independent-process concurrency scenarios passed.\n";
} finally {
    while (DB::transactionLevel()) {
        DB::rollBack();
    }$primary->statement('DROP SCHEMA '.$s.' CASCADE');
    (new Filesystem)->deleteDirectory($root);
}
