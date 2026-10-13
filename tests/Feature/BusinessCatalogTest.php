<?php

use App\Models\Category;
use App\Models\Industry;
use App\Models\Technology;
use App\Models\User;
use Database\Seeders\BusinessCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function catalogPayload(array $extra = []): array
{
    return [...['name' => 'Business Software', 'sort_order' => 0, 'is_active' => true], ...$extra];
}

dataset('catalogs', [
    'categories' => ['categories', Category::class],
    'industries' => ['industries', Industry::class],
    'technologies' => ['technologies', Technology::class],
]);

test('admin can list and open catalog forms', function (string $kind, string $model) {
    $record = $model::factory()->create();
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->get(route("admin.$kind.index"))->assertInertia(fn (Assert $page) => $page
        ->component('admin/catalog/Index')->where('kind', $kind)->has('records.data', 1));
    $this->get(route("admin.$kind.create"))->assertOk();
    $this->get(route("admin.$kind.edit", $record))->assertInertia(fn (Assert $page) => $page
        ->component('admin/catalog/Form')->where('record.id', $record->id));
})->with('catalogs');

test('guests cannot manage catalogs', function (string $kind, string $model) {
    $this->get(route("admin.$kind.index"))->assertRedirect(route('login'));
    $this->post(route("admin.$kind.store"), catalogPayload())->assertRedirect(route('login'));
})->with('catalogs');

test('customers cannot view or mutate admin catalogs', function (string $kind, string $model) {
    $record = $model::factory()->create();
    $this->actingAs(User::factory()->create()->assignRole('customer'));
    $this->get(route("admin.$kind.index"))->assertForbidden();
    $this->get(route("admin.$kind.create"))->assertForbidden();
    $this->get(route("admin.$kind.edit", $record))->assertForbidden();
    $this->post(route("admin.$kind.store"), catalogPayload())->assertForbidden();
    $this->put(route("admin.$kind.update", $record), catalogPayload())->assertForbidden();
    $this->delete(route("admin.$kind.destroy", $record))->assertForbidden();
    expect($model::count())->toBe(1);
})->with('catalogs');

test('authors and reviewers retain read-only catalog access', function (string $kind, string $model, string $role) {
    $record = $model::factory()->create();
    $this->actingAs(User::factory()->create()->assignRole($role));
    $this->get(route("admin.$kind.index"))->assertOk();
    $this->get(route("admin.$kind.create"))->assertForbidden();
    $this->get(route("admin.$kind.edit", $record))->assertForbidden();
    $this->post(route("admin.$kind.store"), catalogPayload())->assertForbidden();
    $this->put(route("admin.$kind.update", $record), catalogPayload())->assertForbidden();
    $this->delete(route("admin.$kind.destroy", $record))->assertForbidden();
})->with('catalogs')->with(['author', 'reviewer']);

test('explicitly authorized customer can create a catalog record', function (string $kind, string $model) {
    $user = User::factory()->create()->assignRole('customer')->givePermissionTo("$kind.create");
    $this->actingAs($user)->post(route("admin.$kind.store"), catalogPayload(['name' => 'HR & Payroll']))
        ->assertRedirect(route("admin.$kind.index"));
    $this->assertDatabaseHas((new $model)->getTable(), ['name' => 'HR & Payroll', 'slug' => 'hr-payroll']);
    $this->get(route("admin.$kind.index"))->assertForbidden();
})->with('catalogs');

test('admin can create edit and delete a catalog record', function (string $kind, string $model) {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->post(route("admin.$kind.store"), catalogPayload(['slug' => 'manual-slug']))->assertRedirect();
    $record = $model::where('slug', 'manual-slug')->firstOrFail();
    $this->put(route("admin.$kind.update", $record), catalogPayload(['name' => 'Renamed', 'slug' => 'manual-slug', 'is_active' => false]))->assertRedirect();
    $this->assertDatabaseHas((new $model)->getTable(), ['id' => $record->id, 'name' => 'Renamed', 'slug' => 'manual-slug', 'is_active' => false]);
    $this->delete(route("admin.$kind.destroy", $record))->assertRedirect();
    $this->assertDatabaseMissing((new $model)->getTable(), ['id' => $record->id]);
})->with('catalogs');

test('manual duplicate slugs fail on create and update', function (string $kind, string $model) {
    $existing = $model::factory()->create(['slug' => 'crm']);
    $other = $model::factory()->create();
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->post(route("admin.$kind.store"), catalogPayload(['slug' => 'crm']))->assertSessionHasErrors('slug');
    $this->put(route("admin.$kind.update", $other), catalogPayload(['slug' => 'crm']))->assertSessionHasErrors('slug');
    expect($existing->fresh()->slug)->toBe('crm');
})->with('catalogs');

test('generated slugs resolve collisions and preserve omitted update slugs', function (string $kind, string $model) {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    foreach (['crm', 'crm-2', 'crm-3'] as $slug) {
        $this->post(route("admin.$kind.store"), catalogPayload(['name' => 'CRM', 'slug' => '']))->assertRedirect();
        $this->assertDatabaseHas((new $model)->getTable(), ['slug' => $slug]);
    }
    $record = $model::where('slug', 'crm')->firstOrFail();
    $this->put(route("admin.$kind.update", $record), catalogPayload(['name' => 'Different Name']))->assertRedirect();
    expect($record->fresh()->slug)->toBe('crm');
    $this->put(route("admin.$kind.update", $record), catalogPayload(['name' => 'Different Name', 'slug' => '']))->assertRedirect();
    expect($record->fresh()->slug)->toBe('different-name');
})->with('catalogs');

