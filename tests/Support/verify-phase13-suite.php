<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$schema = 'templaara_phase13_verify_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
try {
    config()->set('database.connections.phase13_verify', $connection);
    config()->set('database.default', 'phase13_verify');
    config()->set('cache.default', 'array');
    if (DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing migrations outside disposable schema.');
    }
    Artisan::call('migrate', ['--database' => 'phase13_verify', '--force' => true]);
    file_put_contents($directory.'/connection.php', '<?php return '.var_export($connection, true).';');
    chmod($directory.'/connection.php', 0600);
    $testFiles = [];
    foreach (in_array('--refunds-only', $argv, true) ? ['PhaseThirteenTest'] : ['PhaseThirteenTest', 'PhaseTwelveTest', 'PhaseElevenTest', 'PhaseTenTest', 'CommerceTest', 'EntitlementTest'] as $name) {
        $tests = file_get_contents('tests/Feature/'.$name.'.php');
        $setup = <<<'SETUP'
<?php
uses(Tests\TestCase::class);
beforeEach(function (): void {
    app()->instance('env','testing');
    app(Illuminate\Testing\ParallelTesting::class)->resolveTokenUsing(fn()=>basename(__DIR__));
    config()->set('database.default','pgsql');
    $connection = require __DIR__.'/connection.php';
    config()->set('database.connections.pgsql',$connection);
    config()->set('cache.default','array');config()->set('session.driver','array');
    app()->forgetInstance(Illuminate\Cache\RateLimiter::class);
    Illuminate\Support\Facades\Facade::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
    app()->getProvider(App\Providers\AppServiceProvider::class)->boot();
    Illuminate\Support\Facades\DB::purge('pgsql');
    if (Illuminate\Support\Facades\DB::selectOne('SELECT current_schema() AS schema')->schema !== $connection['search_path'] || trim(Illuminate\Support\Facades\DB::selectOne('SHOW search_path')->search_path, '"') !== $connection['search_path']) {
        throw new RuntimeException('Refusing to test outside the isolated verification schema.');
    }
    app()->instance('db.transactions',new Illuminate\Foundation\Testing\DatabaseTransactionsManager(['pgsql']));
    Illuminate\Support\Facades\DB::connection()->setTransactionManager(app('db.transactions'));
    Illuminate\Support\Facades\DB::connection()->beginTransaction();
});
afterEach(function (): void { Illuminate\Support\Facades\DB::connection()->rollBack(); });
SETUP;
        if (! str_starts_with($tests, '<?php')) {
            throw new RuntimeException('Unexpected test source.');
        }
        $tests = $setup.PHP_EOL.substr($tests, 5);
        file_put_contents($directory.'/'.$name.'.php', $tests);
        $testFiles[] = '<file>'.$directory.'/'.$name.'.php</file>';
    }
    $xml = file_get_contents('phpunit.xml');
    $xml = str_replace('<env name=', '<env force="true" name=', $xml);
    $xml = str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="'.getcwd().'/vendor/autoload.php"', $xml);
    $xml = preg_replace('/<testsuites>.*?<\/testsuites>/s', '<testsuites><testsuite name="PostgreSQL Payouts Earnings Updates Activation Commerce Entitlements">'.implode('', $testFiles).'</testsuite></testsuites>', $xml);
    file_put_contents($directory.'/phpunit.xml', $xml);
    $process = proc_open([PHP_BINARY, 'vendor/bin/pest', '--configuration', $directory.'/phpunit.xml', '--compact'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    $status = proc_close($process);
} finally {
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    foreach (glob(storage_path('framework/testing/disks/*_test_'.$schema)) as $root) {
        (new Filesystem)->deleteDirectory($root);
    }
    rmdir($directory);
}
exit($status);
