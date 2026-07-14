<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\GeofenceController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

// ─── Public ──────────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::get('providers', [AuthController::class, 'providers']);
    Route::post('login', [AuthController::class, 'login']);
});

// ─── Authenticated ────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', \App\Http\Middleware\RefreshSafeeToken::class])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',     [AuthController::class, 'me']);
    });

    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index']);

    // Vehicles
    Route::prefix('vehicles')->group(function () {
        Route::get('/',           [VehicleController::class, 'index']);
        Route::get('live',        [VehicleController::class, 'live']);       // for Google Maps
        Route::get('fuel',        [VehicleController::class, 'fuelOverview']); // fleet fuel overview
        Route::get('{id}',        [VehicleController::class, 'show']);
        Route::get('{id}/positions', [VehicleController::class, 'positions']); // path history
        Route::get('{id}/trips',  [VehicleController::class, 'trips']);
        Route::get('{id}/trips/{tripId}/path', [VehicleController::class, 'tripPath']); // full GPS trace
        Route::get('{id}/fuel',   [VehicleController::class, 'fuel']);
    });

    // Drivers
    Route::prefix('drivers')->group(function () {
        Route::get('/',          [DriverController::class, 'index']);
        Route::get('{id}',       [DriverController::class, 'show']);
        Route::get('{id}/trips', [DriverController::class, 'trips']);
    });

    // Sites (for map filter)
    Route::get('sites', [SiteController::class, 'index']);

    // Geofences (for map overlays)
    Route::get('geofences', [GeofenceController::class, 'index']);

    // Alerts
    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/{id}/resolve', [AlertController::class, 'resolve']);

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/',                    [SettingController::class, 'index']);
        Route::get('changelog',            [SettingController::class, 'changelog']);
        Route::get('{key}/changelog',      [SettingController::class, 'changelog']);
        Route::put('{key}',                [SettingController::class, 'update']);
    });
});
