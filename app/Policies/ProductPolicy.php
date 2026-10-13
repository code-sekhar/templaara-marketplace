<?php

namespace App\Policies;

use App\Enums\ProductOrigin;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function manageAll(User $user): bool
    {
        return $user->hasRole('admin') && $user->can('products.view');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('products.view') && ($user->hasRole('author') || $this->manageAll($user));
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view') && ($this->manageAll($user) || $this->owns($user, $product));
    }

    public function create(User $user): bool
    {
        return $user->can('products.create') && $user->hasAnyRole(['admin', 'author']);
    }

    public function createOfficial(User $user): bool
    {
        return $this->manageAll($user) && $user->can('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return match ($product->status) {
            ProductStatus::Draft, ProductStatus::ChangesRequested => $user->can('products.update') && ($this->manageAll($user) || $this->owns($user, $product)),
            default => false,
        };
    }

    public function delete(User $user, Product $product): bool
    {
        return match ($product->status) {
            ProductStatus::Draft => $user->can('products.delete') && ($this->manageAll($user) || $this->owns($user, $product)),
            default => false,
        };
    }

    public function submit(User $user, Product $product): bool
    {
        return $user->can('products.submit') && ($this->owns($user, $product) || ($product->origin === ProductOrigin::Templaara && $this->manageAll($user)));
    }

    public function reviewAny(User $user): bool
    {
        return $user->can('products.review');
    }

    public function review(User $user, Product $product): bool
    {
        return $this->reviewAny($user);
    }

    public function startReview(User $user, Product $product): bool
    {
        return $this->reviewAny($user) && $product->owner_id !== $user->id;
    }

    public function requestChanges(User $user, Product $product): bool
    {
        return $this->startReview($user, $product) && $user->can('products.soft-reject');
    }

    public function reject(User $user, Product $product): bool
    {
        return $this->startReview($user, $product) && $user->can('products.reject');
    }

    public function approve(User $user, Product $product): bool
    {
        return $this->startReview($user, $product) && $user->can('products.approve');
    }

    public function publish(User $user, Product $product): bool
    {
        return $user->hasRole('admin') && $user->can('products.publish');
    }

    private function owns(User $user, Product $product): bool
    {
        return $user->hasRole('author') && $product->origin === ProductOrigin::Author && $product->owner_id === $user->id;
    }
}
