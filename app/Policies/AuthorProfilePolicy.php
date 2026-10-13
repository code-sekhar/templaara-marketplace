<?php

namespace App\Policies;

use App\Enums\AuthorApplicationStatus;
use App\Models\AuthorProfile;
use App\Models\User;

class AuthorProfilePolicy
{
    public function view(User $user, AuthorProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function update(User $user, AuthorProfile $profile): bool
    {
        return $this->view($user, $profile) && (($profile->status === AuthorApplicationStatus::Approved && $user->hasRole('author')) || ($user->hasVerifiedEmail() && $profile->status->editable() && ! $user->hasRole('author')));
    }

    public function submit(User $user, AuthorProfile $profile): bool
    {
        return $user->hasVerifiedEmail() && ! $user->hasRole('author') && $profile->status->editable() && $this->view($user, $profile);
    }
}
