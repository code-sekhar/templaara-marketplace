<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Industry;
use App\Models\Technology;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogs = [
                Category::class => ['POS & Inventory', 'HR & Payroll', 'CRM', 'Accounting & Finance', 'Field Service Management', 'Ecommerce', 'Booking & Reservation', 'Project Management', 'Business Analytics', 'Customer Support'],
                Industry::class => ['Retail', 'Wholesale & Distribution', 'Restaurant & Food Service', 'Healthcare', 'Education', 'Real Estate', 'Construction', 'Manufacturing', 'Logistics & Transportation', 'Professional Services', 'Hospitality'],
                Technology::class => ['Laravel', 'Vue.js', 'React', 'Angular', 'Node.js', 'Spring Boot', 'Flutter', 'PostgreSQL', 'MySQL', 'Redis'],
            ];
            foreach ($catalogs as $model => $names) {
                foreach ($names as $index => $name) {
                    $model::firstOrCreate(['slug' => Str::slug($name)], [
                        'name' => $name,
                        'sort_order' => $index * 10,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }
}
