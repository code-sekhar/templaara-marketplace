<?php

namespace App\Policies;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\User;

class ProductRevisionPolicy
{
    public function create(User $u, Product $p): bool
    {
        return $p->status === ProductStatus::Published && $u->can('view', $p) && $u->can('products.update');
    }

    public function view(User $u, ProductRevision $r): bool
    {
        return $u->can('view', $r->product) || $u->can('review', $r->product);
    }

    public function update(User $u, ProductRevision $r): bool
    {
        return $r->editable() && $this->create($u, $r->product);
    }

    public function submit(User $u, ProductRevision $r): bool
    {
        return $this->update($u, $r) && $u->can('submit', $r->product);
    }
}
