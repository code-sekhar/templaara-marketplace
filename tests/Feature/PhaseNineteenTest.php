<?php

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\Commerce\OrderPaid;
use App\Models\AuthorEarning;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Commerce\ExternalPayments;
use App\Services\Commerce\Gateways\MinorUnits;
use App\Services\Commerce\Gateways\ProviderUnavailable;
use App\Services\Commerce\Gateways\StripeGateway;
use App\Services\Commerce\PaymentGatewayManager;
use App\Services\Commerce\PaymentLifecycle;
use App\Services\Earnings\EarningProvisioner;
use App\Services\Entitlements\EntitlementProvisioner;
use App\Services\Notifications\NotificationRecovery;
use App\Services\Settings\UpdateMarketplaceSettings;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ExternalPaymentFixtures as F;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    ProductFileFixtures::fakeStorage();
    F::configure();
});
function n19Webhook($test, Payment $payment, array $changes = [], ?string $id = null, ?string $type = null)
{
    [$body,$headers] = F::event($payment, $changes, $id, $type);

    return $test->call('POST', '/webhooks/'.$payment->provider, [], [], [], array_combine(array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)), array_values($headers)), $body);
}
test('minor units preserve exact supported currency amounts', function (string $currency, string $amount, int $minor) {
    expect(MinorUnits::fromDecimal($amount, $currency))->toBe($minor)->and(MinorUnits::toDecimal($minor, $currency))->toBe($amount);
})->with([
    ['INR', '100.00', 10000], ['USD', '10.01', 1001], ['EUR', '0.00', 0], ['GBP', '999999999999.99', 99999999999999], ['INR', '0.01', 1], ['USD', '999999999999.99', 99999999999999], ['EUR', '17.20', 1720], ['GBP', '1.99', 199],
]);
test('minor units reject unsupported amounts without rounding', function (string $amount, string $currency) {
    expect(fn () => MinorUnits::fromDecimal($amount, $currency))->toThrow(InvalidArgumentException::class);
})->with([['-1.00', 'INR'], ['1.001', 'USD'], ['1e2', 'EUR'], ['NaN', 'GBP'], ['1.00', 'JPY'], ['1.00', 'usd'], ['1000000000000.00', 'INR'], ['+1.00', 'USD']]);
test('provider amounts reject malformed types', function ($amount) {
    expect(fn () => MinorUnits::toDecimal($amount, 'USD'))->toThrow(InvalidArgumentException::class);
})->with(['100', 100.1, -1, null, true, 100000000000000]);
test('currency selects deliberate available adapter', function (string $currency, string $provider) {
    $manager = app(PaymentGatewayManager::class);
    expect($manager->availableFor($currency))->toBeTrue()->and($manager->external($provider)->available())->toBeTrue();
})->with([['INR', 'razorpay'], ['USD', 'stripe'], ['EUR', 'stripe'], ['GBP', 'stripe']]);
test('missing provider configuration is unavailable', function (string $key) {
    config()->set('commerce.'.$key, null);
    $provider = str_starts_with($key, 'stripe') ? 'stripe' : 'razorpay';
    expect(app(PaymentGatewayManager::class)->external($provider)->available())->toBeFalse();
})->with(['stripe.secret_key', 'stripe.public_key', 'stripe.webhook_secret', 'stripe.enabled', 'stripe.mode', 'razorpay.key_id', 'razorpay.key_secret', 'razorpay.webhook_secret', 'razorpay.enabled', 'razorpay.mode']);
test('test provider keys cannot become production availability', function (string $provider) {
    app()->instance('env', 'production');
    expect(app(PaymentGatewayManager::class)->external($provider)->available())->toBeFalse();
})->with(['stripe', 'razorpay']);
test('disabled and unknown gateways never fall back', function (string $mode) {
    config()->set('commerce.gateway', $mode);
    expect(app(PaymentGateway::class)->available())->toBeFalse();
})->with(['disabled', 'unknown']);
test('production startup rejects development payments', function () {
    app()->instance('env', 'production');
    config()->set('commerce.gateway', 'development');
    expect(fn () => app()->getProvider(AppServiceProvider::class)->boot())->toThrow(RuntimeException::class, 'Development payments cannot run in production.');
});
test('creation reserves identity before provider IO', function (string $provider) {
    $o = F::order($provider, false);
    Http::assertNothingSent();
    $p = $o->payments->first();
    expect($p->provider_payment_id)->toBe('attempt-'.$p->idempotency_key)->and($p->checkout->state)->toBe('reserved');
    app(ExternalPayments::class)->prepare($p->checkout);
    expect($p->checkout->fresh()->state)->toBe('ready');
    Http::assertSentCount(1);
})->with(['stripe', 'razorpay']);
test('creation is server derived and sends no private economics or billing', function (string $provider) {
    $o = F::order($provider);
    $p = $o->payments->first();
    Http::assertSent(function ($r) use ($p, $provider) {
        $data = $r->data();
        $text = json_encode($data);
        expect($text)->not->toContain('billing', 'license', 'path', 'email', 'commission', 'secret');
        if ($provider === 'stripe') {
            expect($r->header('Idempotency-Key'))->toBe(['templaara-'.$p->idempotency_key]);
            expect($data['line_items'][0]['price_data']['unit_amount'])->toBe(500000);
        } else {
            expect($data['amount'])->toBe(500000)->and($data['receipt'])->toBe($p->idempotency_key);
            expect($r->hasHeader('Idempotency-Key'))->toBeFalse();
        }

        return true;
    });
})->with(['stripe', 'razorpay']);
test('ready checkout is not created again', function (string $provider) {
    $o = F::order($provider);
    app(ExternalPayments::class)->prepare(F::checkout($o));
    Http::assertSentCount(1);
})->with(['stripe', 'razorpay']);
test('signed external success enters existing paid boundary exactly once', function (string $provider) {
    $o = F::order($provider);
    $p = $o->payments->first();
    Event::fake([OrderPaid::class]);
    n19Webhook($this, $p)->assertOk();
    n19Webhook($this, $p)->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Paid)->and(PaymentEvent::count())->toBe(1)->and($p->fresh()->amount)->toBe('5000.00');
    Event::assertDispatchedTimes(OrderPaid::class, 1);
})->with(['stripe', 'razorpay']);
test('different provider event identities cannot duplicate settlement', function (string $provider) {
    $o = F::order($provider);
    $p = $o->payments->first();
    Event::fake([OrderPaid::class]);
    n19Webhook($this, $p, [], 'evt_first')->assertOk();
    n19Webhook($this, $p, [], 'evt_second')->assertOk();
    expect(PaymentEvent::count())->toBe(2)->and(PaymentEvent::latest('id')->first()->status)->toBe('ignored');
    Event::assertDispatchedTimes(OrderPaid::class, 1);
})->with(['stripe', 'razorpay']);
test('signed mismatched monetary evidence is quarantined without commercial success', function (string $provider, string $field) {
    $o = F::order($provider);
    $changes = $field === 'currency' ? ['currency' => 'EUR'] : [$provider === 'stripe' ? 'amount_total' : 'amount' => 1];
    n19Webhook($this, $o->payments->first(), $changes)->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending)->and(F::checkout($o)->problem_code)->toBe('evidence_mismatch')->and(PaymentEvent::count())->toBe(0);
})->with([['stripe', 'amount'], ['stripe', 'currency'], ['razorpay', 'amount'], ['razorpay', 'currency']]);
test('stripe internal metadata must match exactly', function (string $field) {
    $o = F::order();
    n19Webhook($this, $o->payments->first(), ['metadata' => [$field => 'forged']])->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending)->and(F::checkout($o)->problem_code)->toBe('evidence_mismatch');
})->with(['attempt_key', 'order_number']);
test('invalid raw signatures cannot settle', function (string $provider, string $signature) {
    $o = F::order($provider);
    [$body,$headers] = F::event($o->payments->first());
    $name = $provider === 'stripe' ? 'Stripe-Signature' : 'X-Razorpay-Signature';
    $headers[$name] = $signature;
    $this->call('POST', '/webhooks/'.$provider, [], [], [], array_combine(array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)), array_values($headers)), $body)->assertUnauthorized();
    expect(PaymentEvent::count())->toBe(0);
})->with([['stripe', ''], ['stripe', 'bad'], ['stripe', 't=1,v1='.str_repeat('a', 64)], ['razorpay', ''], ['razorpay', 'bad'], ['razorpay', str_repeat('a', 64)]]);
test('stripe raw body timestamp tolerance enforced', function (int $difference) {
    $body = '{}';
    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => F::stripeSignature($body, time() + $difference)], $body)->assertUnauthorized();
})->with([-301, 301, -3600]);
test('oversized webhook is rejected', function (string $provider) {
    $this->call('POST', '/webhooks/'.$provider, [], [], [], [], str_repeat('x', 65537))->assertStatus(413);
})->with(['stripe', 'razorpay']);
test('signed malformed JSON is rejected', function (string $provider) {
    $body = '{';
    $signature = $provider === 'stripe' ? F::stripeSignature($body) : hash_hmac('sha256', $body, 'fixture_webhook_secret');
    $this->call('POST', '/webhooks/'.$provider, [], [], [], [$provider === 'stripe' ? 'HTTP_STRIPE_SIGNATURE' : 'HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_bad'], $body)->assertBadRequest();
})->with(['stripe', 'razorpay']);
test('unsupported signed events are acknowledged without state changes', function (string $provider) {
    $o = F::order($provider);
    n19Webhook($this, $o->payments->first(), [], null, 'refund.created')->assertOk();
    expect(PaymentEvent::count())->toBe(0)->and($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with(['stripe', 'razorpay']);
test('stripe complete unpaid is not success', function () {
    $o = F::order();
    n19Webhook($this, $o->payments->first(), ['payment_status' => 'unpaid'])->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
});
test('stripe asynchronous success is supported', function () {
    $o = F::order();
    n19Webhook($this, $o->payments->first(), [], null, 'checkout.session.async_payment_succeeded')->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});
test('stripe expiry and async failure enable new attempt retaining old truth', function (string $type) {
    $o = F::order();
    $p = $o->payments->first();
    $changes = ['payment_status' => 'unpaid', 'status' => $type === 'checkout.session.expired' ? 'expired' : 'complete'];
    n19Webhook($this, $p, $changes, null, $type)->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Failed);
    app(PaymentLifecycle::class)->retry($o->user, $o);
    expect($o->payments()->count())->toBe(2)->and($p->fresh()->status)->toBe(PaymentStatus::Failed);
})->with(['checkout.session.expired', 'checkout.session.async_payment_failed']);
test('external late failed-attempt success is retained for recovery and never duplicates ledger', function () {
    $o = F::order('razorpay');
    $p = $o->payments->first();
    n19Webhook($this, $p, ['status' => 'failed', 'captured' => false], null, 'payment.failed')->assertOk();
    app(PaymentLifecycle::class)->retry($o->user, $o);
    n19Webhook($this, $p, [], 'evt_late')->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending)->and(F::checkout($o)->state)->toBe('reserved')->and($p->checkout->fresh()->problem_code)->toBe('late_success');
});
test('external pending checkout cannot be cancelled locally', function (string $provider) {
    $o = F::order($provider);
    $this->actingAs($o->user)->post(route('commerce.orders.cancel', $o))->assertSessionHasErrors('payment');
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with(['stripe', 'razorpay']);
test('status query strings are UX only and secrets never appear', function (string $provider) {
    $o = F::order($provider);
    $this->actingAs($o->user)->get(route('commerce.payments.status', $o).'?returned=success&status=paid')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->where('order.payment_status', 'pending')->missing('order.billing_email')->missing('order.payments')->where('handoff.provider', $provider));
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with(['stripe', 'razorpay']);
test('status and callback enforce exact owner regardless of admin permission', function (string $role) {
    $o = F::order('razorpay');
    $this->actingAs(User::factory()->create()->assignRole($role))->get(route('commerce.payments.status', $o))->assertForbidden();
    $this->post(route('commerce.payments.callback', $o), [])->assertForbidden();
})->with(['customer', 'author', 'admin']);
test('guest cannot enter status or callback', function () {
    $o = F::order();
    $this->get(route('commerce.payments.status', $o))->assertRedirect(route('login'));
    $this->post(route('commerce.payments.callback', $o))->assertRedirect(route('login'));
});
test('razorpay forged callback cannot reach provider', function () {
    $o = F::order('razorpay');
    F::fake();
    $p = $o->payments->first();
    $this->actingAs($o->user)->post(route('commerce.payments.callback', $o), ['razorpay_payment_id' => F::razor($p)['id'], 'razorpay_order_id' => F::razor($p)['order_id'], 'razorpay_signature' => str_repeat('a', 64)])->assertUnauthorized();
    Http::assertNothingSent();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
});
test('razorpay valid callback fetches authoritative captured state', function (string $state) {
    $o = F::order('razorpay');
    $p = $o->payments->first();
    $data = [...F::razor($p), 'status' => $state, 'captured' => $state === 'captured'];
    F::fake(['https://api.razorpay.com/v1/payments/*' => Http::response($data)]);
    $this->actingAs($o->user)->post(route('commerce.payments.callback', $o), ['razorpay_payment_id' => $data['id'], 'razorpay_order_id' => $data['order_id'], 'razorpay_signature' => hash_hmac('sha256', $data['order_id'].'|'.$data['id'], 'fixture_key_secret')])->assertRedirect();
    expect($o->fresh()->payment_status)->toBe($state === 'captured' ? PaymentStatus::Paid : PaymentStatus::Pending);
})->with(['captured', 'authorized']);
test('provider creation failures leave durable uncertain attempt without blind POST retry', function (string $provider) {
    $o = F::order($provider, false);
    F::fake(['*' => Http::response(['secret' => 'must not surface'], 500)]);
    app(ExternalPayments::class)->prepare(F::checkout($o));
    expect(F::checkout($o)->state)->toBe('uncertain')->and(F::checkout($o)->problem_code)->toBe('provider_unavailable')->and($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
    Http::assertSentCount(1);
})->with(['stripe', 'razorpay']);
test('razorpay ambiguous create recovers by receipt GET without another POST', function () {
    $o = F::order('razorpay', false);
    $c = F::checkout($o);
    $c->forceFill(['state' => 'uncertain'])->save();
    F::fake(['https://api.razorpay.com/v1/orders*' => Http::response(['items' => [F::razorOrder($c->payment)]])]);
    app(ExternalPayments::class)->prepare($c);
    expect($c->fresh()->state)->toBe('ready');
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r['receipt'] === $c->payment->idempotency_key);
    Http::assertSentCount(1);
});
test('ambiguous razorpay missing or multiple receipt matches require manual investigation', function (array $items) {
    $o = F::order('razorpay', false);
    $c = F::checkout($o);
    $c->forceFill(['state' => 'uncertain'])->save();
    F::fake(['*' => Http::response(['items' => $items])]);
    app(ExternalPayments::class)->prepare($c);
    expect($c->fresh()->state)->toBe('review')->and($c->fresh()->problem_code)->toBe('creation_unresolved');
    Http::assertSentCount(1);
})->with([[[]], [[[], []]]]);
test('stripe key recovery stops before key expiration', function () {
    $o = F::order('stripe', false);
    $c = F::checkout($o);
    DB::table('payment_checkouts')->where('id', $c->id)->update(['state' => 'uncertain', 'created_at' => now()->subHours(24)]);
    F::fake();
    app(ExternalPayments::class)->prepare($c->fresh());
    Http::assertNothingSent();
    expect($c->fresh()->state)->toBe('review');
});
test('reconciliation recovers missed signed delivery through the same settlement', function (string $provider) {
    $o = F::order($provider);
    $p = $o->payments->first();
    F::fake($provider === 'stripe' ? ['https://api.stripe.com/v1/checkout/sessions/*' => Http::response(F::stripe($p))] : ['https://api.razorpay.com/v1/orders/*/payments' => Http::response(['items' => [F::razor($p)]]), 'https://api.razorpay.com/v1/orders/*' => Http::response(F::razorOrder($p))]);
    DB::table('payment_checkouts')->where('payment_id', $p->id)->update(['available_at' => now()->subMinute()]);
    $result = app(ExternalPayments::class)->reconcile();
    expect($result)->toBe(['checked' => 1, 'errors' => 0])->and($o->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    $this->artisan('payments:reconcile')->assertSuccessful();
})->with(['stripe', 'razorpay']);
test('reconciliation excludes development and unavailable future work', function () {
    $o = F::order();
    expect(app(ExternalPayments::class)->reconcile())->toBe(['checked' => 0, 'errors' => 0]);
});
test('reconciliation failures back off and contain no provider payload', function () {
    $o = F::order();
    $c = F::checkout($o);
    $c->forceFill(['available_at' => now()->subMinute()])->save();
    F::fake(['*' => Http::response(['secret' => 'secretpayload'], 500)]);
    expect(app(ExternalPayments::class)->reconcile())->toBe(['checked' => 1, 'errors' => 1]);
    expect($c->fresh()->problem_code)->toBe('reconcile_unavailable')->and($c->fresh()->available_at->isFuture())->toBeTrue();
});
test('reconciliation command rejects invalid batches', function (string $limit) {
    $this->artisan('payments:reconcile', ['--limit' => $limit])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '501', 'x', '-1']);
test('provider object identities are write once', function () {
    $o = F::order();
    $c = F::checkout($o);
    expect(fn () => $c->forceFill(['provider_order_id' => 'cs_forged'])->save())->toThrow(LogicException::class);
});
test('postgres rejects raw provider identity rewrite', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$o = F::order();
    expect(fn () => DB::table('payment_checkouts')->where('id', F::checkout($o)->id)->update(['provider_order_id' => 'cs_forged']))->toThrow(QueryException::class);
});
test('provider settlement produces one access license earning and durable notification set', function (string $provider) {
    $o = F::order($provider);
    [$body,$headers] = F::event($o->payments->first());
    $gateway = app(PaymentGatewayManager::class)->external($provider);
    $evidence = $gateway->webhook($body, $headers[$provider === 'stripe' ? 'Stripe-Signature' : 'X-Razorpay-Signature'], $headers['X-Razorpay-Event-Id'] ?? '');
    app(ExternalPayments::class)->settle($evidence);
    app(ExternalPayments::class)->settle($evidence);
    app(EntitlementProvisioner::class)->provision($o->fresh());
    app(EarningProvisioner::class)->provision($o->fresh());
    expect(Entitlement::where('order_id', $o->id)->count())->toBe(1)->and(License::count())->toBe(1)->and(AuthorEarning::where('order_id', $o->id)->count())->toBe(1);
})->with(['stripe', 'razorpay']);

test('new recovery timestamps preserve absolute instants across database timezone', function () {
    $o = F::order('stripe', false);
    $c = F::checkout($o);
    $expected = now()->addMinutes(4);
    $c->forceFill(['claimed_at' => $expected, 'available_at' => $expected])->save();
    expect($c->fresh()->claimed_at->getTimestamp())->toBe($expected->getTimestamp())->and($c->fresh()->available_at->getTimestamp())->toBe($expected->getTimestamp());
});
test('creation lease prevents concurrent network creation before expiry', function () {
    $o = F::order('razorpay', false);
    $c = F::checkout($o);
    $c->forceFill(['state' => 'creating', 'claimed_at' => now(), 'claim_token' => (string) Str::uuid()])->save();
    F::fake();
    app(ExternalPayments::class)->prepare($c);
    Http::assertNothingSent();
});
test('customer safe projection excludes operational identity but authorized admin sees bounded references', function () {
    $o = F::order();
    $this->actingAs($o->user)->get(route('commerce.orders.show', $o))->assertInertia(fn (Assert $p) => $p->where('order.payments.0.provider_name', 'Stripe')->missing('order.payments.0.provider_payment_id')->missing('order.payments.0.external'));
    $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('admin.orders.show', $o))->assertInertia(fn (Assert $p) => $p->where('order.payments.0.external.state', 'ready')->missing('order.payments.0.external.checkout_url')->missing('order.payments.0.external.claim_token'));
});
test('signed reused event identity cannot change already recorded evidence', function () {
    $o = F::order();
    $p = $o->payments->first();
    n19Webhook($this, $p)->assertOk();
    n19Webhook($this, $p, ['url' => 'https://checkout.stripe.com/c/pay/changed'])->assertStatus(503);
    expect(PaymentEvent::count())->toBe(1)->and($o->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});
test('missing razorpay event identity is rejected after signature verification', function () {
    $o = F::order('razorpay');
    [$body,$headers] = F::event($o->payments->first());
    $this->call('POST', '/webhooks/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature']], $body)->assertBadRequest();
});
test('razorpay callback cannot forge financial and actor fields', function (string $field) {
    $o = F::order('razorpay');
    $p = $o->payments->first();
    $d = F::razor($p);
    $this->actingAs($o->user)->post(route('commerce.payments.callback', $o), ['razorpay_payment_id' => $d['id'], 'razorpay_order_id' => $d['order_id'], 'razorpay_signature' => hash_hmac('sha256', $d['order_id'].'|'.$d['id'], 'fixture_key_secret'), $field => 'forged'])->assertSessionHasErrors($field);
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with(['amount', 'currency', 'status', 'provider', 'user_id', 'order_id']);
test('real provider settlements qualify provider neutral analytics', function (string $provider) {
    $o = F::order($provider);
    n19Webhook($this, $o->payments->first())->assertOk();
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin)->get('/admin/reports/sales?period=90days')->assertInertia(fn (Assert $p) => $p->where('rows.total', 1)->where('rows.data.0.currency', $o->currency->value)->where('rows.data.0.gross', '5000.00'));
})->with(['stripe', 'razorpay']);

test('operational leases and eligibility use exact instants under PostgreSQL offsets', function (string $zone) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL session timezone semantics');
    }
    expect(config('app.timezone'))->toBe('UTC');
    DB::statement('SET LOCAL TIME ZONE '.DB::connection()->getPdo()->quote($zone));
    $o = F::order('razorpay', false);
    $c = F::checkout($o);
    $instant = now();
    expect($c->created_at->getTimestamp())->toBe($instant->getTimestamp());
    $c->forceFill(['state' => 'creating', 'claim_token' => (string) Str::uuid(), 'claimed_at' => $instant, 'available_at' => $instant->addMinutes(10)])->save();
    F::fake();
    app(ExternalPayments::class)->prepare($c);
    Http::assertNothingSent();
    expect(app(ExternalPayments::class)->reconcile())->toBe(['checked' => 0, 'errors' => 0]);
    $c->forceFill(['claimed_at' => $instant->subMinutes(4), 'available_at' => $instant->subMinute()])->save();
    F::fake(['*' => Http::response(['items' => [F::razorOrder($c->payment)]])]);
    app(ExternalPayments::class)->prepare($c->fresh());
    expect($c->fresh()->state)->toBe('ready')->and($c->fresh()->available_at->getTimestamp())->toBe(now()->addMinute()->getTimestamp());
})->with(['Asia/Kolkata', 'America/New_York']);

test('lost Stripe create response recovers same idempotent provider session', function () {
    $o = F::order('stripe', false);
    $c = F::checkout($o);
    $saved = null;
    $key = null;
    $calls = 0;
    F::fake(function ($r) use (&$saved, &$key, &$calls, $c) {
        $calls++;
        if ($calls === 1) {
            $saved = $r->data();
            $key = $r->header('Idempotency-Key');
            throw new ConnectionException('Simulated lost response');
        }expect($r->data())->toBe($saved)->and($r->header('Idempotency-Key'))->toBe($key);

        return Http::response([...F::stripe($c->payment), 'status' => 'open', 'payment_status' => 'unpaid']);
    });
    app(ExternalPayments::class)->prepare($c);
    expect($c->fresh()->state)->toBe('uncertain');
    app(ExternalPayments::class)->prepare($c->fresh());
    expect($c->fresh()->state)->toBe('ready')->and($calls)->toBe(2)->and(Order::count())->toBe(1)->and($o->items()->count())->toBe(1);
    n19Webhook($this, $c->payment)->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('lost Razorpay response recovers receipt without another POST or order', function () {
    $o = F::order('razorpay', false);
    $c = F::checkout($o);
    $remote = null;
    $posts = 0;
    F::fake(function ($r) use (&$remote, &$posts, $c) {
        if ($r->method() === 'POST') {
            $posts++;
            $remote = F::razorOrder($c->payment);
            throw new ConnectionException('Simulated lost response');
        }expect($r['receipt'])->toBe($c->payment->idempotency_key);

        return Http::response(['items' => [$remote]]);
    });
    app(ExternalPayments::class)->prepare($c);
    expect($c->fresh()->state)->toBe('uncertain');
    app(ExternalPayments::class)->prepare($c->fresh());
    expect($c->fresh()->provider_order_id)->toBe($remote['id'])->and($posts)->toBe(1)->and(Order::count())->toBe(1);
    n19Webhook($this, $c->payment)->assertOk();
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('payment provider references cannot be substituted', function (string $provider) {
    $o = F::order($provider);
    $change = $provider === 'stripe' ? ['id' => 'cs_unknown'] : ['order_id' => 'order_unknown'];
    n19Webhook($this, $o->payments->first(), $change)->assertStatus(503);
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending)->and(PaymentEvent::count())->toBe(0);
})->with(['stripe', 'razorpay']);

test('contradictory Stripe failure events cannot represent paid success', function (string $type) {
    $o = F::order();
    n19Webhook($this, $o->payments->first(), [], null, $type)->assertStatus(503);
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with(['checkout.session.expired', 'checkout.session.async_payment_failed']);

test('Phase 13 manual refund retains original real provider paid history', function (string $provider) {
    $o = F::order($provider);
    n19Webhook($this, $o->payments->first())->assertOk();
    app(EntitlementProvisioner::class)->provision($o->fresh());
    app(EarningProvisioner::class)->provision($o->fresh());
    $p = $o->payments->first()->fresh();
    $before = $p->getAttributes();
    $r = RefundFixtures::request($o->fresh());
    RefundFixtures::complete($r);
    expect($r->fresh()->status->value)->toBe('completed')->and($p->fresh()->getAttributes())->toBe($before)->and($o->fresh()->payment_status)->toBe(PaymentStatus::Paid)->and(Entitlement::where('order_id', $o->id)->first()->status->value)->toBe('revoked');
    Http::assertSentCount(1); // Only initial checkout creation; refund does not call provider API.
})->with(['stripe', 'razorpay']);

test('existing in flight currency remains settleable after new commerce currency disable', function () {
    $o = F::order();
    $admin = User::factory()->create()->assignRole('admin');
    app(UpdateMarketplaceSettings::class)->save($admin, 'commerce', ['values' => ['enabled_currencies' => ['INR']], 'versions' => ['enabled_currencies' => 0]]);
    n19Webhook($this, $o->payments->first())->assertOk();
    expect($o->fresh()->currency->value)->toBe('USD')->and($o->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('customer purchase notice is recovered once for repeated external settlement', function (string $provider) {
    config()->set('notifications.started_at', '2000-01-01T00:00:00+00:00');
    $o = F::order($provider);
    n19Webhook($this, $o->payments->first())->assertOk();
    app(EntitlementProvisioner::class)->provision($o->fresh());
    app(EarningProvisioner::class)->provision($o->fresh());
    $recovery = app(NotificationRecovery::class);
    $recovery->run('orders');
    n19Webhook($this, $o->payments->first())->assertOk();
    $recovery->run('orders');
    expect(DB::table('notification_deliveries')->where('source', 'orders')->where('source_id', $o->id)->where('user_id', $o->user_id)->count())->toBe(1);
})->with(['stripe', 'razorpay']);

test('Stripe retrieval resolves missed asynchronous outcome without trusting browser return', function (string $state) {
    $o = F::order();
    $p = $o->payments->first();
    $data = F::stripe($p);
    $data['payment_status'] = 'unpaid';
    $intent = ['id' => $data['payment_intent'], 'currency' => 'usd', 'amount' => 500000, 'amount_received' => $state === 'succeeded' ? 500000 : 0, 'status' => $state, 'last_payment_error' => $state === 'requires_payment_method' ? ['code' => 'payment_failed'] : null];
    F::fake(['https://api.stripe.com/v1/checkout/sessions/*' => Http::response($data), 'https://api.stripe.com/v1/payment_intents/*' => Http::response($intent)]);
    $result = app(StripeGateway::class)->fetch($p->checkout);
    app(ExternalPayments::class)->settle($result);
    expect($o->fresh()->payment_status)->toBe(match ($state) {
        'succeeded' => PaymentStatus::Paid, 'processing' => PaymentStatus::Pending, default => PaymentStatus::Failed
    });
    Http::assertSentCount(2);
})->with(['succeeded', 'requires_payment_method', 'canceled', 'processing']);

test('Stripe retrieved PaymentIntent must match the bound session economics', function () {
    $o = F::order();
    $p = $o->payments->first();
    $data = F::stripe($p);
    $data['payment_status'] = 'unpaid';
    F::fake(['https://api.stripe.com/v1/checkout/sessions/*' => Http::response($data), 'https://api.stripe.com/v1/payment_intents/*' => Http::response(['id' => $data['payment_intent'], 'currency' => 'usd', 'amount' => 1, 'amount_received' => 1, 'status' => 'succeeded'])]);
    expect(fn () => app(StripeGateway::class)->fetch($p->checkout))->toThrow(ProviderUnavailable::class);
    expect($o->fresh()->payment_status)->toBe(PaymentStatus::Pending);
});
