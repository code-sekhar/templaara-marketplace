<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(function (): void {
            $permissions = [
                'users.view',
                'users.manage',
                'authors.view',
                'authors.manage',
                'authors.verify',
                'categories.view',
                'categories.create',
                'categories.update',
                'categories.delete',
                'industries.view',
                'industries.create',
                'industries.update',
                'industries.delete',
                'technologies.view',
                'technologies.create',
                'technologies.update',
                'technologies.delete',
                'products.view',
                'products.create',
                'products.update',
                'products.delete',
                'products.submit',
                'products.review',
                'products.approve',
                'products.soft-reject',
                'products.reject',
                'products.publish',
                'orders.view',
                'orders.manage',
                'reviews.view',
                'reviews.manage',
                'support.view',
                'support.manage',
                'commissions.view',
                'commissions.manage',
                'payouts.view',
                'payouts.manage',
                'refunds.view',
                'refunds.manage',
                'settings.view',
                'settings.manage',
                'reports.view',
            ];
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            $roles = [
                'admin' => $permissions,
                'reviewer' => [
                    'authors.view',
                    'categories.view',
                    'industries.view',
                    'technologies.view',
                    'products.view',
                    'products.review',
                    'products.approve',
                    'products.soft-reject',
                    'products.reject',
                ],
                'author' => [
                    'categories.view',
                    'industries.view',
                    'technologies.view',
                    'products.view',
                    'products.create',
                    'products.update',
                    'products.delete',
                    'products.submit',
                ],
                'customer' => [],
            ];

            foreach ($roles as $name => $grants) {
                Role::findOrCreate($name, 'web')->syncPermissions($grants);
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
