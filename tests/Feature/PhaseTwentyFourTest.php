<?php

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Dashboard\CustomerWorkspace;
use App\Services\Support\SupportWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\EarningFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\ReviewFixtures;
use Tests\Support\SupportFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    ProductFileFixtures::fakeStorage();
    config()->set(['commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
    app()->forgetInstance(PaymentGateway::class);
});

function workspaceFor(User $user): array
{
    return app(CustomerWorkspace::class)->forUser($user);
}

test('premium customer dashboard requires authentication', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('customer dashboard zero state contains no invented activity', function (): void {
    $u = User::factory()->create();
    $p = workspaceFor($u);
    expect($p['counts'])->toBe(['purchases' => 0, 'active_licenses' => 0, 'open_tickets' => 0, 'invoices' => 0]);
    expect($p['recent_purchases'])->toBe([])->and($p['support_activity'])->toBe([]);
    $this->actingAs($u)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard')->where('workspace', $p));
});

test('dashboard counts only verified paid commercial orders', function (): void {
    $paid = EarningFixtures::order();
    $unpaid = EarningFixtures::order($paid->items->first()->product, false);
    expect(workspaceFor($paid->user)['counts']['purchases'])->toBe(1);
    expect(workspaceFor($unpaid->user)['counts']['purchases'])->toBe(0);
});

test('customer projection never includes another owners commercial records', function (): void {
    $o = InvoiceFixtures::paid([], '123.45', 'INR', true);
    $other = User::factory()->create();
    expect(workspaceFor($other)['counts'])->toBe(['purchases' => 0, 'active_licenses' => 0, 'open_tickets' => 0, 'invoices' => 0]);
    expect(workspaceFor($other)['recent_purchases'])->toBe([])->and(workspaceFor($other)['support_activity'])->toBe([]);
});

test('customer paid totals remain exact and currency explicit', function (string $currency): void {
    $o = EarningFixtures::order(EarningFixtures::product(null, '123.45', $currency));
    $p = workspaceFor($o->user)['recent_purchases'][0];
    expect($p['total'])->toBe('123.45')->and($p['currency'])->toBe($currency)->and($p['reference'])->toBe($o->order_number);
})->with(['INR', 'USD', 'EUR', 'GBP']);

test('customer dashboard preserves purchased product names and prices', function (): void {
    $o = EarningFixtures::order();
    $item = $o->items->first();
    $item->product->update(['name' => 'Changed current title']);
    $item->product->offers->first()->update(['price' => '9999.00']);
    $p = workspaceFor($o->user)['recent_purchases'][0];
    expect($p['products'])->toBe([$item->product_name])->and($p['total'])->toBe('5000.00');
});

test('customer invoice count excludes credit notes and other owners', function (): void {
    $o = InvoiceFixtures::paid([], '100.00', 'INR', true);
    InvoiceFixtures::paid([], '200.00', 'INR', true);
    expect(workspaceFor($o->user)['counts']['invoices'])->toBe(1);
    expect(Invoice::where('order_id', $o->id)->first()->kind)->toBe('invoice');
});

test('customer support activity uses an explicit projection without conversation contents', function (): void {
    $o = SupportFixtures::order();
    $t = SupportFixtures::ticket($o);
    $p = workspaceFor($o->user);
    expect($p['counts']['open_tickets'])->toBe(1);
    expect(array_keys($p['support_activity'][0]))->toBe(['reference', 'number', 'subject', 'status', 'last_activity_at']);
    expect(json_encode($p))->not->toContain('Please help configure', 'request_key', 'seller_user_id');
    expect($p['support_activity'][0]['reference'])->toBe($t->public_uuid);
});

test('resolved support tickets are not counted as open', function (): void {
    $o = SupportFixtures::order();
    $t = SupportFixtures::ticket($o);
    app(SupportWorkflow::class)->transition(User::findOrFail($t->seller_user_id), $t, 'resolve', [], 'author');
    expect(workspaceFor($o->user)['counts']['open_tickets'])->toBe(0);
});

test('active license count excludes expired or revoked records', function (string $case): void {
    $o = EarningFixtures::order();
    $license = $o->items->first()->entitlement->license;
    expect(workspaceFor($o->user)['counts']['active_licenses'])->toBe(1);
    $license->forceFill($case === 'expired' ? ['expires_at' => now()->subDay()] : ['status' => 'revoked', 'revoked_at' => now()])->save();
    expect(workspaceFor($o->user)['counts']['active_licenses'])->toBe(0);
})->with(['expired', 'revoked']);

test('dashboard commercial summary is an explicit allow list', function (): void {
    $o = EarningFixtures::order();
    $p = workspaceFor($o->user);
    expect(array_keys($p))->toBe(['counts', 'recent_purchases', 'support_activity']);
    expect(array_keys($p['recent_purchases'][0]))->toBe(['reference', 'currency', 'total', 'paid_at', 'products']);
    foreach (['email', 'license_key', 'key_hash', 'artifact', 'billing', 'provider', 'secret', 'bank', 'destination'] as $private) {
        expect(json_encode($p))->not->toContain($private);
    }
});

test('dashboard projection uses bounded queries without writes', function (): void {
    $o = EarningFixtures::order();
    $user = $o->user;
    DB::enableQueryLog();
    DB::flushQueryLog();
    workspaceFor($user);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(7);
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
});

test('dashboard remains available to multi role customers', function (): void {
    $u = User::factory()->create()->assignRole(['customer', 'author']);
    $this->actingAs($u)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('workspace.counts.purchases', 0));
});

test('recent purchases are bounded and deterministically ordered', function (): void {
    $p = EarningFixtures::product();
    $user = User::factory()->create()->assignRole('customer');
    $references = [];
    for ($i = 0; $i < 5; $i++) {
        $o = ReviewFixtures::purchase($p, $user);
        $references[] = $o->order_number;
    }
    $result = workspaceFor($user);
    expect($result['counts']['purchases'])->toBe(5);
    expect(array_column($result['recent_purchases'], 'reference'))->toBe(array_slice(array_reverse($references), 0, 4));
});
