<?php

use App\Contracts\PaymentGateway;
use App\Events\Refunds\RefundCompleted;
use App\Models\Cart;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\Checkout;
use App\Services\Commerce\Money;
use App\Services\Invoicing\CheckoutTax;
use App\Services\Invoicing\InvoiceNumber;
use App\Services\Invoicing\InvoicePdf;
use App\Services\Invoicing\InvoiceRecovery;
use App\Services\Invoicing\IssueInvoice;
use App\Services\Invoicing\TaxCalculator;
use App\Services\Invoicing\TaxPolicy;
use App\Services\Settings\SettingsRegistry;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\InvoiceFixtures as IFx;
use Tests\Support\RefundFixtures;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
    (new CommissionPolicySeeder)->run();
    config()->set(['commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
    app()->forgetInstance(PaymentGateway::class);
});

it('rejects invalid controlled invoice settings', function ($key, $value) {
    expect(fn () => app(SettingsRegistry::class)->canonical('invoicing', $key, $value))->toThrow(ValidationException::class);
})->with([
    ['invoice_prefix', 'lower'], ['invoice_prefix', 'OVERLONG'], ['invoice_prefix', '=SUM'], ['invoice_prefix', '../INV'], ['credit_prefix', 'a'],
    ['gstin', 'invalid'], ['gstin', '99ABCDE1234F1Z5'], ['gstin', '36ABCDE1234F0Z5'], ['gstin', '36ABCDE1234F1Y5'],
    ['country_code', 'ZZ'], ['country_code', 'india'], ['tax_rate', '100.01'], ['tax_rate', '-1'], ['tax_rate', '18.001'], ['tax_rate', '1e1'], ['tax_rate', 18.0],
    ['tax_mode', 'automatic'], ['issuer_mode', 'author'], ['service_code', 'software'], ['service_code', '123'], ['gst_state_code', '99'],
    ['international_type', 'FAKE'], ['international_countries', ['ZZ']], ['international_countries', ['US', 'US']],
    ['enabled', 'yes'], ['policy_reviewed', 'yes'], ['prices_include_tax', 2], ['legal_name', '<script>'], ['contact_email', 'bad'],
    ['stripe_secret_key', 'synthetic-marker'],
]);

it('uses exact configured tax arithmetic', function ($amount, $rate, $inclusive, $expectedBase, $expectedTax, $expectedTotal) {
    $policy = [...array_map(fn ($r) => $r['default'], app(SettingsRegistry::class)->group('invoicing')), 'identifier' => 'test', 'tax_mode' => 'international', 'international_countries' => ['US'], 'tax_rate' => $rate, 'prices_include_tax' => $inclusive];
    $line = app(TaxCalculator::class)->line($amount, ['country_code' => 'US'], $policy);
    expect($line['taxable_amount'])->toBe($expectedBase)->and($line['tax_amount'])->toBe($expectedTax)->and($line['grand_total'])->toBe($expectedTotal);
})->with([
    ['100.00', '18.00', false, '100.00', '18.00', '118.00'], ['118.00', '18.00', true, '100.00', '18.00', '118.00'],
    ['100.00', '18.00', true, '84.75', '15.25', '100.00'], ['0.00', '18.00', false, '0.00', '0.00', '0.00'], ['0.00', '18.00', true, '0.00', '0.00', '0.00'],
    ['0.01', '18.00', false, '0.01', '0.00', '0.01'], ['0.03', '18.00', false, '0.03', '0.01', '0.04'], ['0.03', '18.00', true, '0.03', '0.00', '0.03'],
    ['0.05', '10.00', false, '0.05', '0.01', '0.06'], ['1.00', '0.00', false, '1.00', '0.00', '1.00'], ['1.00', '0.00', true, '1.00', '0.00', '1.00'],
    ['1.00', '100.00', false, '1.00', '1.00', '2.00'], ['1.00', '100.00', true, '0.50', '0.50', '1.00'], ['1.00', '5.50', false, '1.00', '0.06', '1.06'],
    ['1.00', '5.50', true, '0.95', '0.05', '1.00'], ['4999.00', '18.00', false, '4999.00', '899.82', '5898.82'], ['5898.82', '18.00', true, '4999.00', '899.82', '5898.82'],
    ['1.99', '7.25', false, '1.99', '0.14', '2.13'], ['1.99', '7.25', true, '1.86', '0.13', '1.99'], ['999999999999.99', '0.00', false, '999999999999.99', '0.00', '999999999999.99'],
]);

