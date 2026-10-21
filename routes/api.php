<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerArchiveController;
use App\Http\Controllers\Api\CustomerBlockController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DriverCheckController;
use App\Http\Controllers\Api\FleetDocumentController;
use App\Http\Controllers\Api\VehicleArchiveController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleDamageController;
use App\Http\Controllers\Api\VehicleDamageRepairController;
use App\Http\Controllers\Api\VehicleDocumentController;
use App\Http\Controllers\Api\VehicleStatusController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Footprints (owner only)
    Route::get('/activity', ActivityLogController::class);

    // Vehicles (no delete: cars are archived, see CLAUDE.md)
    Route::apiResource('vehicles', VehicleController::class)->except('destroy');
    Route::patch('/vehicles/{vehicle}/status', [VehicleStatusController::class, 'update']);
    Route::post('/vehicles/{vehicle}/archive', [VehicleArchiveController::class, 'store']);
    Route::delete('/vehicles/{vehicle}/archive', [VehicleArchiveController::class, 'destroy']);

    // Vehicle documents (insurance, inspection, vignette, registration)
    Route::get('/vehicle-documents', FleetDocumentController::class);
    Route::get('/vehicles/{vehicle}/documents', [VehicleDocumentController::class, 'index']);
    Route::post('/vehicles/{vehicle}/documents', [VehicleDocumentController::class, 'store']);
    Route::put('/vehicle-documents/{document}', [VehicleDocumentController::class, 'update']);
    Route::delete('/vehicle-documents/{document}', [VehicleDocumentController::class, 'destroy']);

    // Vehicle damage record (open on the car / repaired history)
    Route::get('/vehicles/{vehicle}/damages', [VehicleDamageController::class, 'index']);
    Route::post('/vehicles/{vehicle}/damages', [VehicleDamageController::class, 'store']);
    Route::put('/vehicle-damages/{damage}', [VehicleDamageController::class, 'update']);
    Route::delete('/vehicle-damages/{damage}', [VehicleDamageController::class, 'destroy']);
    Route::post('/vehicle-damages/{damage}/repair', [VehicleDamageRepairController::class, 'store']);
    Route::delete('/vehicle-damages/{damage}/repair', [VehicleDamageRepairController::class, 'destroy']);

    // Customers (no delete: archived, see CLAUDE.md)
    Route::apiResource('customers', CustomerController::class)->except('destroy');
    Route::post('/customers/{customer}/block', [CustomerBlockController::class, 'store']);
    Route::delete('/customers/{customer}/block', [CustomerBlockController::class, 'destroy']);
    Route::post('/customers/{customer}/archive', [CustomerArchiveController::class, 'store']);
    Route::delete('/customers/{customer}/archive', [CustomerArchiveController::class, 'destroy']);
    Route::get('/customers/{customer}/driver-check', DriverCheckController::class);
});
