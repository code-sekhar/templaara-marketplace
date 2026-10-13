<?php

use App\Events\Commerce\OrderPaid;
use App\Models\AuthorEarning;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\Order;
use App\Models\PaymentCheckout;
use App\Models\User;
use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\Gateways\RazorpayGateway;
use App\Services\Commerce\PaymentGatewayManager;
use App\Services\Commerce\PaymentLifecycle;
use App\Services\Earnings\EarningProvisioner;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Settings\UpdateMarketplaceSettings;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExternalPaymentFixtures as F;

chdir(dirname(__DIR__, 2));
if (($argv[1] ?? '') === '--worker') {
    putenv('APP_CONFIG_CACHE='.$argv[2]);
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function guard19(string $s): void
{
    if (! preg_match('/^templaara_phase19_race_[a-f0-9]{12}$/D', $s) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $s || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $s) {
        throw new RuntimeException('Unsafe payment verification schema.');
    }
}
function check19(bool $valid, string $message): void
{
    if (! $valid) {
        throw new RuntimeException($message);
    }
}
function mock19(): void
{
    F::fake(function ($r) {
        foreach (PaymentCheckout::with('payment')->get() as $c) {
            $p = $c->payment;
            if ($r->method() === 'GET' && str_contains($r->url(), '/checkout/sessions/'.$c->provider_order_id)) {
                return Http::response(F::stripe($p));
            }
            if ($r->method() === 'GET' && str_contains($r->url(), '/orders/'.$c->provider_order_id.'/payments')) {
                return Http::response(['items' => [F::razor($p)]]);
            }
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/orders/'.$c->provider_order_id)) {
                return Http::response(F::razorOrder($p));
            }
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/payments/'.F::razor($p)['id'])) {
                return Http::response(F::razor($p));
            }
        }

        return Http::response([], 404);
    });
}
if (($argv[1] ?? '') === '--worker') {
    $s = config('database.connections.'.config('database.default').'.search_path');
    guard19($s);
    mock19();
    Event::listen(OrderPaid::class, function ($e) {
        DB::table('verification_paid_events')->insert(['order_id' => $e->order->id]);
    });
    $job = json_decode(file_get_contents($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($argv[3].'.ready', '1');
    $until = microtime(true) + 15;
    while (! file_exists($argv[3].'.go')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Worker barrier timeout');
        }usleep(20000);
    }
    guard19($s);
    $o = Order::whereKey($job['order'])->firstOrFail();
    $p = $o->payments()->firstOrFail();
    if ($job['action'] === 'webhook') {
        [$body,$h] = F::event($p);
        $e = app(PaymentGatewayManager::class)->external($p->provider)->webhook($body, $h[$p->provider === 'stripe' ? 'Stripe-Signature' : 'X-Razorpay-Signature'], $h['X-Razorpay-Event-Id'] ?? '');
        app(ExternalPayments::class)->settle($e);
    } elseif ($job['action'] === 'reconcile') {
        $e = app(PaymentGatewayManager::class)->external($p->provider)->fetch($p->checkout);
        if ($e) {
            app(ExternalPayments::class)->settle($e);
        }
    } elseif ($job['action'] === 'callback') {
        $d = F::razor($p);
        $e = app(RazorpayGateway::class)->callback($p->checkout, $d['id'], $d['order_id'], hash_hmac('sha256', $d['order_id'].'|'.$d['id'], 'fixture_key_secret'));
        app(ExternalPayments::class)->settle($e);
    } elseif ($job['action'] === 'prepare') {
        F::creation(function ($r) use ($p) {
            DB::table('verification_create_requests')->insert(['payment_id' => $p->id]);
        });
        app(ExternalPayments::class)->prepare($p->checkout);
    } elseif ($job['action'] === 'retry') {
        try {
            app(PaymentLifecycle::class)->retry($o->user, $o);
        } catch (ValidationException) {
        } // Paid result makes retry deliberately invalid.
    } elseif ($job['action'] === 'settings') {
        app(UpdateMarketplaceSettings::class)->save(User::whereKey($job['admin'])->firstOrFail(), 'commerce', ['values' => ['enabled_currencies' => ['INR']], 'versions' => ['enabled_currencies' => 0]]);
    } elseif ($job['action'] === 'provision') {
        app(EntitlementProvisioner::class)->provision($o);
        app(EarningProvisioner::class)->provision($o);
    }
    echo 'committed';
    exit;
}
$s = 'templaara_phase19_race_'.bin2hex(random_bytes(6));
$root = sys_get_temp_dir().'/'.$s;
mkdir($root, 0700);
$primary = DB::connection('pgsql');
$primary->statement('CREATE SCHEMA '.$s);
$children = [];
try {
    $c = config('database.connections.pgsql');
    $c['url'] = null;
    $c['search_path'] = $s;
    config(['database.connections.payment_verify' => $c, 'database.default' => 'payment_verify', 'app.env' => 'testing', 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array']);
    $app->instance('env', 'testing');
    foreach (['product_files.media_disk', 'product_files.artifact_disk'] as $key) {
        config(['filesystems.disks.'.config($key).'.root' => $root.'/'.config($key)]);
    }
    guard19($s);
    Artisan::call('migrate', ['--force' => true]);
    guard19($s);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    guard19($s);
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    F::configure();
    DB::statement('CREATE TABLE verification_paid_events (order_id bigint NOT NULL)');
    DB::statement('CREATE TABLE verification_create_requests (payment_id bigint NOT NULL)');
    config(['database.connections.reservation_visibility' => $c]);
    $probe = function ($r) use ($s): void {
        guard19($s);
        check19(DB::transactionLevel() === 0, 'Provider IO inside commercial transaction');
        $key = $r['metadata']['attempt_key'] ?? $r['receipt'];
        $outside = DB::connection('reservation_visibility');
        check19($outside->selectOne('SELECT current_schema() AS schema')->schema === $s && trim($outside->selectOne('SHOW search_path')->search_path, '"') === $s, 'Unsafe visibility connection');
        $outside->beginTransaction();
        try {
            $p = $outside->table('payments')->where('idempotency_key', $key)->first();
            check19($p !== null, 'Provider called before payment commit');
            check19($outside->table('payment_checkouts')->where('payment_id', $p->id)->value('state') === 'creating', 'Lease not committed before provider call');
            $outside->select('SELECT id FROM payments WHERE id=? FOR UPDATE NOWAIT', [$p->id]);
            $outside->select('SELECT id FROM orders WHERE id=? FOR UPDATE NOWAIT', [$p->order_id]);
            $outside->select('SELECT u.id FROM users u JOIN orders o ON o.user_id=u.id WHERE o.id=? FOR UPDATE OF u NOWAIT', [$p->order_id]);
            $outside->select('SELECT c.id FROM carts c JOIN orders o ON o.user_id=c.user_id WHERE o.id=? FOR UPDATE OF c NOWAIT', [$p->order_id]);
        } finally {
            $outside->rollBack();
        }
    };
    $admin = User::factory()->create()->assignRole('admin');
    $file = $root.'/config.php';
    file_put_contents($file, '<?php return '.var_export(config()->all(), true).';');
    chmod($file, 0600);
    $scenarios = [['stripe', 'webhook', 'webhook'], ['stripe', 'webhook', 'reconcile'], ['razorpay', 'webhook', 'webhook'], ['razorpay', 'callback', 'webhook'], ['stripe', 'webhook', 'retry'], ['stripe', 'webhook', 'settings'], ['razorpay', 'provision', 'provision'], ['razorpay', 'prepare', 'prepare']];
    foreach ($scenarios as $i => [$provider,$left,$right]) {
        guard19($s);
        F::creation($probe);
        $o = F::order($provider, $left !== 'prepare');
        if ($left === 'provision') {
            [$body,$h] = F::event($o->payments->first());
            $e = app(PaymentGatewayManager::class)->external($provider)->webhook($body, $h[$provider === 'stripe' ? 'Stripe-Signature' : 'X-Razorpay-Signature'], $h['X-Razorpay-Event-Id'] ?? '');
            app(ExternalPayments::class)->settle($e);
        }
        $workers = [];
        foreach ([$left, $right] as $n => $action) {
            $job = $root.'/job-'.$i.'-'.$n.'.json';
            file_put_contents($job, json_encode(['order' => $o->id, 'action' => $action, 'admin' => $admin->id]));
            chmod($job, 0600);
            $p = proc_open([PHP_BINARY, __FILE__, '--worker', $file, $job], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $children[] = $p;
            $workers[] = [$p, $pipes, $job];
        }
        $until = microtime(true) + 15;
        foreach ($workers as [$p,$pipes,$job]) {
            while (! file_exists($job.'.ready')) {
                check19(microtime(true) < $until, 'Worker not ready');
                usleep(20000);
            }
        }
        foreach ($workers as [$p,$pipes,$job]) {
            file_put_contents($job.'.go', '1');
        }
        foreach ($workers as [$p,$pipes,$job]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }$code = proc_close($p);
            check19($code === 0 && $out === 'committed', 'Worker failed: '.$err);
        }
        $children = [];
        guard19($s);
        $o->refresh();
        if ($left === 'prepare') {
            check19(F::checkout($o)->state === 'ready' && DB::table('verification_create_requests')->where('payment_id', $o->payments->first()->id)->count() === 1, 'Creation lease duplicated provider request');
            echo 'Race H: creation lease vs creation lease passed'.PHP_EOL;

            continue;
        }
        check19($o->payment_status->value === 'paid', 'Order not settled');
        check19($o->payments()->count() === 1 && $o->payments()->where('status', 'paid')->count() === 1, 'Duplicate attempts/settlements');
        check19(Entitlement::where('order_id', $o->id)->count() === 1, 'Duplicate/missing entitlement');
        check19(AuthorEarning::where('order_id', $o->id)->count() === 1, 'Duplicate/missing earning');
        check19(License::whereHas('entitlement', fn ($q) => $q->where('order_id', $o->id))->count() === 1, 'Duplicate/missing license');
        if ($left !== 'provision') {
            check19(DB::table('verification_paid_events')->where('order_id', $o->id)->count() === 1, 'Duplicate paid event');
        }
        echo 'Race '.chr(65 + $i).': '.$left.' vs '.$right.' passed'.PHP_EOL;
    }
    echo json_encode(['result' => 'passed', 'scenarios' => 8, 'committed_reservation_and_unlocked_financial_rows' => true]).PHP_EOL;
} finally {
    foreach ($children as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
        }
    }
    $primary->statement('DROP SCHEMA '.$s.' CASCADE');
    (new Filesystem)->deleteDirectory($root);
}
