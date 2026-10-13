<?php

use App\Enums\AuthorApplicationAction as Action;
use App\Enums\AuthorApplicationStatus as Status;
use App\Models\AuthorEarning;
use App\Models\AuthorPortfolioItem;
use App\Models\AuthorProfile;
use App\Models\AuthorProfileMedia;
use App\Models\Category;
use App\Models\Product;
use App\Models\Technology;
use App\Services\Authors\AuthorMedia;
use App\Services\Authors\AuthorPortfolio;
use App\Services\Authors\AuthorProfileCompleteness;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\PublicAuthor;
use App\Services\Authors\SaveAuthorProfile;
use App\Services\Invoicing\IssueInvoice;
use Database\Seeders\CommissionPolicySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthorFixtures as F;
use Tests\Support\EarningFixtures as E;
use Tests\Support\InvoiceFixtures;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set(['commerce.gateway' => 'development', 'commerce.development_enabled' => true]);
});
function portfolio22(array $extra = []): array
{
    return [...['title' => 'Retail operations suite', 'short_description' => 'Inventory, purchasing and billing for growing retailers.', 'project_type' => 'personal', 'is_public' => true, 'is_featured' => false, 'sort_order' => 0], ...$extra];
}
function approved22(): AuthorProfile
{
    $r = F::user('admin');
    $a = F::claimed($r);
    app(AuthorWorkflow::class)->transition($r, $a->profile, Action::Approve, $a->id);

    return $a->profile->fresh();
}

