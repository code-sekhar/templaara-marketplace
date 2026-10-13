<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$schema = 'templaara_phase7_verify_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
try {
    config()->set('database.connections.phase7_verify', $connection);
    config()->set('database.default', 'phase7_verify');
    config()->set('cache.default', 'array');
    Artisan::call('migrate', ['--database' => 'phase7_verify', '--force' => true]);
    file_put_contents($directory.'/connection.php', '<?php return '.var_export($connection, true).';');
    chmod($directory.'/connection.php', 0600);
    $testFiles = [];
    foreach (['CommerceTest'] as $name) {
        $tests = file_get_contents('tests/Feature/'.$name.'.php');
        $tests = preg_replace('/<\?php/', '<?php\n'."uses(Tests\\TestCase::class);\nafterEach(function () { Illuminate\\Support\\Facades\\DB::connection()->rollBack(); });\n", $tests, 1);
        $tests = str_replace('<?php\\n', '<?php'.PHP_EOL, $tests);
        $setup = <<<'SETUP'
beforeEach(function () {
    app()->instance('env','testing');
    config()->set('database.default','pgsql');
    config()->set('database.connections.pgsql',require __DIR__.'/connection.php');
    config()->set('cache.default','array');config()->set('session.driver','array');
    app()->forgetInstance(Illuminate\Cache\RateLimiter::class);
    Illuminate\Support\Facades\DB::purge('pgsql');
    app()->instance('db.transactions',new Illuminate\Foundation\Testing\DatabaseTransactionsManager(['pgsql']));
    Illuminate\Support\Facades\DB::connection()->setTransactionManager(app('db.transactions'));
    Illuminate\Support\Facades\DB::connection()->beginTransaction();
SETUP;
        $tests = str_replace('beforeEach(function () {', $setup, $tests, $hookCount);
        if ($hookCount !== 1) {
            throw new RuntimeException('Refusing verification without exactly one isolated setup hook.');
        }
        file_put_contents($directory.'/'.$name.'.php', $tests);
        $testFiles[] = '<file>'.$directory.'/'.$name.'.php</file>';
    }
    $xml = file_get_contents('phpunit.xml');
    $xml = str_replace('<env name=', '<env force="true" name=', $xml);
    $xml = str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="'.getcwd().'/vendor/autoload.php"', $xml);
    $xml = preg_replace('/<testsuites>.*?<\/testsuites>/s', '<testsuites><testsuite name="PostgreSQL Commerce">'.implode('', $testFiles).'</testsuite></testsuites>', $xml);
    file_put_contents($directory.'/phpunit.xml', $xml);
    $process = proc_open([PHP_BINARY, 'vendor/bin/pest', '--configuration', $directory.'/phpunit.xml', '--compact'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    $status = proc_close($process);
} finally {
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
exit($status);
