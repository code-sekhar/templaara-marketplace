<?php

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EarningFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\ProductFileFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    ProductFileFixtures::fakeStorage();
    config()->set(['commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
    app()->forgetInstance(PaymentGateway::class);
});

test('guest discovery retains safe business product presentation', function (): void {
    $p = EarningFixtures::product();
    $this->get('/products/'.$p->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('marketplace/Show')->where('product.slug', $p->slug)->where('product.currency', 'INR')->where('product.starting_price', '5000.00')->has('product.offers', 1)->has('product.features', 1)->missing('product.owner.email')->missing('product.artifacts')->missing('product.submissions'));
});

test('homepage zero state does not fabricate featured software', function (): void {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('marketplace/Home')->has('featured', 0)->has('recent', 0)->where('auth.user', null));
});

test('public discovery preserves canonical and business metadata', function (): void {
    $p = EarningFixtures::product();
    $this->get('/products/'.$p->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->where('seo.canonical', route('marketplace.products.show', $p->slug))->where('seo.title', $p->name)->where('product.business_problem', $p->business_problem));
});

test('authenticated public shell receives no role or account contact data', function (): void {
    $u = User::factory()->create()->assignRole('admin');
    $this->actingAs($u)->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user.name', $u->name)->missing('auth.user.email')->missing('auth.user.roles')->missing('auth.user.permissions')->missing('auth.user.password'));
});

test('workspace navigation inputs retain multi-role permissions', function (string $role): void {
    $u = User::factory()->create()->assignRole($role);
    $response = $this->actingAs($u)->get('/dashboard')->assertOk();
    expect($response->inertiaProps('auth.user.roles'))->toContain($role);
    expect($response->inertiaProps('auth.user.permissions'))->toBe($u->getAllPermissions()->pluck('name')->sort()->values()->all());
})->with(['customer', 'author', 'admin']);

test('customer workspace shortcuts remain guest protected', function (string $path): void {
    $this->get($path)->assertRedirect('/login');
})->with(['/dashboard', '/account/library', '/account/orders', '/account/invoices', '/account/support', '/account/reviews', '/notifications']);

test('customer cannot open operational workspace links', function (string $path): void {
    $u = User::factory()->create()->assignRole('customer');
    $this->actingAs($u)->get($path)->assertForbidden();
})->with(['/admin/dashboard', '/admin/reports', '/admin/authors', '/admin/settings', '/admin/orders', '/admin/payouts', '/admin/refunds']);

test('customer library empty projection is useful without invented purchases', function (): void {
    $u = User::factory()->create()->assignRole('customer');
    $this->actingAs($u)->get('/account/library')->assertOk()->assertInertia(fn (Assert $page) => $page->component('library/Index')->where('admin', false)->has('records.data', 0));
});

test('financial presentation uses invoice snapshots per currency', function (string $currency): void {
    $o = InvoiceFixtures::paid([], '123.45', $currency, true);
    $doc = Invoice::where('order_id', $o->id)->firstOrFail();
    $response = $this->actingAs($o->user)->get('/account/invoices/'.$doc->public_uuid)->assertOk();
    expect($response->inertiaProps('document.currency'))->toBe($currency);
    expect($response->inertiaProps('document.grand_total'))->toBe('123.45');
    expect($response->inertiaProps('document'))->not->toHaveKeys(['disk', 'path', 'provider_payload']);
})->with(['INR', 'USD', 'EUR', 'GBP']);

test('checkout presentation retains exact authoritative exclusive tax quote', function (): void {
    $o = InvoiceFixtures::paid(InvoiceFixtures::gst(), '100.00');
    $doc = Invoice::where('order_id', $o->id)->firstOrFail();
    $response = $this->actingAs($o->user)->get('/account/invoices/'.$doc->public_uuid)->assertOk();
    expect($response->inertiaProps('document.tax_total'))->toBe('18.00');
    expect($response->inertiaProps('document.grand_total'))->toBe('118.00');
    expect($o->payments()->where('status', 'paid')->first()->amount)->toBe('118.00');
});

test('production error presentation preserves status and safe guidance', function (int $status): void {
    app()->instance('env', 'production');
    config()->set('app.debug', false);
    Route::get('/phase23-error-test', fn () => abort($status, 'Private diagnostic /server/path SQLSTATE sk_secret'));
    $r = $this->get('/phase23-error-test')->assertStatus($status);
    $r->assertSee('Return home')->assertDontSee('sk_secret')->assertDontSee('SQLSTATE')->assertDontSee('/server/path');
    if ($status >= 500) {
        $r->assertHeader('Cache-Control', 'no-store, private');
    }
})->with([403, 404, 419, 500, 503]);
