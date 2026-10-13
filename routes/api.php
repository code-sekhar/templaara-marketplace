<?php

use App\Http\Controllers\Api\LicenseActivationController;
use Illuminate\Support\Facades\Route;

Route::post('v1/licenses/{operation}', LicenseActivationController::class)->whereIn('operation', ['activate', 'validate', 'deactivate'])->middleware(['throttle:license-api'])->name('licenses.api');
