<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Development admin seeding is restricted to local and testing environments.');
        }

        $credentials = config('templaara.development_admin');
        if (! $credentials['email'] && ! $credentials['password']) {
            return;
        }

        Validator::make($credentials, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();

        DB::transaction(function () use ($credentials): void {
            if (User::where('email', $credentials['email'])->exists()) {
                throw new RuntimeException('Refusing to promote or overwrite an existing user.');
            }

            $user = User::create($credentials);
            $user->assignRole(['customer', 'admin']);
        });
    }
}
