<?php

namespace App\Services\Authors;

use App\Models\AuthorProfile;
use Illuminate\Validation\ValidationException;

class AuthorProfessional
{
    /** @return array<string,mixed> */
    public function snapshot(AuthorProfile $profile): array
    {
        $profile->load(['technologies', 'expertise', 'portfolio.technologies', 'portfolio.media', 'media']);

        return [...$profile->only(app(ProfessionalProfileInput::class)->fields()),
            'technologies' => $profile->technologies->sortBy('id')->values()->map->only(['id', 'name'])->all(),
            'expertise' => $profile->expertise->sortBy('id')->values()->map->only(['id', 'name'])->all(),
            'portfolio' => $profile->portfolio->map(fn ($item) => app(AuthorPortfolio::class)->present($item))->all(),
            'avatar' => app(AuthorMedia::class)->present($profile->media->firstWhere('purpose', 'avatar')),
            'cover' => app(AuthorMedia::class)->present($profile->media->firstWhere('purpose', 'cover'))];
    }

    /** @param array<string,mixed> $evidence */
    public function ready(array $evidence): void
    {
        $errors = [];
        if (mb_strlen(trim($evidence['professional_headline'] ?? '')) < 5) {
            $errors['professional_headline'] = 'Add a concise professional headline before submitting.';
        }
        if (! isset($evidence['years_of_experience'])) {
            $errors['years_of_experience'] = 'State your years of professional experience (including zero).';
        }
        if (empty($evidence['technologies']) && empty($evidence['expertise'])) {
            $errors['technology_ids'] = 'Choose at least one technology or area of business expertise.';
        }
        if (empty($evidence['portfolio']) && mb_strlen(trim($evidence['confidential_work'] ?? '')) < 30) {
            $errors['confidential_work'] = 'Add portfolio work or explain confidential work in at least 30 characters, with a professional link.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