it('splits configured GST by validated jurisdiction', function ($state, $type, $components) {
    $policy = [...array_map(fn ($r) => $r['default'], app(SettingsRegistry::class)->group('invoicing')), ...IFx::gst(), 'identifier' => 'test'];
    $line = app(TaxCalculator::class)->line('100.00', ['country_code' => 'IN', 'gst_state_code' => $state], $policy);
    expect($line['type'])->toBe($type)->and(array_column($line['components'], 'type'))->toBe($components)->and(Money::sum(array_column($line['components'], 'amount')))->toBe('18.00');
})->with([['36', 'CGST_SGST', ['CGST', 'SGST']], ['27', 'IGST', ['IGST']], ['07', 'IGST', ['IGST']], ['33', 'IGST', ['IGST']]]);

it('requires explicit reviewed policy readiness', function ($overrides) {
    IFx::configure($overrides);
    expect(fn () => app(TaxPolicy::class)->current())->toThrow(ValidationException::class);
})->with([
    [['legal_name' => '']], [['tax_mode' => 'gst']], [['tax_mode' => 'gst', 'tax_rate' => '18.00', 'policy_reviewed' => false]],
    [[...IFx::gst(), 'gstin' => '27ABCDE1234F1Z5']], [['invoice_prefix' => 'SAME', 'credit_prefix' => 'SAME']],
    [['tax_mode' => 'international', 'tax_rate' => '20.00', 'policy_reviewed' => true, 'international_countries' => []]],
]);

it('does not invent tax outside configured countries', function ($country) {
    IFx::configure(IFx::gst());
    $line = app(TaxCalculator::class)->line('100.00', ['country_code' => $country], app(TaxPolicy::class)->current());
    expect($line['type'])->toBe('NONE')->and($line['tax_amount'])->toBe('0.00')->and($line['reason'])->toBe('outside_configured_policy');
})->with(['US', 'GB', 'DE', 'FR']);

it('requires permissions to edit invoice settings', function ($role, $status) {
    $actor = User::factory()->create()->assignRole($role);
    $this->actingAs($actor)->put('/admin/settings/invoicing', ['values' => ['invoice_prefix' => 'NEW'], 'versions' => ['invoice_prefix' => 0]])->assertStatus($status);
})->with([['customer', 403], ['author', 403], ['reviewer', 403], ['admin', 302]]);

it('issues one immutable invoice for verified payment', function ($currency) {
    $order = IFx::paid([], '100.00', $currency);
    $doc = app(IssueInvoice::class)->issue($order);
    expect($doc->currency)->toBe($currency)->and($doc->grand_total)->toBe('100.00')->and($doc->items()->count())->toBe(1);
    expect(app(IssueInvoice::class)->issue($order)->id)->toBe($doc->id);
    expect(DB::table('invoice_events')->where('invoice_id', $doc->id)->count())->toBe(1);
    expect(fn () => $doc->forceFill(['number' => 'EDIT'])->save())->toThrow(LogicException::class);
})->with(['INR', 'USD', 'EUR', 'GBP']);

it('does not issue an invoice for unpaid commerce', function () {
    expect(fn () => app(IssueInvoice::class)->issue(IFx::order()))->toThrow(ValidationException::class);
});

it('captures exclusive tax before immutable payment reservation', function () {
    $o = IFx::order(IFx::gst());
    expect($o->subtotal)->toBe('100.00')->and($o->tax_total)->toBe('18.00')->and($o->grand_total)->toBe('118.00')->and($o->payments()->first()->amount)->toBe('118.00');
    expect($o->items()->first()->line_total)->toBe('100.00')->and($o->items()->first()->tax_snapshot['grand_total'])->toBe('118.00');
});

