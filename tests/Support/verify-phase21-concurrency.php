<?php

use App\Events\Commerce\OrderPaid;
use App\Events\Refunds\RefundCompleted;
use App\Models\Cart;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Invoicing\CheckoutTax;
use App\Services\Invoicing\InvoiceNumber;
use App\Services\Invoicing\InvoiceRecovery;
use App\Services\Invoicing\IssueInvoice;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Settings\MarketplaceSettings;
use App\Services\Settings\UpdateMarketplaceSettings;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceFixtures as C;
use Tests\Support\EntitlementFixtures as E;
use Tests\Support\InvoiceFixtures as F;
use Tests\Support\RefundFixtures as R;

chdir(dirname(__DIR__, 2));
if (($argv[1] ?? '') === '--worker') {
    putenv('APP_CONFIG_CACHE='.$argv[2]);
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function guard21(string $s): void
{
    if (! preg_match('/^templaara_phase21_race_[a-f0-9]{12}$/D', $s) || DB::selectOne('SELECT current_schema() AS s')->s !== $s || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $s) {
        throw new RuntimeException('Unsafe verification scope.');
    }
}
function verify21(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
if (($argv[1] ?? '') === '--worker') {
    guard21(config('database.connections.'.config('database.default').'.search_path'));
    $job = json_decode($argv[3], true);
    touch($job['ready']);
    while (! file_exists($job['go'])) {
        usleep(10000);
    }
    try {
        $result = match ($job['action']) {
            'issue' => app(IssueInvoice::class)->issue(Order::findOrFail($job['id']))?->number,
            'recover' => app(InvoiceRecovery::class)->run(100),
            'pay' => E::pay(Order::findOrFail($job['id']))->id,
            'number' => DB::transaction(fn () => app(InvoiceNumber::class)->next('invoice', 'INV')),
            'credit' => app(IssueInvoice::class)->credit(Refund::findOrFail($job['id']))?->number,
            'complete' => app(RefundWorkflow::class)->transition(User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->firstOrFail(), Refund::findOrFail($job['id']), 'complete', ['external_reference' => 'RACE-REFUND'], true),
            'checkout' => app(Checkout::class)->place(User::findOrFail($job['user']), $job['key'], $job['billing'])->grand_total,
            'settings' => app(UpdateMarketplaceSettings::class)->save(User::findOrFail($job['actor']), 'invoicing', $job['input']),
        };
        echo json_encode(['result' => 'ok', 'value' => $result]);
    } catch (ValidationException) {
        echo json_encode(['result' => 'validation']);
    }exit;
}
$s = 'templaara_phase21_race_'.bin2hex(random_bytes(6));
$root = sys_get_temp_dir().'/'.$s;
mkdir($root, 0700);
$primary = DB::connection('pgsql');
$primary->statement('CREATE SCHEMA '.$s);
try {
    $c = config('database.connections.pgsql');
    $c['search_path'] = $s;
    $c['url'] = null;
    config(['database.connections.invoice_verify' => $c, 'database.default' => 'invoice_verify', 'app.env' => 'testing', 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
    $app->instance('env', 'testing');
    foreach (['product_files.media_disk', 'product_files.artifact_disk'] as $key) {
        config(['filesystems.disks.'.config($key).'.root' => $root.'/'.config($key)]);
    }
    guard21($s);
    Artisan::call('migrate', ['--force' => true]);
    guard21($s);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    guard21($s);
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    $file = $root.'/config.php';
    file_put_contents($file, '<?php return '.var_export(config()->all(), true).';');
    chmod($file, 0600);
    $race = function (array $jobs) use ($root, $file, $s) {
        guard21($s);
        $go = $root.'/go-'.bin2hex(random_bytes(3));
        $workers = [];
        $deadline = microtime(true) + 40;
        foreach ($jobs as $i => $job) {
            $ready = $go.'-'.$i;
            $out = $ready.'.out';
            $err = $ready.'.err';
            $p = proc_open([PHP_BINARY, __FILE__, '--worker', $file, json_encode([...$job, 'ready' => $ready, 'go' => $go])], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes);
            fclose($pipes[0]);
            $workers[] = compact('p', 'ready', 'out', 'err');
        }
        try {
            foreach ($workers as $w) {
                while (! file_exists($w['ready']) && microtime(true) < $deadline) {
                    usleep(10000);
                }verify21(file_exists($w['ready']), 'Worker startup timeout.');
            }touch($go);
            $results = [];
            foreach ($workers as $w) {
                while (proc_get_status($w['p'])['running'] && microtime(true) < $deadline) {
                    usleep(10000);
                }verify21(! proc_get_status($w['p'])['running'], 'Worker timeout.');
                $result = json_decode(file_get_contents($w['out']), true);
                verify21(is_array($result), 'Worker failed: '.file_get_contents($w['out']).file_get_contents($w['err']));
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach ($workers as $w) {
                if (proc_get_status($w['p'])['running']) {
                    proc_terminate($w['p']);
                }proc_close($w['p']);
            }
        }
    };
    // Parent suppresses only event delivery to create a legitimate recoverable paid purchase.
    Event::forget(OrderPaid::class);
    Event::forget(RefundCompleted::class);
    $o = F::paid(F::gst());
    $job = ['action' => 'issue', 'id' => $o->id];
    $race([$job, $job]);
    verify21(Invoice::where('order_id', $o->id)->count() === 1, 'Duplicate invoice.');
    echo "1 duplicate issuance PASS\n";
    $o = F::order(F::gst());
    $race([['action' => 'pay', 'id' => $o->id], ['action' => 'recover']]);
    app(InvoiceRecovery::class)->run();
    verify21(Invoice::where('order_id', $o->id)->count() === 1, 'Paid/recovery duplicate.');
    echo "2 OrderPaid/recovery PASS\n";
    $result = $race(array_fill(0, 8, ['action' => 'number']));
    verify21(count(array_unique(array_column($result, 'value'))) === 8, 'Duplicate sequence.');
    echo "3 independent sequence allocation PASS\n";
    app(EntitlementProvisioner::class)->provision($o->fresh());
    $r = R::request($o->fresh());
    R::complete($r);
    $job = ['action' => 'credit', 'id' => $r->id];
    $race([$job, $job]);
    verify21(Invoice::where('refund_id', $r->id)->count() === 1, 'Duplicate credit.');
    echo "4 duplicate credit PASS\n";
    $o = F::paid(F::gst());
    app(IssueInvoice::class)->issue($o);
    app(EntitlementProvisioner::class)->provision($o);
    $r = R::request($o);
    R::process($r);
    $race([['action' => 'complete', 'id' => $r->id], ['action' => 'recover']]);
    app(InvoiceRecovery::class)->run();
    verify21(Invoice::where('refund_id', $r->id)->count() === 1, 'Refund recovery duplicate.');
    echo "5 refund completion/recovery PASS\n";
    $admin = F::configure(F::gst());
    $buyer = User::factory()->create()->assignRole('customer');
    $p = C::product();
    app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
    $cart = Cart::where('user_id', $buyer->id)->first();
    $billing = [...C::billing(), 'country_code' => 'IN', 'gst_state_code' => '36'];
    $quote = app(CheckoutTax::class)->quote(['4999.00'], $billing);
    $billing['tax_quote_hash'] = app(CheckoutTax::class)->hash($quote, $billing, $cart->checkout_key);
    $section = app(MarketplaceSettings::class)->section('invoicing', true);
    $result = $race([['action' => 'checkout', 'user' => $buyer->id, 'key' => $cart->checkout_key, 'billing' => $billing], ['action' => 'settings', 'actor' => $admin->id, 'input' => ['values' => ['tax_rate' => '5.00'], 'versions' => ['tax_rate' => $section['tax_rate']['version']]]]]);
    verify21(in_array($result[0]['result'], ['ok', 'validation'], true), 'Invalid settings race.');
    if ($result[0]['result'] === 'ok') {
        verify21($result[0]['value'] === '5898.82', 'Mixed tax policy.');
    }echo "6 tax-policy/checkout PASS\n";
    $o = F::order(F::gst());
    $race([['action' => 'pay', 'id' => $o->id], ['action' => 'issue', 'id' => $o->id]]);
    app(IssueInvoice::class)->issue($o->fresh());
    verify21(Invoice::where('order_id', $o->id)->count() === 1, 'Payment/issuance duplicate.');
    echo "7 settlement/issuance PASS\n";
    echo "Seven independent-process PostgreSQL races PASS.\n";
} finally {
    while (DB::transactionLevel()) {
        DB::rollBack();
    }$primary->statement('DROP SCHEMA '.$s.' CASCADE');
    (new Filesystem)->deleteDirectory($root);
}
