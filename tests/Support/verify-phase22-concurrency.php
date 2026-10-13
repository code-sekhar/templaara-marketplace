<?php

// Disposable PostgreSQL schema only. All fixture writes follow an explicit schema assertion.
use App\Enums\AuthorApplicationAction as Action;
use App\Enums\AuthorApplicationStatus as Status;
use App\Models\AuthorPortfolioItem;
use App\Models\AuthorProfile;
use App\Models\AuthorProfileMedia;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Authors\AuthorMedia;
use App\Services\Authors\AuthorPortfolio;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\SaveAuthorProfile;
use App\Services\Invoicing\IssueInvoice;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthorFixtures as F;
use Tests\Support\EarningFixtures;
use Tests\Support\InvoiceFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function assertAuthorSchema(string $expected): void
{
    if (! preg_match('/^templaara_phase22_concurrency_[a-f0-9]{12}$/', $expected) || (DB::selectOne('SELECT current_schema() AS schema')->schema !== $expected || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $expected)) {
        throw new RuntimeException('Refusing author fixtures outside disposable verification schema.');
    }
}
if (($argv[1] ?? null) === '--worker') {
    $job = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    assertAuthorSchema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }usleep(10000);
    }
    try {
        $actor = User::findOrFail($job['actor']);
        if ($job['action'] === 'save') {
            app(SaveAuthorProfile::class)->save($actor, $job['input']);
        } elseif ($job['action'] === 'portfolio') {
            app(AuthorPortfolio::class)->save($actor, $job['input'], AuthorPortfolioItem::findOrFail($job['item']));
        } elseif ($job['action'] === 'reorder') {
            app(AuthorPortfolio::class)->reorder($actor, $job['items'], $job['revision']);
        } elseif ($job['action'] === 'media') {
            app(AuthorMedia::class)->upload($actor, UploadedFile::fake()->image('image.png', 128, 128), 'avatar', $job['revision']);
        } elseif ($job['action'] === 'read') {
            $order = Order::findOrFail($job['order']);
            $current = ['item' => $order->items()->first()->getAttributes(), 'invoice' => Invoice::where('order_id', $order->id)->first()->items()->first()->getAttributes()];
            if ($current !== $job['expected']) {
                throw new RuntimeException('Historical seller snapshots changed.');
            }
        } else {
            app(AuthorWorkflow::class)->transition($actor, AuthorProfile::findOrFail($job['profile']), Action::from($job['action']), $job['round'] ?? null, $job['input'] ?? []);
        }
        echo json_encode(['result' => 'ok']).PHP_EOL;
    } catch (ValidationException|AuthorizationException $e) {
        echo json_encode(['result' => 'conflict']).PHP_EOL;
    }
    exit;
}
$schema = 'templaara_phase22_concurrency_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }$assertions++;
}
/** Run independent processes against the same persisted cart/order with a barrier. */
function workers(array $jobs, ?Closure $beforeRelease = null): array
{
    global $directory, $schema;
    $jobs = array_map(fn ($job) => [...$job, 'schema' => $schema], $jobs);
    $go = $directory.'/go-'.bin2hex(random_bytes(4));
    $processes = [];
    foreach ($jobs as $index => $job) {
        $ready = $go.'-'.$index;
        $output = $ready.'.out';
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', json_encode([...$job, 'go' => $go, 'ready' => $ready])], [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $ready.'.err', 'w']], $pipes, getcwd(), ['APP_CONFIG_CACHE' => $directory.'/config.php', 'PATH' => getenv('PATH')]);
        fclose($pipes[0]);
        $processes[] = compact('process', 'ready', 'output');
    }
    $deadline = microtime(true) + 45;
    try {
        do {
            $waiting = array_filter($processes, fn ($entry) => ! file_exists($entry['ready']));
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker startup timed out.');
            }
            usleep(10000);
        } while ($waiting);
        touch($go);
        $beforeRelease?->__invoke();
        $results = [];
        foreach ($processes as $entry) {
            while (proc_get_status($entry['process'])['running']) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrent operation timed out.');
                }
                usleep(10000);
            }
            $result = json_decode(file_get_contents($entry['output']), true);
            verify(is_array($result), 'Worker failed: '.file_get_contents($entry['ready'].'.err'));
            $results[] = $result;
        }

        return $results;
    } finally {
        foreach ($processes as $entry) {
            if (proc_get_status($entry['process'])['running']) {
                proc_terminate($entry['process']);
            }
            proc_close($entry['process']);
        }
    }
}

