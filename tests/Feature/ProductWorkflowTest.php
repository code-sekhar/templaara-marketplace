<?php

use App\Enums\ProductOrigin;
use App\Enums\ProductReviewDecision;
use App\Enums\ProductStatus;
use App\Enums\ProductWorkflowAction;
use App\Events\Products\ProductApproved;
use App\Events\Products\ProductChangesRequested;
use App\Events\Products\ProductPublished;
use App\Events\Products\ProductRejected;
use App\Events\Products\ProductReviewStarted;
use App\Events\Products\ProductSubmitted;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductLifecycleEvent;
use App\Models\ProductOffer;
use App\Models\ProductSubmission;
use App\Models\Technology;
use App\Models\User;
use App\Services\Products\Workflow\ProductWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProductFileFixtures;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    ProductFileFixtures::fakeStorage();
});

function workflowAuthor(): User
{
    return User::factory()->create()->assignRole(['customer', 'author']);
}
function workflowReviewer(): User
{
    return User::factory()->create()->assignRole('reviewer');
}
function workflowAdmin(): User
{
    return User::factory()->create()->assignRole('admin');
}
function workflowReadyProduct(?User $owner = null, bool $official = false): Product
{
    $product = Product::factory()->create([
        'owner_id' => $official ? null : ($owner ?? workflowAuthor())->id,
        'origin' => $official ? ProductOrigin::Templaara : ProductOrigin::Author,
        'category_id' => Category::factory()->create()->id,
        'short_description' => 'Inventory and billing software for retail and wholesale businesses.',
        'description' => 'Manage billing, purchases, stock and customer dues in one reliable business workspace.',
        'business_problem' => 'Replace manual stock tracking and fragmented billing records.',
        'target_audience' => ['Retail businesses'], 'key_benefits' => ['Faster billing and accurate stock visibility'],
        'demo_url' => 'https://demo.example.com',
    ]);
    $product->industries()->attach(Industry::factory()->create());
    $product->technologies()->attach(Technology::factory()->create());
    ProductFeature::factory()->for($product)->create();
    ProductOffer::factory()->for($product)->create();

    ProductFileFixtures::prepare($product);

    return $product;
}
function workflowSubmit(Product $product, ?User $actor = null): ProductSubmission
{
    return app(ProductWorkflow::class)->transition($actor ?? $product->owner, $product, ProductWorkflowAction::Submit);
}
function workflowStart(Product $product, ProductSubmission $round, User $reviewer): void
{
    app(ProductWorkflow::class)->transition($reviewer, $product, ProductWorkflowAction::StartReview, $round->id);
}
function workflowChanges(Product $product, ProductSubmission $round, User $reviewer): void
{
    app(ProductWorkflow::class)->transition($reviewer, $product, ProductWorkflowAction::RequestChanges, $round->id, [
        'summary' => 'Please clarify the business value.',
        'feedback' => [['section' => 'business_details', 'message' => 'Explain which retail businesses benefit from this product.']],
    ]);
}

test('author submits own ready draft with an immutable snapshot and real actor', function () {
    $author = workflowAuthor();
    $product = workflowReadyProduct($author);
    $this->actingAs($author)->post(route('author.products.submit', $product))->assertRedirect(route('author.products.show', $product));
    $round = $product->submissions()->firstOrFail();
    expect($product->fresh()->status)->toBe(ProductStatus::Submitted)->and($round->round_number)->toBe(1)
        ->and($round->submitted_by)->toBe($author->id)->and($round->getAttribute('submitted_at'))->not->toBeNull()
        ->and($round->snapshot['name'])->toBe($product->name)->and($round->snapshot['features'])->toHaveCount(1);
    expect($product->lifecycleEvents()->firstOrFail()->getAttribute('actor_id'))->toBe($author->id);
});

test('incomplete drafts fail readiness with actionable errors and no history', function (string $field) {
    $author = workflowAuthor();
    $product = workflowReadyProduct($author);
    if (in_array($field, ['target_audience', 'key_benefits'])) {
        $product->update([$field => []]);
    } elseif ($field === 'features') {
        $product->features()->delete();
    } elseif ($field === 'industries') {
        $product->industries()->detach();
    } elseif ($field === 'technologies') {
        $product->technologies()->detach();
    } elseif ($field === 'offers') {
        $product->offers()->delete();
    } else {
        $product->update([$field => null]);
    }
    $this->actingAs($author)->post(route('author.products.submit', $product))->assertSessionHasErrors($field);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft)->and(ProductSubmission::count())->toBe(0)->and(ProductLifecycleEvent::count())->toBe(0);
})->with(['description', 'business_problem', 'target_audience', 'key_benefits', 'features', 'industries', 'technologies', 'offers', 'demo_url']);

