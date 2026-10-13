<?php

// Standalone PostgreSQL verification. Never refreshes or truncates the application schema.
use App\Events\Commerce\OrderPaid;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Commerce\DevelopmentGateway;
use App\Services\Commerce\PaymentLifecycle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
chdir(dirname(__DIR__, 2));
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? null) === '--worker') {
    Event::listen(OrderPaid::class, fn ($event) => DB::table('verification_events')->insert(['order_id' => $event->order->id]));
    $job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(10000);
    }
    try {
        $order = match ($job['action']) {
            'place' => app(Checkout::class)->place(User::findOrFail($job['user']), $job['key'], CommerceFixtures::billing()),
            'retry' => app(PaymentLifecycle::class)->retry(User::findOrFail($job['user']), Order::findOrFail($job['order'])),
            'paid' => app(PaymentLifecycle::class)->process(app(DevelopmentGateway::class)->simulate(Payment::findOrFail($job['payment']), 'paid')),
        };
        echo json_encode(['order' => $order->id, 'result' => 'ok']).PHP_EOL;
    } catch (ValidationException $exception) {
        echo json_encode(['result' => 'invalid', 'errors' => $exception->errors()]).PHP_EOL;
    }
    exit;
}

$schema = 'templaara_commerce_verify_'.bin2hex(random_bytes(6));
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

    $buyer = User::factory()->create()->assignRole('customer');
    $product = CommerceFixtures::product();
    $offer = $product->offers->first();
    app(CartService::class)->add($buyer, $product->slug, $offer->id);
    $key = Cart::where('user_id', $buyer->id)->first()->checkout_key;
    $job = ['action' => 'place', 'user' => $buyer->id, 'key' => $key];
    $results = workers([$job, $job]);
    verify($results[0]['order'] === $results[1]['order'] && Order::count() === 1 && Payment::count() === 1, 'Duplicate checkout created multiple orders or payments.');
    $order = Order::firstOrFail();
    $payment = $order->payments->first();
    $job = ['action' => 'paid', 'payment' => $payment->id];
    workers([$job, $job]);
    verify(PaymentEvent::count() === 1 && DB::table('verification_events')->count() === 1 && $order->fresh()->status->value === 'completed', 'Duplicate callback settled or emitted more than once.');

    app(CartService::class)->add($buyer, $product->slug, $offer->id);
    $failed = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->first()->checkout_key, CommerceFixtures::billing());
    $old = $failed->payments->first();
    app(PaymentLifecycle::class)->process(app(DevelopmentGateway::class)->simulate($old, 'failed'));
    $job = ['action' => 'retry', 'user' => $buyer->id, 'order' => $failed->id];
    workers([$job, $job]);
    verify($failed->payments()->count() === 2 && $failed->payments()->where('status', 'pending')->count() === 1, 'Concurrent retry created extra attempts.');
    $current = $failed->payments()->where('status', 'pending')->firstOrFail();
    workers([['action' => 'paid', 'payment' => $old->id], ['action' => 'paid', 'payment' => $current->id]]);
    verify($failed->payments()->where('status', 'paid')->count() === 1 && $old->fresh()->status->value === 'failed' && DB::table('verification_events')->count() === 2, 'Two attempts settled the same order.');

    foreach (['price', 'offer', 'product', 'category', 'release'] as $change) {
        $candidate = CommerceFixtures::product();
        $selected = $candidate->offers->first();
        app(CartService::class)->add($buyer, $candidate->slug, $selected->id);
        $key = Cart::where('user_id', $buyer->id)->first()->checkout_key;
        $before = Order::count();
        DB::beginTransaction();
        DB::table('products')->where('id', $candidate->id)->lockForUpdate()->first();
        $results = workers([['action' => 'place', 'user' => $buyer->id, 'key' => $key]], function () use ($change, $candidate, $selected) {
            // Child has begun checkout; its product lock must wait for this mutation.
            usleep(150000);
            match ($change) {
                'price' => DB::table('product_offers')->where('id', $selected->id)->update(['price' => '5000.00']),
                'offer' => DB::table('product_offers')->where('id', $selected->id)->update(['is_active' => false, 'is_default' => false]),
                'product' => DB::table('products')->where('id', $candidate->id)->update(['status' => 'approved']),
                'category' => DB::table('categories')->where('id', $candidate->category_id)->update(['is_active' => false]),
                'release' => DB::table('product_artifacts')->whereIn('product_release_id', $candidate->releases()->pluck('id'))->update(['is_current' => false]),
            };
            DB::commit();
        });
        verify($results[0]['result'] === 'invalid' && Order::count() === $before, 'Checkout did not revalidate concurrent '.$change.' change.');
        app(CartService::class)->remove($buyer, Cart::where('user_id', $buyer->id)->first()->items()->where('product_id', $candidate->id)->firstOrFail()->id);
    }
    echo json_encode(['result' => 'passed', 'concurrency_scenarios' => 9, 'assertions' => $assertions]).PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('commerce_verify');
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    (new Filesystem)->deleteDirectory($directory);
}
