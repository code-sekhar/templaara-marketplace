<?php

use App\Events\Reviews\ProductReviewCreated;
use App\Events\Reviews\ProductReviewHidden;
use App\Events\Reviews\ProductReviewResponseCreated;
use App\Events\Reviews\ProductReviewResponseUpdated;
use App\Events\Reviews\ProductReviewRestored;
use App\Events\Reviews\ProductReviewUpdated;
use App\Events\Reviews\ProductReviewWithdrawn;
use App\Models\CommissionPolicy;
use App\Models\ProductReview;
use App\Models\ProductReviewEvent;
use App\Models\ProductReviewResponse;
use App\Models\User;
use App\Services\Refunds\RefundWorkflow;
use App\Services\Reviews\ProductRatingSummary;
use App\Services\Reviews\ReviewPresentation;
use App\Services\Reviews\ReviewWorkflow;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommerceFixtures;
use Tests\Support\EarningFixtures;
use Tests\Support\EntitlementFixtures;
use Tests\Support\ProductFileFixtures;
use Tests\Support\RefundFixtures as RF;
use Tests\Support\ReviewFixtures as RV;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionPolicySeeder::class);
    CommissionPolicy::first()->forceFill(['hold_days' => 0])->save();
    ProductFileFixtures::fakeStorage();
    config()->set('commerce.gateway', 'development');
    config()->set('commerce.development_enabled', true);
    config()->set('refunds.window_days', '7');
});
function summary14(ProductReview $r): array
{
    return app(ProductRatingSummary::class)->forProducts([$r->getAttribute('product_id')])[$r->getAttribute('product_id')];
}
function admin14(): User
{
    return User::factory()->create()->assignRole('admin');
}
function customer14(ProductReview $r): User
{
    return User::findOrFail($r->getAttribute('customer_user_id'));
}
function action14(ProductReview $r, string $action, array $input = []): ProductReview
{
    return app(ReviewWorkflow::class)->action(customer14($r), $r, $action, $input);
}

