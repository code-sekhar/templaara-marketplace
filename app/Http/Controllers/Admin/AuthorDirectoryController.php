<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuthorApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\AuthorProfile;
use App\Services\Authors\AuthorProfileCompleteness;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuthorDirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(AuthorApplicationStatus::class)], 'author_type' => ['nullable', 'in:individual,company'], 'country_code' => ['nullable', Rule::in(Countries::codes())]]);
        $q = AuthorProfile::with(['technologies:id', 'expertise:id', 'portfolio:id,author_profile_id', 'applications' => fn ($q) => $q->select('id', 'author_profile_id', 'round_number')->limit(1)]);
        foreach (['status', 'author_type', 'country_code'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['search'])) {
            $q->where('display_name', 'like', '%'.addcslashes($filters['search'], '%_\\').'%');
        }

        return Inertia::render('authors/Directory', ['filters' => $filters, 'countries' => Countries::codes(), 'records' => $q->orderBy('display_name')->orderBy('id')->paginate(15)->withQueryString()->through(fn ($p) => [...$p->only(['display_name', 'slug', 'status', 'author_type', 'country_code', 'years_of_experience', 'is_legacy']), 'completeness' => app(AuthorProfileCompleteness::class)->calculate($p), 'application_url' => $p->applications->first()?->id ? route('admin.author-applications.show', $p->applications->first()->id, false) : null])]);
    }
}
