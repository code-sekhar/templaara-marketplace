<?php

namespace App\Services\Authors;

use App\Enums\AuthorApplicationStatus;
use App\Models\AuthorProfile;
use App\Models\User;
use App\Support\UniqueSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveAuthorProfile
{
    /** @param array<string,mixed> $input */
    public function save(User $actor, array $input): AuthorProfile
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $input): AuthorProfile {
                    $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                    abort_unless($user->hasVerifiedEmail() || $user->hasRole('author'), 403, 'Verify your email before author onboarding.');
                    $profile = AuthorProfile::where('user_id', $user->id)->lockForUpdate()->first();
                    if ($profile) {
                        Gate::forUser($user)->authorize('update', $profile);
                    }
                    $data = app(AuthorProfileInput::class)->validate($input, $profile);
                    if ($profile && (! isset($data['revision']) || (int) $data['revision'] !== $profile->revision)) {
                        throw ValidationException::withMessages(['revision' => 'Your profile changed. Reload before saving.']);
                    }
                    if ($profile && $profile->status === AuthorApplicationStatus::Approved) {
                        foreach (['display_name', 'company_name', 'author_type', 'country_code', 'slug', 'support_email', 'support_phone'] as $key) {
                            $current = $profile->getAttribute($key);
                            if ($current instanceof \BackedEnum) {
                                $current = $current->value;
                            }
                            if (array_key_exists($key, $data) && ($data[$key] ?? '') !== ($current ?? '')) {
                                throw ValidationException::withMessages([$key => 'Verified identity is locked. Contact the marketplace for a reviewed identity change.']);
                            }
                        }
                    }
                    $technologies = $data['technology_ids'] ?? null;
                    $expertise = $data['expertise_ids'] ?? null;
                    unset($data['technology_ids'],$data['expertise_ids'],$data['revision']);
                    $profile ??= new AuthorProfile;
                    if (empty($data['slug'])) {
                        $data['slug'] = $profile->exists ? $profile->getAttribute('slug') : app(UniqueSlug::class)->generate($profile, in_array(Str::slug($data['display_name'] ?? $user->name), config('author_professional.reserved_slugs'), true) ? ($data['display_name'] ?? $user->name).' software' : ($data['display_name'] ?? $user->name), 'author');
                    }
                    $profile->forceFill($data);
                    if (! $profile->exists) {
                        $profile->forceFill(['user_id' => $user->id, 'status' => $user->hasRole('author') ? AuthorApplicationStatus::Approved : AuthorApplicationStatus::Draft, 'is_legacy' => $user->hasRole('author')]);
                    }
                    $profile->forceFill(['revision' => ($profile->revision ?? 0) + 1])->save();
                    if ($technologies !== null) {
                        $profile->technologies()->sync($technologies);
                    }
                    if ($expertise !== null) {
                        $profile->expertise()->sync($expertise);
                    }

                    return $profile;
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                if (! empty($input['slug']) || $attempt === 4) {
                    throw ValidationException::withMessages(['slug' => 'This author slug is already in use. Choose another.']);
                }
            }
        }
        throw new \LogicException('Unreachable slug retry.');
    }
}