test('guest cannot create review', function () {
    $p = EarningFixtures::product();
    $this->post(route('account.reviews.store', $p->slug), RV::content())->assertRedirect(route('login'));
});
test('unpaid failed and inconsistent purchases do not qualify', function (string $case) {
    $o = RV::purchase(paid: false);
    if ($case !== 'pending') {
        $o = EntitlementFixtures::pay($o, $case === 'failed' ? 'failed' : 'paid');
    }
    if ($case === 'license') {
        $o->items->first()->entitlement->license->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
    }
    if ($case === 'entitlement') {
        $o->items->first()->entitlement->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
    }
    expect(fn () => RV::review($o))->toThrow(ValidationException::class);
})->with(['pending', 'failed', 'license', 'entitlement']);
test('verified purchase publishes real integer rating', function (int $rating) {
    $r = RV::review(rating: $rating);
    expect($r->status->value)->toBe('published')->and($r->getAttribute('is_verified'))->toBeTrue()->and(summary14($r))->toMatchArray(['average' => $rating.'.00', 'count' => 1]);
})->with([1, 5]);
test('invalid review rating text and markup rejected', function (string $field, mixed $value) {
    $o = RV::purchase();
    expect(fn () => app(ReviewWorkflow::class)->create(User::findOrFail($o->user_id), $o->items->first()->product, [...RV::content(), $field => $value]))->toThrow(ValidationException::class);
})->with([['rating', 0], ['rating', 6], ['rating', 1.5], ['rating', null], ['body', 'Too short'], ['body', str_repeat('a', 5001)], ['title', str_repeat('a', 121)], ['body', '<script>alert("private")</script> not permitted review text'], ['title', '<b>Title</b>'], ['body', ['bad']], ['title', ['bad']]]);
test('unrelated product purchase cannot authorize review', function () {
    $o = RV::purchase();
    $other = EarningFixtures::product();
    expect(fn () => app(ReviewWorkflow::class)->create(User::findOrFail($o->user_id), $other, RV::content()))->toThrow(ValidationException::class);
});
test('protected review payload never sets authoritative values', function (string $field, mixed $value) {
    $o = RV::purchase();
    $buyer = User::findOrFail($o->user_id);
    $this->actingAs($buyer)->post(route('account.reviews.store', $o->items->first()->product->slug), [...RV::content(), $field => $value])->assertRedirect();
    $r = ProductReview::first();
    expect($r->getAttribute('customer_user_id'))->toBe($buyer->id)->and($r->getAttribute('is_verified'))->toBeTrue()->and($r->status->value)->toBe('published')->and($r->getAttribute('verified_order_item_id'))->toBe($o->items->first()->id);
})->with([['customer_user_id', 99999], ['is_verified', false], ['status', 'hidden'], ['verified_order_item_id', 99999], ['product_id', 99999], ['owner_id', 99999], ['average_rating', '5.00']]);
test('repeat purchases choose oldest and keep one canonical review', function () {
    $o = RV::purchase();
    $u = User::findOrFail($o->user_id);
    RV::purchase($o->items->first()->product, $u);
    $r = RV::review($o);
    expect($r->getAttribute('verified_order_item_id'))->toBe($o->items->first()->id);
    expect(fn () => RV::review($o))->toThrow(ValidationException::class);
    expect(ProductReview::count())->toBe(1);
});
test('customer edits rating without incrementing count', function () {
    $r = RV::review();
    action14($r, 'edit', RV::content(4));
    expect(summary14($r))->toMatchArray(['average' => '4.00', 'count' => 1])->and($r->fresh()->getAttribute('edited_at'))->not->toBeNull();
});
test('customer review mutations and pages prevent IDOR', function (string $action) {
    $r = RV::review();
    $this->actingAs(User::factory()->create()->assignRole('customer'));
    match ($action) {
        'show' => $this->get(route('account.reviews.show', $r))->assertNotFound(),'edit' => $this->put(route('account.reviews.update', $r), RV::content())->assertNotFound(),default => $this->post(route('account.reviews.'.$action, $r))->assertNotFound()
    };
})->with(['show', 'edit', 'withdraw', 'restore']);
test('withdrawal retains canonical review and permits explicit restore', function () {
    $r = RV::review();
    action14($r, 'withdraw');
    expect(summary14($r)['count'])->toBe(0)->and(ProductReview::count())->toBe(1);
    expect(fn () => action14($r, 'edit', RV::content()))->toThrow(ValidationException::class);
    action14($r->fresh(), 'restore');
    expect(summary14($r)['count'])->toBe(1);
});
test('admin hide is reversible and independent of customer withdrawal', function () {
    $r = RV::review();
    $a = admin14();
    $s = app(ReviewWorkflow::class);
    $s->action($a, $r, 'hide', ['reason' => 'Abusive review under moderation']);
    expect(summary14($r)['count'])->toBe(0);
    action14($r, 'edit', RV::content(4));
    action14($r, 'withdraw');
    action14($r->fresh(), 'restore');
    expect(summary14($r)['count'])->toBe(0)->and($r->fresh()->status->value)->toBe('hidden');
    $s->action($a, $r, 'unhide');
    expect(summary14($r)['average'])->toBe('4.00')->and(ProductReviewEvent::where('type', 'hidden_by_admin')->value('reason'))->toBe('Abusive review under moderation');
});
test('hide requires reason and existing manage permission', function (string $case) {
    $r = RV::review();
    $u = $case === 'reason' ? admin14() : User::factory()->create()->givePermissionTo('reviews.view');
    if ($case === 'reason') {
        expect(fn () => app(ReviewWorkflow::class)->action($u, $r, 'hide'))->toThrow(ValidationException::class);
    } else {
        $this->actingAs($u)->get(route('admin.reviews.show', $r))->assertOk();
        $this->post(route('admin.reviews.hide', $r), ['reason' => 'Meaningful reason'])->assertForbidden();
    }
})->with(['reason', 'read_only']);
test('completed refund removes review verification and preserves text history', function () {
    $o = RV::purchase();
    $r = RV::review($o);
    RF::complete(RF::request($o));
    expect(summary14($r)['count'])->toBe(0)->and($r->fresh()->getAttribute('is_verified'))->toBeFalse()->and($r->fresh()->getAttribute('body'))->toBe(RV::content()['body'])->and(ProductReviewEvent::where('type', 'verification_lost')->count())->toBe(1);
    expect(fn () => action14($r, 'edit', RV::content()))->toThrow(ValidationException::class);
});
test('uncompleted refunds do not affect verified review', function (string $state) {
    $o = RV::purchase();
    $r = RV::review($o);
    $f = RF::request($o);
    if ($state === 'approved') {
        app(RefundWorkflow::class)->transition(admin14(), $f, 'approve');
    }
    if (in_array($state, ['processing', 'failed'])) {
        $a = RF::process($f);
    }if ($state === 'failed') {
        app(RefundWorkflow::class)->transition($a, $f, 'fail', ['reason' => 'External refund did not succeed'], true);
    }if ($state === 'cancelled') {
        app(RefundWorkflow::class)->transition(User::findOrFail($o->user_id), $f, 'cancel');
    }expect(summary14($r)['count'])->toBe(1);
})->with(['requested', 'approved', 'processing', 'failed', 'cancelled']);
test('alternate purchase rebinds verified review without changing rating content', function () {
    $o = RV::purchase();
    $r = RV::review($o);
    $next = RV::purchase($o->items->first()->product, User::findOrFail($o->user_id));
    RF::complete(RF::request($o));
    expect($r->fresh()->getAttribute('verified_order_item_id'))->toBe($next->items->first()->id)->and(summary14($r)['count'])->toBe(1)->and($r->fresh()->getAttribute('body'))->toBe(RV::content()['body']);
});
test('repurchase requires explicit canonical review restoration', function () {
    $o = RV::purchase();
    $r = RV::review($o);
    RF::complete(RF::request($o));
    RV::purchase($o->items->first()->product, User::findOrFail($o->user_id));
    expect(summary14($r)['count'])->toBe(0);
    action14($r->fresh(), 'restore');
    expect(summary14($r)['count'])->toBe(1)->and(ProductReview::count())->toBe(1);
});
test('seller posts and edits one response without customer review authority', function () {
    $r = RV::review();
    $author = $r->product->owner;
    $s = app(ReviewWorkflow::class);
    $s->action($author, $r, 'respond', ['body' => 'Thank you for sharing your experience.', 'author_user_id' => 999]);
    expect($r->fresh()->response->getAttribute('author_user_id'))->toBe($author->id);
    expect(fn () => $s->action($author, $r, 'respond', ['body' => 'Duplicate response']))->toThrow(ValidationException::class);
    $s->action($author, $r, 'edit_response', ['body' => 'Thank you, we are glad this works for your business.']);
    expect(ProductReviewResponse::count())->toBe(1)->and($r->fresh()->response->getAttribute('edited_at'))->not->toBeNull();
    expect(fn () => $s->action($author, $r, 'hide', ['reason' => 'Author tries hide']))->toThrow(AuthorizationException::class);
});
test('other author cannot respond or see review workspace detail', function () {
    $r = RV::review();
    $u = User::factory()->create()->assignRole('author');
    $this->actingAs($u)->get(route('author.reviews.show', $r))->assertNotFound();
    $this->post(route('author.reviews.respond', $r), ['body' => 'Invalid seller'])->assertForbidden();
});
test('official product response uses real admin with Templaara identity', function () {
    $o = RV::purchase(CommerceFixtures::product());
    $r = RV::review($o);
    app(ReviewWorkflow::class)->action(admin14(), $r, 'respond', ['body' => 'Thank you from Templaara.']);
    expect(app(ReviewPresentation::class)->row($r->fresh()->load(['product', 'customer', 'purchase', 'response.author']))['response']['seller'])->toBe('Templaara');
});
test('self review and internal official rating are rejected', function (string $case) {
    $p = $case === 'author' ? EarningFixtures::product() : CommerceFixtures::product();
    $buyer = $case === 'author' ? $p->owner : admin14();
    $o = RV::purchase($p, $buyer);
    expect(fn () => RV::review($o))->toThrow(ValidationException::class);
})->with(['author', 'official_admin']);
test('public presentation exposes no financial customer or refund internals', function () {
    $r = RV::review();
    $this->get('/products/'.$r->product->slug)->assertInertia(fn (Assert $p) => $p->where('reviews.records.data.0.verified_purchase', true)->where('reviews.summary.average', '5.00')->where('reviews.records.data.0.reviewer', fn ($v) => ! str_contains($v, '@')));
    $data = json_encode(app(ReviewPresentation::class)->row($r->load(['product', 'customer', 'purchase', 'response.author'])));
    foreach (['email', 'order_number', 'license_key', 'artifact', 'refund', 'payment', 'billing', 'customer_user_id'] as $key) {
        expect($data)->not->toContain($key);
    }
});
test('hidden withdrawn and unverified reviews are absent from public storefront', function (string $state) {
    $r = RV::review();
    if ($state === 'hidden') {
        app(ReviewWorkflow::class)->action(admin14(), $r, 'hide', ['reason' => 'Abusive review content']);
    } elseif ($state === 'withdrawn') {
        action14($r, 'withdraw');
    } else {
        RF::complete(RF::request($r->purchase->order));
    }$this->get('/products/'.$r->product->slug)->assertInertia(fn (Assert $p) => $p->has('reviews.records.data', 0)->where('reviews.summary.count', 0)->where('product.rating.average', null));
})->with(['hidden', 'withdrawn', 'refunded']);
test('integer sum produces correct decimal average and distribution across currencies', function () {
    $product = EarningFixtures::product();
    foreach ([5, 5, 4] as $rating) {
        RV::review(RV::purchase($product), $rating);
    }$s = app(ProductRatingSummary::class)->forProducts([$product->id])[$product->id];
    expect($s)->toMatchArray(['average' => '4.67', 'count' => 3, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 2]]);
});
test('review queue customer and author scopes are authoritative', function () {
    $r = RV::review();
    $other = RV::review();
    $this->actingAs(customer14($r))->get(route('account.reviews.index'))->assertInertia(fn (Assert $p) => $p->has('records.data', 1)->where('records.data.0.uuid', $r->getAttribute('public_uuid')));
    $this->actingAs($r->product->owner)->get(route('author.reviews.index'))->assertInertia(fn (Assert $p) => $p->has('records.data', 1));
});
test('review model and event history cannot be destructively deleted', function (string $model) {
    $r = RV::review();
    $row = $model === 'review' ? $r : ProductReviewEvent::first();
    expect(fn () => $row->delete())->toThrow(LogicException::class);
    if ($model === 'event') {
        expect(fn () => $row->forceFill(['reason' => 'Rewritten'])->save())->toThrow(LogicException::class);
    }
})->with(['review', 'event']);
test('review domain events are safe and rollback does not emit', function () {
    $o = RV::purchase();
    Event::fake([ProductReviewCreated::class]);
    expect(fn () => DB::transaction(function () use ($o) {
        RV::review($o);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);
    Event::assertNotDispatched(ProductReviewCreated::class);
    $r = RV::review($o);
    Event::assertDispatched(ProductReviewCreated::class, fn ($e) => get_object_vars($e) === ['reviewId' => $r->id]);
});
test('review mutations have authenticated rate limits', function () {
    $o = RV::purchase();
    $u = User::findOrFail($o->user_id);
    $this->actingAs($u);
    for ($i = 0; $i < 30; $i++) {
        $this->post(route('account.reviews.store', $o->items->first()->product->slug), ['rating' => 0])->assertSessionHasErrors();
    }$this->post(route('account.reviews.store', $o->items->first()->product->slug), RV::content())->assertStatus(429);
});

test('response malformed or HTML content fails plain text validation', function (mixed $body) {
    $r = RV::review();
    expect(fn () => app(ReviewWorkflow::class)->action($r->product->owner, $r, 'respond', ['body' => $body]))->toThrow(ValidationException::class);
})->with(['x', str_repeat('a', 3001), '<script>alert(1)</script>', [['array']]]);
test('hidden review hides its response and seller workspace', function () {
    $r = RV::review();
    app(ReviewWorkflow::class)->action($r->product->owner, $r, 'respond', ['body' => 'Thanks for the review.']);
    app(ReviewWorkflow::class)->action(admin14(), $r, 'hide', ['reason' => 'Moderation action taken']);
    $this->get('/products/'.$r->product->slug)->assertInertia(fn (Assert $p) => $p->has('reviews.records.data', 0));
    $this->actingAs($r->product->owner)->get(route('author.reviews.index'))->assertInertia(fn (Assert $p) => $p->has('records.data', 0));
});
test('review sorting and pagination are deterministic with filter persistence', function (string $sort) {
    $p = EarningFixtures::product();
    $reviews = [];
    for ($i = 0; $i < 11; $i++) {
        $reviews[] = RV::review(RV::purchase($p), ($i % 5) + 1);
    }$expected = collect($reviews)->sort(function ($a, $b) use ($sort) {
        if ($sort === 'highest_rating' && $a->getAttribute('rating') !== $b->getAttribute('rating')) {
            return $b->getAttribute('rating') <=> $a->getAttribute('rating');
        }if ($sort === 'lowest_rating' && $a->getAttribute('rating') !== $b->getAttribute('rating')) {
            return $a->getAttribute('rating') <=> $b->getAttribute('rating');
        }

        return $b->id <=> $a->id;
    })->first();
    $this->get('/products/'.$p->slug.'?review_sort='.$sort)->assertInertia(fn (Assert $v) => $v->has('reviews.records.data', 10)->where('reviews.records.data.0.uuid', $expected->getAttribute('public_uuid'))->where('reviews.records.next_page_url', fn ($v) => str_contains($v, 'review_sort='.$sort)));
    $this->get('/products/'.$p->slug.'?review_sort='.$sort.'&reviews_page=2')->assertInertia(fn (Assert $v) => $v->has('reviews.records.data', 1));
})->with(['newest', 'highest_rating', 'lowest_rating']);
test('refund of unrelated product preserves canonical review', function () {
    $o = RF::mixed();
    $r = RV::review($o);
    $other = $o->items->last();
    $f = app(RefundWorkflow::class)->request(User::findOrFail($o->user_id), $o, RF::input($o, [$other->id]));
    RF::complete($f);
    expect(summary14($r)['count'])->toBe(1)->and($r->fresh()->getAttribute('is_verified'))->toBeTrue();
});
test('all review events carry only safe IDs', function () {
    $types = [ProductReviewCreated::class, ProductReviewUpdated::class, ProductReviewWithdrawn::class, ProductReviewHidden::class, ProductReviewRestored::class, ProductReviewResponseCreated::class, ProductReviewResponseUpdated::class];
    Event::fake($types);
    $r = RV::review();
    action14($r, 'edit', RV::content(4));
    $s = app(ReviewWorkflow::class);
    $s->action($r->product->owner, $r, 'respond', ['body' => 'Thank you']);
    $s->action($r->product->owner, $r, 'edit_response', ['body' => 'Thank you again']);
    action14($r, 'withdraw');
    action14($r->fresh(), 'restore');
    $a = admin14();
    $s->action($a, $r, 'hide', ['reason' => 'Temporary moderation']);
    $s->action($a, $r, 'unhide');
    foreach ($types as $type) {
        Event::assertDispatched($type, fn ($e) => array_keys(get_object_vars($e)) === ['reviewId']);
    }
});
test('withdrawn historical reviews protect product and account deletion', function () {
    $r = RV::review();
    action14($r, 'withdraw');
    expect(fn () => DB::transaction(fn () => $r->product->delete()))->toThrow(QueryException::class);
    $this->actingAs(customer14($r))->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
});
test('public review queries recheck access even without reconciliation', function () {
    $r = RV::review();
    $r->purchase->entitlement->license->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
    expect($r->fresh()->getAttribute('is_verified'))->toBeTrue()->and(summary14($r)['count'])->toBe(0);
});
test('review entry points exist for qualifying library order and product', function () {
    $o = RV::purchase();
    $u = User::findOrFail($o->user_id);
    $url = route('account.reviews.create', $o->items->first()->product->slug);
    $this->actingAs($u)->get(route('commerce.orders.show', $o))->assertInertia(fn (Assert $p) => $p->where('order.items.0.review_entry.url', $url));
    $this->get(route('library.show', $o->items->first()->entitlement))->assertInertia(fn (Assert $p) => $p->where('purchase.review_entry.url', $url));
    $this->get('/products/'.$o->items->first()->product->slug)->assertInertia(fn (Assert $p) => $p->where('reviews.entry.url', $url));
});
function reject14(callable $fn): void
{
    expect(fn () => DB::transaction(function () use ($fn) {
        $fn();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
}
test('postgresql enforces review rating and controlled states', function (string $field, mixed $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RV::review();
    reject14(fn () => DB::table('product_reviews')->where('id', $r->id)->update([$field => $value, 'edited_at' => now(), 'event_sequence' => 2]));
})->with([['rating', 0], ['rating', 6], ['status', 'pending'], ['status', 'hidden'], ['customer_user_id', 999999], ['verified_order_item_id', 999999], ['product_id', 999999]]);
test('postgresql protects review and response economic identity and history', function (string $table, string $operation) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RV::review();
    app(ReviewWorkflow::class)->action($r->product->owner, $r, 'respond', ['body' => 'Thank you for reviewing']);
    reject14(function () use ($table, $operation) {
        $q = DB::table($table);
        $operation === 'delete' ? $q->delete() : $q->update($table === 'product_reviews' ? ['rating' => 3] : ($table === 'product_review_events' ? ['reason' => 'Rewritten audit'] : ['body' => 'Rewritten body']));
    });
})->with([['product_reviews', 'delete'], ['product_reviews', 'update'], ['product_review_events', 'delete'], ['product_review_events', 'update'], ['product_review_responses', 'delete'], ['product_review_responses', 'update']]);
test('postgresql disallows duplicate canonical review and mismatched real purchase binding', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RV::review();
    $attributes = $r->getAttributes();
    $attributes['public_uuid'] = (string) Str::uuid();
    unset($attributes['id']);
    if ($case === 'other_purchase') {
        $attributes['verified_order_item_id'] = RV::purchase()->items->first()->id;
    }reject14(fn () => DB::table('product_reviews')->insert($attributes));
})->with(['duplicate', 'other_purchase']);
test('postgresql requires audit for raw review edit or response', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity');
    }$r = RV::review();
    reject14(fn () => $case === 'edit' ? DB::table('product_reviews')->where('id', $r->id)->update(['rating' => 4, 'edited_at' => now(), 'event_sequence' => 2]) : DB::table('product_review_responses')->insert(['public_uuid' => (string) Str::uuid(), 'review_id' => $r->id, 'author_user_id' => $r->product->owner_id, 'body' => 'Unaudited seller response', 'published_at' => now(), 'review_sequence' => 2, 'created_at' => now(), 'updated_at' => now()]));
})->with(['edit', 'response']);

test('product owner cannot edit customer review or access another customer history', function () {
    $r = RV::review();
    $this->actingAs($r->product->owner)->put(route('account.reviews.update', $r), RV::content(1))->assertNotFound();
    $this->get(route('account.reviews.show', $r))->assertNotFound();
    expect($r->fresh()->rating)->toBe(5);
});
test('reviewer cannot moderate or respond without explicit permissions', function () {
    $r = RV::review();
    $reviewer = User::factory()->create()->assignRole('reviewer');
    $this->actingAs($reviewer)->post(route('admin.reviews.hide', $r), ['reason' => 'Attempted unauthorized hiding'])->assertForbidden();
    expect(fn () => app(ReviewWorkflow::class)->action($reviewer, $r, 'respond', ['body' => 'Unauthorized response']))->toThrow(AuthorizationException::class);
});
