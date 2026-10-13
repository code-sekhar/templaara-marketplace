<?php

namespace App\Http\Middleware;

use App\Services\Commerce\DevelopmentGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevelopmentPaymentsOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(DevelopmentGateway::class)->available(), 404);

        return $next($request);
    }
}
