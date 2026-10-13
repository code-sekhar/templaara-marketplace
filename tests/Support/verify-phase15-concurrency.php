<?php

use App\Models\CommissionPolicy;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Support\SupportWorkflow;
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
use Tests\Support\SupportFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase15Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase15_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase15Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }phase15Schema($job['schema']);
    try {
        $actor = User::findOrFail($job['actor']);
        $support = app(SupportWorkflow::class);
        if ($job['kind'] === 'create') {
            $support->create($actor, OrderItem::findOrFail($job['item']), $job['input']);
        } elseif ($job['kind'] === 'refund') {
            app(RefundWorkflow::class)->transition($actor, Refund::findOrFail($job['refund']), 'complete', ['external_reference' => 'EXTERNAL-SUPPORT-REFUND'], true);
        } elseif ($job['kind'] === 'reply') {
            $support->reply($actor, SupportTicket::findOrFail($job['ticket']), $job['input'], [], $job['mode']);
        } else {
            $support->transition($actor, SupportTicket::findOrFail($job['ticket']), $job['kind'], $job['input'] ?? [], $job['mode']);
        }
        echo '{"ok":true}';
    } catch (ValidationException|AuthorizationException $e) {
        echo '{"ok":false}';
    }exit;
}
$schema = 'templaara_phase15_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify15(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race15(array $jobs): array
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
            verify15(is_array($result), 'Worker failed: '.substr(file_get_contents($p['out']), 0, 2500).file_get_contents($p['err']));
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
    config()->set('database.connections.phase15_race', $connection);
    config()->set('database.default', 'phase15_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase15Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase15Schema($schema);
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
    phase15Schema($schema);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $admin = User::factory()->create()->assignRole('admin');
    config()->set('support.attachment_disk', config('product_files.artifact_disk'));
    foreach (['duplicate-create', 'reply-resolve', 'seller-close', 'refund-reply', 'refund-create', 'double-seller-reply', 'close-reopen'] as $scenario) {
        phase15Schema($schema);
        $o = SupportFixtures::order();
        $buyer = User::findOrFail($o->user_id);
        $seller = $o->items->first()->product->owner;
        $create = ['kind' => 'create', 'actor' => $buyer->id, 'item' => $o->items->first()->id, 'input' => SupportFixtures::input()];
        if ($scenario === 'duplicate-create') {
            $results = race15([$create, $create]);
            verify15($results[0]['ok'] && $results[1]['ok'], 'Idempotent creators must both receive same ticket.');
            verify15(SupportTicket::where('order_item_id', $o->items->first()->id)->count() === 1, 'Duplicate creation.');

            continue;
        }
        if ($scenario === 'refund-create') {
            $f = RefundFixtures::request($o);
            RefundFixtures::process($f, $admin);
            $results = race15([$create, ['kind' => 'refund', 'actor' => $admin->id, 'refund' => $f->id]]);
            verify15($results[1]['ok'], 'Refund failed.');
            verify15(! SupportTicket::where('order_item_id', $o->items->first()->id)->where('status', '!=', 'closed')->exists(), 'Active ticket after refund.');

            continue;
        }
        $t = SupportFixtures::ticket($o);
        $service = app(SupportWorkflow::class);
        $job = fn ($kind, $u, $mode, $input = []) => ['kind' => $kind, 'actor' => $u->id, 'ticket' => $t->id, 'mode' => $mode, 'input' => $input];
        if ($scenario === 'reply-resolve') {
            $results = race15([$job('reply', $buyer, 'customer', SupportFixtures::reply()), $job('resolve', $seller, 'author')]);
            verify15($results[1]['ok'], 'Resolve failed.');
            verify15($t->fresh()->status->value === 'resolved', 'Resolution lost.');
            verify15($t->messages()->count() === ($results[0]['ok'] ? 2 : 1), 'Lost reply.');
        } elseif ($scenario === 'seller-close') {
            $service->transition($seller, $t, 'resolve', [], 'author');
            $results = race15([$job('reply', $seller, 'author', SupportFixtures::reply()), $job('close', $buyer, 'customer')]);
            verify15(! $results[0]['ok'] && $results[1]['ok'], 'Resolved conversation must close, not silently resume.');
            verify15($t->fresh()->status->value === 'closed', 'Close lost.');
        } elseif ($scenario === 'refund-reply') {
            $f = RefundFixtures::request($o);
            RefundFixtures::process($f, $admin);
            $results = race15([$job('reply', $buyer, 'customer', SupportFixtures::reply()), ['kind' => 'refund', 'actor' => $admin->id, 'refund' => $f->id]]);
            verify15($results[1]['ok'] && $t->fresh()->status->value === 'closed', 'Refund must close support.');
            verify15($t->messages()->count() === ($results[0]['ok'] ? 2 : 1), 'Reply lost during refund.');
        } elseif ($scenario === 'double-seller-reply') {
            $results = race15([$job('reply', $seller, 'author', SupportFixtures::reply('First seller diagnostic message')), $job('reply', $seller, 'author', SupportFixtures::reply('Second seller diagnostic message'))]);
            verify15($results[0]['ok'] && $results[1]['ok'], 'Concurrent seller replies failed.');
            verify15($t->messages()->count() === 3 && $t->fresh()->status->value === 'awaiting_customer', 'Lost seller reply.');
        } else {
            $service->transition($seller, $t, 'resolve', [], 'author');
            $results = race15([$job('close', $admin, 'admin', ['reason' => 'Administrative closure during race']), $job('reopen', $buyer, 'customer')]);
            verify15($results[0]['ok'], 'Admin close failed.');
            verify15($t->fresh()->status->value === 'closed' && $t->fresh()->getAttribute('admin_locked'), 'Reopen escaped terminal closure.');
        }
        verify15($t->events()->count() === $t->fresh()->getAttribute('event_sequence'), 'Incomplete support audit.');
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
