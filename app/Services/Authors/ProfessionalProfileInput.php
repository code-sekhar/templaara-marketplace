<?php

namespace App\Services\Authors;

use App\Models\AuthorProfile;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProfessionalProfileInput
{
    /** @return list<string> */
    public function fields(): array
    {
        return ['professional_headline', 'years_of_experience', 'founded_year', 'team_size', 'state_region', 'city', 'public_location', 'languages', 'support_availability', 'confidential_work'];
    }

    public function link(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : false;
            $host = strtolower($parts['host'] ?? '');
            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || ! str_contains($host, '.') || in_array($host, ['localhost', 'localhost.localdomain']) || preg_match('/\.(localhost|local|internal|test)$/D', $host) || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                $fail('Use a public HTTPS link without credentials or a custom port.');
            }
            if ($attribute === 'github_url' && $host !== 'github.com') {
                $fail('Use a github.com profile or organization link.');
            }
            if ($attribute === 'linkedin_url' && ! in_array($host, ['linkedin.com', 'www.linkedin.com'])) {
                $fail('Use a linkedin.com professional profile link.');
            }
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function validate(array $input, ?AuthorProfile $profile = null): array
    {
        return Validator::make($input, [
            'professional_headline' => ['nullable', 'string', 'max:160'],
            'years_of_experience' => ['nullable', 'integer', 'between:0,60'],
            'founded_year' => ['nullable', 'integer', 'between:1900,'.now()->year],
            'team_size' => ['nullable', Rule::in(config('author_professional.team_sizes'))],
            'state_region' => ['nullable', 'string', 'max:100'], 'city' => ['nullable', 'string', 'max:100'],
            'public_location' => ['sometimes', 'boolean'],
            'languages' => ['sometimes', 'array', 'list', 'max:10'], 'languages.*' => ['string', 'distinct', Rule::in(config('author_professional.languages'))],
            'support_availability' => ['nullable', 'string', 'max:200'],
            'confidential_work' => ['nullable', 'string', 'max:2000'],
            'technology_ids' => ['sometimes', 'array', 'list', 'max:15'], 'technology_ids.*' => ['integer', 'distinct', Rule::exists('technologies', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $profile?->technologies()->pluck('technologies.id')->all() ?? []))],
            'expertise_ids' => ['sometimes', 'array', 'list', 'max:10'], 'expertise_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $profile?->expertise()->pluck('categories.id')->all() ?? []))],
            'revision' => ['sometimes', 'integer', 'min:0'],
        ])->validate();
    }
}
