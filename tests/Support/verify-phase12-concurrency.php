<?php

use App\Models\AuthorEarning;
use App\Models\CommissionPolicy;
use App\Models\PayoutAllocation;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Earnings\ReleaseEarnings;
use App\Services\Payouts\FinancialBalances;
use App\Services\Payouts\PayoutWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\EarningFixtures;
use Tests\Support\PayoutFixtures;
use Tests\Support\ProductFileFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase12Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase12_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase12Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }phase12Schema($job['schema']);
    try {
        $actor = User::findOrFail($job['actor']);
        if ($job['kind'] === 'request') {
            app(PayoutWorkflow::class)->request($actor, $job['input']);
            echo '{"ok":true}';
        } elseif ($job['kind'] === 'release') {
            $ok = app(ReleaseEarnings::class)->release(AuthorEarning::findOrFail($job['earning']));
            echo json_encode(['ok' => $ok]);
        } else {
            app(PayoutWorkflow::class)->transition($actor, PayoutRequest::findOrFail($job['payout']), $job['kind'], $job['input'] ?? [], asAdmin: $job['admin'] ?? true);
            echo '{"ok":true}';
        }
    } catch (ValidationException|AuthorizationException $e) {
        echo '{"ok":false}';
    }exit;
}
$schema = 'templaara_phase12_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify12(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race12(array $jobs): array
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
            verify12(is_array($result), 'Worker failed: '.file_get_contents($p['err']));
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
    config()->set('database.connections.phase12_race', $connection);
    config()->set('database.default', 'phase12_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase12Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase12Schema($schema);
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
    phase12Schema($schema);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $admin = User::factory()->create()->assignRole('admin');
    $workflow = app(PayoutWorkflow::class);
    $balances = app(FinancialBalances::class);
    foreach (['double-request', 'double-paid', 'fail-paid', 'cancel-approve', 'release-request', 'partial-allocators'] as $scenario) {
        phase12Schema($schema);
        $author = User::factory()->create()->assignRole('author');
        $e = PayoutFixtures::available($author);
        $method = PayoutFixtures::method($author);
        $request = fn (string $amount) => ['kind' => 'request', 'actor' => $author->id, 'input' => PayoutFixtures::input($method, $amount)];
        if ($scenario === 'double-request' || $scenario === 'partial-allocators') {
            $amount = $scenario === 'double-request' ? '4000.00' : '2500.00';
            $r = race12([$request($amount), $request($amount)]);
            verify12(count(array_filter($r, fn ($x) => $x['ok'])) === ($scenario === 'double-request' ? 1 : 2), 'Incorrect reservation winners.');
            verify12($balances->available($author->id, 'INR') === ($scenario === 'double-request' ? '1000.00' : '0.00'), 'Available balance mismatch.');
            verify12(PayoutAllocation::where('author_earning_id', $e->id)->sum('amount') == ($scenario === 'double-request' ? '4000.00' : '5000.00'), 'Allocation capacity mismatch.');
        } elseif ($scenario === 'release-request') {
            $p = EarningFixtures::product($author, '1250.00');
            $o = EarningFixtures::order($p);
            $pending = AuthorEarning::where('order_id', $o->id)->firstOrFail();
            $r = race12([['kind' => 'release', 'actor' => $author->id, 'earning' => $pending->id], $request('4000.00')]);
            verify12($r[0]['ok'] && $r[1]['ok'], 'Release/request serialization failed.');
            verify12($balances->available($author->id, 'INR') === '2000.00', 'Release/request balance mismatch.');
        } else {
            $p = PayoutFixtures::request($author, '4000.00', $method);
            $job = fn (string $kind, array $input = []) => ['kind' => $kind, 'actor' => $admin->id, 'payout' => $p->id, 'input' => $input];
            if ($scenario === 'cancel-approve') {
                $r = race12([['kind' => 'cancel', 'actor' => $author->id, 'payout' => $p->id, 'admin' => false], $job('approve')]);
                $status = $p->fresh()->status->value;
                verify12(in_array($status, ['approved', 'cancelled']), 'Cancellation/approval invalid state.');
                verify12(count(array_filter($r, fn ($x) => $x['ok'])) === 1, 'Cancellation/approval must have one winner.');
                verify12($balances->available($author->id, 'INR') === ($status === 'cancelled' ? '5000.00' : '1000.00'), 'Cancellation/approval mismatch.');
            } else {
                PayoutFixtures::process($p, $admin);
                $pay = $job('pay', ['external_reference' => 'TEST-EXTERNAL-REFERENCE']);
                $r = race12([$scenario === 'double-paid' ? $pay : $job('fail', ['reason' => 'External bank transfer failed.']), $pay]);
                verify12(count(array_filter($r, fn ($x) => $x['ok'])) === 1, 'Terminal transition must have one winner.');
                $status = $p->fresh()->status->value;
                verify12(in_array($status, $scenario === 'double-paid' ? ['paid'] : ['paid', 'failed']), 'Invalid terminal state.');
                verify12($balances->available($author->id, 'INR') === ($status === 'failed' ? '5000.00' : '1000.00'), 'Terminal balance mismatch.');
                verify12($e->ledger()->whereIn('type', ['payout_paid', 'payout_released'])->count() === 1, 'Duplicate terminal ledger.');
            }
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
    echo json_encode(['scenarios' => 6, 'assertions' => $assertions, 'result' => 'passed']).PHP_EOL;
} finally {
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        $root = config('filesystems.disks.'.$disk.'.root');
        if (is_string($root) && str_contains($root, 'framework/testing/disks/')) {
            (new Filesystem)->deleteDirectory($root);
        }
    } (new Filesystem)->deleteDirectory($directory);
}
