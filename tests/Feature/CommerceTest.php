<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Commerce\OrderPaid;
use App\Events\Commerce\OrderPlaced;
use App\Events\Commerce\PaymentFailed;
use App\Events\Commerce\PaymentSucceeded;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductArtifact;
use App\Models\ProductOffer;
use App\Models\ProductRelease;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Commerce\DevelopmentGateway;
use App\Services\Commerce\Money;
use App\Services\Commerce\PaymentLifecycle;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommerceFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
});
function commerceBuyer(): User
{
    return User::factory()->create()->assignRole('customer');
}
function commerceOrder(?User $user = null): Order
{
    $user ??= commerceBuyer();
    $product = CommerceFixtures::product();
    app(CartService::class)->add($user, $product->slug, $product->offers->first()->id);

    return app(Checkout::class)->place($user, Cart::where('user_id', $user->id)->first()->checkout_key, CommerceFixtures::billing());
}
function commerceSimulate(Order $order, string $outcome = 'paid'): Order
{
    $payment = $order->payments()->latest('id')->firstOrFail();

    return app(PaymentLifecycle::class)->process(app(DevelopmentGateway::class)->simulate($payment, $outcome));
}
function commerceWebhook(Payment $payment, array $overrides = []): array
{
    $payload = json_encode(['event_id' => 'event-'.$payment->idempotency_key, 'reference' => $payment->provider_payment_id,
        'order_reference' => $payment->provider_order_id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'outcome' => 'paid', ...$overrides], JSON_THROW_ON_ERROR);

    return [$payload, app(DevelopmentGateway::class)->signature($payload)];
}

test('commerce uses existing authentication and guests still browse', function (string $path) {
    $this->get('/products')->assertOk();
    $this->get($path)->assertRedirect(route('login'));
})->with(['/cart', '/checkout', '/account/orders']);

test('any authenticated multirole user can select a visible active package', function (array $roles) {
    $buyer = User::factory()->create()->assignRole($roles);
    $product = CommerceFixtures::product();
    $this->actingAs($buyer)->post(route('commerce.cart.store'), ['product_slug' => $product->slug, 'offer_id' => $product->offers->first()->id])->assertRedirect(route('commerce.cart.index'));
    $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('cart.total', '4999.00')->has('cart.items', 1)->where('cart.valid', true)->where('cartCount', 1));
})->with([[['customer']], [['customer', 'author']], [['admin']], [['reviewer']]]);

test('cart rejects unavailable software and invalid offer membership', function (string $problem) {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    $offer = $product->offers->first();
    match ($problem) {
        'draft' => DB::table('products')->where('id', $product->id)->update(['status' => 'draft']),
        'inactive' => $product->update(['is_active' => false]),'category' => $product->category->update(['is_active' => false]),
        'offer' => $offer->update(['is_active' => false, 'is_default' => false]),
        'membership' => $offer = ProductOffer::factory()->create(),
        'cover' => $product->cover()->update(['is_current' => false]),
        'release' => $product->releases()->first()->artifacts()->update(['is_current' => false])
    };
    $this->actingAs($buyer)->post('/cart/items', ['product_slug' => $product->slug, 'offer_id' => $offer->id])->assertSessionHasErrors('cart');
    expect(CartItem::count())->toBe(0);
})->with(['draft', 'inactive', 'category', 'offer', 'membership', 'cover', 'release']);

test('cart rejects forged commerce and ownership fields', function (string $field) {
    $product = CommerceFixtures::product();
    $this->actingAs(commerceBuyer())->post('/cart/items', ['product_slug' => $product->slug, 'offer_id' => $product->offers->first()->id, $field => '999'])->assertSessionHasErrors($field);
    expect(CartItem::count())->toBe(0);
})->with(['price', 'currency', 'quantity', 'user_id', 'owner_id', 'status', 'license_type', 'support_period_days', 'updates_period_days']);