try {
    config()->set('database.connections.author_verify', $connection);
    config()->set('database.default', 'author_verify');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    assertAuthorSchema($schema);
    Artisan::call('migrate', ['--database' => 'author_verify', '--force' => true]);
    Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);
    config()->set(['commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
    config()->set('filesystems.disks.public.root', $directory.'/media');
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);
    $r = F::user('admin');
    $p = F::profile();
    $save = ['action' => 'save', 'actor' => $p->user_id, 'profile' => $p->id, 'input' => ['display_name' => 'Concurrent identity', 'revision' => $p->revision]];
    $results = workers([$save, $save]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Concurrent profile edits lost revision.');
    echo "1 profile identity updates PASS\n";
    $p = F::profile();
    $results = workers([['action' => 'save', 'actor' => $p->user_id, 'input' => ['professional_headline' => 'Concurrent professional edit', 'revision' => $p->revision]], ['action' => 'submit', 'actor' => $p->user_id, 'profile' => $p->id]]);
    $a = $p->applications()->firstOrFail();
    verify($a->snapshot['professional']['professional_headline'] === $p->fresh()->professional_headline, 'Submission/profile snapshot diverged.');
    echo "2 submit/profile edit PASS\n";
    $p = F::profile();
    $input = ['title' => 'Professional project', 'short_description' => 'Business software project for retailers.', 'project_type' => 'personal', 'is_public' => true, 'is_featured' => false, 'sort_order' => 0];
    $item = app(AuthorPortfolio::class)->save($p->user, $input);
    $p->refresh();
    $results = workers([['action' => 'portfolio', 'actor' => $p->user_id, 'item' => $item->id, 'input' => [...$input, 'title' => 'Edited project', 'revision' => $item->revision]], ['action' => 'reorder', 'actor' => $p->user_id, 'items' => [$item->public_uuid], 'revision' => $p->revision]]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Concurrent reorder/edit lost update.');
    echo "3 portfolio reorder/edit PASS\n";
    $first = F::user();
    $second = F::user();
    $results = workers([['action' => 'save', 'actor' => $first->id, 'input' => F::ready(['display_name' => 'Concurrent Public Store'])], ['action' => 'save', 'actor' => $second->id, 'input' => F::ready(['display_name' => 'Concurrent Public Store'])]]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 2, 'Generated slug allocation failed.');
    verify(AuthorProfile::whereIn('user_id', [$first->id, $second->id])->distinct()->count('slug') === 2, 'Duplicate public slug.');
    echo "4 public identifier allocation PASS\n";
    $a = F::claimed($r);
    $results = workers([['action' => 'approve', 'actor' => $r->id, 'profile' => $a->author_profile_id, 'round' => $a->id], ['action' => 'submit', 'actor' => $a->user_id, 'profile' => $a->author_profile_id]]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Review/resubmission violated lifecycle.');
    verify($a->profile->fresh()->status === Status::Approved, 'Approval lost.');
    echo "5 approval/resubmission PASS\n";
    $p = F::profile();
    $media = ['action' => 'media', 'actor' => $p->user_id, 'revision' => $p->revision];
    $results = workers([$media, $media]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Media replacement lost update.');
    verify(AuthorProfileMedia::where('author_profile_id', $p->id)->where('is_current', true)->count() === 1, 'Multiple current images.');
    echo "6 media replacement PASS\n";
    Artisan::call('db:seed', ['--class' => CommissionPolicySeeder::class, '--force' => true]);
    $p = F::profile(F::user('author'));
    $o = EarningFixtures::order(EarningFixtures::product($p->user));
    InvoiceFixtures::configure(InvoiceFixtures::gst());
    $doc = app(IssueInvoice::class)->issue($o);
    $expected = ['item' => $o->items()->first()->getAttributes(), 'invoice' => $doc->items()->first()->getAttributes()];
    // Worker policy config is refreshed after fixture settings; neither job mutates commerce.
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    $results = workers([['action' => 'save', 'actor' => $p->user_id, 'input' => ['professional_headline' => 'Updated professional headline', 'revision' => $p->revision]], ['action' => 'read', 'actor' => $r->id, 'order' => $o->id, 'expected' => $expected]]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 2, 'Historical-read/profile-edit race failed.');
    echo "7 immutable seller history/profile edit PASS\n";
    echo json_encode(['result' => 'passed', 'concurrency_scenarios' => 7, 'assertions' => $assertions]).PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }DB::disconnect('author_verify');
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    (new Filesystem)->deleteDirectory($directory);
}