test('inactive catalog or product cannot pass submission readiness', function (string $kind) {
    $product = workflowReadyProduct();
    if ($kind === 'category') {
        $product->category->update(['is_active' => false]);
    } elseif ($kind === 'industry') {
        $product->industries()->firstOrFail()->update(['is_active' => false]);
    } elseif ($kind === 'technology') {
        $product->technologies()->firstOrFail()->update(['is_active' => false]);
    } else {
        $product->update(['is_active' => false]);
    }
    $this->actingAs($product->owner)->post(route('author.products.submit', $product))->assertSessionHasErrors();
    expect(ProductSubmission::count())->toBe(0);
})->with(['category', 'industry', 'technology', 'product']);

test('customers other authors and guests cannot submit', function () {
    $product = workflowReadyProduct();
    $this->post(route('author.products.submit', $product))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create()->assignRole('customer'))->post(route('author.products.submit', $product))->assertForbidden();
    $this->actingAs(workflowAuthor())->post(route('author.products.submit', $product))->assertForbidden();
    expect(ProductSubmission::count())->toBe(0);
});

test('duplicate submission clicks are rejected without new rounds', function () {
    $product = workflowReadyProduct();
    workflowSubmit($product);
    $this->actingAs($product->owner)->post(route('author.products.submit', $product))->assertSessionHasErrors('workflow');
    expect($product->submissions()->count())->toBe(1)->and($product->lifecycleEvents()->count())->toBe(1);
});

test('submitted under review approved rejected and published products cannot be edited or deleted', function (string $status) {
    $product = workflowReadyProduct();
    $product->forceFill(['status' => ProductStatus::from($status)])->save();
    $this->actingAs($product->owner)->get(route('author.products.edit', $product))->assertForbidden();
    $this->put(route('author.products.update', $product), [])->assertForbidden();
    $this->delete(route('author.products.destroy', $product))->assertForbidden();
    $this->actingAs(workflowAdmin())->put(route('admin.products.update', $product), [])->assertForbidden();
    $this->delete(route('admin.products.destroy', $product))->assertForbidden();
})->with(['submitted', 'under_review', 'approved', 'rejected', 'published']);

test('reviewer queue is permission protected and lists latest rounds without snapshots', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $this->actingAs(workflowReviewer())->get(route('admin.product-reviews.index'))->assertInertia(fn (Assert $page) => $page
        ->component('product-reviews/Index')->has('records.data', 1)->where('records.data.0.id', $round->id)->missing('records.data.0.snapshot'));
    $this->get(route('admin.product-reviews.show', $round))->assertInertia(fn (Assert $page) => $page
        ->component('product-reviews/Show')->where('product.name', $product->name)->where('actions.start', true));
    $this->actingAs($product->owner)->get(route('admin.product-reviews.index'))->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('customer'))->get(route('admin.product-reviews.index'))->assertForbidden();
});

test('reviewer starts review once and only that reviewer can decide', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    $other = workflowReviewer();
    $this->actingAs($reviewer)->post(route('admin.product-reviews.start', $round))->assertRedirect();
    expect($product->fresh()->status)->toBe(ProductStatus::UnderReview)->and($round->fresh()->reviewed_by)->toBe($reviewer->id);
    $this->actingAs($other)->post(route('admin.product-reviews.start', $round))->assertSessionHasErrors('workflow');
    $this->post(route('admin.product-reviews.approve', $round))->assertSessionHasErrors('workflow');
    $this->actingAs(workflowAdmin())->get(route('admin.product-reviews.show', $round))->assertOk();
    $this->post(route('admin.product-reviews.approve', $round))->assertSessionHasErrors('workflow');
    expect($round->fresh()->reviewed_by)->toBe($reviewer->id)->and($round->fresh()->decision)->toBeNull();
});

