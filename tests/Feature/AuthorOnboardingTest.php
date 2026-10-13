<?php

use App\Enums\AuthorApplicationAction as Action;
use App\Enums\AuthorApplicationStatus as Status;
use App\Events\Authors\AuthorApplicationApproved;
use App\Http\Requests\Commerce\CheckoutRequest;
use App\Models\AuthorApplication;
use App\Models\AuthorApplicationEvent;
use App\Models\AuthorProfile;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Authors\AuthorWorkflow;
use App\Support\Countries;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthorFixtures as F;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('Fortify still starts registration with customer only', function (): void {
    $this->post('/register', ['name' => 'New Seller', 'email' => 'new-seller@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'author'])->assertRedirect();
    expect(User::where('email', 'new-seller@example.com')->firstOrFail()->getRoleNames()->all())->toBe(['customer']);
});
test('authenticated verified customer can begin onboarding without promotion', function (): void {
    $u = F::user();
    $this->actingAs($u)->get('/author/onboarding')->assertInertia(fn (Assert $p) => $p->component('authors/Onboarding')->where('profile', null)->where('editable', true));
    expect($u->fresh()->hasRole('author'))->toBeFalse()->and(AuthorProfile::count())->toBe(0);
});
test('guests and unverified accounts cannot begin onboarding', function (): void {
    $this->get('/author/onboarding')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/author/onboarding')->assertRedirect('/email/verify');
});
test('draft can be incomplete and support contact does not change login identity', function (): void {
    $u = F::user();
    $email = $u->email;
    $this->actingAs($u)->put('/author/onboarding', ['support_email' => 'other-support@example.com'])->assertRedirect();
    $p = AuthorProfile::firstOrFail();
    expect($p->status)->toBe(Status::Draft)->and($p->user_id)->toBe($u->id)->and($u->fresh()->email)->toBe($email)->and($u->fresh()->hasRole('author'))->toBeFalse();
    $this->put('/author/onboarding', ['revision' => $p->fresh()->revision])->assertRedirect();
    expect(AuthorProfile::count())->toBe(1);
});
test('forged profile workflow fields are prohibited', function (string $field): void {
    $u = F::user();
    $this->actingAs($u)->put('/author/onboarding', F::ready([$field => 'forged']))->assertSessionHasErrors($field);
    expect(AuthorProfile::count())->toBe(0)->and($u->fresh()->hasRole('author'))->toBeFalse();
})->with(['user_id', 'status', 'reviewed_by', 'roles', 'role', 'author', 'round_number', 'snapshot', 'is_legacy', 'decision_at']);
test('another user cannot choose or edit someone else profile', function (): void {
    $p = F::profile();
    $other = F::user();
    $this->actingAs($other)->put('/author/onboarding', F::ready(['user_id' => $p->user_id]))->assertSessionHasErrors('user_id');
    $this->put('/author/profile', F::ready())->assertForbidden();
    expect($p->fresh()->getAttribute('display_name'))->toBe('Acme Business Software');
    $this->put('/author/onboarding', F::ready(['display_name' => 'Other seller']))->assertRedirect();
    expect(AuthorProfile::count())->toBe(2)->and($p->fresh()->getAttribute('display_name'))->toBe('Acme Business Software');
});
test('submission readiness requires meaningful profile fields', function (string $field, mixed $value): void {
    $p = F::profile(extra: [$field => $value]);
    $this->actingAs($p->user)->post('/author/onboarding/submit')->assertSessionHasErrors($field);
    expect($p->fresh()->status)->toBe(Status::Draft)->and(AuthorApplication::count())->toBe(0);
})->with([['display_name', null], ['author_type', null], ['country_code', null], ['bio', 'Too short'], ['support_email', null], ['website_url', null]]);
test('company requires name and LinkedIn alone is not professional presence', function (): void {
    $p = F::profile(extra: ['author_type' => 'company', 'company_name' => null, 'website_url' => null, 'linkedin_url' => 'https://linkedin.com/in/example']);
    $this->actingAs($p->user)->post('/author/onboarding/submit')->assertSessionHasErrors(['company_name', 'website_url']);
});
test('invalid optional URLs and controlled metadata are rejected', function (string $field, string $value): void {
    $this->actingAs(F::user())->put('/author/onboarding', F::ready([$field => $value]))->assertSessionHasErrors($field);
})->with([['country_code', 'ZZ'], ['author_type', 'agency'], ['website_url', 'javascript:alert(1)'], ['portfolio_url', 'ftp://example.com'], ['github_url', 'bad-url'], ['linkedin_url', 'data:text/html,bad'], ['support_email', 'not-email'], ['slug', '../bad']]);
test('author slug generation and manual uniqueness are authoritative', function (): void {
    $p = F::profile();
    $p2 = F::profile();
    expect($p->getAttribute('slug'))->toBe('acme-business-software')->and($p2->getAttribute('slug'))->toBe('acme-business-software-2');
    $this->actingAs($p2->user)->put('/author/onboarding', F::ready(['slug' => $p->getAttribute('slug')]))->assertSessionHasErrors('slug');
    $this->put('/author/onboarding', F::ready(['slug' => $p2->getAttribute('slug'), 'revision' => $p2->fresh()->revision]))->assertRedirect();
});
test('submission freezes snapshot records audit and does not grant author', function (): void {
    $p = F::profile();
    $this->actingAs($p->user)->post('/author/onboarding/submit')->assertRedirect();
    $a = AuthorApplication::firstOrFail();
    expect($a->snapshot['display_name'])->toBe('Acme Business Software')->and($a->snapshot['support_email'])->toBe('support@example.com')->and($a->round_number)->toBe(1)->and($p->fresh()->status)->toBe(Status::Submitted)->and($p->user->fresh()->hasRole('author'))->toBeFalse();
    $e = AuthorApplicationEvent::firstOrFail();
    expect($e->getAttribute('actor_id'))->toBe($p->user_id)->and($e->getAttribute('action'))->toBe('submitted')->and($e->getAttribute('occurred_at'))->not->toBeNull();
    $this->post('/author/onboarding/submit')->assertForbidden();
    $this->put('/author/onboarding', F::ready(['display_name' => 'Changed']))->assertForbidden();
});
test('submitted applicant cannot create products or access author dashboard', function (): void {
    $a = F::submitted();
    $this->actingAs($a->user)->get('/author/products/create')->assertForbidden();
    $this->get('/author')->assertForbidden();
});
test('review queue is permission based and default reviewer cannot decide', function (): void {
    $a = F::submitted();
    $reviewer = F::user('reviewer');
    $this->actingAs($reviewer)->get('/admin/author-applications')->assertInertia(fn (Assert $p) => $p->component('authors/Applications')->where('records.total', 1));
    $this->post("/admin/author-applications/{$a->id}/start")->assertForbidden();
    $this->actingAs(F::user())->get('/admin/author-applications')->assertForbidden();
});
test('explicit verify grant permits reviewer claim and competing claim is blocked', function (): void {
    $a = F::submitted();
    $r = F::user('reviewer')->givePermissionTo('authors.verify');
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/start")->assertRedirect();
    expect($a->fresh()->reviewed_by)->toBe($r->id);
    $this->actingAs(F::user('admin'))->post("/admin/author-applications/{$a->id}/start")->assertSessionHasErrors('workflow');
    $this->post("/admin/author-applications/{$a->id}/approve")->assertSessionHasErrors('workflow');
});
test('applicant cannot review self even with all administrative permissions', function (): void {
    $u = F::user()->assignRole('admin');
    $a = F::submitted($u);
    foreach (['start', 'approve', 'reject', 'changes'] as $action) {
        $this->actingAs($u)->post("/admin/author-applications/{$a->id}/$action", ['summary' => 'A valid summary for rejection.'])->assertForbidden();
    }
    expect($u->fresh()->hasRole('author'))->toBeFalse();
});
test('changes request requires valid actionable feedback', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/changes")->assertSessionHasErrors('feedback');
    $this->post("/admin/author-applications/{$a->id}/changes", ['feedback' => [['section' => 'portfolio', 'message' => 'short']]])->assertSessionHasErrors('feedback.0.message');
    expect($a->fresh()->status)->toBe(Status::UnderReview);
});
test('changes permit edits and resubmission retains old snapshot feedback and events', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/changes", ['summary' => 'Improve your professional profile.', 'feedback' => [['section' => 'portfolio', 'message' => 'Explain your previous business software projects.']]])->assertRedirect();
    $this->actingAs($a->user)->get('/author/onboarding')->assertInertia(fn (Assert $p) => $p->where('profile.status', 'changes_requested')->where('history.rounds.data.0.feedback.0.message', 'Explain your previous business software projects.'));
    $this->put('/author/onboarding', F::ready(['display_name' => 'Acme Updated Software', 'revision' => $a->profile->fresh()->revision]))->assertRedirect();
    $this->post('/author/onboarding/submit')->assertRedirect();
    $latest = AuthorApplication::orderByDesc('id')->firstOrFail();
    expect($latest->round_number)->toBe(2)->and($latest->snapshot['display_name'])->toBe('Acme Updated Software')->and($a->fresh()->snapshot['display_name'])->toBe('Acme Business Software')->and($a->feedback()->count())->toBe(1)->and($a->events()->count())->toBe(3);
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/approve")->assertSessionHasErrors('workflow');
});
test('rejection requires reason and is terminal with no author grant', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/reject")->assertSessionHasErrors('summary');
    $this->post("/admin/author-applications/{$a->id}/reject", ['summary' => 'The portfolio does not demonstrate complete business software.'])->assertRedirect();
    expect($a->fresh()->status)->toBe(Status::Rejected)->and($a->user->fresh()->hasRole('author'))->toBeFalse();
    $this->actingAs($a->user)->put('/author/onboarding', F::ready())->assertForbidden();
    $this->post('/author/onboarding/submit')->assertForbidden();
});
test('approval is atomic additive and grants existing product capabilities', function (): void {
    $u = F::user()->assignRole('reviewer');
    $r = F::user('admin');
    $a = F::claimed($r, F::submitted($u));
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/approve")->assertRedirect();
    expect($u->fresh()->getRoleNames()->sort()->values()->all())->toBe(['author', 'customer', 'reviewer'])->and($a->fresh()->status)->toBe(Status::Approved);
    $this->post("/admin/author-applications/{$a->id}/approve")->assertSessionHasErrors('workflow');
    expect(AuthorApplicationEvent::where('action', 'approved')->count())->toBe(1);
    $this->actingAs($u->fresh())->get('/author/products/create')->assertOk();
    $this->get('/author')->assertOk();
    $category = Category::factory()->create();
    $this->post('/author/products', ['name' => 'New Seller POS', 'short_description' => 'Business software for inventory management.', 'category_id' => $category->id, 'currency' => 'INR', 'offers' => [['name' => 'License', 'price' => '999.00', 'license_type' => 'single_business', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]]])->assertRedirect();
    expect(Product::firstOrFail()->owner_id)->toBe($u->id);
    $this->put('/author/profile', F::ready(['display_name' => 'Changed identity', 'revision' => $a->profile->fresh()->revision]))->assertSessionHasErrors('display_name');
});
test('approval failure rolls back state role and history together', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    AuthorApplicationEvent::creating(fn () => throw new RuntimeException('Injected failure'));
    try {
        expect(fn () => app(AuthorWorkflow::class)->transition($r, $a->profile, Action::Approve, $a->id))->toThrow(RuntimeException::class);
    } finally {
        AuthorApplicationEvent::flushEventListeners();
        AuthorApplicationEvent::clearBootedModels();
    }
    expect($a->fresh()->status)->toBe(Status::UnderReview)->and($a->profile->fresh()->status)->toBe(Status::UnderReview)->and($a->user->fresh()->hasRole('author'))->toBeFalse();
});
test('review decision cannot forge actor or nested feedback identity', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/approve", ['reviewed_by' => $a->user_id])->assertSessionHasErrors('reviewed_by');
    $this->post("/admin/author-applications/{$a->id}/changes", ['feedback' => [['section' => 'profile', 'message' => 'Describe the supported business use cases.', 'created_by' => $a->user_id]]])->assertSessionHasErrors('feedback.0');
    expect($a->fresh()->status)->toBe(Status::UnderReview);
});
test('legacy authors retain access and maintain low risk profile without invented history', function (): void {
    $u = F::user()->assignRole('author');
    $this->actingAs($u)->get('/author/products/create')->assertOk();
    $this->get('/author')->assertOk();
    $this->get('/author/profile')->assertInertia(fn (Assert $p) => $p->where('legacy', true)->where('editable', true));
    $this->put('/author/profile', F::ready())->assertRedirect();
    $p = AuthorProfile::firstOrFail();
    expect($p->status)->toBe(Status::Approved)->and($p->is_legacy)->toBeTrue()->and(AuthorApplication::count())->toBe(0)->and($u->fresh()->hasRole('author'))->toBeTrue();
    $this->post('/author/onboarding/submit')->assertForbidden();
    $this->put('/author/profile', F::ready(['revision' => $p->fresh()->revision]))->assertRedirect();
    expect($p->fresh()->status)->toBe(Status::Approved)->and(AuthorApplication::count())->toBe(0);
});
test('dashboard counts belong only to authenticated author and omit earnings', function (): void {
    $u = F::user()->assignRole('author');
    Product::factory()->count(2)->create(['owner_id' => $u->id]);
    Product::factory()->create();
    $this->actingAs($u)->get('/author')->assertInertia(fn (Assert $p) => $p->component('authors/Dashboard')->where('counts.draft', 2)->missing('revenue'));
});
test('application and immutable audit cannot be overwritten or deleted', function (): void {
    $a = F::submitted();
    expect(fn () => $a->forceFill(['snapshot' => ['display_name' => 'forged']])->save())->toThrow(LogicException::class);
    expect(fn () => $a->delete())->toThrow(LogicException::class);
    $e = $a->events()->firstOrFail();
    expect(fn () => $e->forceFill(['actor_id' => 999999])->save())->toThrow(LogicException::class)->and(fn () => $e->delete())->toThrow(LogicException::class);
});
test('account deletion is prevented for applicant and review actor', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    foreach ([$r, $a->user] as $u) {
        $this->actingAs($u)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
    }
    expect($a->user()->exists())->toBeTrue();
});
test('application history is private to owner and authorized queue', function (): void {
    $a = F::submitted();
    $other = F::user()->assignRole('author');
    $this->actingAs($other)->get('/author/onboarding')->assertInertia(fn (Assert $p) => $p->where('history', null)->where('profile', null));
    $this->get("/admin/author-applications/{$a->id}")->assertForbidden();
});
test('queue filters search snapshots and paginate with retained parameters', function (): void {
    for ($i = 0; $i < 16; $i++) {
        F::submitted();
    }$r = F::user('admin');
    $this->actingAs($r)->get('/admin/author-applications?search=Acme&country=IN&status=submitted&author_type=individual')->assertInertia(fn (Assert $p) => $p->where('records.total', 16)->has('records.data', 15)->where('records.next_page_url', fn ($url) => str_contains($url, 'search=Acme')));
    $this->get('/admin/author-applications?search=%25')->assertInertia(fn (Assert $p) => $p->where('records.total', 0));
});
test('navigation tracks onboarding and approval without exposing it to guests', function (): void {
    $u = F::user();
    $this->actingAs($u)->get('/')->assertInertia(fn (Assert $p) => $p->where('authorNavigation.label', 'Sell on Templaara')->missing('auth.user.email'));
    $a = F::submitted($u);
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('authorNavigation.label', 'Author Application'));
    $r = F::user('admin');
    F::claimed($r, $a);
    app(AuthorWorkflow::class)->transition($r, $a->profile, Action::Approve, $a->id);
    $this->actingAs($u->fresh())->get('/')->assertInertia(fn (Assert $p) => $p->where('authorNavigation.label', 'Author Dashboard'));
});
test('author events are after commit notification foundations', function (): void {
    expect(is_subclass_of(AuthorApplicationApproved::class, ShouldDispatchAfterCommit::class))->toBeTrue();
});
test('PostgreSQL blocks invalid author history writes', function (string $case): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integrity verification.');
    }
    $a = F::submitted();
    $r = F::user('admin');
    expect(fn () => DB::transaction(function () use ($case, $a, $r): void {
        switch ($case) {
            case 'snapshot': DB::table('author_applications')->where('id', $a->id)->update(['snapshot' => '{}']);
                break;
            case 'user': DB::table('author_applications')->where('id', $a->id)->update(['user_id' => $r->id]);
                break;
            case 'delete': DB::table('author_applications')->where('id', $a->id)->delete();
                break;
            case 'self_review': DB::table('author_applications')->where('id', $a->id)->update(['status' => 'under_review', 'reviewed_by' => $a->user_id, 'review_started_at' => now()]);
                break;
            case 'state': DB::table('author_profiles')->where('id', $a->author_profile_id)->update(['status' => 'bogus']);
                break;
            case 'profile': DB::table('author_profiles')->where('id', $a->author_profile_id)->update(['display_name' => 'Changed']);
                break;
            case 'audit': DB::table('author_application_events')->where('author_application_id', $a->id)->update(['actor_id' => $r->id]);
                break;
            case 'feedback_without_decision': DB::table('author_application_feedback')->insert(['author_application_id' => $a->id, 'section' => 'general', 'message' => 'A request that is not authorized.', 'created_by' => $r->id]);
                break;
        }
    }))->toThrow(QueryException::class);
})->with(['snapshot', 'user', 'delete', 'self_review', 'state', 'profile', 'audit', 'feedback_without_decision']);

