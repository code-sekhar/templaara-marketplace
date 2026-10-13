<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ProductionSurfaceLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            $surface = self::surface($request);
            if ($surface) {
                [$name, $maximum, $seconds] = $surface;
                $identity = hash('sha256', ($request->user()->id ?? 'guest').'|'.$request->ip());
                $key = 'production:'.$name.':'.$identity;
                if (RateLimiter::tooManyAttempts($key, $maximum)) {
                    return response()->json(['message' => 'Too many requests. Please try again later.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key))->header('Cache-Control', 'no-store');
                }
                RateLimiter::hit($key, $seconds);
            }
        }

        return $next($request);
    }

    /** @return array{string,int,int}|null */
    public static function surface(Request $request): ?array
    {
        if ($request->isMethod('POST') && $request->is('register')) {
            return ['registration', 10, 3600];
        }
        if ($request->isMethod('POST') && $request->is('forgot-password', 'reset-password')) {
            return ['password-reset', 5, 60];
        }
        if ($request->isMethod('POST') && $request->is('checkout', 'account/orders/*/retry')) {
            return ['checkout', 10, 60];
        }
        if ($request->is('admin/reports/*/export')) {
            return ['export', 10, 60];
        }
        if (! $request->isMethodSafe() && $request->is('admin/*')) {
            return ['admin-mutations', 60, 60];
        }
        if (! $request->isMethodSafe() && $request->is('author/onboarding*')) {
            return ['author-application', 10, 3600];
        }

        return null;
    }
}
