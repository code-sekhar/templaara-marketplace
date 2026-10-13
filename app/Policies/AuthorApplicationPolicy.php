<?php

namespace App\Policies;

use App\Models\AuthorApplication;
use App\Models\User;

class AuthorApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('authors.view');
    }

    public function view(User $user, AuthorApplication $application): bool
    {
        return $application->user_id === $user->id || $this->viewAny($user);
    }

    public function review(User $user, AuthorApplication $application): bool
    {
        return $user->hasVerifiedEmail() && $application->user_id !== $user->id && $this->viewAny($user) && $user->can('authors.verify');
    }
}
