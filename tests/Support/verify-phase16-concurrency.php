<?php

use App\Events\Commerce\OrderPaid;
use App\Events\Commerce\PaymentSucceeded;
use App\Events\Payouts\PayoutPaid;
use App\Events\Refunds\RefundCompleted;
use App\Events\Support\SupportMessageSent;
use App\Jobs\DeliverNotificationEmail;
use App\Models\CommissionPolicy;
use App\Models\Order;
use App\Models\User;
use App\Services\Earnings\EarningProvisioner;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Notifications\NotificationRecovery;
use App\Services\Payouts\PayoutWorkflow;
use App\Services\Support\SupportWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\PayoutFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures;
use Tests\Support\SupportFixtures;

chdir(dirname(__DIR__, 2));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function phase16Schema(string $schema): void
{
    if (! preg_match('/^templaara_phase16_race_[a-f0-9]{12}$/', $schema) || DB::selectOne('SELECT current_schema() AS schema')->schema !== $schema || trim(DB::selectOne('SHOW search_path')->search_path, '"') !== $schema) {
        throw new RuntimeException('Refusing writes outside disposable verification schema.');
    }
}
if (($argv[1] ?? '') === '--worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    phase16Schema($job['schema']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 40;
    while (! file_exists($job['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out');
        } usleep(10000);
    }
    phase16Schema($job['schema']);
    if ($job['kind'] === 'recover') {
        app(NotificationRecovery::class)->run($job['source']);
    } elseif ($job['kind'] === 'deliver') {
        (new DeliverNotificationEmail($job['delivery']))->handle();
    } elseif ($job['kind'] === 'preference') {
        DB::transaction(function () use ($job) {
            User::whereKey($job['user'])->lockForUpdate()->firstOrFail();
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $job['user'], 'group' => 'orders'], ['in_app_enabled' => false, 'email_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    } else {
        DB::transaction(function () use ($job) {
            $u = User::whereKey($job['user'])->lockForUpdate()->firstOrFail();
            $q = $u->unreadNotifications();
            if ($job['kind'] === 'read') {
                $q->where('id', $job['delivery']);
            }
            $q->update(['read_at' => now(), 'updated_at' => now()]);
        }, 3);
    }
    echo '{"ok":true}';
    exit;
}
$schema = 'templaara_phase16_race_'.bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory, 0700);
$db = DB::connection('pgsql');
$db->statement('CREATE SCHEMA '.$schema);
$connection = config('database.connections.pgsql');
$connection['search_path'] = $schema;
$connection['url'] = null;
$assertions = 0;
function verify16(bool $ok, string $message): void
{
    global $assertions;
    if (! $ok) {
        throw new RuntimeException($message);
    }$assertions++;
}
function race16(array $jobs): array
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
            verify16(is_array($result), 'Worker failed: '.substr(file_get_contents($p['out']), 0, 2500).file_get_contents($p['err']));
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
    config()->set('database.connections.phase16_race', $connection);
    config()->set('database.default', 'phase16_race');
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.env', 'testing');
    app()->instance('env', 'testing');
    phase16Schema($schema);
    Artisan::call('migrate', ['--force' => true]);
    phase16Schema($schema);
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
    config()->set('mail.default', 'array');
    config()->set('notifications.started_at', '2000-01-01 00:00:00+00');
    config()->set('app.url', 'http://localhost');
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    chmod($directory.'/config.php', 0600);
    phase16Schema($schema);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    $admin = User::factory()->create()->assignRole('admin');
    config()->set('support.attachment_disk', config('product_files.artifact_disk'));
    // Real committed purchases, with notification wakeups deliberately absent.
    Event::fake([OrderPaid::class, PaymentSucceeded::class]);
    $o = SupportFixtures::order();
    app(EntitlementProvisioner::class)->provision($o);
    app(EarningProvisioner::class)->provision($o);
    $recover = fn ($source) => ['kind' => 'recover', 'source' => $source];
    race16([$recover('orders'), $recover('orders')]);
    $delivery = DB::table('notification_deliveries')->where('user_id', $o->user_id)->where('notification_type', 'purchase_ready')->first();
    verify16((bool) $delivery, 'Purchase delivery missing');
    verify16(DB::table('notifications')->where('id', $delivery->id)->count() === 1, 'Duplicate in-app record');
    verify16(DB::table('notification_deliveries')->where('user_id', $o->user_id)->where('notification_type', 'purchase_ready')->count() === 1, 'Duplicate event delivery');

    race16([['kind' => 'read', 'user' => $o->user_id, 'delivery' => $delivery->id], ['kind' => 'read-all', 'user' => $o->user_id]]);
    verify16(DB::table('notifications')->where('id', $delivery->id)->value('read_at') !== null, 'Lost read');

    $o2 = SupportFixtures::order();
    app(EntitlementProvisioner::class)->provision($o2);
    app(EarningProvisioner::class)->provision($o2);
    race16([$recover('orders'), ['kind' => 'preference', 'user' => $o2->user_id]]);
    $d2 = DB::table('notification_deliveries')->where('user_id', $o2->user_id)->where('notification_type', 'purchase_ready')->first();
    verify16($d2->in_app_enabled, 'Mandatory notice disabled');
    verify16(in_array($d2->email_status, ['pending', 'skipped'], true), 'Preference snapshot invalid');
    app(NotificationRecovery::class)->run('orders');
    verify16(DB::table('notification_deliveries')->where('id', $d2->id)->value('email_status') === $d2->email_status, 'Snapshot changed after preference update');

    race16([['kind' => 'deliver', 'delivery' => $delivery->id], ['kind' => 'deliver', 'delivery' => $delivery->id], $recover('orders')]);
    $sent = DB::table('notification_deliveries')->where('id', $delivery->id)->first();
    verify16($sent->email_status === 'sent' && $sent->attempts === 1, 'Duplicate email attempt');
    verify16(DB::table('notifications')->where('id', $delivery->id)->count() === 1, 'Retry duplicated in-app');

    Event::fake([SupportMessageSent::class]);
    $t = SupportFixtures::ticket($o);
    app(SupportWorkflow::class)->reply($t->seller, $t, SupportFixtures::reply(), [], 'author');
    race16([$recover('support_ticket_events'), $recover('support_ticket_events')]);
    verify16(DB::table('notification_deliveries')->where('user_id', $t->customer_user_id)->where('notification_type', 'support_seller_reply')->count() === 1, 'Duplicate support reply notification');

    Event::fake([RefundCompleted::class]);
    $r = RefundFixtures::request($o);
    RefundFixtures::complete($r, $admin);
    race16([$recover('refund_events'), $recover('refund_events')]);
    verify16(DB::table('notification_deliveries')->where('user_id', $o->user_id)->where('notification_type', 'refund_completed')->count() === 1, 'Duplicate refund completion');
    verify16($r->fresh()->getRawOriginal('status') === 'completed', 'Refund truth changed');

    // Payout setup has real earnings; faking only its paid wakeup preserves provisioning.
    Event::fake([PayoutPaid::class]);
    $e = PayoutFixtures::available();
    $p = PayoutFixtures::request($e->author);
    PayoutFixtures::process($p, $admin);
    app(PayoutWorkflow::class)->transition($admin, $p, 'pay', ['external_reference' => 'CONFIRMED-TRANSFER'], true);
    race16([$recover('payout_events'), $recover('payout_events')]);
    verify16(DB::table('notification_deliveries')->where('user_id', $p->author_user_id)->where('notification_type', 'payout_paid')->count() === 1, 'Duplicate payout paid');
    verify16($p->fresh()->getRawOriginal('status') === 'paid', 'Payout truth changed');

    // Transport failure occurs outside every domain transaction and retains durable recovery.
    $failure = DB::table('notification_deliveries')->where('email_status', 'pending')->first();
    Mail::mailer('array')->setSymfonyTransport(new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Controlled local failure');
        }

        public function __toString(): string
        {
            return 'controlled-test';
        }
    });
    try {
        (new DeliverNotificationEmail($failure->id))->handle();
        throw new RuntimeException('Expected mail failure');
    } catch (TransportException $expected) {
    }
    verify16(DB::table('notification_deliveries')->where('id', $failure->id)->value('email_status') === 'pending', 'Mail failure lost delivery');
    verify16(Order::findOrFail($o->id)->getRawOriginal('payment_status') === 'paid', 'Mail failure rolled back payment');
    verify16(DB::table('notification_deliveries')->where('id', $failure->id)->value('attempts') === 1, 'Failure attempt not recorded');
    echo json_encode(['scenarios' => 7, 'assertions' => $assertions, 'mail_failure' => 'passed', 'result' => 'passed']).PHP_EOL;
} finally {
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
    foreach ([config('product_files.media_disk'), config('product_files.artifact_disk')] as $disk) {
        $root = config('filesystems.disks.'.$disk.'.root');
        if (is_string($root) && str_contains($root, 'framework/testing/disks/')) {
            (new Filesystem)->deleteDirectory($root);
        }
    }
    (new Filesystem)->deleteDirectory($directory);
}
