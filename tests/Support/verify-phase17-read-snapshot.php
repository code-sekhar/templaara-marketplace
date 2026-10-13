<?php

use App\Models\CommissionPolicy;
use App\Models\Refund;
use App\Services\Analytics\AnalyticsReports;
use App\Services\Analytics\FinancialFacts;
use App\Services\Analytics\ReportCsv;
use App\Services\Analytics\ReportFilters;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EarningFixtures;
use Tests\Support\RefundFixtures;

chdir(dirname(__DIR__, 2));
if (isset($argv[1]) && $argv[1] === '--worker') {
    putenv('APP_CONFIG_CACHE='.$argv[2]);
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function assertAnalyticsSchema17(string $schema): void
{
    if (! preg_match('/^templaara_phase17_snapshot_[a-f0-9]{12}$/', $schema)
        || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema
        || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing verification outside exact isolated schema/search_path.');
    }
}
if (isset($argv[1]) && $argv[1] === '--worker') {
    $schema = config('database.connections.'.config('database.default').'.search_path');
    assertAnalyticsSchema17($schema);
    RefundFixtures::complete(Refund::findOrFail((int) $argv[3]));
    echo 'Concurrent completion committed.'.PHP_EOL;
    exit;
}
$schema = 'templaara_phase17_snapshot_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$primary = DB::connection('pgsql');
$primary->statement('CREATE SCHEMA '.$schema);
try {
    $connection = config('database.connections.pgsql');
    $connection['search_path'] = $schema;
    $connection['url'] = null;
    config()->set('database.connections.analytics_verify', $connection);
    config()->set('database.default', 'analytics_verify');
    config()->set('app.env', 'testing');
    $app->instance('env', 'testing');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('mail.default', 'array');
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    config()->set('analytics.export_chunk_size', 1);
    foreach (['product_files.media_disk', 'product_files.artifact_disk'] as $key) {
        $disk = config($key);
        config()->set('filesystems.disks.'.$disk.'.root', $directory.'/'.$disk);
    }
    assertAnalyticsSchema17($schema);
    Artisan::call('migrate', ['--force' => true]);
    assertAnalyticsSchema17($schema);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    assertAnalyticsSchema17($schema);
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $references = [];
    for ($i = 0; $i < 3; $i++) {
        assertAnalyticsSchema17($schema);
        $r = RefundFixtures::request(EarningFixtures::order());
        if ($i < 2) {
            RefundFixtures::complete($r);
        } else {
            $pending = $r;
        }
        $references[] = $r->reference;
        usleep(10000);
    }
    $configFile = $directory.'/config.php';
    file_put_contents($configFile, '<?php return '.var_export(config()->all(), true).';');
    chmod($configFile, 0600);
    $fired = false;
    DB::listen(function ($event) use (&$fired, $configFile, $pending): void {
        if ($fired || ! str_contains($event->sql, 'from "refunds"') || ! str_contains($event->sql, 'limit 1')) {
            return;
        }
        $fired = true;
        // First chunk has been read in the export snapshot. Another connection commits a
        // newer qualifying row, which would shift OFFSET and repeat a row at READ COMMITTED.
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $configFile, (string) $pending->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || ! str_contains($output, 'committed')) {
            throw new RuntimeException('Concurrent worker failed: '.$error);
        }
    });
    assertAnalyticsSchema17($schema);
    $filters = ReportFilters::from(Request::create('/', 'GET', ['status' => 'completed']));
    ob_start();
    app(ReportCsv::class)->response('refunds', $filters)->sendContent();
    $csv = ob_get_clean();
    if (! $fired || substr_count($csv, $references[0]) !== 1 || substr_count($csv, $references[1]) !== 1 || str_contains($csv, $references[2])) {
        throw new RuntimeException('Snapshot export duplicated/skipped/introduced a row.');
    }
    if (Refund::where('status', 'completed')->count() !== 3) {
        throw new RuntimeException('Concurrent completion did not persist outside the export snapshot.');
    }
    // Read-only projection plans, representative real commercial fixtures and intact triggers.
    $f = ReportFilters::from(Request::create('/'));
    $reports = app(AnalyticsReports::class);
    $queries = ['financial' => app(FinancialFacts::class)->totals($f), 'sales' => $reports->sales($f), 'products' => $reports->products($f), 'authors' => $reports->authors($f), 'refunds' => $reports->refunds($f), 'support' => DB::query()->fromSub($reports->support($f)->reorder(), 'tickets')->selectRaw('status, COUNT(*) AS count')->groupBy('status')];
    $plans = [];
    foreach ($queries as $name => $query) {
        assertAnalyticsSchema17($schema);
        $data = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $plan = json_decode($data[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        $plans[$name] = ['planning_ms' => $plan['Planning Time'], 'execution_ms' => $plan['Execution Time'], 'root' => $plan['Plan']['Node Type']];
    }
    echo json_encode(['result' => 'passed', 'snapshot_assertions' => 5, 'concurrent_completion' => true, 'plans' => $plans]).PHP_EOL;
} finally {
    $primary->statement('DROP SCHEMA '.$schema.' CASCADE');
    (new Filesystem)->deleteDirectory($directory);
}
