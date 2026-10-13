<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthorEmailVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Legacy authors retain existing access; verification is required for new applicants and review actors.
        $legacy = $request->routeIs('author.*') && $user?->hasRole('author');
        if (! $legacy && ! $user?->hasVerifiedEmail()) {
            if ($request->expectsJson()) {
                abort(403, 'Verify your account email before author onboarding.');
            }

            return redirect()->guest(route('verification.notice'));
        }

        return $next($request);
    }
}