it('partitions inclusive tax without changing purchased charge', function () {
    $o = IFx::paid(IFx::gst(true));
    $d = app(IssueInvoice::class)->issue($o);
    expect($o->grand_total)->toBe('100.00')->and($d->taxable_amount)->toBe('84.75')->and($d->tax_total)->toBe('15.25');
});

it('preserves existing author economics for exclusive tax', function () {
    $o = IFx::paid(IFx::gst(), '100.00', 'INR', true);
    $e = $o->items()->first()->earning;
    expect($o->grand_total)->toBe('118.00')->and($e->gross_amount)->toBe('100.00')->and($e->commission_amount)->toBe('20.00')->and($e->author_amount)->toBe('80.00');
});

it('stores exact original tax on a completed refund credit', function () {
    $o = IFx::paid(IFx::gst());
    $d = app(IssueInvoice::class)->issue($o);
    $r = RefundFixtures::request($o);
    expect($r->getAttribute('amount'))->toBe('118.00')->and(Invoice::where('kind', 'credit_note')->count())->toBe(0);
    RefundFixtures::complete($r);
    $c = app(IssueInvoice::class)->credit($r->fresh());
    expect($c->grand_total)->toBe('118.00')->and($c->tax_total)->toBe('18.00')->and($c->items()->first()->tax_snapshot)->toBe($d->items()->first()->tax_snapshot);
    expect($o->items()->first()->entitlement->fresh()->status->value)->toBe('revoked');
    expect(Money::decimal((string) DB::table('refund_compensations')->first()->gross_amount))->toBe('100.00');
});

it('preserves legacy paid total and records unknown historical tax', function () {
    $o = EarningFixtures::order();
    IFx::configure(IFx::gst());
    $d = app(IssueInvoice::class)->issue($o);
    expect($d->grand_total)->toBe($o->grand_total)->and($d->tax_total)->toBe('0.00')->and($d->provenance)->toBe('legacy_undetermined');
});

it('authorizes private document access', function ($actor, $path, $expected) {
    $o = IFx::paid();
    $d = app(IssueInvoice::class)->issue($o);
    if ($actor !== 'guest') {
        $this->actingAs($actor === 'owner' ? $o->user : User::factory()->create()->assignRole($actor));
    }
    $this->get(str_replace('{uuid}', $d->public_uuid, $path))->assertStatus($expected);
})->with([
    ['guest', '/account/invoices/{uuid}', 302], ['customer', '/account/invoices/{uuid}', 403], ['author', '/account/invoices/{uuid}', 403],
    ['reviewer', '/account/invoices/{uuid}', 403], ['owner', '/account/invoices/{uuid}', 200], ['admin', '/admin/invoices/{uuid}', 200],
    ['customer', '/admin/invoices/{uuid}', 403], ['author', '/admin/invoices/{uuid}', 403], ['reviewer', '/admin/invoices/{uuid}', 403],
]);