test('professional draft validates distinct bounded fields', function (string $field, mixed $value): void {
    $this->actingAs(F::user())->put('/author/onboarding', F::ready([$field => $value]))->assertSessionHasErrors(is_array($value) && in_array($field, ['languages', 'technology_ids', 'expertise_ids']) && count($value) <= 10 ? $field.'.0' : $field);
    expect(AuthorProfile::count())->toBe(0);
})->with([
    ['years_of_experience', -1], ['years_of_experience', 61], ['years_of_experience', 1.5], ['years_of_experience', 'many'],
    ['professional_headline', str_repeat('a', 161)], ['bio', str_repeat('b', 5001)], ['author_type', 'agency'],
    ['founded_year', 1899], ['founded_year', 2101], ['team_size', '1000000'], ['state_region', str_repeat('a', 101)], ['city', str_repeat('a', 101)],
    ['languages', ['Unknown']], ['languages', ['English', 'English']], ['languages', 'English'], ['languages', array_fill(0, 11, 'English')],
    ['support_availability', str_repeat('a', 201)], ['confidential_work', str_repeat('a', 2001)], ['public_location', 'yes'],
    ['technology_ids', [999999]], ['technology_ids', 'Laravel'], ['expertise_ids', [999999]],
    ['website_url', 'http://example.com'], ['website_url', 'https://localhost'], ['website_url', 'https://127.0.0.1'],
    ['website_url', 'https://10.0.0.1'], ['website_url', 'https://192.168.1.1'], ['website_url', 'https://[::1]'], ['website_url', 'https://name.internal'],
    ['website_url', 'https://user:pass@example.com'], ['website_url', 'https://example.com:8000'], ['website_url', 'javascript:alert(1)'],
    ['website_url', 'data:text/html,test'], ['website_url', 'file:///etc/passwd'], ['github_url', 'https://evil.example.com'], ['linkedin_url', 'https://evil.example.com'],
    ['slug', 'admin'], ['slug', 'templaara'], ['slug', '../escape'], ['slug', 'Uppercase'],
]);
test('zero and maximum professional experience persist exactly', function (int $years): void {
    $p = F::profile(extra: ['years_of_experience' => $years]);
    expect($p->years_of_experience)->toBe($years);
})->with([0, 60]);
test('individual and company share the existing user profile domain', function (string $type): void {
    $p = F::profile(extra: ['author_type' => $type, 'company_name' => $type === 'company' ? 'Legal Test Company' : null]);
    expect($p->author_type->value)->toBe($type)->and($p->user->hasRole('customer'))->toBeTrue()->and($p->user->hasRole('author'))->toBeFalse();
})->with(['individual', 'company']);
test('active taxonomies are reused and no duplicate skill strings are created', function (): void {
    $t = Technology::factory()->create();
    $c = Category::factory()->create();
    $p = F::profile(extra: ['technology_ids' => [$t->id], 'expertise_ids' => [$c->id]]);
    expect($p->technologies->modelKeys())->toBe([$t->id])->and($p->expertise->modelKeys())->toBe([$c->id]);
});
test('inactive technology cannot be newly selected', function (): void {
    $t = Technology::factory()->create(['is_active' => false]);
    $this->actingAs(F::user())->put('/author/onboarding', F::ready(['technology_ids' => [$t->id]]))->assertSessionHasErrors('technology_ids.0');
});
test('duplicate taxonomy IDs fail', function (): void {
    $t = Technology::factory()->create();
    $this->actingAs(F::user())->put('/author/onboarding', F::ready(['technology_ids' => [$t->id, $t->id]]))->assertSessionHasErrors('technology_ids.0');
});
test('new application requires professional readiness', function (string $field, mixed $value, string $error): void {
    $p = F::profile(extra: [$field => $value]);
    $this->actingAs($p->user)->post('/author/onboarding/submit')->assertSessionHasErrors($error);
    expect($p->fresh()->status)->toBe(Status::Draft);
})->with([['professional_headline', null, 'professional_headline'], ['years_of_experience', null, 'years_of_experience'], ['technology_ids', [], 'technology_ids'], ['confidential_work', null, 'confidential_work']]);
test('confidential work alternative requires explanation and another professional link', function (): void {
    $p = F::profile(extra: ['confidential_work' => 'Our client engagements are confidential and protected by contracts.', 'website_url' => null]);
    $this->actingAs($p->user)->post('/author/onboarding/submit')->assertSessionHasErrors('website_url');
});
test('portfolio evidence can replace confidential explanation', function (): void {
    $p = F::profile(extra: ['confidential_work' => null]);
    app(AuthorPortfolio::class)->save($p->user, portfolio22());
    $a = app(AuthorWorkflow::class)->transition($p->user, $p, Action::Submit);
    expect($a->snapshot['professional']['portfolio'])->toHaveCount(1)->and($a->snapshot_version)->toBe(2);
});
test('snapshot freezes experience skills and portfolio evidence', function (): void {
    $p = F::profile();
    $i = app(AuthorPortfolio::class)->save($p->user, portfolio22());
    $a = app(AuthorWorkflow::class)->transition($p->user, $p, Action::Submit);
    expect($a->snapshot['professional']['years_of_experience'])->toBe(6)->and($a->snapshot['professional']['portfolio'][0]['title'])->toBe($i->title);
    $this->actingAs($p->user)->put('/author/portfolio/'.$i->public_uuid, portfolio22(['title' => 'Changed', 'revision' => $i->revision]))->assertForbidden();
});
test('reviewer sees frozen professional evidence rather than live profile', function (): void {
    $a = F::submitted();
    $this->actingAs(F::user('admin'))->get('/admin/author-applications/'.$a->id)->assertInertia(fn (Assert $p) => $p->where('application.snapshot.professional.years_of_experience', 6)->where('application.snapshot.professional.professional_headline', 'Business software specialist'));
});
test('corrections create a new snapshot while old evidence remains unchanged', function (): void {
    $r = F::user('admin');
    $a = F::claimed($r);
    app(AuthorWorkflow::class)->transition($r, $a->profile, Action::Changes, $a->id, ['feedback' => [['section' => 'profile', 'message' => 'Clarify your business software experience.']]]);
    app(SaveAuthorProfile::class)->save($a->user, ['professional_headline' => 'Retail implementation specialist', 'revision' => $a->profile->fresh()->revision]);
    $next = app(AuthorWorkflow::class)->transition($a->user, $a->profile, Action::Submit);
    expect($a->fresh()->snapshot['professional']['professional_headline'])->toBe('Business software specialist')->and($next->snapshot['professional']['professional_headline'])->toBe('Retail implementation specialist')->and($next->round_number)->toBe(2);
});
test('approved profile permits low risk edits and retains additive roles', function (): void {
    $p = approved22();
    app(SaveAuthorProfile::class)->save($p->user, ['bio' => 'Updated professional introduction for business customers.', 'professional_headline' => 'Retail specialist', 'languages' => ['English'], 'revision' => $p->revision]);
    expect($p->fresh()->status)->toBe(Status::Approved)->and($p->user->fresh()->hasAllRoles(['customer', 'author']))->toBeTrue();
});
test('approved high trust identity edits are blocked', function (string $field, mixed $value): void {
    $p = approved22();
    $this->actingAs($p->user)->put('/author/profile', [$field => $value, 'revision' => $p->revision])->assertSessionHasErrors(is_array($value) && in_array($field, ['languages', 'technology_ids', 'expertise_ids']) && count($value) <= 10 ? $field.'.0' : $field);
    expect($p->fresh()->status)->toBe(Status::Approved);
})->with([['display_name', 'Another identity'], ['author_type', 'company'], ['company_name', 'Different legal company'], ['country_code', 'US'], ['slug', 'another-store'], ['support_email', 'different@example.com'], ['support_phone', 'new-number']]);
test('stale profile update is rejected rather than overwriting a newer change', function (): void {
    $p = F::profile();
    $old = $p->revision;
    app(SaveAuthorProfile::class)->save($p->user, ['professional_headline' => 'First change', 'revision' => $old]);
    $this->actingAs($p->user)->put('/author/onboarding', ['professional_headline' => 'Lost change', 'revision' => $old])->assertSessionHasErrors('revision');
    expect($p->fresh()->professional_headline)->toBe('First change');
});
test('approved incomplete legacy authors retain workspace without fabricated approval', function (): void {
    $u = F::user()->assignRole('author');
    $p = app(SaveAuthorProfile::class)->save($u, []);
    $this->actingAs($u)->get('/author')->assertOk();
    $this->get('/author/profile')->assertInertia(fn (Assert $a) => $a->where('editable', true)->where('completeness.percentage', 0));
    expect(app(PublicAuthor::class)->card($u->fresh())['verified'])->toBeFalse()->and($p->is_legacy)->toBeTrue();
});
test('portfolio fields reject invalid or unsafe values', function (string $field, mixed $value): void {
    $p = F::profile();
    $this->actingAs($p->user)->post('/author/portfolio', portfolio22([$field => $value]))->assertSessionHasErrors(is_array($value) && in_array($field, ['languages', 'technology_ids', 'expertise_ids']) && count($value) <= 10 ? $field.'.0' : $field);
    expect(AuthorPortfolioItem::count())->toBe(0);
})->with([
    ['title', ''], ['title', str_repeat('a', 161)], ['short_description', str_repeat('a', 501)], ['description', str_repeat('a', 5001)], ['project_type', 'unverified award'], ['client_name', str_repeat('a', 151)], ['completion_year', 1899], ['completion_year', 2101], ['sort_order', -1], ['sort_order', 1000], ['is_public', 'yes'], ['project_url', 'http://example.com'], ['project_url', 'javascript:alert(1)'], ['case_study_url', 'https://127.0.0.1'], ['repository_url', 'https://user:pass@example.com'], ['technology_ids', [999999]], ['author_profile_id', 123], ['user_id', 123], ['public_uuid', 'forged'],
]);
test('portfolio create update hide show delete retain ownership', function (): void {
    $p = F::profile();
    $service = app(AuthorPortfolio::class);
    $i = $service->save($p->user, portfolio22());
    $i = $service->save($p->user, portfolio22(['is_public' => false, 'revision' => $i->revision]), $i);
    expect($i->is_public)->toBeFalse();
    $i = $service->save($p->user, portfolio22(['is_public' => true, 'revision' => $i->revision]), $i);
    expect($i->is_public)->toBeTrue();
    $service->delete($p->user, $i, $i->revision);
    expect(AuthorPortfolioItem::count())->toBe(0);
});
test('cross author portfolio mutation returns not found', function (string $method): void {
    $p = F::profile();
    $other = F::profile();
    $i = app(AuthorPortfolio::class)->save($p->user, portfolio22());
    $data = portfolio22(['revision' => $i->revision]);
    $this->actingAs($other->user)->{$method}('/author/portfolio/'.$i->public_uuid, $data)->assertNotFound();
    expect($i->fresh()->title)->toBe('Retail operations suite');
})->with(['put', 'delete']);
test('portfolio maximum is enforced under the profile lock', function (): void {
    $p = F::profile();
    for ($n = 0; $n < 12; $n++) {
        app(AuthorPortfolio::class)->save($p->user, portfolio22(['title' => 'Project '.$n]));
    }$this->actingAs($p->user)->post('/author/portfolio', portfolio22())->assertSessionHasErrors('title');
    expect($p->portfolio()->count())->toBe(12);
});
test('portfolio reorder requires complete owned set and protects concurrent edit', function (): void {
    $p = F::profile();
    $s = app(AuthorPortfolio::class);
    $a = $s->save($p->user, portfolio22(['title' => 'A']));
    $b = $s->save($p->user, portfolio22(['title' => 'B']));
    $p->refresh();
    $s->reorder($p->user, [$b->public_uuid, $a->public_uuid], $p->revision);
    expect($p->portfolio()->pluck('title')->all())->toBe(['B', 'A']);
    expect(fn () => $s->reorder($p->user, [$a->public_uuid], $p->fresh()->revision))->toThrow(ValidationException::class);
    expect(fn () => $s->save($p->user, portfolio22(['revision' => $a->revision]), $a))->toThrow(ValidationException::class);
});
test('portfolio media uses safe public keys and validates replacement cleanup', function (): void {
    Storage::fake(config('product_files.media_disk'));
    $p = F::profile();
    $i = app(AuthorPortfolio::class)->save($p->user, portfolio22());
    app(AuthorMedia::class)->upload($p->user, UploadedFile::fake()->image('unsafe-name.png', 320, 200), 'portfolio', $p->fresh()->revision, $i);
    $m = AuthorProfileMedia::firstOrFail();
    expect($m->path)->toStartWith('authors/')->not->toContain('unsafe-name')->and($m->disk)->toBe(config('product_files.media_disk'));
    Storage::disk($m->disk)->assertExists($m->path);
    app(AuthorMedia::class)->upload($p->user, UploadedFile::fake()->image('replacement.png', 320, 200), 'portfolio', $p->fresh()->revision, $i);
    $m->fresh()->forceFill(['updated_at' => now()->subDays(2)])->save();
    expect(app(AuthorMedia::class)->cleanup())->toBe(1);
    Storage::disk($m->disk)->assertMissing($m->path);
});
test('avatar and optional cover accept safe images', function (string $purpose): void {
    Storage::fake(config('product_files.media_disk'));
    $p = F::profile();
    app(AuthorMedia::class)->upload($p->user, UploadedFile::fake()->image('identity.webp', 128, 128), $purpose, $p->revision);
    expect(AuthorProfileMedia::firstOrFail()->purpose)->toBe($purpose);
})->with(['avatar', 'cover']);
test('unsafe image formats dimensions and size are rejected', function (string $kind): void {
    Storage::fake(config('product_files.media_disk'));
    $p = F::profile();
    $file = match ($kind) {
        'svg' => UploadedFile::fake()->create('image.svg', 10, 'image/svg+xml'),'php' => UploadedFile::fake()->create('image.php', 10, 'application/x-php'),'small' => UploadedFile::fake()->image('small.png', 10, 10),'huge' => UploadedFile::fake()->image('large.png', 100, 100)->size(4097)
    };
    $this->actingAs($p->user)->post('/author/profile/media', ['file' => $file, 'purpose' => 'avatar', 'revision' => $p->revision])->assertSessionHasErrors('file');
    expect(AuthorProfileMedia::count())->toBe(0);
})->with(['svg', 'php', 'small', 'huge']);
test('submitted evidence retains replaced and deleted image objects', function (): void {
    Storage::fake(config('product_files.media_disk'));
    $p = F::profile();
    $i = app(AuthorPortfolio::class)->save($p->user, portfolio22());
    app(AuthorMedia::class)->upload($p->user, UploadedFile::fake()->image('evidence.png', 100, 100), 'portfolio', $p->fresh()->revision, $i);
    $media = AuthorProfileMedia::firstOrFail();
    $r = F::user('admin');
    $a = app(AuthorWorkflow::class)->transition($p->user, $p, Action::Submit);
    app(AuthorWorkflow::class)->transition($r, $p, Action::Start, $a->id);
    app(AuthorWorkflow::class)->transition($r, $p, Action::Approve, $a->id);
    app(AuthorPortfolio::class)->delete($p->user, $i, $i->revision);
    $media->refresh()->forceFill(['updated_at' => now()->subDays(2)])->save();
    expect(app(AuthorMedia::class)->cleanup())->toBe(0);
    Storage::disk($media->disk)->assertExists($media->path);
});
test('private commercial disk cannot be used for public author images', function (): void {
    $p = F::profile();
    config()->set('product_files.media_disk', config('product_files.artifact_disk'));
    expect(fn () => app(AuthorMedia::class)->upload($p->user, UploadedFile::fake()->image('avatar.png', 100, 100), 'avatar', $p->revision))->toThrow(ValidationException::class);
});
test('unapproved author profiles have no public page', function (): void {
    $p = F::profile();
    $this->get('/authors/'.$p->slug)->assertNotFound();
    $a = app(AuthorWorkflow::class)->transition($p->user, $p, Action::Submit);
    $this->get('/authors/'.$p->slug)->assertNotFound();
});
test('public verified profile exposes an explicit safe allow list', function (): void {
    $p = approved22();
    app(AuthorPortfolio::class)->save($p->user, portfolio22());
    app(AuthorPortfolio::class)->save($p->user, portfolio22(['title' => 'Hidden client work', 'is_public' => false]));
    $this->get('/authors/'.$p->slug)->assertInertia(fn (Assert $a) => $a->component('marketplace/Author')->where('author.verified', true)->where('author.years_of_experience', 6)->has('author.portfolio', 1)->missing('author.support_email')->missing('author.support_phone')->missing('author.company_name')->missing('author.user_id')->missing('author.confidential_work')->missing('author.applications')->missing('author.earnings')->missing('author.payouts')->missing('author.roles')->where('author.country_code', null)->where('seo.canonical', route('marketplace.authors.show', $p->slug)));
});
test('location is only exposed by explicit public choice', function (): void {
    $p = approved22();
    app(SaveAuthorProfile::class)->save($p->user, ['public_location' => true, 'city' => 'Hyderabad', 'revision' => $p->revision]);
    $this->get('/authors/'.$p->slug)->assertInertia(fn (Assert $a) => $a->where('author.city', 'Hyderabad')->where('author.country_code', 'IN'));
});
test('removing author role hides public profile without deleting history', function (): void {
    $p = approved22();
    $p->user->removeRole('author');
    $this->get('/authors/'.$p->slug)->assertNotFound();
    expect($p->applications()->count())->toBe(1);
});
test('public author products reuse the existing full visibility boundary', function (): void {
    $p = approved22();
    $product = E::product($p->user);
    Product::factory()->create(['owner_id' => $p->user_id]);
    $this->get('/authors/'.$p->slug)->assertInertia(fn (Assert $a) => $a->where('products.total', 1)->where('products.data.0.slug', $product->slug));
    $product->forceFill(['is_active' => false])->save();
    $this->get('/authors/'.$p->slug)->assertInertia(fn (Assert $a) => $a->where('products.total', 0));
});
test('product detail includes a verified linked professional author card', function (): void {
    $p = approved22();
    $product = E::product($p->user);
    $this->get('/products/'.$product->slug)->assertInertia(fn (Assert $a) => $a->where('product.author.verified', true)->where('product.author.url', '/authors/'.$p->slug)->missing('product.author.email'));
});
test('profile completeness is deterministic and does not assign approval', function (): void {
    $p = F::profile(extra: ['languages' => ['English']]);
    $s = app(AuthorProfileCompleteness::class);
    expect($s->calculate(null)['percentage'])->toBe(0)->and($s->calculate($p)['percentage'])->toBe(100)->and($s->calculate($p))->toBe($s->calculate($p))->and($p->fresh()->status)->toBe(Status::Draft)->and($p->user->hasRole('author'))->toBeFalse();
});
test('admin directory is permission protected paginated and preserves filters', function (): void {
    $p = F::profile();
    $this->actingAs(F::user())->get('/admin/authors')->assertForbidden();
    $this->actingAs(F::user('reviewer'))->get('/admin/authors?author_type=individual')->assertInertia(fn (Assert $a) => $a->component('authors/Directory')->where('records.total', 1)->where('filters.author_type', 'individual')->missing('records.data.0.support_email'));
});
test('profile edits never rewrite invoice purchased seller or earning truth', function (): void {
    $this->seed(CommissionPolicySeeder::class);
    $p = approved22();
    $o = E::order(E::product($p->user));
    InvoiceFixtures::configure(InvoiceFixtures::gst());
    $invoice = app(IssueInvoice::class)->issue($o);
    $item = $o->items->first()->getAttributes();
    $line = $invoice->items->first()->getAttributes();
    $earning = AuthorEarning::where('order_id', $o->id)->first()->getAttributes();
    app(SaveAuthorProfile::class)->save($p->user, ['bio' => 'A revised profile that must not change purchased financial history.', 'professional_headline' => 'Updated specialist', 'revision' => $p->revision]);
    expect($o->items->first()->fresh()->getAttributes())->toBe($item)->and($invoice->items->first()->fresh()->getAttributes())->toBe($line)->and(AuthorEarning::where('order_id', $o->id)->first()->getAttributes())->toBe($earning);
});
test('new professional PostgreSQL bounds reject direct bypasses', function (string $field, mixed $value): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint case verified in isolated PostgreSQL suite.');
    }$p = F::profile();
    expect(fn () => DB::transaction(fn () => DB::table('author_profiles')->where('id', $p->id)->update([$field => $value])))->toThrow(QueryException::class);
})->with([['years_of_experience', 61], ['years_of_experience', -1], ['team_size', 'invalid'], ['founded_year', 1800]]);

