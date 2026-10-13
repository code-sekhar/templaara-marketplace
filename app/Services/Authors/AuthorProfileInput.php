<?php

namespace App\Services\Authors;

use App\Enums\AuthorType;
use App\Models\AuthorProfile;
use App\Support\Countries;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AuthorProfileInput
{
    /** @return list<string> */
    public function fields(): array
    {
        return ['display_name', 'company_name', 'slug', 'author_type', 'country_code', 'bio', 'website_url', 'portfolio_url', 'github_url', 'linkedin_url', 'support_email', 'support_phone'];
    }

    /** @return list<string> */
    public function protectedFields(): array
    {
        return ['user_id', 'author_profile_id', 'reviewed_by', 'actor_id', 'created_by', 'submitted_by', 'status', 'decision', 'decision_at', 'review_started_at', 'submitted_at', 'round_number', 'snapshot', 'snapshot_version', 'roles', 'role', 'permissions', 'author', 'is_legacy', 'approved_at'];
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public function validate(array $data, ?AuthorProfile $profile = null, bool $submission = false): array
    {
        foreach ($this->fields() as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (is_string($data['country_code'] ?? null)) {
            $data['country_code'] = strtoupper($data['country_code']);
        }
        $required = $submission ? 'required' : 'nullable';
        $rules = ['display_name' => [$required, 'string', 'min:2', 'max:150'], 'company_name' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('author_profiles', 'slug')->ignore($profile?->id), Rule::notIn(array_diff(config('author_professional.reserved_slugs'), [$profile?->getAttribute('slug')]))],
            'author_type' => [$required, Rule::enum(AuthorType::class)], 'country_code' => [$required, Rule::in(Countries::codes())],
            'bio' => [$required, 'string', ...($submission ? ['min:50'] : []), 'max:5000'],
            'support_email' => [$required, 'email', 'max:255'], 'support_phone' => ['nullable', 'string', 'max:40']];
        foreach (['website_url', 'portfolio_url', 'github_url', 'linkedin_url'] as $field) {
            $rules[$field] = ['nullable', 'url:http,https', 'max:2048', app(ProfessionalProfileInput::class)->link()];
        }
        foreach ($this->protectedFields() as $field) {
            $rules[$field] = ['prohibited'];
        }
        $validator = Validator::make($data, $rules, ['bio.min' => 'Describe your software experience in at least 50 characters.']);
        if ($submission) {
            $validator->after(function ($v) use ($data): void {
                if (($data['author_type'] ?? null) === 'company' && empty($data['company_name'])) {
                    $v->errors()->add('company_name', 'Add your company name before submitting.');
                }
                if (empty($data['website_url']) && empty($data['portfolio_url']) && empty($data['github_url']) && empty($data['professional']['portfolio'])) {
                    $v->errors()->add('website_url', 'Add a website, portfolio, or GitHub profile before submitting.');
                }
            });
        }

        // Laravel's prohibited rule accepts empty values; never persist those protected keys.
        return [...array_intersect_key($validator->validate(), array_fill_keys($this->fields(), true)), ...app(ProfessionalProfileInput::class)->validate($data, $profile)];
    }
}