test('cart has one selection per product and switches package instead of duplicating', function () {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    $offer = ProductOffer::factory()->additional()->for($product)->create();
    app(CartService::class)->add($buyer, $product->slug, $product->offers()->first()->id);
    app(CartService::class)->add($buyer, $product->slug, $offer->id);
    expect(CartItem::count())->toBe(1)->and(CartItem::first()->product_offer_id)->toBe($offer->id);
    $this->actingAs($buyer)->get('/cart')->assertInertia(fn (Assert $p) => $p->where('cart.total', '8999.00'));
});

test('mixed currencies are rejected and empty cart permits a new currency', function () {
    $buyer = commerceBuyer();
    $inr = CommerceFixtures::product();
    $usd = CommerceFixtures::product(['currency' => 'USD']);
    app(CartService::class)->add($buyer, $inr->slug, $inr->offers->first()->id);
    $this->actingAs($buyer)->post('/cart/items', ['product_slug' => $usd->slug, 'offer_id' => $usd->offers->first()->id])->assertSessionHasErrors('cart');
    $this->delete(route('commerce.cart.destroy', CartItem::first()->id))->assertRedirect();
    $this->post('/cart/items', ['product_slug' => $usd->slug, 'offer_id' => $usd->offers->first()->id])->assertRedirect();
    expect(Cart::where('user_id', $buyer->id)->first()->currency->value)->toBe('USD');
});

test('customers cannot remove another users cart selection', function () {
    $order = commerceOrder();
    $this->actingAs(commerceBuyer())->delete(route('commerce.cart.destroy', CartItem::first()->id))->assertNotFound();
    expect(CartItem::count())->toBe(1);
});

test('cart and checkout surface stale eligibility and terms and refuse order creation', function (string $change) {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    match ($change) {
        'status' => DB::table('products')->where('id', $product->id)->update(['status' => 'approved']),
        'inactive' => $product->update(['is_active' => false]),'offer' => $product->offers()->update(['is_active' => false, 'is_default' => false]),
        'price' => $product->offers()->update(['price' => '4999.01']), 'currency' => $product->update(['currency' => 'USD']),
        'support' => $product->offers()->update(['support_period_days' => 91]),
        'description' => $product->offers()->update(['description' => 'Installation scope changed']),
        'deleted_offer' => $product->offers()->delete()
    };
    $key = Cart::where('user_id', $buyer->id)->first()->checkout_key;
    $this->actingAs($buyer)->get('/cart')->assertInertia(fn (Assert $p) => $p->where('cart.valid', false)->where('cart.items.0.issue', fn ($v) => is_string($v) && strlen($v) > 10));
    $this->post('/checkout', ['idempotency_key' => $key, ...CommerceFixtures::billing()])->assertSessionHasErrors('cart');
    expect(Order::count())->toBe(0);
})->with(['status', 'inactive', 'offer', 'price', 'currency', 'support', 'description', 'deleted_offer']);

test('price updates require explicit observed quote confirmation', function () {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    $offer = $product->offers->first();
    app(CartService::class)->add($buyer, $product->slug, $offer->id);
    $offer->update(['price' => '9.99']);
    $this->actingAs($buyer)->get('/cart')->assertInertia(fn (Assert $p) => $p->where('cart.items.0.price', '9.99')->where('cart.items.0.previous_price', '4999.00')->where('cart.valid', false));
    app(CartService::class)->add($buyer, $product->slug, $offer->id);
    expect(app(CartService::class)->view($buyer)['valid'])->toBeTrue();
});

test('billing validation rejects invalid fields', function (string $field, mixed $value) {
    $this->actingAs(commerceBuyer())->post('/checkout', ['idempotency_key' => (string) Str::uuid(), ...CommerceFixtures::billing(), $field => $value])->assertSessionHasErrors($field);
})->with([['customer_name', ''], ['billing_email', 'invalid'], ['address_line_1', ''], ['city', ''], ['state_region', ''], ['postal_code', str_repeat('1', 31)], ['country_code', 'ZZ'], ['country_code', 'USA'], ['idempotency_key', 'invalid']]);

