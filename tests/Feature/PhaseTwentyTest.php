<?php

use App\Http\Middleware\ProductionSurfaceLimits;
use App\Http\Middleware\ResponseSecurity;
use App\Http\Middleware\TrustedProxies;
use App\Services\Operations\ProductionReadiness;
use App\Services\Operations\Readiness;
use App\Services\Products\Files\PrivateArtifactStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function p20Production(): void
{
    app()->instance('env', 'production');
    config()->set(['app.debug' => false, 'app.url' => 'https://marketplace.example.org', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'app.timezone' => 'UTC', 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.domain' => null, 'cache.default' => 'database', 'session.driver' => 'database', 'queue.default' => 'database', 'notifications.connection' => 'database', 'commerce.gateway' => 'external', 'commerce.development_enabled' => false, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org', 'mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.timeout' => 30, 'mail.from.address' => 'support@example.org', 'production.trusted_proxies' => [], 'production.image_origins' => [], 'production.csp_mode' => 'enforced']);
}

it('provides a read-only readiness command with blocking exit status', function () {
    $before = DB::table('orders')->count();
    Http::preventStrayRequests();
    $this->artisan('marketplace:production-check')->assertExitCode(1);
    expect(DB::table('orders')->count())->toBe($before);
    Http::assertNothingSent();
});

it('rejects each unsafe production configuration explicitly', function ($key, $value, $check) {
    p20Production();
    $original = config('database.default');
    config()->set($key, $value);
    $result = app(ProductionReadiness::class)->configuration();
    config()->set('database.default', $original);
    expect($result[$check]['status'])->toBe('FAIL');
    expect(json_encode($result))->not->toContain('synthetic-sensitive-marker');
})->with([
    'debug' => ['app.debug', true, 'debug'],
    'http origin' => ['app.url', 'http://example.org', 'url'],
    'localhost origin' => ['app.url', 'https://localhost', 'url'],
    'URL userinfo' => ['app.url', 'https://synthetic-sensitive-marker@example.org', 'url'],
    'URL query' => ['app.url', 'https://example.org?token=synthetic-sensitive-marker', 'url'],
    'URL fragment' => ['app.url', 'https://example.org#fragment', 'url'],
    'URL subpath' => ['app.url', 'https://example.org/app', 'url'],
    'missing key' => ['app.key', '', 'key'],
    'invalid key' => ['app.key', 'synthetic-sensitive-marker', 'key'],
    'invalid base64 key' => ['app.key', 'base64:??', 'key'],
    'mysql' => ['database.default', 'mysql', 'driver'],
    'sqlite' => ['database.default', 'sqlite', 'driver'],
    'unverified TLS' => ['database.connections.sqlite.sslmode', 'prefer', 'ssl'],
    'non UTC app' => ['app.timezone', 'Asia/Kolkata', 'app_timezone'],
    'unsafe schema' => ['production.schema', 'public, attacker', 'schema_name'],
    'cache process memory' => ['cache.default', 'array', 'cache'],
    'cache local files' => ['cache.default', 'file', 'cache'],
    'client sessions' => ['session.driver', 'cookie', 'session_driver'],
    'volatile sessions' => ['session.driver', 'array', 'session_driver'],
    'insecure cookie' => ['session.secure', false, 'secure_cookie'],
    'script session access' => ['session.http_only', false, 'http_only'],
    'cross site cookie' => ['session.same_site', 'none', 'same_site'],
    'strict return cookie' => ['session.same_site', 'strict', 'same_site'],
    'wide cookie domain' => ['session.domain', '.example.org', 'session_domain'],
    'sync jobs' => ['queue.default', 'sync', 'queue'],
    'sync notification' => ['notifications.connection', 'sync', 'notification_queue'],
    'unsafe retry timeout' => ['queue.connections.database.retry_after', 45, 'retry_after'],
    'fake mail' => ['mail.default', 'log', 'mail'],
    'missing SMTP' => ['mail.mailers.smtp.host', '', 'mail'],
    'unbounded SMTP' => ['mail.mailers.smtp.timeout', 0, 'mail'],
    'SMTP outlives worker' => ['mail.mailers.smtp.timeout', 60, 'mail'],
    'placeholder sender' => ['mail.from.address', 'hello@example.com', 'mail'],
    'development gateway' => ['commerce.gateway', 'development', 'gateway'],
    'development switch' => ['commerce.development_enabled', true, 'gateway'],
    'invalid CSP mode' => ['production.csp_mode', 'disabled', 'csp'],
    'null logging' => ['logging.default', 'null', 'logging'],
    'wildcard proxy' => ['production.trusted_proxies', ['*'], 'proxy_0'],
    'proxy hostname' => ['production.trusted_proxies', ['proxy.example.org'], 'proxy_0'],
    'wildcard CSP image' => ['production.image_origins', ['https://*'], 'image_origin_0'],
    'unsafe image scheme' => ['production.image_origins', ['http://images.example.org'], 'image_origin_0'],
]);

