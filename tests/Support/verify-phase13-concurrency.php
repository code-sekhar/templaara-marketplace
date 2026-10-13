<?php

use App\Models\AuthorEarning;
use App\Models\CommissionPolicy;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\Refund;
use App\Models\RefundCompensation;
use App\Models\User;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Payouts\FinancialBalances;
use App\Services\Payouts\PayoutWorkflow;
use App\Services\Refunds\RefundPresentation;
use App\Services\Refunds\RefundWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\PayoutFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase13Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase13_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase13Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }phase13Schema($job['schema']);
    try {
        $actor = User::findOrFail($job['actor']);
        if ($job['kind'] === 'request') {
            app(RefundWorkflow::class)->request($actor, Order::findOrFail($job['order']), $job['input']);
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'payout-request') {
            app(PayoutWorkflow::class)->request($actor, $job['input']);
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'payout-paid') {
            app(PayoutWorkflow::class)->transition($actor, PayoutRequest::findOrFail($job['payout']), 'pay', ['external_reference' => 'BANK-RACE'], true);
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'release') {
            echo json_encode(['ok' => app(ReleaseEarnings::class)->release(AuthorEarning::findOrFail($job['earning']))]);
        } else {
            app(RefundWorkflow::class)->transition($actor, Refund::findOrFail($job['refund']), $job['kind'], $job['input'] ?? [], true);
            echo '{"ok":true}';
        }
    } catch (ValidationException|AuthorizationException $e) {
        echo '{"ok":false}';
    }exit;
}
$schema = 'templaara_phase13_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify13(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race13(array $jobs): array
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
            verify13(is_array($result), 'Worker failed: '.file_get_contents($p['err']));
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
    config()->set('database.connections.phase13_race', $connection);
    config()->set('database.default', 'phase13_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase13Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase13Schema($schema);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    ProductFileFixtures::fakeStorage();
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        mkdir($directory.'/'.$disk, 0700);
        config()->set('filesystems.disks.'.$disk.'.root', $directory.'/'.$disk);
        Storage::forgetDisk($disk);
    }
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);
    phase13Schema($schema);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $admin = User::factory()->create()->assignRole('admin');
    $workflow = app(RefundWorkflow::class);
    $balances = app(FinancialBalances::class);
    foreach (['double-request', 'double-complete', 'reserve-refund', 'paid-refund', 'release-refund', 'two-items', 'fail-complete'] as $scenario) {
        phase13Schema($schema);
        $o = $scenario === 'two-items' ? RefundFixtures::mixed() : RefundFixtures::order(in_array($scenario, ['reserve-refund', 'paid-refund']));
        $buyer = User::findOrFail($o->user_id);
        $e = $o->items()->where('seller_type', 'author')->first()->earning;
        $job = fn ($r, $kind) => ['kind' => $kind, 'actor' => $admin->id, 'refund' => $r->id, 'input' => ['external_reference' => 'EXTERNAL-REFUND-RACE', 'reason' => 'External refund failed.']];
        if ($scenario === 'double-request') {
            $req = fn () => ['kind' => 'request', 'actor' => $buyer->id, 'order' => $o->id, 'input' => RefundFixtures::input($o)];
            $results = race13([$req(), $req()]);
            verify13(count(array_filter($results, fn ($r) => $r['ok'])) === 1, 'Only one refund claim may win.');
            verify13(Refund::where('order_id', $o->id)->count() === 1, 'Duplicate refund request.');

            continue;
        }
        if ($scenario === 'two-items') {
            $ids = $o->items()->orderBy('id')->pluck('id');
            $r = $workflow->request($buyer, $o, RefundFixtures::input($o, [$ids[0]]));
            $other = $workflow->request($buyer, $o, RefundFixtures::input($o, [$ids[1], $ids[2]]));
            RefundFixtures::process($r, $admin);
            RefundFixtures::process($other, $admin);
            $results = race13([$job($r, 'complete'), $job($other, 'complete')]);
            verify13($results[0]['ok'] && $results[1]['ok'], 'Separate item completions failed.');
            verify13(app(RefundPresentation::class)->orderStatus($o) === 'full', 'Concurrent order state incorrect.');

            continue;
        }
        $r = RefundFixtures::request($o);
        RefundFixtures::process($r, $admin);
        $complete = $job($r, 'complete');
        if ($scenario === 'reserve-refund') {
            $m = PayoutFixtures::method($e->author);
            $results = race13([$complete, ['kind' => 'payout-request', 'actor' => $e->author_user_id, 'input' => PayoutFixtures::input($m, '2000.00')]]);
            verify13(count(array_filter($results, fn ($r) => $r['ok'])) === 1, 'Reserve/refund must have one safe winner.');
            verify13(in_array($balances->available($e->author_user_id, 'INR'), ['0.00', '2000.00']), 'Reserve/refund balance invalid.');
        } elseif ($scenario === 'paid-refund') {
            $p = PayoutFixtures::request($e->author, '4000.00');
            PayoutFixtures::process($p, $admin);
            $results = race13([$complete, ['kind' => 'payout-paid', 'actor' => $admin->id, 'payout' => $p->id]]);
            verify13($results[1]['ok'], 'Payout settlement failed.');
            $b = $balances->all($e->author_user_id)[0];
            verify13($b['paid'] === '4000.00' && $b['available'] === '0.00', 'Paid/refund lost accounting.');
            verify13($b['outstanding_adjustment'] === ($results[0]['ok'] ? '4000.00' : '0.00'), 'Paid/refund debt mismatch.');
        } elseif ($scenario === 'release-refund') {
            $results = race13([$complete, ['kind' => 'release', 'actor' => $e->author_user_id, 'earning' => $e->id]]);
            verify13($results[0]['ok'], 'Refund completion failed.');
            $b = $balances->all($e->author_user_id)[0];
            verify13($b['pending'] === '0.00' && $b['available'] === '0.00', 'Release/refund balance mismatch.');
        } else {
            $results = race13([$complete, $scenario === 'double-complete' ? $complete : $job($r, 'fail')]);
            verify13($scenario === 'double-complete' ? ($results[0]['ok'] && $results[1]['ok']) : count(array_filter($results, fn ($r) => $r['ok'])) === 1, 'Terminal refund race incorrect.');
            $completed = $r->fresh()->status->value === 'completed';
            verify13(RefundCompensation::whereIn('order_item_id', $o->items()->select('id'))->count() === ($completed ? 1 : 0), 'Duplicate/wrong compensation.');
            verify13($o->items->first()->entitlement->fresh()->status->value === ($completed ? 'revoked' : 'active'), 'Refund/access state mismatch.');
        }
    }
    echo json_encode(['scenarios' => 7, 'assertions' => $assertions, 'result' => 'passed']).PHP_EOL;
} finally {
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        $root = config('filesystems.disks.'.$disk.'.root');
        if (is_string($root) && str_contains($root, 'framework/testing/disks/')) {
            (new Filesystem)->deleteDirectory($root);
        }
    } (new Filesystem)->deleteDirectory($directory);
}
