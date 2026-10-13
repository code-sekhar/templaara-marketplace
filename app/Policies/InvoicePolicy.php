<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

final class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->order->user_id === $user->id;
    }

    public function inspect(User $user, Invoice $invoice): bool
    {
        return $user->can('orders.view');
    }
}
