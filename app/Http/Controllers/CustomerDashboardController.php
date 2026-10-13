<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\CustomerWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerDashboardController extends Controller
{
    public function __invoke(Request $request, CustomerWorkspace $workspace): Response
    {
        return Inertia::render('Dashboard', ['workspace' => $workspace->forUser($request->user())]);
    }
}
