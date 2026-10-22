<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AgencySettingsController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerArchiveController;
use App\Http\Controllers\Api\CustomerBlockController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DriverCheckController;
use App\Http\Controllers\Api\FleetDocumentController;
use App\Http\Controllers\Api\MaintenanceJobController;
use App\Http\Controllers\Api\MaintenancePlanController;
use App\Http\Controllers\Api\RentalCancelController;
use App\Http\Controllers\Api\RentalController;
use App\Http\Controllers\Api\RentalExtensionController;
use App\Http\Controllers\Api\RentalHandoverController;
use App\Http\Controllers\Api\RentalOverviewController;
use App\Http\Controllers\Api\RentalPaymentController;
use App\Http\Controllers\Api\RentalReturnController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\VehicleArchiveController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleDamageController;
use App\Http\Controllers\Api\VehicleDamageRepairController;
use App\Http\Controllers\Api\VehicleDocumentController;
use App\Http\Controllers\Api\VehicleStatusController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');
Route::post('/login/two-factor', [AuthController::class, 'twoFactor'])
    ->middleware('throttle:two-factor');

// Logged in, account still active (a deactivated person is thrown out at the next request),
// and own password chosen (a temporary password only allows /me, /logout and changing it)
Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsurePasswordIsChanged::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // My account (everyone)
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1');
    Route::put('/account/profile', [AccountController::class, 'profile']);
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/account/two-factor', [TwoFactorController::class, 'store']);
        Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::delete('/account/two-factor', [TwoFactorController::class, 'destroy']);
    });

    // Settings: the user's own agency (read: everyone, edit: owner)
    Route::get('/settings/agency', [AgencySettingsController::class, 'show']);
    Route::put('/settings/agency', [AgencySettingsController::class, 'update']);

    // Settings: staff accounts (owner only)
    Route::get('/settings/users', [StaffController::class, 'index']);
    Route::post('/settings/users', [StaffController::class, 'store']);
    Route::put('/settings/users/{id}', [StaffController::class, 'update'])->whereNumber('id');
    Route::post('/settings/users/{id}/deactivate', [StaffController::class, 'deactivate'])->whereNumber('id');
    Route::post('/settings/users/{id}/reactivate', [StaffController::class, 'reactivate'])->whereNumber('id');
    Route::post('/settings/users/{id}/reset-password', [StaffController::class, 'resetPassword'])->whereNumber('id');
    Route::post('/settings/users/{id}/reset-two-factor', [StaffController::class, 'resetTwoFactor'])->whereNumber('id');

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
    Route::get('/rentals/overview', RentalOverviewController::class);
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

    // Maintenance (garage work)
    Route::get('/maintenance/upcoming', [MaintenancePlanController::class, 'upcoming']);
    Route::get('/vehicles/{vehicle}/maintenance-plans', [MaintenancePlanController::class, 'index']);
    Route::post('/vehicles/{vehicle}/maintenance-plans', [MaintenancePlanController::class, 'store']);
    Route::put('/maintenance-plans/{plan}', [MaintenancePlanController::class, 'update']);
    Route::delete('/maintenance-plans/{plan}', [MaintenancePlanController::class, 'destroy']);
    Route::get('/maintenance', [MaintenanceJobController::class, 'index']);
    Route::post('/maintenance', [MaintenanceJobController::class, 'store']);
    Route::get('/maintenance/{job}', [MaintenanceJobController::class, 'show']);
    Route::put('/maintenance/{job}', [MaintenanceJobController::class, 'update']);
    Route::delete('/maintenance/{job}', [MaintenanceJobController::class, 'destroy']);
    Route::post('/maintenance/{job}/start', [MaintenanceJobController::class, 'start']);
    Route::post('/maintenance/{job}/finish', [MaintenanceJobController::class, 'finish']);
    Route::post('/maintenance/{job}/cancel', [MaintenanceJobController::class, 'cancel']);

    // Money reports (owner only)
    Route::get('/reports/maintenance-costs', [ReportController::class, 'maintenanceCosts']);
    Route::get('/reports/damages', [ReportController::class, 'damages']);
});