test('request changes requires feedback then author sees feedback and can edit but not delete', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    $this->actingAs($reviewer)->post(route('admin.product-reviews.changes', $round), [])->assertSessionHasErrors('feedback');
    $this->post(route('admin.product-reviews.changes', $round), ['feedback' => [['section' => 'demo', 'message' => 'Demo credentials are invalid. Please update them.']]])->assertRedirect();
    expect($product->fresh()->status)->toBe(ProductStatus::ChangesRequested)->and($round->fresh()->decision)->toBe(ProductReviewDecision::ChangesRequested);
    $this->actingAs($product->owner)->get(route('author.products.show', $product))->assertInertia(fn (Assert $page) => $page
        ->where('history.rounds.data.0.feedback.0.message', 'Demo credentials are invalid. Please update them.')
        ->where('workflowActions.submit', true));
    $this->get(route('author.products.edit', $product))->assertOk();
    $offer = $product->offers()->firstOrFail();
    $this->put(route('author.products.update', $product), [
        'name' => $product->name, 'category_id' => $product->category_id, 'short_description' => 'Updated inventory software with improved business billing.', 'currency' => 'INR',
        'offers' => [['id' => $offer->id, 'name' => 'Software', 'price' => '4999.00', 'license_type' => 'single_business', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]],
    ])->assertRedirect();
    $this->delete(route('author.products.destroy', $product))->assertForbidden();
});

test('resubmission preserves old feedback snapshot and creates a new review round', function () {
    $product = workflowReadyProduct();
    $first = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $first, $reviewer);
    workflowChanges($product, $first, $reviewer);
    $oldName = $first->snapshot['name'];
    $product->refresh()->update(['name' => 'Improved Business POS']);
    $this->actingAs($product->owner)->post(route('author.products.submit', $product))->assertRedirect();
    $second = $product->submissions()->firstOrFail();
    expect($second->round_number)->toBe(2)->and($first->fresh()->decision)->toBe(ProductReviewDecision::ChangesRequested)
        ->and($first->fresh()->snapshot['name'])->toBe($oldName)->and($first->feedback()->count())->toBe(1)
        ->and($second->snapshot['name'])->toBe('Improved Business POS')->and($second->reviewed_by)->toBeNull();
    $this->actingAs($reviewer)->post(route('admin.product-reviews.start', $first))->assertSessionHasErrors('workflow');
    $this->post(route('admin.product-reviews.start', $second))->assertRedirect();
    $this->get(route('admin.product-reviews.show', $first))->assertInertia(fn (Assert $page) => $page->where('actions.approve', false));
});

test('rejection requires a reason and prevents ordinary editing or resubmission', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    $this->actingAs($reviewer)->post(route('admin.product-reviews.reject', $round), [])->assertSessionHasErrors('summary');
    $this->post(route('admin.product-reviews.reject', $round), ['summary' => 'The submitted product is unsuitable for this marketplace.'])->assertRedirect();
    expect($product->fresh()->status)->toBe(ProductStatus::Rejected);
    $this->actingAs($product->owner)->get(route('author.products.edit', $product))->assertForbidden();
    $this->post(route('author.products.submit', $product))->assertSessionHasErrors('workflow');
});

test('approval and publication are separate permission protected audited transitions', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    $this->actingAs($product->owner)->post(route('admin.product-reviews.approve', $round))->assertForbidden();
    $this->actingAs($reviewer)->post(route('admin.product-reviews.approve', $round))->assertRedirect();
    expect($product->fresh()->status)->toBe(ProductStatus::Approved)->and($round->fresh()->decision)->toBe(ProductReviewDecision::Approved);
    $this->get('/products/'.$product->slug)->assertNotFound();
    $this->post(route('admin.products.publish', $product))->assertForbidden();
    $admin = workflowAdmin();
    $this->actingAs($admin)->post(route('admin.products.publish', $product))->assertRedirect();
    expect($product->fresh()->status)->toBe(ProductStatus::Published);
    $this->post(route('admin.products.publish', $product))->assertSessionHasErrors('workflow');
    expect($product->lifecycleEvents()->count())->toBe(4);
    $event = $product->lifecycleEvents()->firstOrFail();
    expect($event->getAttribute('actor_id'))->toBe($admin->id)->and($event->getAttribute('occurred_at'))->not->toBeNull();
    $this->get('/products/'.$product->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('marketplace/Show'));
});