test('checkout prohibits client authoritative money state identity and card data', function (string $field) {
    $this->actingAs(commerceBuyer())->post('/checkout', ['idempotency_key' => (string) Str::uuid(), ...CommerceFixtures::billing(), $field => '999'])->assertSessionHasErrors($field);
})->with(['price', 'currency', 'user_id', 'status', 'payment_status', 'grand_total', 'tax_total', 'items', 'card_number', 'cvv']);

test('checkout creates immutable commercial snapshots and exact release binding', function () {
    $buyer = commerceBuyer();
    $order = commerceOrder($buyer);
    $item = $order->items->first();
    expect($order->user_id)->toBe($buyer->id)->and($order->status)->toBe(OrderStatus::PendingPayment)->and($order->grand_total)->toBe('4999.00')
        ->and($order->billing_email)->toBe('billing@example.com')->and($buyer->fresh()->email)->toBe($buyer->email)
        ->and($item->product_name)->toBe('Nexora Inventory & POS')->and($item->offer_name)->toBe('Software License')
        ->and($item->license_type)->toBe('single_business')->and($item->unit_price)->toBe('4999.00')->and($item->line_total)->toBe('4999.00')
        ->and($item->support_period_days)->toBe(90)->and($item->updates_period_days)->toBe(365)->and($item->version)->toBe('1.0.0')
        ->and($item->product_release_id)->toBe(ProductRelease::where('product_id', $item->product_id)->first()->id)
        ->and($order->payments->first()->amount)->toBe('4999.00')->and($order->payments->first()->status)->toBe(PaymentStatus::Pending)
        ->and(CartItem::count())->toBe(1);
});

test('later product offer and visibility changes do not alter historical commercial snapshots', function () {
    $order = commerceOrder();
    $item = $order->items->first();
    DB::table('products')->where('id', $item->product_id)->update(['name' => 'Renamed software', 'is_active' => false, 'status' => 'approved']);
    DB::table('product_offers')->where('id', $item->product_offer_id)->update(['name' => 'Renamed package', 'price' => '0.01', 'support_period_days' => 1, 'updates_period_days' => 2, 'license_type' => 'extended']);
    $this->actingAs($order->user)->get(route('commerce.orders.show', $order))->assertInertia(fn (Assert $p) => $p->where('order.items.0.product_name', 'Nexora Inventory & POS')
        ->where('order.items.0.offer_name', 'Software License')->where('order.items.0.unit_price', '4999.00')->where('order.items.0.support_period_days', 90)->where('order.items.0.updates_period_days', 365));
});

test('place order is idempotent and preserves a single initial payment', function () {
    $order = commerceOrder();
    $same = app(Checkout::class)->place($order->user, $order->checkout_key, CommerceFixtures::billing());
    expect($same->id)->toBe($order->id)->and(Order::count())->toBe(1)->and(Payment::count())->toBe(1);
});

test('a changed checkout key cannot place a stale order', function () {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    $old = Cart::first()->checkout_key;
    app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    $this->actingAs($buyer)->post('/checkout', ['idempotency_key' => $old, ...CommerceFixtures::billing()])->assertSessionHasErrors('cart');
    expect(Order::count())->toBe(0);
});

test('verified payment success is audited idempotent and clears only purchased selections', function () {
    $order = commerceOrder();
    Event::fake([OrderPaid::class, PaymentSucceeded::class]);
    commerceSimulate($order);
    commerceSimulate($order);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed)->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->fresh()->paid_at)->not->toBeNull()->and($order->fresh()->invoice_number)->toStartWith('INV-')
        ->and($order->payments()->first()->status)->toBe(PaymentStatus::Paid)->and(PaymentEvent::count())->toBe(1)->and(CartItem::count())->toBe(0);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

