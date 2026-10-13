<?php

// Standalone PostgreSQL verification. Never refreshes or truncates the application schema.
use App\Events\Commerce\OrderPaid;
use App\Listeners\ProvisionPaidOrder;
use App\Models\Cart;
use App\Models\DownloadAccess;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use App\Services\Entitlements\EntitlementDownload;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Entitlements\ReconcileEntitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\EntitlementFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
chdir(dirname(__DIR__, 2));
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? null) === '--worker') {
    $job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        } usleep(10000);
    }
    match ($job['action']) {
        'provision' => app(EntitlementProvisioner::class)->provision(Order::findOrFail($job['order'])),
        'listener' => app(ProvisionPaidOrder::class)->handle(new OrderPaid(Order::findOrFail($job['order']))),
        'reconcile' => app(ReconcileEntitlements::class)->run(),
        'download' => app(EntitlementDownload::class)->deliver(User::findOrFail($job['user']), Entitlement::findOrFail($job['entitlement'])),
    };
    echo json_encode(['result' => 'ok']).PHP_EOL;
    exit;
}

$schema = 'templaara_entitlement_verify_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}
/** Run independent processes against the same persisted cart/order with a barrier. */
function workers(array $jobs, ?Closure $beforeRelease = null): array
{
    global $directory;
    $go = $directory.'/go-'.bin2hex(random_bytes(4));
    $processes = [];
    foreach ($jobs as $index => $job) {
        $ready = $go.'-'.$index;
        $output = $ready.'.out';
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', json_encode([...$job, 'go' => $go, 'ready' => $ready])], [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $ready.'.err', 'w']], $pipes, getcwd(), ['APP_CONFIG_CACHE' => $directory.'/config.php', 'PATH' => getenv('PATH')]);
        fclose($pipes[0]);
        $processes[] = compact('process', 'ready', 'output');
    }
    $deadline = microtime(true) + 45;
    try {
        do {
            $waiting = array_filter($processes, fn ($entry) => ! file_exists($entry['ready']));
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker startup timed out.');
            }
            usleep(10000);
        } while ($waiting);
        touch($go);
        $beforeRelease?->__invoke();
        $results = [];
        foreach ($processes as $entry) {
            while (proc_get_status($entry['process'])['running']) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrent operation timed out.');
                }
                usleep(10000);
            }
            $result = json_decode(file_get_contents($entry['output']), true);
            verify(is_array($result), 'Worker failed: '.file_get_contents($entry['ready'].'.err'));
            $results[] = $result;
        }

        return $results;
    } finally {
        foreach ($processes as $entry) {
            if (proc_get_status($entry['process'])['running']) {
                proc_terminate($entry['process']);
            }
            proc_close($entry['process']);
        }
    }
}

try {
    config()->set('database.connections.commerce_verify', $connection);
    config()->set('database.default', 'commerce_verify');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    config()->set('filesystems.disks.public.root', $directory.'/media');
    config()->set('filesystems.disks.product_private.root', $directory.'/private');
    Artisan::call('migrate', ['--database' => 'commerce_verify', '--force' => true]);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    DB::statement('CREATE TABLE verification_events (order_id bigint PRIMARY KEY)');
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);

    Event::fake([OrderPaid::class]);
    $order = EntitlementFixtures::order();
    workers([['action' => 'listener', 'order' => $order->id], ['action' => 'listener', 'order' => $order->id]]);
    verify(Entitlement::count() === 1 && License::count() === 1, 'Duplicate OrderPaid handling duplicated access.');
    $key = License::first()->getRawOriginal('license_secret');
    workers([['action' => 'provision', 'order' => $order->id], ['action' => 'reconcile'], ['action' => 'listener', 'order' => $order->id]]);
    verify(License::first()->getRawOriginal('license_secret') === $key, 'Duplicate provisioning rotated license.');
    $second = EntitlementFixtures::order();
    workers([['action' => 'reconcile'], ['action' => 'listener', 'order' => $second->id]]);
    verify(Entitlement::count() === 2 && License::count() === 2, 'Listener/reconciliation race duplicated access.');
    $third = EntitlementFixtures::order();
    workers([['action' => 'reconcile'], ['action' => 'reconcile']]);
    verify(Entitlement::count() === 3 && License::count() === 3, 'Two reconciliation processes duplicated access.');
    $e = Entitlement::where('order_id', $order->id)->firstOrFail();
    $job = ['action' => 'download', 'user' => $e->user_id, 'entitlement' => $e->id];
    workers([$job, $job]);
    verify(DownloadAccess::where('entitlement_id', $e->id)->count() === 2, 'Concurrent downloads were not independently authorized and audited.');
    verify(License::first()->getRawOriginal('license_secret') === $key, 'Download mutated license.');
    echo json_encode(['result' => 'passed', 'concurrency_scenarios' => 5, 'assertions' => $assertions]).PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('commerce_verify');
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    (new Filesystem)->deleteDirectory($directory);
}
