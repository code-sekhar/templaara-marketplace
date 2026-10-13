<?php

namespace Database\Seeders;

use App\Models\CommissionPolicy;
use App\Services\Earnings\CommissionPolicies;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CommissionPolicySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            app(CommissionPolicies::class)->lock();
            if (CommissionPolicy::exists()) {
                return;
            }(new CommissionPolicy)->forceFill(['name' => 'Initial marketplace commission', 'percentage' => '20.00', 'hold_days' => 7, 'is_default' => true, 'is_active' => true, 'effective_from' => now()->startOfDay()])->save();
        });
    }
}
