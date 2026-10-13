<?php

namespace App\Services\Authors;

use App\Enums\AuthorApplicationStatus as Status;
use App\Enums\AuthorType;
use App\Models\AuthorApplication;
use App\Models\AuthorProfile;
use App\Models\Category;
use App\Models\Technology;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuthorWorkspace
{
    /** @return array<string,mixed> */
    public function applicant(User $user): array
    {
        $p = AuthorProfile::where('user_id', $user->id)->first();

        $p?->load(['technologies', 'expertise', 'portfolio.technologies', 'portfolio.media', 'media']);
        $latestFeedback = $p && $p->status === Status::ChangesRequested ? ($p->applications()->first()?->feedback->map(fn ($f) => $f->only(['section', 'message', 'created_at']))->all() ?? []) : [];

        return ['latestFeedback' => $latestFeedback, 'profile' => $p ? [...$p->only([...app(AuthorProfileInput::class)->fields(), ...app(ProfessionalProfileInput::class)->fields(), 'status', 'is_legacy', 'revision']), 'professional' => ! $p->status->editable() ? $p->applications()->first()?->snapshot['professional'] ?? null : null, 'technology_ids' => $p->technologies->pluck('id')->all(), 'expertise_ids' => $p->expertise->pluck('id')->all()] : null,
            'legacy' => $user->hasRole('author') && (! $p || $p->is_legacy),
            'editable' => ! $p || $user->can('update', $p),
            'completeness' => app(AuthorProfileCompleteness::class)->calculate($p),
            'portfolio' => $p ? $p->portfolio->map(fn ($i) => app(AuthorPortfolio::class)->present($i))->all() : [],
            'media' => $p ? $p->media->whereNull('author_portfolio_item_id')->map(fn ($m) => [...(app(AuthorMedia::class)->present($m) ?? []), 'purpose' => $m->purpose])->all() : [],
            'professional_options' => ['technologies' => Technology::active()->ordered()->limit(100)->get(['id', 'name'])->toArray(), 'expertise' => Category::active()->ordered()->limit(100)->get(['id', 'name'])->toArray(), 'languages' => config('author_professional.languages'), 'team_sizes' => config('author_professional.team_sizes')],
            'countries' => Countries::codes(), 'history' => $p ? $this->history($p) : null];
    }

    /** @return array<string,mixed> */
    public function history(AuthorProfile $profile): array
    {
        return ['rounds' => $profile->applications()->with(['feedback', 'events.actor:id,name'])->paginate(10, ['*'], 'rounds_page')->withQueryString()->through(fn ($a) => $this->round($a))];
    }

    /** @return array<string,mixed> */
    public function round(AuthorApplication $a): array
    {
        return [...$a->only(['id', 'round_number', 'status', 'submitted_at', 'review_started_at', 'decision_at', 'decision_summary', 'snapshot']),
            'feedback' => $a->feedback->map(fn ($f) => $f->only(['section', 'message', 'created_at']))->all(),
            'events' => $a->events->map(fn ($e) => [...$e->only(['action', 'from_status', 'to_status', 'occurred_at']), 'actor_name' => $e->actor->name])->all()];
    }

    /** @return array<string,mixed> */
    public function queue(Request $request): array
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(Status::class)], 'author_type' => ['nullable', Rule::enum(AuthorType::class)], 'country' => ['nullable', Rule::in(Countries::codes())],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')]]);
        $q = AuthorApplication::with('user:id,name,email')->whereRaw('round_number = (select max(a.round_number) from author_applications a where a.author_profile_id=author_applications.author_profile_id)');
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        foreach (['author_type' => 'author_type', 'country' => 'country_code'] as $key => $field) {
            if (! empty($filters[$key])) {
                $q->where('snapshot->'.$field, $filters[$key]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $q->where(function ($q) use ($term): void {
                // JSON extraction is portable; lowercase expressions keep matching case insensitive.
                $display = DB::getDriverName() === 'pgsql' ? "snapshot->>'display_name'" : "json_extract(snapshot, '$.display_name')";
                $company = DB::getDriverName() === 'pgsql' ? "snapshot->>'company_name'" : "json_extract(snapshot, '$.company_name')";
                $q->whereRaw("LOWER($display) LIKE ? ESCAPE '\\'", [$term])->orWhereRaw("LOWER($company) LIKE ? ESCAPE '\\'", [$term])->orWhereHas('user', fn ($u) => $u->whereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$term]));
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (! empty($filters[$key])) {
                $q->whereDate('submitted_at', $op, $filters[$key]);
            }
        }

        return ['filters' => $filters, 'countries' => Countries::codes(), 'records' => $q->orderByDesc('submitted_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($a) => [...$a->only(['id', 'round_number', 'status', 'submitted_at']), 'display_name' => $a->snapshot['display_name'], 'company_name' => $a->snapshot['company_name'] ?? null, 'author_type' => $a->snapshot['author_type'], 'country_code' => $a->snapshot['country_code'], 'applicant' => $a->user->only(['name', 'email'])])];
    }

    /** @return array<string,mixed> */
    public function review(Request $request, AuthorApplication $a): array
    {
        $a->load(['profile', 'user:id,name,email', 'reviewer:id,name', 'feedback', 'events.actor:id,name']);
        $current = $a->profile->applications()->first()?->id === $a->id && $a->profile->status === $a->status;
        $allowed = $request->user()->can('review', $a) && $current;

        return ['application' => $this->round($a), 'applicant' => $a->user->only(['name', 'email']), 'reviewer' => $a->reviewer?->only(['name']),
            'history' => $this->history($a->profile), 'actions' => ['start' => $allowed && $a->status === Status::Submitted, 'decide' => $allowed && $a->status === Status::UnderReview && $a->reviewed_by === $request->user()->id]];
    }
}
