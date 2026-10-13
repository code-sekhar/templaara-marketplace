<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResponseSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate our own identifier rather than trusting a caller's log context.
        $request->attributes->set('request_id', (string) Str::uuid());
        Log::withContext(['request_id' => $request->attributes->get('request_id')]);
        if (app()->isProduction()) {
            $request->attributes->set('csp_nonce', base64_encode(random_bytes(24)));
            Vite::useCspNonce($request->attributes->get('csp_nonce'));
        }
        try {
            return self::apply($request, $next($request));
        } finally {
            Log::withoutContext(['request_id']);
        }
    }

    public static function apply(Request $request, Response $response): Response
    {
        if ($request->user() && $request->routeIs('home', 'marketplace.*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id') ?: (string) Str::uuid());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (app()->isProduction()) {
            if ($request->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
            $nonce = $request->attributes->get('csp_nonce') ?: base64_encode(random_bytes(24));
            $origins = array_filter(config('production.image_origins', []), fn ($origin) => preg_match('#^https://[a-z0-9.-]+(?::[0-9]+)?$#iD', $origin) === 1);
            // Hosted Stripe is a navigation. Razorpay scripts/frames need exact origins.
            // Vue/provider style attributes need a narrowly scoped style-src exception.
            $policy = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; script-src 'self' 'nonce-{$nonce}' https://checkout.razorpay.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://cdn.razorpay.com ".implode(' ', $origins)."; font-src 'self'; connect-src 'self' https://api.razorpay.com https://checkout.razorpay.com; frame-src https://api.razorpay.com https://checkout.razorpay.com; form-action 'self'; upgrade-insecure-requests";
            $response->headers->set(config('production.csp_mode') === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $policy);
        }

        return $response;
    }
}
