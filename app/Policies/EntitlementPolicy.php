<?php

namespace App\Policies;

use App\Models\Entitlement;
use App\Models\User;
use App\Services\Entitlements\PurchaseAccess;

class EntitlementPolicy
{
    public function view(User $user, Entitlement $e): bool
    {
        return $user->id === $e->user_id;
    }

    public function download(User $user, Entitlement $e): bool
    {
        return $this->view($user, $e) && app(PurchaseAccess::class)->valid($e);
    }
}
