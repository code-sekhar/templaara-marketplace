<?php

namespace App\Http\Controllers\Author;

use App\Enums\AuthorApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authors\AuthorProfileRequest;
use App\Models\AuthorProfile;
use App\Services\Authors\AuthorProfileCompleteness;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\AuthorWorkspace;
use App\Services\Authors\SaveAuthorProfile;
use App\Services\Earnings\EarningPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthorOnboardingController extends Controller
{
    public function show(Request $request, AuthorWorkspace $workspace): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('authors/Onboarding', $workspace->applicant($request->user()));
    }

    public function save(AuthorProfileRequest $request, SaveAuthorProfile $save): RedirectResponse
    {
        $save->save($request->user(), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Author profile saved.']);

        return back();
    }

    public function submit(AuthorProfileRequest $request, AuthorWorkflow $workflow): RedirectResponse
    {
        $p = AuthorProfile::where('user_id', $request->user()->id)->firstOrFail();
        $workflow->transition($request->user(), $p, AuthorApplicationAction::Submit, input: $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your author application has been submitted.']);

        return to_route('author.onboarding.show');
    }

    public function dashboard(Request $request): Response
    {
        abort_unless($request->user()->hasRole('author'), 403);
        $counts = $request->user()->products()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('authors/Dashboard', ['completeness' => app(AuthorProfileCompleteness::class)->calculate($request->user()->authorProfile), 'earnings' => app(EarningPresentation::class)->balances($request->user()->id), 'counts' => $counts, 'sellerStatus' => ($request->user()->authorProfile->is_legacy ?? true) ? 'Existing Author' : 'Approved Author']);
    }
}