test('catalog validation rejects invalid fields', function (string $kind, string $model) {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->post(route("admin.$kind.store"), catalogPayload(['name' => '', 'slug' => 'Invalid Slug', 'sort_order' => -1, 'is_active' => 'invalid']))
        ->assertSessionHasErrors(['name', 'slug', 'sort_order', 'is_active']);
    expect($model::count())->toBe(0);
})->with('catalogs');

test('category cannot be its own parent or a descendant of itself', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->put(route('admin.categories.update', $parent), catalogPayload(['parent_id' => $parent->id]))->assertSessionHasErrors('parent_id');
    $this->put(route('admin.categories.update', $parent), catalogPayload(['parent_id' => $child->id]))->assertSessionHasErrors('parent_id');
    expect($parent->fresh()->parent_id)->toBeNull();
});

test('category parent must exist and relationships resolve correctly', function () {
    $parent = Category::factory()->create();
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->post(route('admin.categories.store'), catalogPayload(['parent_id' => 999999]))->assertSessionHasErrors('parent_id');
    $this->post(route('admin.categories.store'), catalogPayload(['parent_id' => $parent->id]))->assertRedirect();
    $child = Category::where('slug', 'business-software')->firstOrFail();
    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children()->count())->toBe(1);
});

test('parent categories cannot be deleted before children are reassigned', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->delete(route('admin.categories.destroy', $parent))->assertSessionHasErrors('delete');
    $this->assertDatabaseHas('categories', ['id' => $parent->id]);
    $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $parent->id]);
    $this->put(route('admin.categories.update', $child), catalogPayload(['parent_id' => null]))->assertRedirect();
    $this->delete(route('admin.categories.destroy', $parent))->assertRedirect();
});

test('database foreign key independently prevents deleting category parents', function () {
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);
    expect(fn () => $parent->delete())->toThrow(QueryException::class);
});

test('technology website must be an http or https URL', function () {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    foreach (['not-a-url', 'javascript:alert(1)', 'ftp://example.com'] as $url) {
        $this->post(route('admin.technologies.store'), catalogPayload(['website_url' => $url]))->assertSessionHasErrors('website_url');
    }
    $this->post(route('admin.technologies.store'), catalogPayload(['website_url' => 'https://laravel.com']))->assertRedirect();
    $this->assertDatabaseHas('technologies', ['website_url' => 'https://laravel.com']);
});

test('public catalog includes only active metadata in deterministic order', function (string $kind, string $model) {
    $model::factory()->inactive()->create(['name' => 'Hidden']);
    $first = $model::factory()->create(['name' => 'Alpha', 'sort_order' => 0]);
    $model::factory()->create(['name' => 'Beta', 'sort_order' => 0]);
    $model::factory()->create(['name' => 'Earlier alphabet', 'sort_order' => 10]);
    $this->getJson(route("catalog.$kind"))->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $first->id)->assertJsonMissing(['name' => 'Hidden'])
        ->assertJsonMissingPath('data.0.created_at')->assertJsonMissingPath('data.0.is_active')
        ->assertJsonMissingPath('data.0.sort_order');
    $this->getJson(route("catalog.$kind", ['per_page' => 1]))->assertJsonCount(1, 'data')->assertJsonPath('last_page', 3);
    $this->getJson(route("catalog.$kind", ['per_page' => 1000]))->assertUnprocessable()->assertJsonValidationErrors('per_page');
})->with('catalogs');

test('public category does not reveal an inactive parent', function () {
    $parent = Category::factory()->inactive()->create();
    Category::factory()->create(['parent_id' => $parent->id]);
    $this->getJson(route('catalog.categories'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.parent_id', null);
});

test('admin listings support search status sorting pagination and parent filters', function () {
    $parent = Category::factory()->create(['name' => 'Business Management']);
    Category::factory()->create(['name' => 'CRM Alpha', 'parent_id' => $parent->id]);
    Category::factory()->inactive()->create(['name' => 'CRM Hidden', 'parent_id' => $parent->id]);
    Category::factory()->count(17)->create();
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    $this->get(route('admin.categories.index', ['search' => 'crm', 'active' => 'active', 'parent' => $parent->id, 'sort' => 'name']))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.name', 'CRM Alpha')
            ->where('records.data.0.parent.name', 'Business Management'));
    $this->get(route('admin.categories.index'))->assertInertia(fn (Assert $page) => $page->has('records.data', 15)->where('records.total', 20));
    $this->get(route('admin.categories.index', ['parent' => 'root']))->assertInertia(fn (Assert $page) => $page->where('records.total', 18));
    $this->get(route('admin.categories.index', ['search' => '%']))->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
});

test('business seed data is idempotent and preserves admin changes', function () {
    $this->seed(BusinessCatalogSeeder::class);
    $category = Category::where('slug', 'crm')->firstOrFail();
    $category->update(['name' => 'Custom CRM', 'is_active' => false]);
    $this->seed(BusinessCatalogSeeder::class);
    expect(Category::count())->toBe(10)->and(Industry::count())->toBe(11)->and(Technology::count())->toBe(10)
        ->and($category->fresh()->name)->toBe('Custom CRM')->and($category->fresh()->is_active)->toBeFalse();
});
