<?php

use App\Events\Commerce\OrderPaid;
use App\Listeners\ProvisionAuthorEarnings;
use App\Models\AuthorEarning;
use App\Models\Cart;
use App\Models\CommissionPolicy;
use App\Models\Order;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Earnings\CommissionPolicies;
use App\Services\Earnings\EarningProvisioner;
use App\Services\Earnings\ReconcileEarnings;
use App\Services\Earnings\ReleaseEarnings;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase11Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase11_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase11Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }phase11Schema($job['schema']);
    try {
        $order = Order::find($job['order'] ?? 0);
        if ($job['kind'] === 'provision') {
            $count = app(EarningProvisioner::class)->provision($order);
            echo json_encode(['ok' => true, 'created' => $count]);
        } elseif ($job['kind'] === 'listener') {
            app(ProvisionAuthorEarnings::class)->handle(new OrderPaid($order));
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'reconcile') {
            $counts = app(ReconcileEarnings::class)->run($order->order_number);
            echo json_encode(['ok' => $counts['errors'] === 0]);
        } elseif ($job['kind'] === 'pay') {
            EntitlementFixtures::pay($order);
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'release') {
            $ok = app(ReleaseEarnings::class)->release(AuthorEarning::findOrFail($job['earning']));
            echo json_encode(['ok' => $ok]);
        } elseif ($job['kind'] === 'checkout') {
            $u = User::findOrFail($job['buyer']);
            $cart = Cart::where('user_id', $u->id)->first();
            app(Checkout::class)->place($u, $cart->checkout_key, CommerceFixtures::billing());
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'policy') {
            app(CommissionPolicies::class)->create(User::findOrFail($job['actor']), ['name' => 'Changed rate', 'percentage' => '15.00', 'hold_days' => 2, 'effective_from' => now()->addSecond()->toIso8601String()]);
            echo '{"ok":true}';
        }
    } catch (ValidationException|AuthorizationException $e) {
        echo '{"ok":false}';
    }exit;
}
$schema = 'templaara_phase11_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify11(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race11(array $jobs): array
{
    global $directory,$schema;
    $go = $directory.'/go-'.bin2hex(random_bytes(3));
    $processes = [];
    $deadline = microtime(true) + 45;
    foreach ($jobs as $i => $job) {
        $ready = $go.'-'.$i;
        $file = $ready.'.json';
        file_put_contents($file, json_encode([...$job, 'schema' => $schema, 'go' => $go, 'ready' => $ready]));
        chmod($file, 0600);
        $out = $ready.'.out';
        $err = $ready.'.err';
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, getcwd(), ['APP_CONFIG_CACHE' => $directory.'/config.php', 'PATH' => getenv('PATH')]);
        fclose($pipes[0]);
        $processes[] = compact('process', 'ready', 'out', 'err');
    }
    try {
        while (array_filter($processes, fn ($p) => ! file_exists($p['ready']))) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers not ready.');
            }usleep(10000);
        }touch($go);
        $results = [];
        foreach ($processes as $p) {
            while (proc_get_status($p['process'])['running']) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Workers timed out.');
                }usleep(10000);
            }$result = json_decode(file_get_contents($p['out']), true);
            verify11(is_array($result), 'Worker failed: '.file_get_contents($p['err']));
            $results[] = $result;
        }

        return $results;
    } finally {
        foreach ($processes as $p) {
            if (proc_get_status($p['process'])['running']) {
                proc_terminate($p['process']);
            }proc_close($p['process']);
        }
    }
}
try {
    config()->set('database.connections.phase11_race', $connection);
    config()->set('database.default', 'phase11_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase11Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase11Schema($schema);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    ProductFileFixtures::fakeStorage();
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        config()->set('filesystems.disks.'.$disk.'.root', Storage::disk($disk)->path(''));
    }
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);
    phase11Schema($schema);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $p = EarningFixtures::product();
    $orders = [];
    foreach (['callback', 'recovery', 'listener'] as $scenario) {
        phase11Schema($schema);
        Event::fake([OrderPaid::class]);
        $o = EarningFixtures::order($p, $scenario !== 'callback');
        $orders[] = $o;
        $jobs = match ($scenario) {
            'callback' => [['kind' => 'pay', 'order' => $o->id], ['kind' => 'pay', 'order' => $o->id]],'recovery' => [['kind' => 'reconcile', 'order' => $o->id], ['kind' => 'reconcile', 'order' => $o->id]],'listener' => [['kind' => 'listener', 'order' => $o->id], ['kind' => 'reconcile', 'order' => $o->id]]
        };
        $results = race11($jobs);
        verify11($results[0]['ok'] && $results[1]['ok'], 'Provision race worker failed.');
        verify11(AuthorEarning::where('order_id', $o->id)->count() === 1, 'Duplicate/missing earning.');
        $e = AuthorEarning::where('order_id', $o->id)->first();
        verify11($e->ledger()->count() === 1, 'Duplicate/missing pending entry.');
    }
    phase11Schema($schema);
    $e = AuthorEarning::first();
    $job = ['kind' => 'release', 'earning' => $e->id];
    $r = race11([$job, $job]);
    verify11(count(array_filter($r, fn ($x) => $x['ok'])) === 1, 'Availability should have one winner.');
    verify11($e->ledger()->count() === 2, 'Availability duplicated.');
    phase11Schema($schema);
    $buyer = User::factory()->create()->assignRole('customer');
    app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
    $admin = User::factory()->create()->assignRole('admin');
    $r = race11([['kind' => 'checkout', 'buyer' => $buyer->id], ['kind' => 'policy', 'actor' => $admin->id]]);
    verify11($r[0]['ok'] && $r[1]['ok'], 'Policy switch checkout failed.');
    $o = Order::where('user_id', $buyer->id)->first();
    $item = $o->items->first();
    $policy = CommissionPolicy::find($item->getAttribute('commission_policy_id'));
    verify11($item->getAttribute('commission_rate') === $policy->getAttribute('percentage'), 'Policy/rate snapshot mismatch.');
    verify11($policy->getAttribute('effective_from')->lessThanOrEqualTo($o->getAttribute('placed_at')) && (! $policy->getAttribute('effective_until') || $policy->getAttribute('effective_until')->greaterThan($o->getAttribute('placed_at'))), 'Policy selection time inconsistent.');
    echo json_encode(['scenarios' => 5, 'assertions' => $assertions, 'result' => 'passed']).PHP_EOL;
} finally {
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        $root = config('filesystems.disks.'.$disk.'.root');
        if (is_string($root) && str_contains($root, 'framework/testing/disks/')) {
            (new Filesystem)->deleteDirectory($root);
        }
    } (new Filesystem)->deleteDirectory($directory);
}
