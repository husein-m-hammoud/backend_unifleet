<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\GeofenceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

// ─── Public ──────────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// Unauthenticated health check for an external uptime monitor. Terse by design;
// pass OPS_HEALTH_TOKEN (?token= or X-Health-Token) for the full report.
// Returns 503 when a check fails so monitors alert on it.
Route::get('health', [DiagnosticsController::class, 'health']);

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
        Route::get('fuel',        [VehicleController::class, 'fuelOverview'])->middleware('page:fuel'); // fleet fuel overview (page-gated)
        Route::get('device-conflicts', [VehicleController::class, 'deviceConflicts']); // duplicate tracker detection
        Route::get('{id}',        [VehicleController::class, 'show']);
        Route::get('{id}/positions', [VehicleController::class, 'positions']); // path history
        Route::get('{id}/trips',  [VehicleController::class, 'trips']);
        Route::get('{id}/trips/{tripId}/path', [VehicleController::class, 'tripPath']); // full GPS trace
        Route::get('{id}/fuel',   [VehicleController::class, 'fuel']);
        Route::get('{id}/zones',  [ZoneController::class, 'vehicleZones']);   // assigned zones
        Route::put('{id}/zones',  [ZoneController::class, 'setVehicleZones']); // assign (admin)
        Route::get('{id}/zone-log', [ZoneController::class, 'vehicleZoneLog']); // assignment history
        Route::delete('{id}',     [VehicleController::class, 'destroy'])->whereNumber('id'); // manager-only, no-signal only
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

    // Canonical zones & sites management (name-based). Specific paths before {id}.
    Route::get('zones/sites',        [ZoneController::class, 'sites']);
    Route::post('zones/sites',       [ZoneController::class, 'storeSite']);
    Route::post('zones/import',      [ZoneController::class, 'import']);
    Route::put('zones/sites/{siteId}', [ZoneController::class, 'updateSite'])->whereNumber('siteId');
    Route::get('zones/{id}/contacts', [ContactController::class, 'zoneContacts'])->whereNumber('id');
    Route::get('zones',              [ZoneController::class, 'index']);
    Route::post('zones',             [ZoneController::class, 'storeZone']);
    Route::put('zones/{id}',         [ZoneController::class, 'update'])->whereNumber('id');

    // Zone responsibles / contacts (admin only). Notify these about zone issues.
    Route::middleware('manager')->group(function () {
        Route::get('contacts',        [ContactController::class, 'index']);
        Route::post('contacts',       [ContactController::class, 'store']);
        Route::put('contacts/{id}',   [ContactController::class, 'update'])->whereNumber('id');
        Route::delete('contacts/{id}', [ContactController::class, 'destroy'])->whereNumber('id');
    });

    // User management (manager-only — enforced by middleware AND controller).
    Route::middleware('manager')->group(function () {
        Route::get('users',        [UserController::class, 'index']);
        Route::post('users',       [UserController::class, 'store']);
        Route::put('users/{id}',   [UserController::class, 'update'])->whereNumber('id');
        Route::post('users/{id}/password', [UserController::class, 'changePassword'])->whereNumber('id');
        Route::delete('users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
    });

    // Alerts
    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/resolve-all', [AlertController::class, 'resolveAll']);
    Route::post('alerts/{id}/resolve', [AlertController::class, 'resolve'])->whereNumber('id');

    // AI Insights (deterministic analytics — no external LLM, no recurring cost)
    Route::get('analytics/insights', [AnalyticsController::class, 'insights'])->middleware('page:ai');

    // Reports (page-gated: scoped users need the 'reports' grant)
    Route::middleware('page:reports')->group(function () {
        Route::get('reports',       [ReportController::class, 'index']);
        Route::post('reports',      [ReportController::class, 'store']);
        Route::get('reports/{id}',  [ReportController::class, 'show'])->whereNumber('id');
    });

    // Diagnostics / System page — OWNER ONLY. Exposes log contents, config
    // state and provider failure history, so it is gated by role, not by the
    // absence of a nav link.
    Route::middleware('owner')->prefix('admin/diagnostics')->group(function () {
        Route::get('/',           [DiagnosticsController::class, 'index']);
        Route::get('logs',        [DiagnosticsController::class, 'logs']);
        Route::get('failures',    [DiagnosticsController::class, 'failures']);
        Route::post('test-alert', [DiagnosticsController::class, 'testAlert']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/',                    [SettingController::class, 'index']);
        Route::get('changelog',            [SettingController::class, 'changelog']);
        // Per-type idle thresholds — before the {key} catch-all so they aren't
        // swallowed by PUT settings/{key}.
        Route::get('idle-thresholds',      [SettingController::class, 'idleThresholds']);
        Route::get('speed-limits',         [SettingController::class, 'speedLimits']);
        Route::get('{key}/changelog',      [SettingController::class, 'changelog']);
        // Writes are manager-only. Reads stay open — thresholds are read app-wide
        // (dashboard, fuel, alerts, map) to hydrate client-side status logic.
        Route::middleware('manager')->group(function () {
            Route::put('idle-thresholds',  [SettingController::class, 'updateIdleThresholds']);
            Route::put('speed-limits',     [SettingController::class, 'updateSpeedLimits']);
            Route::put('{key}',            [SettingController::class, 'update']);
        });
    });
});
