<?php

namespace App\Policies;

use App\Models\ProductReview;
use App\Models\User;

class ProductReviewPolicy
{
    public function update(User $u, ProductReview $r): bool
    {
        return $r->getAttribute('customer_user_id') === $u->id;
    }

    public function withdraw(User $u, ProductReview $r): bool
    {
        return $this->update($u, $r);
    }

    public function moderate(User $u, ProductReview $r): bool
    {
        return $u->can('reviews.manage');
    }

    public function respond(User $u, ProductReview $r): bool
    {
        $p = $r->product;

        return ($p->origin->value === 'author' && $p->owner_id === $u->id && $u->hasRole('author') && $u->can('products.view')) || ($p->origin->value === 'templaara' && $u->hasRole('admin') && $u->can('reviews.manage'));
    }
}