test('moderation requires each decision permission and never grants content ownership', function (string $capability, string $endpoint) {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = User::factory()->create()->givePermissionTo('products.review');
    workflowStart($product, $round, $reviewer);
    $this->actingAs($reviewer)->post(route('admin.product-reviews.'.$endpoint, $round), ['summary' => 'The product needs a different implementation.'])->assertForbidden();
    $this->put(route('author.products.update', $product), [])->assertForbidden();
    $reviewer->givePermissionTo($capability);
    expect($reviewer->can($endpoint === 'changes' ? 'requestChanges' : $endpoint, $product))->toBeTrue();
})->with([['products.approve', 'approve'], ['products.reject', 'reject'], ['products.soft-reject', 'changes']]);

test('authors cannot review or approve their own product even with reviewer capabilities', function () {
    $author = workflowAuthor()->assignRole('reviewer');
    $product = workflowReadyProduct($author);
    $round = workflowSubmit($product);
    $this->actingAs($author)->post(route('admin.product-reviews.start', $round))->assertForbidden();
    $this->post(route('admin.product-reviews.approve', $round))->assertForbidden();
});

test('workflow payloads cannot forge actors or status', function (string $endpoint, string $field) {
    $product = workflowReadyProduct();
    $author = $product->owner;
    if ($endpoint === 'submit') {
        $this->actingAs($author)->post(route('author.products.submit', $product), [$field => 999])->assertSessionHasErrors($field);
    } else {
        $round = workflowSubmit($product);
        $reviewer = workflowReviewer();
        if ($endpoint === 'publish') {
            workflowStart($product, $round, $reviewer);
            app(ProductWorkflow::class)->transition($reviewer, $product, ProductWorkflowAction::Approve, $round->id);
            $this->actingAs(workflowAdmin())->post(route('admin.products.publish', $product), [$field => 999])->assertSessionHasErrors($field);
        } else {
            $this->actingAs($reviewer)->post(route('admin.product-reviews.start', $round), [$field => 999])->assertSessionHasErrors($field);
        }
    }
})->with([['submit', 'submitted_by'], ['submit', 'status'], ['start', 'reviewed_by'], ['start', 'actor_id'], ['publish', 'published_by']]);

test('feedback identity comes from actor and injected nested identity is rejected', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    $this->actingAs($reviewer)->post(route('admin.product-reviews.changes', $round), ['feedback' => [['section' => 'general', 'message' => 'Explain the installation package more clearly.', 'created_by' => 999]]])->assertSessionHasErrors('feedback.0');
    workflowChanges($product, $round, $reviewer);
    expect($round->feedback()->firstOrFail()->getAttribute('created_by'))->toBe($reviewer->id);
});

test('official products use the same readiness review and publish process', function () {
    $admin = workflowAdmin();
    $product = workflowReadyProduct(official: true);
    $this->actingAs($admin)->post(route('admin.products.submit', $product))->assertRedirect();
    $round = $product->submissions()->firstOrFail();
    $this->post(route('admin.product-reviews.start', $round))->assertRedirect();
    $this->post(route('admin.product-reviews.approve', $round))->assertRedirect();
    $this->post(route('admin.products.publish', $product))->assertRedirect();
    expect($product->fresh()->status)->toBe(ProductStatus::Published)->and($product->owner_id)->toBeNull();
});

test('review readiness is rechecked before approval and publication', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    $category = $product->category;
    $category->update(['is_active' => false]);
    $this->actingAs($reviewer)->post(route('admin.product-reviews.approve', $round))->assertSessionHasErrors('category_id');
    expect($product->fresh()->status)->toBe(ProductStatus::UnderReview)->and($round->fresh()->decision)->toBeNull();
    $category->update(['is_active' => true]);
    $this->post(route('admin.product-reviews.approve', $round))->assertRedirect();
    $category->update(['is_active' => false]);
    $this->actingAs(workflowAdmin())->post(route('admin.products.publish', $product))->assertSessionHasErrors('category_id');
    expect($product->fresh()->status)->toBe(ProductStatus::Approved);
});

test('history is immutable and closed rounds cannot be overwritten', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    workflowChanges($product, $round, $reviewer);
    expect(fn () => $round->fresh()->forceFill(['decision_summary' => 'Overwritten'])->save())->toThrow(LogicException::class);
    expect(fn () => $round->fresh()->delete())->toThrow(LogicException::class);
    expect(fn () => $round->feedback()->firstOrFail()->forceFill(['message' => 'Overwritten'])->save())->toThrow(LogicException::class);
    expect(fn () => $product->lifecycleEvents()->firstOrFail()->delete())->toThrow(LogicException::class);
});