it('renders and privately retains an exact reproducible PDF', function () {
    Storage::fake('product_private');
    $o = IFx::paid(IFx::gst());
    $d = app(IssueInvoice::class)->issue($o);
    $pdf = app(InvoicePdf::class)->bytes($d);
    expect($pdf)->toStartWith('%PDF-')->and(strlen($pdf))->toBeGreaterThan(1000);
    expect(app(InvoicePdf::class)->bytes($d))->toBe($pdf)->and(DB::table('invoice_files')->first()->checksum)->toBe(hash('sha256', $pdf));
    $this->actingAs($o->user)->get('/account/invoices/'.$d->public_uuid.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
});

it('recovers documents idempotently in bounded batches', function () {
    $o = IFx::paid();
    $result = app(InvoiceRecovery::class)->run(1);
    expect($result['errors'])->toBe(0)->and(Invoice::where('order_id', $o->id)->where('kind', 'invoice')->count())->toBe(1);
    expect(app(InvoiceRecovery::class)->run(1))->toBe(['invoices' => 0, 'credits' => 0, 'errors' => 0]);
});

it('copies purchase policy instead of applying later tax changes', function () {
    $o = IFx::order(IFx::gst());
    IFx::configure(['tax_rate' => '5.00', 'legal_name' => 'Changed issuer']);
    EntitlementFixtures::pay($o);
    $d = app(IssueInvoice::class)->issue($o->fresh());
    expect($d->tax_total)->toBe('18.00')->and($d->issuer_snapshot['legal_name'])->toBe('Templaara Test Company');
});

it('keeps the original PDF and billing after account settings change', function () {
    Storage::fake('product_private');
    $o = IFx::paid();
    $d = app(IssueInvoice::class)->issue($o);
    $bytes = app(InvoicePdf::class)->bytes($d);
    $o->user->forceFill(['name' => 'Changed user', 'email' => 'changed@example.test'])->save();
    IFx::configure(['legal_name' => 'New legal issuer']);
    expect(app(InvoicePdf::class)->bytes($d->fresh()))->toBe($bytes)->and($d->fresh()->customer_snapshot['billing_email'])->toBe($o->billing_email);
});

it('rejects corrupted retained PDF bytes', function () {
    Storage::fake('product_private');
    $d = app(IssueInvoice::class)->issue(IFx::paid());
    app(InvoicePdf::class)->bytes($d);
    $file = DB::table('invoice_files')->first();
    Storage::disk($file->disk)->put($file->path, 'corrupt');
    expect(fn () => app(InvoicePdf::class)->bytes($d))->toThrow(RuntimeException::class);
});

it('does not render HTML or remote payloads as executable invoice content', function () {
    $o = IFx::paid();
    $d = app(IssueInvoice::class)->issue($o);
    $html = view('invoicing.document', ['document' => $d->load('items')])->render();
    expect($html)->not->toContain('<script', '<iframe', 'storage_path', 'license_key');
});

it('rejects PDF access from another customer', function () {
    $d = app(IssueInvoice::class)->issue(IFx::paid());
    $this->actingAs(User::factory()->create()->assignRole('customer'))->get('/account/invoices/'.$d->public_uuid.'/pdf')->assertForbidden();
});

it('keeps private storage details out of document props', function () {
    $o = IFx::paid();
    $d = app(IssueInvoice::class)->issue($o);
    $r = $this->actingAs($o->user)->get('/account/invoices/'.$d->public_uuid)->assertOk();
    $r->assertInertia(fn ($p) => $p->has('document')->missing('document.disk')->missing('document.path')->missing('document.payment_payload')->missing('document.license_key'));
});

it('rejects credit notes before refund completion', function () {
    $r = RefundFixtures::request(IFx::paid());
    expect(fn () => app(IssueInvoice::class)->credit($r))->toThrow(ValidationException::class);
    expect(Invoice::where('kind', 'credit_note')->count())->toBe(0);
});

it('recovers the same completed credit without another number', function () {
    $o = IFx::paid(IFx::gst());
    $r = RefundFixtures::request($o);
    RefundFixtures::complete($r);
    $first = app(IssueInvoice::class)->credit($r->fresh());
    expect(app(IssueInvoice::class)->credit($r->fresh())->number)->toBe($first->number)->and(Invoice::where('refund_id', $r->id)->count())->toBe(1);
});

it('copies inclusive tax to refund credit', function () {
    $o = IFx::paid(IFx::gst(true));
    $r = RefundFixtures::request($o);
    RefundFixtures::complete($r);
    $d = app(IssueInvoice::class)->credit($r->fresh());
    expect($r->fresh()->getAttribute('amount'))->toBe('100.00')->and($d->taxable_amount)->toBe('84.75')->and($d->tax_total)->toBe('15.25');
});

it('allocates a bounded number inside a transaction', function () {
    // RefreshDatabase has its own enclosing transaction; use an independent empty connection.
    $connection = DB::connection();
    $level = $connection->transactionLevel();
    expect($level)->toBeGreaterThan(0);
    DB::transaction(function () {
        expect(app(InvoiceNumber::class)->next('invoice', 'INV'))->toMatch('/^INV-\d{2}-\d{6}$/');
    });
});

it('uses a shared counter across changed invoice and credit prefixes', function () {
    $numbers = DB::transaction(fn () => [app(InvoiceNumber::class)->next('invoice', 'INV'), app(InvoiceNumber::class)->next('credit_note', 'INV')]);
    expect($numbers[0])->not->toBe($numbers[1]);
});

it('blocks unsupported precision and overflow in tax calculations', function ($price) {
    $policy = [...array_map(fn ($r) => $r['default'], app(SettingsRegistry::class)->group('invoicing')), ...IFx::gst(), 'identifier' => 'test'];
    expect(fn () => app(TaxCalculator::class)->line($price, ['country_code' => 'IN', 'gst_state_code' => '36'], $policy))->toThrow(match ($price) {
        '100.001' => RoundingNecessaryException::class,'-0.01' => InvalidArgumentException::class,default => ValidationException::class
    });
})->with(['100.001', '999999999999.99', '-0.01']);

it('rejects forged GST place of supply', function () {
    $policy = [...array_map(fn ($r) => $r['default'], app(SettingsRegistry::class)->group('invoicing')), ...IFx::gst(), 'identifier' => 'test'];
    expect(fn () => app(TaxCalculator::class)->line('100.00', ['country_code' => 'IN', 'gst_state_code' => '36', 'customer_gstin' => '27ABCDE1234F1Z5'], $policy))->toThrow(ValidationException::class);
});

it('supports explicit international country policy and no implicit foreign tax', function ($country, $type, $total) {
    $policy = [...array_map(fn ($r) => $r['default'], app(SettingsRegistry::class)->group('invoicing')), 'tax_mode' => 'international', 'tax_rate' => '20.00', 'international_countries' => ['GB'], 'international_type' => 'VAT', 'identifier' => 'test'];
    $line = app(TaxCalculator::class)->line('100.00', ['country_code' => $country], $policy);
    expect($line['type'])->toBe($type)->and($line['grand_total'])->toBe($total);
})->with([['GB', 'VAT', '120.00'], ['US', 'NONE', '100.00'], ['IN', 'NONE', '100.00']]);

it('rejects unbounded invoice recovery', function ($limit) {
    expect(fn () => app(InvoiceRecovery::class)->run($limit))->toThrow(InvalidArgumentException::class);
})->with([0, 501, -1]);

it('collects server tax quote without issuer privacy or trusted client totals', function () {
    $o = IFx::order(IFx::gst());
    $p = CommerceFixtures::product();
    $buyer = $o->user;
    app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
    $r = $this->actingAs($buyer)->postJson('/checkout/quote', ['country_code' => 'IN', 'gst_state_code' => '36', 'subtotal' => '0.01'])->assertOk();
    expect($r->json('tax_total'))->toBe('917.82')->and($r->json())->not->toHaveKeys(['policy', 'issuer_snapshot', 'legal_name']);
});

it('requires confirmation of changed tax before another reservation', function () {
    $o = IFx::order(IFx::gst());
    $p = CommerceFixtures::product();
    $buyer = $o->user;
    app(CartService::class)->add($buyer, $p->slug, $p->offers->first()->id);
    $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
    $billing = [...CommerceFixtures::billing(), 'country_code' => 'IN', 'gst_state_code' => '36'];
    $quote = app(CheckoutTax::class)->quote(['4999.00'], $billing);
    $billing['tax_quote_hash'] = app(CheckoutTax::class)->hash($quote, $billing, $cart->checkout_key);
    IFx::configure(['tax_rate' => '5.00']);
    expect(fn () => app(Checkout::class)->place($buyer, $cart->checkout_key, $billing))->toThrow(ValidationException::class);
    expect(Order::where('user_id', $buyer->id)->count())->toBe(1);
});

it('rejects browser supplied commercial and tax snapshots', function ($field) {
    $o = IFx::order();
    $this->actingAs($o->user)->postJson('/checkout', [...CommerceFixtures::billing(), 'idempotency_key' => $o->checkout_key, $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['tax_snapshot', 'issuer_snapshot', 'policy_snapshot', 'invoice_number', 'tax_total']);

it('keeps aggregate tax reports currency separated and credits original tax', function () {
    $o = IFx::paid(IFx::gst());
    IFx::paid(IFx::gst(), '50.00', 'USD');
    $r = RefundFixtures::request($o);
    RefundFixtures::complete($r);
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin)->get('/admin/reports/tax')->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 2)->where('rows.data.0.currency', 'INR')->where('rows.data.0.tax_collected', '18.00')->where('rows.data.0.tax_credited', '18.00')->where('rows.data.0.net_tax', '0.00')->where('rows.data.1.currency', 'USD'));
});

it('keeps tax exports free of billing identity and storage secrets', function () {
    $o = IFx::paid(IFx::gst());
    $admin = User::factory()->create()->assignRole('admin');
    $r = $this->actingAs($admin)->get('/admin/reports/tax/export?currency=INR')->assertOk();
    $csv = $r->streamedContent();
    expect($csv)->toContain('INR', '18.00')->not->toContain($o->billing_email, 'BUSINESS-TEST', 'Platform Road', 'product_private', 'customer_gstin');
});

it('denies tax analytics to users without reports permission', function ($role) {
    $this->actingAs(User::factory()->create()->assignRole($role))->get('/admin/reports/tax')->assertForbidden();
})->with(['customer', 'author', 'reviewer']);

it('extracts issued identifiers and totals from a valid PDF', function () {
    $d = app(IssueInvoice::class)->issue(IFx::paid(IFx::gst()));
    $path = tempnam(sys_get_temp_dir(), 'invoice-test-');
    file_put_contents($path, app(InvoicePdf::class)->render($d));
    try {
        $text = shell_exec('pdftotext '.escapeshellarg($path).' -');
        expect($text)->toContain($d->number, $d->order->order_number, '118.00', '18.00')->not->toContain('license_key', 'secret_key', 'storage/app');
    } finally {
        unlink($path);
    }
});

it('retains invoice audit instants across database session offsets', function ($timezone) {
    if (DB::getDriverName() === 'pgsql') {
        DB::statement("SET LOCAL TIME ZONE '$timezone'");
    }
    $o = IFx::paid();
    $d = app(IssueInvoice::class)->issue($o);
    $event = DB::table('invoice_events')->where('invoice_id', $d->id)->first();
    expect(CarbonImmutable::parse($event->occurred_at)->timestamp)->toBe($d->fresh()->issued_at->timestamp);
    if (DB::getDriverName() === 'pgsql') {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
})->with(['UTC', 'Asia/Kolkata', 'Pacific/Auckland', 'America/New_York']);

it('rejects direct PostgreSQL writes to retained invoice evidence', function ($table, $column, $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL immutable-document trigger verification.');
    }
    Storage::fake('product_private');
    $d = app(IssueInvoice::class)->issue(IFx::paid());
    app(InvoicePdf::class)->bytes($d);
    $id = DB::table($table)->first()->id;
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update([$column => $value])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class);
})->with([['invoices', 'number', 'FORGED'], ['invoice_items', 'unit_price', '999.00'], ['invoice_events', 'type', 'forged'], ['invoice_files', 'bytes', 1]]);

it('does not strand an eligible credit behind disabled legacy refunds', function () {
    IFx::configure(['enabled' => false]);
    $legacy = EarningFixtures::order();
    $old = RefundFixtures::request($legacy);
    RefundFixtures::complete($old);
    expect(Invoice::where('order_id', $legacy->id)->count())->toBe(0);
    $o = IFx::paid(IFx::gst());
    Event::fake([RefundCompleted::class]);
    $r = RefundFixtures::request($o);
    RefundFixtures::complete($r);
    IFx::configure(['enabled' => false, 'tax_mode' => 'none']);
    expect(app(InvoiceRecovery::class)->run(1))->toBe(['invoices' => 0, 'credits' => 1, 'errors' => 0]);
    expect(Invoice::where('refund_id', $r->id)->count())->toBe(1)->and(Invoice::where('refund_id', $old->id)->count())->toBe(0);
});
