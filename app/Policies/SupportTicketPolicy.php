<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function customer(User $u, SupportTicket $t): bool
    {
        return $t->getAttribute('customer_user_id') === $u->id;
    }

    public function seller(User $u, SupportTicket $t): bool
    {
        return $t->getAttribute('seller_user_id') === $u->id && $u->hasRole('author') && $u->can('products.view');
    }

    public function view(User $u, SupportTicket $t): bool
    {
        return $this->customer($u, $t) || $this->seller($u, $t) || $u->can('support.view');
    }

    public function manage(User $u, SupportTicket $t): bool
    {
        return $u->can('support.manage');
    }
}
