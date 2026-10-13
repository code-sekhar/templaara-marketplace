<?php

use App\Contracts\PaymentGateway;
use App\Models\Cart;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Invoicing\CheckoutTax;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures;
use Tests\Support\ReviewFixtures;
use Tests\Support\SupportFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    ProductFileFixtures::fakeStorage();
    config()->set(['commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
    app()->forgetInstance(PaymentGateway::class);
});

$workspaceFilters = [
    'orders' => ['search' => 'missing-reference', 'status' => 'completed', 'payment_status' => 'paid', 'currency' => 'INR', 'from' => '2020-01-01', 'to' => '2099-12-31'],
    'invoices' => ['search' => 'missing-reference', 'currency' => 'INR', 'kind' => 'credit_note', 'tax_type' => 'IGST', 'from' => '2020-01-01', 'to' => '2099-12-31'],
    'support' => ['search' => 'missing-subject', 'product' => 'missing-software', 'status' => 'awaiting_seller', 'priority' => 'high', 'category' => 'installation', 'unread' => '1', 'escalated' => '1', 'from' => '2020-01-01', 'until' => '2099-12-31'],
    'reviews' => ['search' => 'missing-review', 'product' => 'missing-software', 'rating' => '5', 'status' => 'withdrawn', 'response' => 'answered', 'from' => '2020-01-01', 'until' => '2099-12-31'],
    'refunds' => ['search' => 'missing-reference', 'status' => 'completed', 'currency' => 'INR', 'reason' => 'technical_issue', 'from' => '2020-01-01', 'until' => '2099-12-31'],
];

test('customer list retains every validated filter and reset state', function (string $area, array $filters): void {
    $user = User::factory()->create()->assignRole('customer');
    $response = $this->actingAs($user)->get('/account/'.$area.'?'.http_build_query($filters))->assertOk();
    foreach ($filters as $key => $value) {
        expect((string) $response->inertiaProps('filters.'.$key))->toBe($value);
    }
    $records = $area === 'refunds' ? 'refunds' : 'records';
    expect($response->inertiaProps($records.'.data'))->toBe([]);
    expect($this->get('/account/'.$area)->assertOk()->inertiaProps('filters'))->toBe([]);
})->with(array_map(fn ($area, $filters) => [$area, $filters], array_keys($workspaceFilters), array_values($workspaceFilters)));

$invalidFilters = [];
foreach ($workspaceFilters as $area => $filters) {
    foreach (array_keys($filters) as $key) {
        $invalidFilters[$area.' '.$key] = [$area, $key];
    }
}
test('customer filter array input is rejected without changing records', function (string $area, string $field): void {
    $user = User::factory()->create()->assignRole('customer');
    $this->actingAs($user)->getJson('/account/'.$area.'?'.http_build_query([$field => ['unexpected']]))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with($invalidFilters);

test('populated customer workspace preserves documents access reviews support and refund ownership', function (): void {
    InvoiceFixtures::configure();
    $product = EarningFixtures::product();
    $product->offers->first()->forceFill(['support_period_days' => 180])->save();
    $buyer = User::factory()->create()->assignRole('customer');
    app(CartService::class)->add($buyer, $product->slug, $product->offers->first()->id);
    $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
    $billing = CommerceFixtures::billing();
    $tax = app(CheckoutTax::class);
    $quote = $tax->quote([$product->offers->first()->price], $billing);
    $billing['tax_quote_hash'] = $tax->hash($quote, $billing, $cart->checkout_key);
    $order = EntitlementFixtures::pay(app(Checkout::class)->place($buyer, $cart->checkout_key, $billing));
    $user = $order->user;
    $review = ReviewFixtures::review($order);
    $ticket = SupportFixtures::ticket($order);
    $refund = RefundFixtures::request($order);
    $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
    $pdf = $this->actingAs($user)->get('/account/invoices/'.$invoice->public_uuid.'/pdf')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($pdf)->toStartWith('%PDF');
    $checks = [
        'orders' => ['records.data.0.order_number', $order->order_number],
        'invoices' => ['records.data.0.number', $invoice->number],
        'reviews' => ['records.data.0.uuid', $review->public_uuid],
        'support' => ['records.data.0.uuid', $ticket->public_uuid],
        'refunds' => ['refunds.data.0.reference', $refund->reference],
        'library' => ['records.data.0.order_number', $order->order_number],
    ];
    foreach ($checks as $area => [$key, $value]) {
        expect($this->get('/account/'.$area)->assertOk()->inertiaProps($key))->toBe($value);
    }
    $stranger = User::factory()->create()->assignRole('customer');
    foreach ($checks as $area => [$key, $value]) {
        $r = $this->actingAs($stranger)->get('/account/'.$area)->assertOk();
        expect($r->inertiaProps(($area === 'refunds' ? 'refunds' : 'records').'.data'))->toBe([]);
    }
    $this->get('/account/invoices/'.$invoice->public_uuid.'/pdf')->assertForbidden();
    expect($this->actingAs($user)->get('/account/invoices/'.$invoice->public_uuid.'/pdf')->assertOk()->getContent())->toBe($pdf);
});
