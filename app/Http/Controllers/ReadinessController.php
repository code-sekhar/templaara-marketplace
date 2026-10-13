<?php

namespace App\Http\Controllers;

use App\Services\Operations\Readiness;
use Illuminate\Http\JsonResponse;

final class ReadinessController
{
    public function __invoke(Readiness $readiness): JsonResponse
    {
        $ready = $readiness->available();

        return response()->json(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