it('requires production environment', function () {
    expect(app(ProductionReadiness::class)->configuration()['environment']['status'])->toBe('FAIL');
});

it('detects enabled provider mode and missing webhook configuration', function ($provider, $mode, $secret, $check) {
    p20Production();
    config()->set(['commerce.'.$provider.'.enabled' => true, 'commerce.'.$provider.'.mode' => $mode, 'commerce.'.$provider.'.webhook_secret' => $secret]);
    expect(app(ProductionReadiness::class)->configuration()[$provider.'_'.$check]['status'])->toBe('FAIL');
})->with([
    ['stripe', 'test', 'synthetic', 'mode'], ['razorpay', 'test', 'synthetic', 'mode'],
    ['stripe', 'live', null, 'webhook'], ['razorpay', 'live', null, 'webhook'],
]);

it('accepts explicit proxy and image origins without broadening policy', function () {
    p20Production();
    config()->set(['production.trusted_proxies' => ['10.0.0.1', '192.168.0.0/16'], 'production.image_origins' => ['https://images.example.org']]);
    $checks = app(ProductionReadiness::class)->configuration();
    expect($checks['proxy_0']['status'])->toBe('PASS')->and($checks['proxy_1']['status'])->toBe('PASS')->and($checks['image_origin_0']['status'])->toBe('PASS');
});

it('keeps readiness response minimal and read-only', function () {
    Http::preventStrayRequests();
    $before = DB::table('payments')->count();
    $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    expect(DB::table('payments')->count())->toBe($before);
    Http::assertNothingSent();
});

it('returns safe readiness failure without dependency details', function () {
    $manager = DB::getFacadeRoot();
    try {
        DB::shouldReceive('select')->andThrow(new RuntimeException('synthetic-sensitive-marker'));
        DB::shouldReceive('connection')->andThrow(new RuntimeException('synthetic-sensitive-marker'));
        DB::shouldReceive('purge');
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable'])->assertHeader('Cache-Control', 'no-store, private');
    } finally {
        DB::swap($manager);
    }
});

it('keeps liveness cheap and anonymous', function () {
    $this->get('/up')->assertOk()->assertDontSee('password');
});

it('does not require Redis when database infrastructure is chosen', function () {
    config()->set(['cache.default' => 'database', 'session.driver' => 'database', 'queue.default' => 'database', 'notifications.connection' => 'database']);
    expect(app(Readiness::class)->redisConnections())->toBe([]);
});

it('probes only configured Redis connections', function () {
    config()->set(['cache.default' => 'redis', 'session.driver' => 'redis', 'queue.default' => 'redis', 'notifications.connection' => 'redis']);
    expect(app(Readiness::class)->redisConnections())->toBe(['cache', 'default']);
});

it('returns security headers and generated correlation identifiers', function () {
    $a = $this->withHeader('X-Request-ID', 'synthetic-sensitive-marker')->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $b = $this->get('/up');
    expect(Str::isUuid($a->headers->get('X-Request-ID')))->toBeTrue()->and($a->headers->get('X-Request-ID'))->not->toBe($b->headers->get('X-Request-ID'));
});

it('sets HSTS only for production HTTPS', function ($production, $secure, $expected) {
    if ($production) {
        p20Production();
    }
    $request = Request::create(($secure ? 'https' : 'http').'://example.org/up');
    $response = ResponseSecurity::apply($request, response('safe'));
    expect($response->headers->has('Strict-Transport-Security'))->toBe($expected);
})->with([[false, false, false], [false, true, false], [true, false, false], [true, true, true]]);

it('serves production CSP with no wildcard scripts or eval', function () {
    p20Production();
    $result = $this->get('/up')->assertOk();
    $csp = $result->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self' 'nonce-", 'https://checkout.razorpay.com', "frame-ancestors 'none'")->not->toContain('unsafe-eval', 'script-src *');
    expect(explode(';', substr($csp, strpos($csp, 'script-src')))[0])->not->toContain('unsafe-inline');
});

