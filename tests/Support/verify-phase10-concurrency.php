<?php

use App\Enums\ProductWorkflowAction as Action;
use App\Models\Entitlement;
use App\Models\LicenseActivation;
use App\Models\Product;
use App\Models\ProductRelease;
use App\Models\ProductRevision;
use App\Models\User;
use App\Services\Entitlements\LicenseActivationService;
use App\Services\Products\ProductUpdates;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase10Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase10_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase10Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }phase10Schema($job['schema']);
    try {
        if ($job['kind'] === 'activation') {
            $result = app(LicenseActivationService::class)->execute('activate', $job['input']);
            echo json_encode(['ok' => $result['valid'], 'uuid' => $result['activation_id'] ?? null]);
        } elseif ($job['kind'] === 'create') {
            app(ProductUpdates::class)->create(User::findOrFail($job['actor']), Product::findOrFail($job['product']), '1.1.0');
            echo '{"ok":true}';
        } else {
            app(ProductUpdates::class)->transition(User::findOrFail($job['actor']), ProductRevision::findOrFail($job['revision']), Action::from($job['action']));
            echo '{"ok":true}';
        }
    } catch (ValidationException|AuthorizationException $e) {
        echo '{"ok":false}';
    }exit;
}
$schema = 'templaara_phase10_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify10(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race10(array $jobs): array
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
            verify10(is_array($result), 'Worker failed: '.file_get_contents($p['err']));
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
    config()->set('database.connections.phase10_race', $connection);
    config()->set('database.default', 'phase10_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase10Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase10Schema($schema);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    ProductFileFixtures::fakeStorage();
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        config()->set('filesystems.disks.'.$disk.'.root', Storage::disk($disk)->path(''));
    }
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);
    phase10Schema($schema);
    EntitlementFixtures::order();
    $e = Entitlement::firstOrFail();
    $input = ['license_key' => $e->license->license_secret, 'product' => $e->item->product->public_uuid, 'installation_id' => (string) Str::uuid()];
    $results = race10([['kind' => 'activation', 'input' => $input], ['kind' => 'activation', 'input' => [...$input, 'installation_id' => (string) Str::uuid()]]]);
    verify10(count(array_filter($results, fn ($x) => $x['ok'])) === 1, 'Parallel activation exceeded purchased limit.');
    verify10(LicenseActivation::where('status', 'active')->count() === 1, 'Active slot count differs.');
    phase10Schema($schema);
    app(LicenseActivationService::class)->execute('deactivate', [...$input, 'installation_id' => $results[0]['ok'] ? $input['installation_id'] : $input['installation_id']]);
    // Separate purchase for duplicate-installation scenario.
    EntitlementFixtures::order();
    $e = Entitlement::orderByDesc('id')->first();
    $input = ['license_key' => $e->license->license_secret, 'product' => $e->item->product->public_uuid, 'installation_id' => (string) Str::uuid()];
    $results = race10([['kind' => 'activation', 'input' => $input], ['kind' => 'activation', 'input' => $input]]);
    verify10($results[0]['ok'] && $results[1]['ok'] && $results[0]['uuid'] === $results[1]['uuid'], 'Duplicate installations were not idempotent.');
    phase10Schema($schema);
    $p = CommerceFixtures::product();
    $admin = User::factory()->create()->assignRole('admin');
    $job = ['kind' => 'create', 'actor' => $admin->id, 'product' => $p->id];
    $results = race10([$job, $job]);
    verify10(count(array_filter($results, fn ($x) => $x['ok'])) === 1, 'Duplicate open proposals created.');
    $r = ProductRevision::where('product_id', $p->id)->first();
    $updates = app(ProductUpdates::class);
    $updates->release($admin, $r, ['version' => '1.1.0', 'changelog' => 'Concurrent publication verification.']);
    $updates->upload($admin, $r, 'source_package', ProductFileFixtures::zip('New exact package'));
    foreach (['submit', 'start_review', 'publish'] as $action) {
        phase10Schema($schema);
        if ($action === 'publish') {
            $updates->transition($admin, $r, Action::Approve);
        }$job = ['kind' => 'transition', 'action' => $action, 'actor' => $admin->id, 'revision' => $r->id];
        $second = $job;
        if ($action === 'start_review') {
            $second['actor'] = User::factory()->create()->assignRole('reviewer')->id;
        }$results = race10([$job, $second]);
        verify10(count(array_filter($results, fn ($x) => $x['ok'])) === 1, 'Transition race did not resolve to one winner.');
        if ($action === 'start_review') {
            $admin = User::findOrFail($r->submissions()->first()->reviewed_by)->assignRole('admin');
        }
    }
    verify10($updates->current($p)->version === '1.1.0', 'Latest release incorrect.');
    verify10(ProductRelease::where('product_id', $p->id)->where('status', 'published')->count() === 2, 'Historical release was removed.');
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
