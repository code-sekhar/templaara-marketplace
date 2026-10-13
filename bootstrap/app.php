<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProductionSurfaceLimits;
use App\Http\Middleware\ResponseSecurity;
use App\Http\Middleware\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ResponseSecurity::class);
        $middleware->replace(TrustProxies::class, TrustedProxies::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->preventRequestForgery(except: ['payments/development/webhook', 'webhooks/stripe', 'webhooks/razorpay']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            ProductionSurfaceLimits::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error): ?bool {
            if (app()->isProduction()) {
                Log::error('Application operation failed', ['category' => get_class($error), 'request_id' => app()->bound('request') ? request()->attributes->get('request_id') : null]);

                return false;
            }

            return null;
        });
        $exceptions->respond(function (Response $response): Response {
            if (app()->isProduction() && $response->getStatusCode() >= 500) {
                $status = $response->getStatusCode();
                $response = request()->expectsJson()
                    ? response()->json(['message' => $status === 503 ? 'Service temporarily unavailable.' : 'Unable to complete this request.'], $status)
                    : response()->view('errors.minimal', ['status' => $status, 'title' => $status === 503 ? 'Service temporarily unavailable' : 'Unable to complete this request', 'description' => 'Please try again later.'], $status);
                $response->headers->set('Cache-Control', 'no-store');
            }

            return ResponseSecurity::apply(request(), $response);
        });
        $exceptions->dontFlash(['razorpay_signature', 'razorpay_payment_id', 'razorpay_order_id', 'account_holder_name', 'account_number', 'iban', 'routing_code', 'swift_bic', 'details_encrypted', 'method_snapshot_encrypted']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