it('supports explicit report-only rollout', function () {
    p20Production();
    config()->set('production.csp_mode', 'report-only');
    $this->get('/up')->assertHeader('Content-Security-Policy-Report-Only')->assertHeaderMissing('Content-Security-Policy');
});

it('supports public CDN images without arbitrary schemes', function () {
    p20Production();
    config()->set('production.image_origins', ['https://images.example.org', 'javascript:bad', 'https://*']);
    $csp = $this->get('/up')->headers->get('Content-Security-Policy');
    expect($csp)->toContain('https://images.example.org')->not->toContain('javascript:', 'https://*');
});

it('trusts only explicit remote proxies and never forwarded hosts', function ($remote, $trusted, $secure) {
    config()->set('production.trusted_proxies', $trusted);
    $request = Request::create('http://marketplace.example.org/up', server: ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'attacker.example']);
    (new TrustedProxies)->handle($request, fn ($r) => response('safe'));
    expect($request->isSecure())->toBe($secure)->and($request->getHost())->toBe('marketplace.example.org');
})->with([['10.0.0.1', [], false], ['10.0.0.1', ['10.0.0.1'], true], ['10.0.0.2', ['10.0.0.1'], false], ['10.0.0.1', ['*'], false]]);

it('classifies sensitive production rate limits separately', function ($method, $path, $name, $limit) {
    $surface = ProductionSurfaceLimits::surface(Request::create('/'.$path, $method));
    expect($surface[0])->toBe($name)->and($surface[1])->toBe($limit);
})->with([['POST', 'register', 'registration', 10], ['POST', 'forgot-password', 'password-reset', 5], ['POST', 'reset-password', 'password-reset', 5], ['POST', 'checkout', 'checkout', 10], ['POST', 'account/orders/x/retry', 'checkout', 10], ['GET', 'admin/reports/sales/export', 'export', 10], ['PUT', 'admin/settings/refunds', 'admin-mutations', 60], ['POST', 'author/onboarding/submit', 'author-application', 10]]);

it('does not blanket throttle storefront or provider webhooks', function ($path) {
    expect(ProductionSurfaceLimits::surface(Request::create('/'.$path, 'POST')))->toBeNull();
})->with(['webhooks/stripe', 'webhooks/razorpay', 'products']);

it('hides production exception contents even if debug was misconfigured', function ($status) {
    p20Production();
    config()->set('app.debug', true);
    Route::get('/verification-only-error', fn () => abort($status, 'synthetic-sensitive-marker'));
    $this->getJson('/verification-only-error')->assertStatus($status)->assertDontSee('synthetic-sensitive-marker')->assertHeader('X-Request-ID');
})->with([500, 503]);

it('retains meaningful safe client errors', function ($status) {
    p20Production();
    Route::get('/verification-only-error', fn () => abort($status));
    $this->getJson('/verification-only-error')->assertStatus($status)->assertHeader('X-Request-ID')->assertDontSee('vendor/');
})->with([403, 404, 419, 422]);

it('records safe request context and clears it across requests', function () {
    Log::spy();
    Route::get('/verification-only-log', function () {
        Log::info('Safe operation');

        return response('ok');
    });
    $result = $this->get('/verification-only-log');
    Log::shouldHaveReceived('withContext')->with(['request_id' => $result->headers->get('X-Request-ID')])->once();
    Log::shouldHaveReceived('withoutContext')->with(['request_id'])->once();
});

it('retains private storage guard against public exposure', function ($key, $value) {
    config()->set('filesystems.disks.product_private.'.$key, $value);
    expect(fn () => app(PrivateArtifactStorage::class)->assertSecure('product_private'))->toThrow(ValidationException::class);
})->with([['visibility', 'public'], ['serve', true], ['url', 'https://files.example.org'], ['root', getcwd().'/public/unsafe']]);

it('detects production build versus hot assets', function () {
    p20Production();
    Vite::useHotFile('/tmp/nonexistent-phase20-hot');
    expect(app(ProductionReadiness::class)->checks()['manifest']['status'])->toBe(is_file(public_path('build/manifest.json')) ? 'PASS' : 'FAIL');
});

it('rejects unbounded or malformed proxy networks', function ($proxy) {
    expect(TrustedProxies::valid($proxy))->toBeFalse();
})->with(['0.0.0.0/0', '::/0', '10.0.0.1/33', '::1/129', '10.0.0.1/bad', '10.0.0.1/24/24']);
