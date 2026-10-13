<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuthorApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authors\AuthorProfileRequest;
use App\Models\AuthorApplication;
use App\Services\Authors\AuthorWorkflow;
use App\Services\Authors\AuthorWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthorApplicationController extends Controller
{
    public function index(Request $request, AuthorWorkspace $workspace): Response
    {
        return Inertia::render('authors/Applications', $workspace->queue($request));
    }

    public function show(Request $request, AuthorApplication $application, AuthorWorkspace $workspace): Response
    {
        return Inertia::render('authors/Review', $workspace->review($request, $application));
    }

    public function transition(AuthorProfileRequest $request, AuthorApplication $application, AuthorWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($request->user(), $application->profile, AuthorApplicationAction::from($request->route('action')), $application->id, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Author review action recorded.']);

        return to_route('admin.author-applications.show', $application);
    }
}
