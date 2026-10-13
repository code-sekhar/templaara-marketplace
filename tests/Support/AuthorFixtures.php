<?php

namespace Tests\Support;

use App\Enums\AuthorApplicationAction as Action;
use App\Models\AuthorApplication;
use App\Models\AuthorProfile;
use App\Models\Technology;
use App\Models\User;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\SaveAuthorProfile;

class AuthorFixtures
{
    /** @return array<string,mixed> */
    public static function ready(array $extra = []): array
    {
        return [...['display_name' => 'Acme Business Software', 'author_type' => 'individual', 'country_code' => 'IN', 'bio' => 'We build inventory and business management software for retailers and growing companies.', 'support_email' => 'support@example.com', 'website_url' => 'https://example.com', 'professional_headline' => 'Business software specialist', 'years_of_experience' => 6, 'confidential_work' => 'Client engagements are protected by non-disclosure agreements; the website provides professional evidence.', 'technology_ids' => [Technology::firstOrCreate(['slug' => 'laravel'], ['name' => 'Laravel', 'is_active' => true, 'sort_order' => 0])->id]], ...$extra];
    }

    public static function user(string $role = 'customer'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public static function profile(?User $user = null, array $extra = []): AuthorProfile
    {
        return app(SaveAuthorProfile::class)->save($user ?? self::user(), self::ready($extra));
    }

    public static function submitted(?User $user = null): AuthorApplication
    {
        $p = self::profile($user);

        return app(AuthorWorkflow::class)->transition($p->user, $p, Action::Submit);
    }

    public static function claimed(?User $actor = null, ?AuthorApplication $a = null): AuthorApplication
    {
        $a ??= self::submitted();
        $actor ??= self::user('admin');

        return app(AuthorWorkflow::class)->transition($actor, $a->profile, Action::Start, $a->id);
    }
}