test('payment does not clear selections changed since placement', function () {
    $order = commerceOrder();
    $item = $order->items->first();
    $product = Product::find($item->product_id);
    $other = ProductOffer::factory()->additional()->for($product)->create();
    app(CartService::class)->add($order->user, $product->slug, $other->id);
    commerceSimulate($order);
    expect(CartItem::count())->toBe(1)->and(CartItem::first()->product_offer_id)->toBe($other->id);
});

test('failure preserves history and cart and retry creates only one new pending attempt', function () {
    $order = commerceOrder();
    commerceSimulate($order, 'failed');
    expect($order->fresh()->status)->toBe(OrderStatus::PaymentFailed)->and(CartItem::count())->toBe(1);
    app(PaymentLifecycle::class)->retry($order->user, $order);
    app(PaymentLifecycle::class)->retry($order->user, $order);
    expect(Payment::count())->toBe(2)->and($order->payments()->oldest('id')->first()->status)->toBe(PaymentStatus::Failed);
    commerceSimulate($order);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('late success of a closed attempt never settles a second time', function () {
    $order = commerceOrder();
    $old = $order->payments()->first();
    commerceSimulate($order, 'failed');
    app(PaymentLifecycle::class)->retry($order->user, $order);
    commerceSimulate($order);
    app(PaymentLifecycle::class)->process(app(DevelopmentGateway::class)->simulate($old, 'paid'));
    expect($order->payments()->where('status', 'paid')->count())->toBe(1)->and($old->fresh()->status)->toBe(PaymentStatus::Failed)->and(PaymentEvent::latest('id')->first()->status)->toBe('ignored');
});

test('retry revalidates unchanged commercial terms', function () {
    $order = commerceOrder();
    commerceSimulate($order, 'failed');
    ProductOffer::where('id', $order->items->first()->product_offer_id)->update(['price' => '5000.00']);
    $this->actingAs($order->user)->post(route('commerce.orders.retry', $order))->assertSessionHasErrors('payment');
    expect(Payment::count())->toBe(1);
});

test('wrong provider evidence cannot settle an order', function (array $override) {
    $order = commerceOrder();
    [$raw,$signature] = commerceWebhook($order->payments->first(), $override);
    $this->call('POST', '/payments/development/webhook', [], [], [], ['HTTP_X_PAYMENT_SIGNATURE' => $signature, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $raw)->assertUnprocessable();
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)->and(PaymentEvent::count())->toBe(0);
})->with([[['amount' => '4999.01']], [['currency' => 'USD']], [['reference' => 'unknown']], [['order_reference' => 'wrong-order']]]);

test('unsigned webhook is rejected before any payment mutation', function () {
    $order = commerceOrder();
    [$raw] = commerceWebhook($order->payments->first());
    $this->call('POST', '/payments/development/webhook', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $raw)->assertUnauthorized();
    expect(PaymentEvent::count())->toBe(0);
});

test('signed duplicate webhook is idempotent but changed replay contents are rejected', function () {
    $order = commerceOrder();
    $payment = $order->payments->first();
    [$raw,$signature] = commerceWebhook($payment);
    for ($i = 0; $i < 2; $i++) {
        $this->call('POST', '/payments/development/webhook', [], [], [], ['HTTP_X_PAYMENT_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $raw)->assertOk();
    }
    [$changed,$sig] = commerceWebhook($payment, ['outcome' => 'failed']);
    $this->call('POST', '/payments/development/webhook', [], [], [], ['HTTP_X_PAYMENT_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $changed)->assertUnprocessable();
    expect(PaymentEvent::count())->toBe(1)->and($order->payments()->where('status', 'paid')->count())->toBe(1);
});

test('fake gateway is unavailable in production even with explicit fake configuration', function () {
    $order = commerceOrder();
    app()->instance('env', 'production');
    config()->set('app.env', 'production');
    expect(app(PaymentGateway::class)->available())->toBeFalse();
    $this->actingAs($order->user)->withSession(['_token' => 'production-test-token'])->post(route('commerce.payments.simulate', $order->payments->first()->idempotency_key), ['outcome' => 'paid', '_token' => 'production-test-token'])->assertNotFound();
    $this->post('/payments/development/webhook')->assertNotFound();
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('disabled gateway never leaves an order or payment behind', function () {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    config()->set('commerce.development_enabled', false);
    $this->actingAs($buyer)->post('/checkout', ['idempotency_key' => Cart::first()->checkout_key, ...CommerceFixtures::billing()])->assertStatus(503);
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)->and(CartItem::count())->toBe(1);
});

test('browser cannot forge payment amount identity or status through simulator', function (string $field) {
    $order = commerceOrder();
    $this->actingAs($order->user)->post(route('commerce.payments.simulate', $order->payments->first()->idempotency_key), ['outcome' => 'paid', $field => '999'])->assertSessionHasErrors($field);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
})->with(['amount', 'currency', 'status', 'provider_payment_id', 'order_id', 'user_id']);

test('order ownership applies to detail retry cancellation and simulator', function () {
    $order = commerceOrder();
    $this->actingAs(commerceBuyer())->get(route('commerce.orders.show', $order))->assertForbidden();
    $this->post(route('commerce.orders.retry', $order))->assertForbidden();
    $this->post(route('commerce.orders.cancel', $order))->assertForbidden();
    $this->post(route('commerce.payments.simulate', $order->payments->first()->idempotency_key), ['outcome' => 'paid'])->assertForbidden();
    $this->get('/account/orders')->assertInertia(fn (Assert $p) => $p->has('records.data', 0));
});

test('customer and admin order workspaces enforce existing permissions', function () {
    $order = commerceOrder();
    $this->actingAs($order->user)->get('/account/orders')->assertInertia(fn (Assert $p) => $p->has('records.data', 1));
    $this->get('/admin/orders')->assertForbidden();
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin)->get('/admin/orders')->assertInertia(fn (Assert $p) => $p->has('records.data', 1));
    $this->get(route('admin.orders.show', $order))->assertOk();
});

test('cancellation closes unpaid attempts and allows new checkout without refunding paid orders', function () {
    $order = commerceOrder();
    app(PaymentLifecycle::class)->cancel($order->user, $order);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and($order->payments->first()->fresh()->status)->toBe(PaymentStatus::Cancelled);
    $new = app(Checkout::class)->place($order->user, Cart::where('user_id', $order->user_id)->first()->checkout_key, CommerceFixtures::billing());
    expect($new->id)->not->toBe($order->id)->and($new->order_number)->not->toBe($order->order_number);
    commerceSimulate($new);
    $this->actingAs($new->user)->post(route('commerce.orders.cancel', $new))->assertSessionHasErrors('payment');
});

test('paid orders reveal snapshots but never grant commercial artifact access', function () {
    $order = commerceOrder();
    commerceSimulate($order);
    $item = $order->items->first();
    $artifact = ProductArtifact::where('product_release_id', $item->product_release_id)->first();
    $response = $this->actingAs($order->user)->get(route('commerce.orders.show', $order))->assertOk();
    $json = json_encode($response->inertiaProps('order'));
    foreach ([$artifact->path, $artifact->disk, $artifact->checksum, $artifact->original_name, 'download_url', 'snapshot', 'card_number', 'cvv'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    $this->get(route('products.files.artifacts.download', [$item->product_id, $item->product_release_id, $artifact->id]))->assertForbidden();
});

test('customers with retained orders cannot delete their account', function () {
    $order = commerceOrder();
    $this->actingAs($order->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect($order->user->fresh())->not->toBeNull();
});

test('models forbid editing snapshots or deleting commercial history', function () {
    $order = commerceOrder();
    expect(fn () => $order->forceFill(['grand_total' => '0.00'])->save())->toThrow(LogicException::class);
    expect(fn () => $order->items->first()->forceFill(['product_name' => 'forged'])->save())->toThrow(LogicException::class);
    expect(fn () => $order->payments->first()->forceFill(['amount' => '0.00'])->save())->toThrow(LogicException::class);
    expect(fn () => $order->delete())->toThrow(LogicException::class);
});

test('exact money never rounds or uses floating point', function (string $input, string $expected) {
    expect(Money::decimal($input))->toBe($expected);
    expect(Money::sum([$input, '0.01']))->toBe(BigDecimal::of($expected)->plus('0.01')->__toString());
})->with([['0.00', '0.00'], ['4999.00', '4999.00'], ['9999999.99', '9999999.99']]);

test('money rejects fractional cent rounding', function () {
    expect(fn () => Money::decimal('1.001'))->toThrow(RoundingNecessaryException::class);
    expect(Money::sum(['0.10', '0.20']))->toBe('0.30');
});

test('order placed and failed payment events are dispatched once after commit', function () {
    Event::fake([OrderPlaced::class, PaymentFailed::class]);
    $order = commerceOrder();
    commerceSimulate($order, 'failed');
    commerceSimulate($order, 'failed');
    Event::assertDispatchedTimes(OrderPlaced::class, 1);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);
});

test('package scope is retained in the immutable order snapshot', function () {
    $buyer = commerceBuyer();
    $product = CommerceFixtures::product();
    $offer = $product->offers->first();
    $offer->update(['description' => 'Installation includes one business location.']);
    app(CartService::class)->add($buyer, $product->slug, $offer->id);
    $order = app(Checkout::class)->place($buyer, Cart::where('user_id', $buyer->id)->first()->checkout_key, CommerceFixtures::billing());
    $offer->update(['description' => 'A different package scope.']);
    $this->actingAs($buyer)->get(route('commerce.orders.show', $order))->assertInertia(fn (Assert $p) => $p->where('order.items.0.offer_description', 'Installation includes one business location.'));
});

test('order filtering permits an end date without a start date', function () {
    $order = commerceOrder();
    $this->actingAs($order->user)->get('/account/orders?to='.now()->format('Y-m-d'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('records.data', 1));
});

test('signed malformed webhook is rejected without writing payment history', function () {
    $raw = '{invalid';
    $this->call('POST', '/payments/development/webhook', [], [], [], ['HTTP_X_PAYMENT_SIGNATURE' => app(DevelopmentGateway::class)->signature($raw), 'CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(400);
    expect(PaymentEvent::count())->toBe(0);
});

test('postgresql protects raw commercial snapshots and retention', function (string $mutation) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific integrity verification.');
    }
    $order = commerceOrder();
    $item = $order->items->first();
    expect(fn () => match ($mutation) {
        'order' => DB::table('orders')->where('id', $order->id)->update(['customer_name' => 'Forged']),
        'item' => DB::table('order_items')->where('id', $item->id)->update(['unit_price' => '0.00']),
        'description' => DB::table('order_items')->where('id', $item->id)->update(['offer_description' => 'Forged']),
        'payment' => DB::table('payments')->where('order_id', $order->id)->update(['amount' => '0.00']),
        'delete_order' => DB::table('orders')->where('id', $order->id)->delete(),
        'delete_user' => DB::table('users')->where('id', $order->user_id)->delete(),
        'delete_offer' => DB::table('product_offers')->where('id', $item->product_offer_id)->delete(),
        'delete_release' => DB::table('product_releases')->where('id', $item->product_release_id)->delete(),
        'status' => DB::table('orders')->where('id', $order->id)->update(['status' => 'completed']),
    })->toThrow(QueryException::class);
})->with(['order', 'item', 'description', 'payment', 'delete_order', 'delete_user', 'delete_offer', 'delete_release', 'status']);
