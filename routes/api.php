<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerArchiveController;
use App\Http\Controllers\Api\CustomerBlockController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DriverCheckController;
use App\Http\Controllers\Api\FleetDocumentController;
use App\Http\Controllers\Api\RentalCancelController;
use App\Http\Controllers\Api\RentalController;
use App\Http\Controllers\Api\RentalExtensionController;
use App\Http\Controllers\Api\RentalHandoverController;
use App\Http\Controllers\Api\RentalPaymentController;
use App\Http\Controllers\Api\RentalReturnController;
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

    // Files (encrypted, private): customers, rentals, cars, car documents, damage
    Route::get('/attachments', [AttachmentController::class, 'index']);
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachment}/file', [AttachmentController::class, 'file']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

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

    // Rentals
    Route::post('/rentals/quote', [RentalController::class, 'quote']);
    Route::get('/rentals', [RentalController::class, 'index']);
    Route::post('/rentals', [RentalController::class, 'store']);
    Route::get('/rentals/{rental}', [RentalController::class, 'show']);
    Route::post('/rentals/{rental}/cancel', RentalCancelController::class);
    Route::post('/rentals/{rental}/extend', RentalExtensionController::class);
    Route::post('/rentals/{rental}/payments', [RentalPaymentController::class, 'store']);
    Route::get('/rentals/{rental}/handover', [RentalHandoverController::class, 'show']);
    Route::post('/rentals/{rental}/handover', [RentalHandoverController::class, 'store']);
    Route::get('/rentals/{rental}/return', [RentalReturnController::class, 'show']);
    Route::post('/rentals/{rental}/return', [RentalReturnController::class, 'store']);
});
