<?php

use App\Models\CommissionPolicy;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payouts\FinancialBalances;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Reviews\ProductRatingSummary;
use App\Services\Reviews\ReviewWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures;
use Tests\Support\ReviewFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase14Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase14_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase14Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }phase14Schema($job['schema']);
    try {
        $actor = User::findOrFail($job['actor']);
        if ($job['kind'] === 'create') {
            app(ReviewWorkflow::class)->create($actor, Product::findOrFail($job['product']), $job['input']);
        } elseif ($job['kind'] === 'refund') {
            app(RefundWorkflow::class)->transition($actor, Refund::findOrFail($job['refund']), 'complete', ['external_reference' => 'EXTERNAL-REFUND-RACE'], true);
        } else {
            app(ReviewWorkflow::class)->action($actor, ProductReview::findOrFail($job['review']), $job['kind'], $job['input'] ?? []);
        }
        echo '{"ok":true}';
    } catch (ValidationException|AuthorizationException $e) {
        echo '{"ok":false}';
    }exit;
}
$schema = 'templaara_phase14_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify14(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race14(array $jobs): array
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
            verify14(is_array($result), 'Worker failed: '.substr(file_get_contents($p['out']), 0, 2500).file_get_contents($p['err']));
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
    config()->set('database.connections.phase14_race', $connection);
    config()->set('database.default', 'phase14_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase14Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase14Schema($schema);
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
    phase14Schema($schema);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $admin = User::factory()->create()->assignRole('admin');
    $workflow = app(RefundWorkflow::class);
    $balances = app(FinancialBalances::class);
    foreach (['double-create', 'create-refund', 'hide-edit', 'double-response', 'alternate-refunds', 'withdraw-hide'] as $scenario) {
        phase14Schema($schema);
        $o = ReviewFixtures::purchase();
        $product = $o->items->first()->product;
        $buyer = User::findOrFail($o->user_id);
        $create = ['kind' => 'create', 'actor' => $buyer->id, 'product' => $product->id, 'input' => ReviewFixtures::content()];
        $view = app(ProductRatingSummary::class);
        if ($scenario === 'double-create') {
            $result = race14([$create, $create]);
            verify14(count(array_filter($result, fn ($r) => $r['ok'])) === 1, 'Only one canonical creator wins.');
            verify14(ProductReview::where('product_id', $product->id)->count() === 1, 'Duplicate canonical review.');

            continue;
        }
        if ($scenario === 'create-refund') {
            $refund = RefundFixtures::request($o);
            RefundFixtures::process($refund, $admin);
            $result = race14([$create, ['kind' => 'refund', 'actor' => $admin->id, 'refund' => $refund->id]]);
            verify14($result[1]['ok'], 'Refund completion failed.');
            verify14($view->forProducts([$product->id])[$product->id]['count'] === 0, 'Stale public review after refund.');

            continue;
        }
        $review = ReviewFixtures::review($o);
        $job = fn ($kind, $actor, $input = []) => ['kind' => $kind, 'actor' => $actor->id, 'review' => $review->id, 'input' => $input];
        if ($scenario === 'alternate-refunds') {
            $other = ReviewFixtures::purchase($product, $buyer);
            $first = RefundFixtures::request($o);
            $second = RefundFixtures::request($other);
            RefundFixtures::process($first, $admin);
            RefundFixtures::process($second, $admin);
            $result = race14([['kind' => 'refund', 'actor' => $admin->id, 'refund' => $first->id], ['kind' => 'refund', 'actor' => $admin->id, 'refund' => $second->id]]);
            verify14($result[0]['ok'] && $result[1]['ok'], 'Alternate refund completion failed.');
            verify14(! $review->fresh()->getAttribute('is_verified') && $view->forProducts([$product->id])[$product->id]['count'] === 0, 'Alternate rebind left invalid verification.');
        } elseif ($scenario === 'double-response') {
            $job = $job('respond', $product->owner, ['body' => 'Thanks for sharing your business experience.']);
            $result = race14([$job, $job]);
            verify14(count(array_filter($result, fn ($r) => $r['ok'])) === 1, 'Duplicate response race.');
            verify14($review->response()->count() === 1, 'Multiple seller responses.');
        } else {
            $result = race14([$job('hide', $admin, ['reason' => 'Moderation race reason']), $scenario === 'hide-edit' ? $job('edit', $buyer, ReviewFixtures::content(4)) : $job('withdraw', $buyer)]);
            verify14($result[0]['ok'] && $result[1]['ok'], 'Serialized moderation/customer action failed.');
            verify14($review->fresh()->status->value === 'hidden' && $view->forProducts([$product->id])[$product->id]['count'] === 0, 'Moderation escaped in race.');
            verify14($scenario === 'hide-edit' ? $review->fresh()->getAttribute('rating') === 4 : $review->fresh()->getAttribute('withdrawn_at') !== null, 'Customer race outcome inconsistent.');
        }
        verify14($review->events()->count() === $review->fresh()->getAttribute('event_sequence'), 'Audit sequence incomplete.');
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
