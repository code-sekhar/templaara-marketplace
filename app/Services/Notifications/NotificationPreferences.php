<?php

namespace App\Services\Notifications;

use App\Enums\MarketplaceNotificationType as Type;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NotificationPreferences
{
    /** @return array<string,string> */
    public function groups(): array
    {
        return ['orders' => 'Orders & purchases', 'refunds' => 'Refund updates', 'author_workflow' => 'Author applications & product decisions', 'product_updates' => 'Product update decisions', 'earnings' => 'Author earnings', 'payouts' => 'Payout updates', 'reviews' => 'Customer reviews & seller responses', 'support' => 'Product support messages'];
    }

    /** @return array<int,array<string,mixed>> */
    public function forUser(User $user): array
    {
        $rows = DB::table('notification_preferences')->where('user_id', $user->id)->get()->keyBy('group');
        $out = [];
        foreach ($this->groups() as $group => $label) {
            $r = $rows->get($group);
            $out[] = ['group' => $group, 'label' => $label, 'in_app_enabled' => $r ? (bool) $r->in_app_enabled : true, 'email_enabled' => $r ? (bool) $r->email_enabled : ! in_array($group, ['earnings', 'reviews']), 'mandatory_in_app' => in_array($group, ['orders', 'refunds', 'payouts'])];
        }

        return $out;
    }

    /**
     * Caller locks recipient, serializing preference snapshots and update.
     *
     * @return array{in_app:bool,email:bool}
     */
    public function channels(User $u, Type $type): array
    {
        $r = DB::table('notification_preferences')->where('user_id', $u->id)->where('group', $type->group())->first();

        return ['in_app' => $type->mandatory() || ! $r || (bool) $r->in_app_enabled, 'email' => $type->email() && ($r ? (bool) $r->email_enabled : ! in_array($type->group(), ['earnings', 'reviews']))];
    }
}