test('unverified new applicant uses existing Fortify signed email verification', function (): void {
    $u = User::factory()->unverified()->create()->assignRole('customer');
    Notification::fake();
    $this->actingAs($u)->get('/author/onboarding')->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertInertia(fn (Assert $p) => $p->component('auth/VerifyEmail'));
    $this->post(route('verification.send'))->assertRedirect();
    Notification::assertSentTo($u, VerifyEmail::class);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $u->id, 'hash' => sha1($u->email)]);
    $this->get($url)->assertRedirect();
    expect($u->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->actingAs($u->fresh())->get('/author/onboarding')->assertOk();
});
test('legacy author remains functional without an email verification retrofit', function (): void {
    $u = User::factory()->unverified()->create()->assignRole(['customer', 'author']);
    $this->actingAs($u)->get('/author')->assertOk();
    $this->get('/author/products/create')->assertOk();
    $this->get('/author/profile')->assertOk();
});
test('approval requires applicant current account email to remain verified', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    $a->user->forceFill(['email' => 'changed@example.com', 'email_verified_at' => null])->save();
    $this->actingAs($r)->post("/admin/author-applications/{$a->id}/approve")->assertSessionHasErrors('workflow');
    expect($a->user->fresh()->hasRole('author'))->toBeFalse()->and($a->fresh()->status)->toBe(Status::UnderReview);
});