test('queue filtering and history pagination preserve prior rounds', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    workflowChanges($product, $round, $reviewer);
    workflowSubmit($product, $product->owner);
    $this->actingAs($reviewer)->get(route('admin.product-reviews.index', ['search' => $product->name, 'author' => $product->owner_id, 'category' => $product->category_id, 'status' => 'submitted', 'from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d')]))
        ->assertInertia(fn (Assert $page) => $page->where('records.total', 1)->where('records.data.0.round_number', 2));
    $this->actingAs($product->owner)->get(route('author.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('history.rounds.total', 2)->where('history.events.total', 4));
});

test('workflow actors cannot delete accounts required by moderation audit', function () {
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    $this->actingAs($reviewer)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    $this->assertAuthenticatedAs($reviewer);
});

test('PostgreSQL lifecycle constraints accept enum values and reject invalid status and audit rewrites', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint verification.');
    }
    $product = workflowReadyProduct();
    foreach (ProductStatus::cases() as $status) {
        DB::table('products')->where('id', $product->id)->update(['status' => $status->value]);
    }
    expect(fn () => DB::transaction(fn () => DB::table('products')->where('id', $product->id)->update(['status' => 'nonsense'])))->toThrow(QueryException::class);
    DB::table('products')->where('id', $product->id)->update(['status' => 'draft']);
    $round = workflowSubmit($product);
    $event = $product->lifecycleEvents()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('product_lifecycle_events')->where('id', $event->id)->update(['actor_id' => $product->owner_id])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('product_submissions')->where('id', $round->id)->update(['snapshot' => '{}'])))->toThrow(QueryException::class);
});

test('workflow emits domain events after commit and never for failed transitions', function () {
    Event::fake([
        ProductSubmitted::class,
        ProductReviewStarted::class,
        ProductChangesRequested::class,
        ProductApproved::class,
        ProductPublished::class,
        ProductRejected::class,
    ]);
    $product = workflowReadyProduct();
    $round = workflowSubmit($product);
    Event::assertDispatched(ProductSubmitted::class, fn ($event) => $event->actorId === $product->owner_id && $event->submissionId === $round->id);
    $reviewer = workflowReviewer();
    workflowStart($product, $round, $reviewer);
    workflowChanges($product, $round, $reviewer);
    $second = workflowSubmit($product);
    workflowStart($product, $second, $reviewer);
    app(ProductWorkflow::class)->transition($reviewer, $product, ProductWorkflowAction::Approve, $second->id);
    app(ProductWorkflow::class)->transition(workflowAdmin(), $product, ProductWorkflowAction::Publish);
    foreach ([ProductReviewStarted::class, ProductChangesRequested::class, ProductApproved::class, ProductPublished::class] as $event) {
        Event::assertDispatched($event);
    }
    expect(fn () => workflowSubmit($product))->toThrow(ValidationException::class);
    Event::assertDispatchedTimes(ProductSubmitted::class, 2);
    $rejected = workflowReadyProduct();
    $third = workflowSubmit($rejected);
    workflowStart($rejected, $third, $reviewer);
    app(ProductWorkflow::class)->transition($reviewer, $rejected, ProductWorkflowAction::Reject, $third->id, ['summary' => 'This software is unsuitable for our marketplace.']);
    Event::assertDispatched(ProductRejected::class);
});

test('author product edits cannot set lifecycle status directly', function () {
    $product = workflowReadyProduct();
    $this->actingAs($product->owner)->put(route('author.products.update', $product), ['status' => 'published'])->assertSessionHasErrors('status');
    expect($product->fresh()->status)->toBe(ProductStatus::Draft)->and(ProductLifecycleEvent::count())->toBe(0);
});

test('review queue accepts an end date without a start date', function () {
    workflowSubmit(workflowReadyProduct());
    $this->actingAs(workflowReviewer())->get(route('admin.product-reviews.index', ['to' => now()->format('Y-m-d')]))
        ->assertInertia(fn (Assert $page) => $page->where('records.total', 1));
});

test('rolled back transitions preserve draft state and dispatch no domain event', function () {
    Event::fake([ProductSubmitted::class]);
    $product = workflowReadyProduct();
    expect(fn () => DB::transaction(function () use ($product) {
        workflowSubmit($product);
        throw new RuntimeException('Roll back the encompassing transaction.');
    }))->toThrow(RuntimeException::class);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft)->and(ProductSubmission::count())->toBe(0)->and(ProductLifecycleEvent::count())->toBe(0);
    Event::assertNotDispatched(ProductSubmitted::class);
});
