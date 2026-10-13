<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Route::middleware(['web', 'auth', 'role:admin'])->get('/_test/admin', fn () => response('ok'));
    Route::middleware(['web', 'auth', 'role:admin|reviewer'])->get('/_test/reviewer', fn () => response('ok'));
    Route::middleware(['web', 'auth', 'permission:products.review'])->get('/_test/review', fn () => response('ok'));
    Route::middleware(['web', 'auth', 'can:settings.manage'])->get('/_test/settings', fn () => response('ok'));
});

test('registration creates only a customer even when roles are supplied', function () {
    $this->get(route('register'))->assertOk();
    $this->post(route('register.store'), [
        'name' => 'New Customer',
        'email' => 'customer@example.com',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
        'roles' => ['admin'],
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::where('email', 'customer@example.com')->firstOrFail()->getRoleNames()->all())->toBe(['customer']);
});

test('invalid registration does not create a user', function () {
    $this->post(route('register.store'), [
        'name' => 'Invalid', 'email' => 'invalid@example.com',
        'password' => 'SecurePassword123!', 'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
    $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);
});

test('admin receives all defined permissions and passes backend authorization', function () {
    $user = User::factory()->create()->assignRole('admin');
    expect($user->getAllPermissions()->count())->toBe(42);
    foreach (Permission::all() as $permission) {
        expect($user->can($permission->name))->toBeTrue();
    }
    $this->actingAs($user)->get('/_test/admin')->assertOk();
    $this->get('/_test/settings')->assertOk();
});

test('guests cannot access protected routes', function () {
    $this->get('/_test/admin')->assertRedirect(route('login'));
    $this->get('/_test/reviewer')->assertRedirect(route('login'));
});

test('customers cannot access administrative or moderation routes', function () {
    $user = User::factory()->create()->assignRole('customer');
    $this->actingAs($user)->get('/_test/admin')->assertForbidden();
    $this->get('/_test/reviewer')->assertForbidden();
    $this->get('/_test/review')->assertForbidden();
    $this->get('/_test/settings')->assertForbidden();
    expect($user->getAllPermissions())->toHaveCount(0);
});

test('reviewers can moderate but cannot administer or publish', function () {
    $user = User::factory()->create()->assignRole('reviewer');
    $this->actingAs($user)->get('/_test/reviewer')->assertOk();
    $this->get('/_test/review')->assertOk();
    $this->get('/_test/admin')->assertForbidden();
    $this->get('/_test/settings')->assertForbidden();
    expect($user->can('products.approve'))->toBeTrue()
        ->and($user->can('products.publish'))->toBeFalse()
        ->and($user->can('users.manage'))->toBeFalse();
});

test('authors do not inherit administrative or moderation permissions', function () {
    $user = User::factory()->create()->assignRole('author');
    expect($user->can('products.submit'))->toBeTrue()
        ->and($user->can('users.manage'))->toBeFalse()
        ->and($user->can('products.approve'))->toBeFalse();
    $this->actingAs($user)->get('/_test/admin')->assertForbidden();
    $this->get('/_test/review')->assertForbidden();
});

test('users can retain customer and author roles together', function () {
    $user = User::factory()->create()->assignRole('customer');
    $user->assignRole('author');
    expect($user->hasAllRoles(['customer', 'author']))->toBeTrue()
        ->and($user->can('products.submit'))->toBeTrue();
});

test('shared user data includes role names and effective permissions without internals', function () {
    $user = User::factory()->create()->assignRole(['customer', 'author']);
    $user->givePermissionTo('reports.view');
    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('auth.user.name', $user->name)
        ->where('auth.user.roles', $user->getRoleNames()->all())
        ->where('auth.user.permissions', $user->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->missing('auth.user.password')
        ->missing('auth.user.two_factor_secret')
        ->missing('auth.user.pivot'));
});

test('role seeding is repeatable and restores the defined grants', function () {
    Role::findByName('customer')->givePermissionTo('users.manage');
    $this->seed(RolesAndPermissionsSeeder::class);
    expect(Role::count())->toBe(4)
        ->and(Permission::count())->toBe(42)
        ->and(Role::findByName('customer')->permissions)->toHaveCount(0);
});
