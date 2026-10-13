<?php

namespace App\Services\Authors;

use App\Models\AuthorProfile;

class AuthorProfileCompleteness
{
    /** @return array{percentage:int,completed:list<string>,missing:list<string>} */
    public function calculate(?AuthorProfile $p): array
    {
        $p ??= new AuthorProfile;
        $sections = ['Identity' => ! empty($p->display_name) && ! empty($p->country_code) && $p->author_type !== null,
            'Headline' => ! empty($p->professional_headline), 'About' => mb_strlen($p->bio ?? '') >= 50,
            'Experience' => $p->years_of_experience !== null,
            'Expertise' => ($p->technologies->isNotEmpty() || $p->expertise->isNotEmpty()),
            'Professional links' => ($p->getAttribute('website_url') || $p->getAttribute('github_url') || $p->getAttribute('portfolio_url') || $p->getAttribute('linkedin_url')),
            'Work evidence' => ($p->portfolio->isNotEmpty() || mb_strlen($p->confidential_work ?? '') >= 30),
            'Communication' => ! empty($p->languages)];
        $complete = array_keys(array_filter($sections));

        return ['percentage' => (int) floor(count($complete) * 100 / count($sections)), 'completed' => $complete, 'missing' => array_keys(array_filter($sections, fn ($v) => ! $v))];
    }
}
