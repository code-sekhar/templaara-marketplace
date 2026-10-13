<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Entitlements\LicenseActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LicenseActivationController extends Controller
{
    public function __invoke(Request $request, LicenseActivationService $service): JsonResponse
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            return response()->json(['valid' => false, 'code' => 'HTTPS_REQUIRED'], 426)->header('Cache-Control', 'no-store');
        }
        if ($request->query->count() || ! $request->isJson()) {
            return response()->json(['valid' => false, 'code' => 'INVALID_REQUEST'], 422)->header('Cache-Control', 'no-store');
        }
        try {
            $data = $request->validate(['license_key' => ['required', 'string', 'max:100', 'regex:/^TMP(?:-?[A-Fa-f0-9]{5}){8}$/i'], 'product' => ['required', 'uuid'], 'installation_id' => ['required', 'uuid'], 'installation_label' => ['nullable', 'string', 'max:100'], 'domain' => ['nullable', 'string', 'max:2048']]);
            $result = $service->execute($request->route('operation'), $data);
        } catch (ValidationException $exception) {
            return response()->json(['valid' => false, 'code' => 'INVALID_REQUEST', 'errors' => $exception->errors()], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json($result, $result['valid'] ? 200 : ($result['code'] === 'ACTIVATION_LIMIT' ? 409 : 422))->header('Cache-Control', 'no-store');
    }
}
