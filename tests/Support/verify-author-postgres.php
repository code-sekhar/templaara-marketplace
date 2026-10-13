<?php

// Disposable PostgreSQL schema only. All fixture writes follow an explicit schema assertion.
use App\Enums\AuthorApplicationAction as Action;
use App\Enums\AuthorApplicationStatus as Status;
use App\Models\AuthorProfile;
use App\Models\User;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\SaveAuthorProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthorFixtures as F;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function assertAuthorSchema(string $expected): void
{
    if (! preg_match('/^templaara_author_concurrency_[a-f0-9]{12}$/', $expected) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $expected) {
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
        if ($job['action'] === 'save') {
            app(SaveAuthorProfile::class)->save(User::findOrFail($job['actor']), F::ready(['display_name' => 'Updated Concurrent Author']));
        } else {
            app(AuthorWorkflow::class)->transition(User::findOrFail($job['actor']), AuthorProfile::findOrFail($job['profile']), Action::from($job['action']), $job['round'] ?? null, $job['input'] ?? []);
        }
        echo json_encode(['result' => 'ok']).PHP_EOL;
    } catch (ValidationException|AuthorizationException $e) {
        echo json_encode(['result' => 'conflict']).PHP_EOL;
    }
    exit;
}
$schema = 'templaara_author_concurrency_'.bin2hex(random_bytes(6));
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
    $r = F::user('admin');
    $r2 = F::user('admin');
    $p = F::profile();
    $submit = ['action' => 'submit', 'actor' => $p->user_id, 'profile' => $p->id];
    $results = workers([$submit, $submit]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Duplicate submission succeeded twice.');
    verify($p->applications()->count() === 1, 'Duplicate submission created two rounds.');
    $a = $p->applications()->firstOrFail();
    $claim = ['action' => 'start', 'profile' => $p->id, 'round' => $a->id];
    $results = workers([[...$claim, 'actor' => $r->id], [...$claim, 'actor' => $r2->id]]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Two reviewers claimed one round.');
    $claimed = $a->fresh()->reviewed_by;
    $decision = ['profile' => $p->id, 'round' => $a->id, 'actor' => $claimed];
    $results = workers([[...$decision, 'action' => 'approve'], [...$decision, 'action' => 'reject', 'input' => ['summary' => 'The applicant is not suitable for the marketplace.']]]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Contradictory decisions both succeeded.');
    verify($p->fresh()->status === $a->fresh()->status, 'Profile and round diverged.');
    verify($p->user->fresh()->hasRole('author') === ($a->fresh()->status === Status::Approved), 'Role and decision diverged.');
    $a = F::claimed($r);
    $p = $a->profile;
    $approve = ['action' => 'approve', 'profile' => $p->id, 'round' => $a->id, 'actor' => $r->id];
    $results = workers([$approve, $approve]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Duplicate approval succeeded twice.');
    verify($a->events()->where('action', 'approved')->count() === 1, 'Approval audit duplicated.');
    verify($a->user->fresh()->hasAllRoles(['author', 'customer']), 'Approval lost customer role.');
    $a = F::claimed($r);
    $p = $a->profile;
    $change = ['action' => 'changes', 'profile' => $p->id, 'round' => $a->id, 'actor' => $r->id, 'input' => ['feedback' => [['section' => 'portfolio', 'message' => 'Please describe previous business software projects.']]]];
    $results = workers([$change, $change]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Duplicate changes decision succeeded.');
    verify($a->feedback()->count() === 1, 'Feedback duplicated.');
    $submit = ['action' => 'submit', 'actor' => $p->user_id, 'profile' => $p->id];
    $results = workers([$submit, $submit]);
    verify(count(array_filter($results, fn ($v) => $v['result'] === 'ok')) === 1, 'Duplicate resubmission succeeded.');
    verify($p->applications()->count() === 2, 'Resubmission round count wrong.');
    verify($a->fresh()->status === Status::ChangesRequested && $a->feedback()->count() === 1, 'Previous round was lost.');
    $p = F::profile();
    $results = workers([['action' => 'save', 'actor' => $p->user_id, 'profile' => $p->id], ['action' => 'submit', 'actor' => $p->user_id, 'profile' => $p->id]]);
    $a = $p->applications()->firstOrFail();
    verify($a->snapshot['display_name'] === $p->fresh()->getAttribute('display_name'), 'Concurrent edit changed frozen submitted content.');
    verify($a->events()->count() === 1, 'Concurrent save/submit corrupted audit.');
    echo json_encode(['result' => 'passed', 'concurrency_scenarios' => 7, 'assertions' => $assertions]).PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }DB::disconnect('author_verify');
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    (new Filesystem)->deleteDirectory($directory);
}
