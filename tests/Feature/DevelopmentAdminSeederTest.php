<?php

use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('templaara.development_admin', [
        'name' => 'Local Admin', 'email' => 'admin@example.com', 'password' => 'StrongLocalPassword123!',
    ]);
});

test('development admin credentials are hashed and both roles are assigned', function () {
    $this->seed(DevelopmentAdminSeeder::class);
    $user = User::where('email', 'admin@example.com')->firstOrFail();
    expect(Hash::check('StrongLocalPassword123!', $user->password))->toBeTrue()
        ->and($user->hasAllRoles(['admin', 'customer']))->toBeTrue();
});

test('development admin seeding refuses to promote existing users', function () {
    $user = User::factory()->create(['email' => 'admin@example.com']);
    expect(fn () => $this->seed(DevelopmentAdminSeeder::class))->toThrow(RuntimeException::class);
    expect($user->fresh()->hasRole('admin'))->toBeFalse();
});

test('development admin seeding cannot run in production', function () {
    app()->instance('env', 'production');
    expect(fn () => (new DevelopmentAdminSeeder)->run())->toThrow(RuntimeException::class);
    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('development admin seeding skips absent credentials', function () {
    config()->set('templaara.development_admin.email', null);
    config()->set('templaara.development_admin.password', null);
    $this->seed(DevelopmentAdminSeeder::class);
    expect(User::count())->toBe(0);
});

test('development admin seeding rejects weak or incomplete credentials', function () {
    config()->set('templaara.development_admin.password', 'password');
    expect(fn () => $this->seed(DevelopmentAdminSeeder::class))->toThrow(ValidationException::class);
    expect(User::count())->toBe(0);
});
