<?php

use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\Gateways\StripeGateway;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExternalPaymentFixtures as EF;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$source = 'templaara_phase20_backup_'.bin2hex(random_bytes(6));
$target = 'templaara_phase20_restore_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$source;
mkdir($directory, 0700);
$base = DB::connection('pgsql');
$c = config('database.connections.pgsql');
$c['url'] = null;
$c['search_path'] = $source;
$base->statement('CREATE SCHEMA '.$source);
$guard = function (string $expected): void {
    if (! preg_match('/^templaara_phase20_(backup|restore)_[a-f0-9]{12}$/D', $expected)
        || DB::selectOne('SELECT current_schema() AS name')->name !== $expected
        || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $expected) {
        throw new RuntimeException('Refusing write/restore outside disposable schema.');
    }
};
$run = function (array $command, array $environment) use ($directory): void {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $directory.'/tool-output', 'w'], 2 => ['file', $directory.'/tool-errors', 'w']], $pipes, null, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Backup tooling unavailable.');
    }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Backup/restore tool failed; private diagnostic file withheld.');
    }
};
try {
    config()->set(['database.default' => 'phase20_restore', 'database.connections.phase20_restore' => $c, 'cache.default' => 'array', 'session.driver' => 'array']);
    $guard($source);
    Artisan::call('migrate', ['--database' => 'phase20_restore', '--force' => true]);
    app()->instance('env', 'testing');
    $guard($source);
    (new RolesAndPermissionsSeeder)->run();
    $guard($source);
    (new CommissionPolicySeeder)->run();
    EF::configure();
    $guard($source);
    $order = EF::order();
    $payment = $order->payments()->firstOrFail();
    [$body, $headers] = EF::event($payment);
    $guard($source);
    app(ExternalPayments::class)->settle(app(StripeGateway::class)->webhook($body, $headers['Stripe-Signature']));
    $tables = ['orders', 'order_items', 'payments', 'payment_checkouts', 'payment_events', 'entitlements', 'licenses', 'author_earnings', 'author_earning_ledger_entries', 'notification_deliveries', 'migrations'];
    $fingerprint = function () use ($tables): array {
        $out = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $out[$table] = ['count' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        }

        return $out;
    };
    $before = $fingerprint();
    // Credentials exist only in child environment; never arguments or public output.
    $environment = array_merge(getenv(), ['PGHOST' => (string) $c['host'], 'PGPORT' => (string) $c['port'], 'PGDATABASE' => (string) $c['database'], 'PGUSER' => (string) $c['username'], 'PGPASSWORD' => (string) $c['password']]);
    $run(['pg_dump', '--format=custom', '--inserts', '--no-owner', '--no-acl', '--schema='.$source, '--file='.$directory.'/fixture.dump'], $environment);
    $run(['pg_restore', '--no-owner', '--no-acl', '--file='.$directory.'/restore.sql', $directory.'/fixture.dump'], $environment);
    $sql = file_get_contents($directory.'/restore.sql');
    // Substitute only the generated schema identity; never accept caller-supplied targets.
    $sql = str_replace($source, $target, $sql);
    $sql = preg_replace('/^\\\\(?:un)?restrict.*$/m', '', $sql);
    $base->statement('CREATE SCHEMA '.$target);
    $c['search_path'] = $target;
    config()->set('database.connections.phase20_restore', $c);
    DB::purge('phase20_restore');
    $guard($target);
    $sql = str_replace('CREATE SCHEMA '.$target.';', '', $sql);
    DB::unprepared($sql);
    DB::statement('SET search_path TO '.$target);
    $guard($target);
    $after = $fingerprint();
    if ($before !== $after || $after['orders']['count'] !== 1 || $after['payments']['count'] !== 1 || $after['entitlements']['count'] !== 1) {
        throw new RuntimeException('Restored representative financial/access evidence did not match.');
    }
    echo json_encode(['result' => 'passed', 'scope' => 'disposable schema fixture, not production database backup', 'tables' => count($tables), 'exact_counts_and_hashes' => true]).PHP_EOL;
} finally {
    DB::purge('phase20_restore');
    $base->statement('DROP SCHEMA IF EXISTS '.$source.' CASCADE');
    $base->statement('DROP SCHEMA IF EXISTS '.$target.' CASCADE');
    (new Filesystem)->deleteDirectory($directory);
    foreach (glob(storage_path('framework/testing/disks/*_'.getmypid())) as $root) {
        (new Filesystem)->deleteDirectory($root);
    }
}
