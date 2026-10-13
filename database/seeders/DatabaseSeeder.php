<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call(DevelopmentAdminSeeder::class);
            $this->call(BusinessCatalogSeeder::class);
            $this->call(CommissionPolicySeeder::class);
            $this->call(DevelopmentProductSeeder::class);
        }
    }
}
