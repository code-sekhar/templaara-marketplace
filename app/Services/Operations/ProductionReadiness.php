<?php

namespace App\Services\Operations;

use App\Http\Middleware\TrustedProxies;
use App\Services\Commerce\Gateways\RazorpayGateway;
use App\Services\Commerce\Gateways\StripeGateway;
use App\Services\Invoicing\TaxPolicy;
use App\Services\Products\Files\PrivateArtifactStorage;
use App\Services\Settings\MarketplaceSettings;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;

final class ProductionReadiness
{
    /** @return array<string,array{status:string,message:string}> */
    public function checks(): array
    {
        $checks = $this->configuration();
        $add = function (string $key, bool $ok, string $message) use (&$checks): void {
            $checks[$key] = ['status' => $ok ? 'PASS' : 'FAIL', 'message' => $message];
        };
        try {
            $db = DB::connection();
            $add('database', $db->getDriverName() === 'pgsql', 'PostgreSQL connectivity required.');
            if ($db->getDriverName() === 'pgsql') {
                $schema = $db->selectOne('SELECT current_schema() AS name')->name;
                $path = $db->selectOne('SHOW search_path')->search_path;
                $add('schema', $schema === config('production.schema') && trim($path, '"') === config('production.schema'), 'Exact expected schema/search_path required.');
                $add('database_timezone', $db->selectOne('SHOW TIMEZONE')->TimeZone === 'UTC', 'New production sessions must use UTC; no historical repair.');
                $ran = Schema::hasTable('migrations') ? $db->table('migrations')->pluck('migration')->all() : [];
                $expected = array_map(fn ($file) => pathinfo($file, PATHINFO_FILENAME), glob(database_path('migrations/*.php')) ?: []);
                $add('migrations', ! array_diff($expected, $ran), 'All release migrations must be applied.');
                $add('notification_epoch', Schema::hasTable('notification_epoch') && $db->table('notification_epoch')->exists(), 'Durable notification epoch required.');
                $add('queue_tables', Schema::hasTable('jobs') && Schema::hasTable('failed_jobs'), 'Persistent queue and failed-job tables required.');
                $settings = app(MarketplaceSettings::class);
                $add('refund_policy', $settings->section('refunds', true)['refund_window_days']['value'] !== null, 'Explicit production refund policy required.');
                foreach ($settings->currencies() as $currency) {
                    $add('payout_'.$currency, $settings->section('payouts', true)['minimum_'.$currency]['value'] !== null, 'Explicit minimum required for enabled payout currency.');
                    $provider = config('commerce.providers.'.$currency);
                    $add('provider_'.$currency, in_array($provider, ['stripe', 'razorpay'], true) && ($provider !== 'razorpay' || $currency === 'INR'), 'Supported enabled-currency provider mapping required.');
                    $gateway = $provider === 'stripe' ? app(StripeGateway::class) : app(RazorpayGateway::class);
                    $add('credentials_'.$currency, in_array($provider, ['stripe', 'razorpay'], true) && $gateway->available(), 'Enabled live provider credentials and webhook secret required.');
                }
                $add('reporting_timezone', in_array($settings->timezone(), timezone_identifiers_list(), true), 'Valid explicit business reporting timezone required.');
            }
        } catch (\Throwable) {
            $add('dependencies', false, 'Database or commercial configuration unavailable; details intentionally omitted.');
        }
        try {
            $policy = app(TaxPolicy::class)->current();
            $add('invoice_configuration', true, 'Enabled invoicing requires complete platform identity and explicit reviewed tax rules.');
            if ($policy['enabled']) {
                app(PrivateArtifactStorage::class)->assertSecure(config('invoicing.disk'));
                $this->storageCheck($checks, config('invoicing.disk'));
                $add('invoice_storage', true, 'Invoice PDF disk passes private storage guards; external policy requires operator proof.');
                if ($policy['tax_mode'] !== 'international' && array_diff(app(MarketplaceSettings::class)->currencies(), ['INR'])) {
                    $checks['international_tax_review'] = ['status' => 'WARN', 'message' => 'International currencies enabled without a configured international tax policy; professional launch review required.'];
                }
            }
        } catch (\Throwable) {
            $add('invoice_configuration', false, 'Invoice/tax configuration incomplete or private PDF storage unsafe. Tax identifiers are intentionally omitted.');
        }
        $add('readiness', app(Readiness::class)->available(), 'Critical read-only database/Redis probes must succeed.');
        foreach (['artifact_disk', 'attachment_disk'] as $kind) {
            $disk = config($kind === 'artifact_disk' ? 'product_files.artifact_disk' : 'support.attachment_disk');
            try {
                app(PrivateArtifactStorage::class)->assertSecure($disk);
                $add($kind, true, 'Private storage configuration passes the existing domain guard.');
            } catch (\Throwable) {
                $add($kind, false, 'Private storage configuration unsafe.');
            }
            $this->storageCheck($checks, $disk);
        }
        $this->storageCheck($checks, config('product_files.media_disk'));
        $add('manifest', is_file(public_path('build/manifest.json')) && ! Vite::isRunningHot(), 'Production asset manifest required; Vite hot mode forbidden.');
        foreach ([storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')] as $index => $path) {
            $add('writable_'.$index, is_dir($path) && is_writable($path), 'Required local runtime directory must be writable.');
        }
        $checks['operations'] = ['status' => 'WARN', 'message' => 'Worker/scheduler processes, external delivery, bucket policy, backups and alerts require independent operator proof.'];
        $checks['maintenance'] = ['status' => app()->isDownForMaintenance() ? 'WARN' : 'PASS', 'message' => 'Maintenance state checked; webhooks retry/reconcile after reopening.'];

        return $checks;
    }

    /** @return array<string,array{status:string,message:string}> */
    public function configuration(): array
    {
        $url = parse_url((string) config('app.url')) ?: [];
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $queue = config('queue.connections.'.config('queue.default'), []);
        $notification = config('queue.connections.'.config('notifications.connection'), []);
        $rules = [
            'environment' => [app()->isProduction(), 'APP_ENV must be production.'],
            'debug' => [config('app.debug') === false, 'APP_DEBUG must be false.'],
            'url' => [($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && in_array($url['path'] ?? '', ['', '/'], true) && ! in_array($url['host'], ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true), 'APP_URL must be a clean production HTTPS origin.'],
            'key' => [is_string($key) && Encrypter::supported($key, config('app.cipher')), 'Valid APP_KEY required; value is never printed.'],
            'driver' => [config('database.connections.'.config('database.default').'.driver') === 'pgsql', 'PostgreSQL driver required.'],
            'ssl' => [in_array(config('database.connections.'.config('database.default').'.sslmode'), ['verify-full', 'verify-ca'], true), 'Verified database TLS required; document local socket exceptions separately.'],
            'app_timezone' => [config('app.timezone') === 'UTC', 'Application absolute-time standard is UTC.'],
            'schema_name' => [is_string(config('production.schema')) && preg_match('/^[a-z_][a-z0-9_]*$/D', config('production.schema')) === 1, 'Expected schema must be one safe fixed identifier.'],
            'cache' => [in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database', 'redis'], true), 'Shared persistent atomic-lock cache required.'],
            'session_driver' => [in_array(config('session.driver'), ['database', 'redis'], true), 'Persistent server-side sessions required.'],
            'secure_cookie' => [config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.'],
            'http_only' => [config('session.http_only') === true, 'Session cookie must be HttpOnly.'],
            'same_site' => [config('session.same_site') === 'lax', 'SameSite=lax preserves cross-site payment return.'],
            'session_domain' => [config('session.domain') === null || config('session.domain') === ($url['host'] ?? ''), 'Host-only or exact application cookie domain required.'],
            'queue' => [in_array($queue['driver'] ?? '', ['database', 'redis'], true), 'Asynchronous persistent queue required.'],
            'notification_queue' => [in_array($notification['driver'] ?? '', ['database', 'redis'], true), 'Notifications require a persistent asynchronous queue.'],
            'retry_after' => [($notification['retry_after'] ?? 0) > 45, 'Queue retry_after must exceed the 45-second notification timeout.'],
            'mail' => [config('mail.mailers.'.config('mail.default').'.transport') === 'smtp' && filled(config('mail.mailers.smtp.host')) && in_array(config('mail.mailers.smtp.scheme'), ['smtp', 'smtps'], true) && config('mail.mailers.smtp.timeout') > 0 && config('mail.mailers.smtp.timeout') < 45 && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false && ! str_ends_with((string) config('mail.from.address'), '@example.com'), 'Explicit real SMTP/from/timeout required; external delivery is not proven.'],
            'gateway' => [config('commerce.gateway') === 'external' && ! config('commerce.development_enabled'), 'External gateway only; development switch must be off.'],
            'csp' => [in_array(config('production.csp_mode'), ['enforced', 'report-only'], true), 'CSP_MODE must be enforced or report-only.'],
            'logging' => [! in_array(config('logging.default'), ['null'], true), 'Durable operational logging required.'],
        ];
        foreach (config('production.trusted_proxies', []) as $index => $proxy) {
            $rules['proxy_'.$index] = [TrustedProxies::valid($proxy), 'Trust only explicit bounded proxy IP/CIDR ranges; never wildcard or all-address ranges.'];
        }
        foreach (config('production.image_origins', []) as $index => $origin) {
            $rules['image_origin_'.$index] = [preg_match('#^https://[a-z0-9.-]+(?::[0-9]+)?$#iD', $origin) === 1, 'CSP image origins must be explicit HTTPS origins.'];
        }
        foreach (['stripe', 'razorpay'] as $provider) {
            if (config('commerce.'.$provider.'.enabled')) {
                $rules[$provider.'_mode'] = [config('commerce.'.$provider.'.mode') === 'live', 'Production provider must be live.'];
                $rules[$provider.'_webhook'] = [filled(config('commerce.'.$provider.'.webhook_secret')), 'Required webhook secret missing.'];
            }
        }

        return array_map(fn ($r) => ['status' => $r[0] ? 'PASS' : 'FAIL', 'message' => $r[1]], $rules);
    }

    /** @param array<string,array{status:string,message:string}> $checks */
    private function storageCheck(array &$checks, string $disk): void
    {
        $c = config('filesystems.disks.'.$disk, []);
        $valid = ($c['driver'] ?? '') === 's3' && filled($c['bucket'] ?? null) && filled($c['region'] ?? null) && filled($c['key'] ?? null) && filled($c['secret'] ?? null) && (! isset($c['endpoint']) || str_starts_with($c['endpoint'], 'https://'));
        $checks['storage_'.$disk] = ['status' => $valid ? 'PASS' : 'FAIL', 'message' => 'Configured S3-compatible production disk required; remote policy/bytes/backup must be externally verified.'];
    }
}
