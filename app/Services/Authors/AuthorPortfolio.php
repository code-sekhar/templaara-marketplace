<?php

namespace App\Services\Authors;

use App\Models\AuthorPortfolioItem;
use App\Models\AuthorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthorPortfolio
{
    public function locked(User $user): AuthorProfile
    {
        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $profile = AuthorProfile::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($user)->authorize('update', $profile);

        return $profile;
    }

    /** @param array<string,mixed> $input */
    public function save(User $user, array $input, ?AuthorPortfolioItem $item = null): AuthorPortfolioItem
    {
        return DB::transaction(function () use ($user, $input, $item): AuthorPortfolioItem {
            $profile = $this->locked($user);
            $existing = $item ? $profile->portfolio()->whereKey($item->id)->lockForUpdate()->firstOrFail() : null;
            $link = app(ProfessionalProfileInput::class)->link();
            $data = Validator::make($input, [
                'title' => ['required', 'string', 'max:160'], 'short_description' => ['required', 'string', 'max:500'],
                'description' => ['nullable', 'string', 'max:5000'], 'project_type' => ['required', Rule::in(config('author_professional.project_types'))],
                'project_url' => ['nullable', 'url:https', 'max:2048', $link], 'case_study_url' => ['nullable', 'url:https', 'max:2048', $link], 'repository_url' => ['nullable', 'url:https', 'max:2048', $link],
                'client_name' => ['nullable', 'string', 'max:150'], 'completion_year' => ['nullable', 'integer', 'between:1900,'.now()->year],
                'is_public' => ['required', 'boolean'], 'is_featured' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'between:0,999'],
                'revision' => [$existing ? 'required' : 'sometimes', 'integer', 'min:0'],
                'technology_ids' => ['sometimes', 'array', 'list', 'max:15'], 'technology_ids.*' => ['integer', 'distinct', Rule::exists('technologies', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $existing?->technologies()->pluck('technologies.id')->all() ?? []))],
                'author_profile_id' => ['prohibited'], 'user_id' => ['prohibited'], 'public_uuid' => ['prohibited'],
            ])->validate();
            if ($existing && (int) $data['revision'] !== $existing->revision) {
                throw ValidationException::withMessages(['revision' => 'This portfolio item changed. Reload before saving.']);
            }
            if (! $existing && $profile->portfolio()->count() >= config('author_professional.portfolio_max')) {
                throw ValidationException::withMessages(['title' => 'Your portfolio has reached its 12-item limit.']);
            }
            $ids = $data['technology_ids'] ?? [];
            unset($data['revision'],$data['technology_ids'],$data['user_id'],$data['author_profile_id'],$data['public_uuid']);
            $existing ??= new AuthorPortfolioItem;
            if (! $existing->exists) {
                $existing->forceFill(['author_profile_id' => $profile->id, 'public_uuid' => (string) Str::uuid()]);
            }
            $existing->forceFill([...$data, 'revision' => ($existing->revision ?? 0) + 1])->save();
            $existing->technologies()->sync($ids);
            foreach ($profile->portfolio()->lockForUpdate()->get() as $position => $record) {
                if ($record->sort_order !== $position) {
                    $record->forceFill(['sort_order' => $position, 'revision' => $record->revision + 1])->save();
                }
            }
            $existing->refresh();
            $profile->increment('revision');

            return $existing;
        }, 3);
    }

    public function delete(User $user, AuthorPortfolioItem $item, int $revision): void
    {
        DB::transaction(function () use ($user, $item, $revision): void {
            $p = $this->locked($user);
            $i = $p->portfolio()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($i->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'This item changed. Reload before deleting.']);
            }
            $i->media()->update(['is_current' => false]);
            $i->delete();
            $p->increment('revision');
        }, 3);
    }

    /** @param list<string> $uuids */
    public function reorder(User $user, array $uuids, int $revision): void
    {
        DB::transaction(function () use ($user, $uuids, $revision): void {
            $p = $this->locked($user);
            if ($p->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Your portfolio changed. Reload before reordering.']);
            }
            $items = $p->portfolio()->lockForUpdate()->get();
            if (count($uuids) !== $items->count() || count(array_unique($uuids)) !== count($uuids) || array_diff($uuids, $items->pluck('public_uuid')->all())) {
                throw ValidationException::withMessages(['items' => 'Provide each of your portfolio items exactly once.']);
            }
            foreach ($uuids as $index => $uuid) {
                $items->firstWhere('public_uuid', $uuid)->forceFill(['sort_order' => $index, 'revision' => $items->firstWhere('public_uuid', $uuid)->revision + 1])->save();
            }
            $p->increment('revision');
        }, 3);
    }

    /** @return array<string,mixed> */
    public function present(AuthorPortfolioItem $item): array
    {
        return [...$item->only(['public_uuid', 'title', 'short_description', 'description', 'project_type', 'project_url', 'case_study_url', 'repository_url', 'client_name', 'completion_year', 'is_public', 'is_featured', 'sort_order', 'revision']),
            'technologies' => $item->technologies->map->only(['id', 'name'])->all(),
            'image' => app(AuthorMedia::class)->present($item->media->first())];
    }
}