test('PostgreSQL enforces deferred author decision integrity', function (string $case): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL deferred integrity verification.');
    }
    $r = F::user('admin');
    $a = F::claimed($r);
    $p = $a->profile;
    expect(fn () => DB::transaction(function () use ($case, $a, $p, $r): void {
        if ($case === 'missing_feedback') {
            DB::table('author_applications')->where('id', $a->id)->update(['status' => 'changes_requested', 'decision_at' => now()]);
        } elseif ($case === 'missing_role') {
            DB::table('author_applications')->where('id', $a->id)->update(['status' => 'approved', 'decision_at' => now()]);
            DB::table('author_profiles')->where('id', $p->id)->update(['status' => 'approved']);
        } elseif ($case === 'missing_reason') {
            DB::table('author_applications')->where('id', $a->id)->update(['status' => 'rejected', 'decision_at' => now(), 'decision_summary' => null]);
        } elseif ($case === 'missing_audit') {
            DB::table('author_applications')->where('id', $a->id)->update(['status' => 'rejected', 'decision_at' => now(), 'decision_summary' => 'This application is not suitable.']);
            DB::table('author_profiles')->where('id', $p->id)->update(['status' => 'rejected']);
        } elseif ($case === 'profile_ownership') {
            DB::table('author_profiles')->where('id', $p->id)->update(['user_id' => $r->id]);
        } elseif ($case === 'restrictive_user') {
            DB::table('users')->where('id', $a->user_id)->delete();
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
})->with(['missing_feedback', 'missing_role', 'missing_reason', 'missing_audit', 'profile_ownership', 'restrictive_user']);

test('current required feedback is independent of history pagination', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    app(AuthorWorkflow::class)->transition($r, $a->profile, Action::Changes, $a->id, ['feedback' => [['section' => 'portfolio', 'message' => 'Explain your relevant previous business projects.']]]);
    $this->actingAs($a->user)->get('/author/onboarding?rounds_page=2')->assertInertia(fn (Assert $p) => $p->where('latestFeedback.0.message', 'Explain your relevant previous business projects.')->has('history.rounds.data', 0));
});

test('empty protected values cannot overwrite profile identity or state', function (): void {
    $p = F::profile();
    $this->actingAs($p->user)->put('/author/onboarding', F::ready(['user_id' => null, 'status' => null, 'roles' => [], 'snapshot' => [], 'is_legacy' => null, 'revision' => $p->fresh()->revision]))->assertRedirect()->assertSessionHasNoErrors();
    expect($p->fresh()->user_id)->toBe($p->user_id)->and($p->fresh()->status)->toBe(Status::Draft)->and($p->fresh()->is_legacy)->toBeFalse()->and($p->user->fresh()->hasRole('author'))->toBeFalse();
});

test('checkout country coverage is preserved for author onboarding', function (): void {
    expect(Countries::codes())->toHaveCount(249)->toContain('CV');
    $this->actingAs(F::user())->put('/author/onboarding', F::ready(['country_code' => 'cv']))->assertRedirect()->assertSessionHasNoErrors();
    expect(AuthorProfile::firstOrFail()->getAttribute('country_code'))->toBe('CV');
    $rules = (new CheckoutRequest)->rules();
    expect(Validator::make(['country_code' => 'CV'], ['country_code' => $rules['country_code']])->passes())->toBeTrue();
});