test('existing profile saves require a revision token', function (): void {
    $p = F::profile();
    $this->actingAs($p->user)->put('/author/onboarding', ['professional_headline' => 'Unversioned edit'])->assertSessionHasErrors('revision');
    expect($p->fresh()->professional_headline)->toBe('Business software specialist');
});
test('generated public identifier avoids reserved words without changing historical slugs', function (): void {
    $p = F::profile(extra: ['display_name' => 'Admin']);
    expect($p->slug)->toBe('admin-software');
});
test('portfolio professional evidence does not force an unnecessary external link', function (): void {
    $p = F::profile(extra: ['website_url' => null, 'confidential_work' => null]);
    app(AuthorPortfolio::class)->save($p->user, portfolio22());
    $a = app(AuthorWorkflow::class)->transition($p->user, $p, Action::Submit);
    expect($a->snapshot_version)->toBe(2)->and($a->snapshot['professional']['portfolio'])->toHaveCount(1);
});
test('unrelated user cannot upload media to an owned portfolio item', function (): void {
    Storage::fake(config('product_files.media_disk'));
    $p = F::profile();
    $other = F::profile();
    $item = app(AuthorPortfolio::class)->save($p->user, portfolio22());
    $this->actingAs($other->user)->post('/author/portfolio/'.$item->public_uuid.'/media', ['file' => UploadedFile::fake()->image('picture.png', 128, 128), 'purpose' => 'portfolio', 'revision' => $other->revision])->assertNotFound();
    expect(AuthorProfileMedia::count())->toBe(0)->and(Storage::disk(config('product_files.media_disk'))->allFiles())->toBe([]);
});
test('profile snapshot HTML is escaped on public pages', function (): void {
    $p = approved22();
    app(SaveAuthorProfile::class)->save($p->user, ['bio' => '<script>alert("profile")</script> Professional biography', 'revision' => $p->revision]);
    $this->get('/authors/'.$p->slug)->assertOk()->assertDontSee('<script>alert("profile")</script>', false);
});
test('portfolio upload has bounded rate middleware and normal CSRF protection', function (): void {
    $route = app('router')->getRoutes()->getByName('author.portfolio.media');
    expect($route->gatherMiddleware())->toContain('throttle:30,1', 'web', 'auth');
});

test('retired taxonomy selections remain editable without adding new inactive skills', function (): void {
    $p = F::profile();
    $technology = $p->technologies()->firstOrFail();
    $technology->forceFill(['is_active' => false])->save();
    $this->actingAs($p->user)->put('/author/onboarding', ['professional_headline' => 'Updated professional context', 'technology_ids' => [$technology->id], 'revision' => $p->revision])->assertRedirect()->assertSessionHasNoErrors();
    expect($p->fresh()->technologies->modelKeys())->toBe([$technology->id]);
});
