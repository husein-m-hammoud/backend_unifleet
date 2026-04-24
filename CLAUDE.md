# UNIFLEET — AI Fleet Intelligence Platform
## Project Memory for Claude Code (VS Code)

> Auto-loaded by Claude Code. Contains all project context, decisions, scope, and API reference.
> Company: Cedars Technology | Client: UNIMAC | Last updated: April 2026

---

## 1. What We're Building

The **UNIFLEET** platform — an AI-powered fleet analytics system that:

- Pulls real-time data from the **DSCO (Desco) tracking API** (GPS, speed, fuel per vehicle)
- Detects anomalies: geofence violations, excessive idling, unusual patterns
- Sends **SMS + voice call alerts** via Twilio to drivers and managers
- Generates **AI-powered reports** (daily, weekly, monthly) using Claude API
- Builds **per-driver behavioral profiles** and flags deviations from personal baseline
- Provides an **operational heatmap** and **telematic analytics dashboard**
- Includes a **mobile logistics application** (included in agreed price)

---

## 2. Scope of Work (Contract Sections)

### 3.1 Data Integration
- Integration with DSCO APIs
- Extraction of fleet operational data and geofence information
- Data normalization and processing for analytics

### 3.2 AI Analytics & Anomaly Detection
- Detection of operational anomalies
- Detection of geofence violations
- Detection of excessive vehicle idling
- Detection of unusual operational patterns
- AI generates alerts, insights, and recommendations based on detected patterns

### 3.3 Automated Intelligence Reporting
- Daily operational report
- Weekly fleet performance report
- Monthly executive intelligence report

### 3.4 Alerting & Notification System
- Twilio SMS alerts
- Twilio automated voice call alerts
- Alerts: vehicles outside authorized geofenced areas
- Alerts: vehicles idling beyond defined thresholds
- Alerts: AI-detected anomalies

### 3.5 Operational Heatmap Visualization
- Heatmaps generated using DSCO geofence data
- Operational density visualization
- Vehicle movement pattern visualization

### 3.6 Telematic Analytics Dashboard
- Weekly operational trends
- Fleet performance indicators
- Operational risk summaries
- AI-generated insights

### 3.7 Mobile Application
- Logistics mobile app (included in project price, no extra cost)

### Timeline
- Estimated implementation: **5 months** from agreement signing

---

## 3. Tech Stack

| Layer | Technology | Notes |
|---|---|---|
| Backend | Laravel (PHP) | Team already uses it |
| Frontend | Next.js / React | Existing demo project |
| Database | PostgreSQL + TimescaleDB | Relational + time-series in one |
| Cloud | Alibaba Cloud RDS | Being provisioned |
| AI Reports | Claude API (Anthropic) | `claude-sonnet-4-20250514` |
| Alerts | Twilio | SMS + voice calls (confirmed) |
| Background Jobs | Laravel Queues | Poll DSCO API, process data |
| Cache (future) | Redis | Live map updates |
| Mobile | TBD | Logistics app (iOS + Android) |

---

## 4. Architecture

```
DSCO API  ──┐
DB / Logs  ──┼──► Laravel Backend (AI Engine + Queue Workers)
Driver data ─┘         │
                        ├──► Anomaly Detection (Isolation Forest / LSTM)
                        ├──► Alert Engine → Twilio (SMS + Voice)
                        ├──► AI Reports → Claude API
                        └──► Heatmap & Pattern Engine
                                    │
                        ┌───────────┘
                        ├──► Next.js Dashboard (manager view)
                        ├──► Mobile App (logistics)
                        ├──► Alert Log (stored for retraining)
                        └──► Model retraining loop ↺
```

---

## 5. Build Phases

### Phase 1 — Data Pipeline
- Connect to DSCO API (auth token in header)
- Poll: vehicle positions, states, events, fuel, trips
- Store in PostgreSQL + TimescaleDB
- Design and run Laravel migrations

### Phase 2 — Alert System
- Geofencing logic (vehicle exits defined polygon → alert)
- Idling threshold detection
- Speed limit violation detection
- Twilio SMS + voice call via Laravel Notifications

### Phase 3 — AI Reports (Claude API)
- Daily / weekly / monthly report generation
- Input: aggregated telemetry from DB
- Output: readable natural language summaries
- Example: "Vehicle ABC drove 340 km, exited geofence zone 2x, fuel efficiency down 12% vs last week."

### Phase 4 — Per-Driver Anomaly Detection
- Build behavioral baseline per driver
- Flag deviations from personal baseline (not global threshold)
- Start with: Isolation Forest or LSTM (time-series)
- Model improves as trip data accumulates

### Phase 5 — Dashboard + Heatmap + Mobile
- Next.js dashboard: live map, driver scorecards, alert logs, downloadable reports
- Operational heatmap using geofence polygon data
- Mobile logistics app

---

## 6. Database Design (PostgreSQL + TimescaleDB)

All migrations: `database/migrations/2026_04_24_000001_*.php` through `*_000014_*.php`

### Reference Tables (upserted on every poll, soft-disabled when missing from API)
```sql
sites           (id, dsco_site_id, name, status[active|disabled], dsco_last_seen_at)
categories      (id, dsco_category_id, dsco_site_id, dsco_parent_id, name, status, dsco_last_seen_at)
geofences       (id, dsco_geofence_id, dsco_uuid, name, code, dsco_company_id, description, points_json, status, dsco_last_seen_at)
drivers         (id, dsco_driver_id, dsco_uuid, name, mobile, gender, email, license_status, residency_status, access_key, badge_number_1, badge_number_2, status[active|disabled], dsco_last_seen_at, dsco_created_at, dsco_updated_at)
vehicles        (id, dsco_vehicle_id, dsco_uuid, plate_no, type, dsco_company_id, dsco_company_name, dsco_site_id, dsco_category_id, current_driver_id→drivers, vin, vehicle_model, vehicle_make, dsco_device_id, device_sim, device_imei, device_type, device_serial, device_installation_date, status[active|disabled], dsco_last_seen_at)
```

### Assignment History
```sql
vehicle_driver_assignments  (id, vehicle_id→vehicles, driver_id→drivers, started_at, ended_at[null=current])
-- A new row is inserted every time the poller detects a driver change on a vehicle
```

### Time-Series Tables (append-only, hypertables on production TimescaleDB)
```sql
vehicle_positions  (time, vehicle_id, driver_id, lat, lon, alt, speed, heading, ignition_status, odometer, dsco_event_id, event_name, event_code)
vehicle_events     (time, vehicle_id, driver_id, event_code, event_name, speed, lat, lon, alt, reason, arguments jsonb)
vehicle_fuel_logs  (time, vehicle_id, fuel_liters, fuel_pct, total_fuel_used, total_idle_fuel_used, fuel_consumption_per_100km, range_km, fuel_low_indicator)
vehicle_speed_logs (time, vehicle_id, speed, max_speed, avg_speed, raw jsonb)
vehicle_weight_logs(time, vehicle_id, weight, raw jsonb)
```

### Transactional Tables
```sql
trips    (id, dsco_trip_id, vehicle_id, driver_id, start_time, end_time, distance, avg_speed, max_speed, idle_time, driving_time, completed, start_lat/lon/alt, end_lat/lon/alt)
alerts   (id, vehicle_id, driver_id, type, triggered_at, resolved_at, twilio_status, meta jsonb)
reports  (id, type[daily|weekly|monthly], generated_at, content, vehicle_id, driver_id)
```

### Soft-disable logic
- Every poll: entities returned by DSCO → `status = active`, `dsco_last_seen_at = now`
- Entities **absent** from response → `status = disabled` (never deleted)
- If an entity reappears in a future poll → `status = active` again automatically
- This handles user login/account changes gracefully

---

## 7. DSCO API Reference

**Base:** `https://<dsco-host>/api/v2/`
**Auth:** OAuth2 password grant — token fetched by `DscoAuthService`, cached 270s, auto-refreshed on 401.

### Confirmed Working Endpoints (tested April 2026)

#### Reference / Utility
| Method | URL | Body | Description |
|---|---|---|---|
| POST | `api/v2/site/list` | `{}` | All sites |
| POST | `api/v2/category/list` | `{}` | All categories (tree with parentId) |
| POST | `api/v2/geofence/list` | `{}` | All geofences with polygon points |

#### Vehicles
| Method | URL | Body | Description |
|---|---|---|---|
| POST | `api/v2/vehicle/list-info` | `{}` | Full list — device, driver, site, category embedded |
| POST | `api/v2/vehicle/last-state` | `{"live":true,"vehicles":[id,...],"startDate":null,"endDate":null}` | Live GPS + ignition + odometer per vehicle |
| POST | `api/v2/vehicle/get-fuel-data` | `{"vehicleId":X,"startDate":ts,"endDate":ts}` | Fuel snapshot |
| POST | `api/v2/vehicle/get-speed-data` | `{"vehicleId":X,"startDate":ts,"endDate":ts}` | Speed data |
| POST | `api/v2/vehicle/get-weight-data` | `{"vehicleId":X,"startDate":ts,"endDate":ts}` | Weight/load data |
| POST | `api/v2/vehicle/trips` | `{"vehicleId":X,"startDate":ts,"endDate":ts}` | Trips for one vehicle |

#### Drivers
| Method | URL | Body | Description |
|---|---|---|---|
| POST | `api/v2/driver/list-info` | `{}` | Full driver list with uuid, access key, license status |
| POST | `api/v2/driver/trips` | `{"driverId":X,"startDate":ts,"endDate":ts}` | Trips for one driver |

#### Other
| Method | URL | Description |
|---|---|---|
| POST | `api/v2/trip/path` | GPS path points for a trip (`{"tripId":X}`) |
| POST | `api/v2/event/list` | Vehicle events (speed, geofence, etc.) |
| GET | `api/v2/maintenance/tasks` | Maintenance task list |

---

## 8. DSCO API Data Structures (JSON)

### VehiclePosition
```json
{
  "id": 5,
  "plateNo": "123 abc",
  "speed": 93.7,
  "date": 1509946353.033,
  "heading": 5,
  "status": "moving",
  "event": { "id": 2, "code": "ignition_on", "name": "Ignition On" },
  "position": { "lat": 25.25698, "lon": 49.56874, "alt": 0 }
}
```

### VehicleState (extends VehicleEvent)
```json
{
  "id": 5,
  "plateNo": "123 abc",
  "speed": 93.7,
  "date": 1509946353.033,
  "event": { "id": 2, "code": "ignition_on", "name": "Ignition On" },
  "position": { "lat": 25.25698, "lon": 49.56874, "alt": 0 },
  "mileage": { "today": 315.5, "lastTwoHours": 60.5 },
  "driver": { "id": 111, "name": "Ahmad Hamdan" }
}
```

### VehicleEvent
```json
{
  "id": 5,
  "plateNo": "123 abc",
  "reason": "External Power Disconnected",
  "date": 1509946353.033,
  "speed": 93.7,
  "status": "moving",
  "heading": 0,
  "position": { "lat": 25.25698, "lon": 49.56874, "alt": 0 },
  "type": { "key": "1212", "value": "External Power Disconnected" },
  "driver": { "id": "105", "name": "Ahmad Hamdan" },
  "vehicle": { "id": "105", "name": "ABC-1234" },
  "event": { "id": 2, "code": "ignition_on", "name": "Ignition On" },
  "arguments": [{ "name": "Speed", "type": "double", "value": "115.5" }]
}
```

### VehicleFuel
```json
{
  "totalFuelUsed": 114.23,
  "fuelConsumptionPerHunderedKm": 123.12,
  "fuelLiters": 40.0,
  "fuelPercentage": 12.12,
  "totalIdleFuelUsed": 123.12,
  "rangeKm": 345.12,
  "fuelLowIndicatorOn": true
}
```

### Trip
```json
{
  "id": 30470386,
  "vehicle": { "id": 121, "name": "123 abc" },
  "startTime": 1508416750,
  "endTime": 1508420216,
  "avgSpeed": 31.09,
  "maxSpeed": 82,
  "distance": 25804.99,
  "idleTime": 60,
  "drivingTime": 3406,
  "completed": true,
  "startLocation": { "alt": 582.64, "lat": 24.6553085, "lon": 46.7147515 },
  "endLocation": { "alt": 591.98, "lat": 24.6175093, "lon": 46.7041756 }
}
```

### Geofence
```json
{
  "id": 5,
  "name": "Some Zone",
  "description": "Authorized area",
  "points": [
    { "lat": 25.25698, "lon": 49.56874, "alt": 0 },
    { "lat": 25.25700, "lon": 49.56900, "alt": 0 }
  ]
}
```

### Position
```json
{ "lat": 25.25698, "lon": 49.56874, "alt": 1305.56 }
```

### PositionState
```json
{
  "lat": 25.25698, "lon": 49.56874, "alt": 1305.56,
  "speed": 65.8,
  "date": 1509946353.033,
  "event": { "id": "2", "code": "over_speed", "name": "Over Speed" }
}
```

### VehicleFilter (for bulk queries)
```json
{
  "siteId": 102,
  "categoryId": null,
  "geofenceId": 1013,
  "vehicleId": null,
  "status": "OK",
  "minSpeed": null,
  "maxSpeed": 75.5,
  "includeExtraFields": false
}
```

### VehicleCanbus
```json
{
  "odometer": 11412.23,
  "engineRPM": 123,
  "canSpeed": 27,
  "engineTemperature": 12
}
```

### AlarmNotificationConfig
```json
{
  "id": "1265",
  "name": "Aramco Project Over Speed",
  "siteId": 982,
  "geofenceId": 1982,
  "vehicleId": null,
  "alarmTypes": ["over_speed"]
}
```

### Common Event Codes
- `ignition_on` / `ignition_off`
- `over_speed`
- `geofence_exit` / `geofence_enter`
- `idle_start` / `idle_end`
- `external_power_disconnected`

### API Response Wrapper (all endpoints)
```json
{
  "code": 0,
  "time": 1509946353.033,
  "status": "success",
  "message": "operation completed successfully",
  "result": {}
}
```

---

## 9. Poller — `dsco:poll` Command

File: `app/Console/Commands/DscoPollCommand.php`

### How it works
1. **Interval guard** — checks `DSCO_POLL_INTERVAL_MINUTES` (.env, default 2) using a cache key. Exits early if interval hasn't elapsed. Use `--force` flag to bypass.
2. **Sync reference data** — sites → categories → geofences → drivers → vehicles (upsert + soft-disable missing)
3. **Track driver changes** — if a vehicle's driver changed since last poll, closes the old `vehicle_driver_assignments` row and opens a new one
4. **Live state** — calls `vehicle/last-state` in chunks of 50 → inserts one `vehicle_positions` row per vehicle
5. **Per-vehicle telemetry** — for each active vehicle: trips, fuel, speed, weight (all since `dsco_last_sync_timestamp` cache key, fallback to `DSCO_LOOKBACK_HOURS`)
6. **Updates cache** — stores `dsco_last_poll_ran_at` and `dsco_last_sync_timestamp` for next cycle

### Scheduler
Registered in `routes/console.php`:
```php
Schedule::command('dsco:poll')->everyMinute()->withoutOverlapping();
```
To activate: add to server crontab:
```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

### .env variables
```
DSCO_POLL_INTERVAL_MINUTES=2   # how often to actually poll (default 2)
DSCO_LOOKBACK_HOURS=24         # fallback window when no prior sync timestamp
```

### Models
- `Site`, `Category`, `Geofence` — reference data, `scopeActive()`
- `Driver` — soft-disable, `scopeActive()`, relations: vehicles, assignments, trips
- `Vehicle` — soft-disable, `scopeActive()`, relations: currentDriver, driverAssignments, positions, fuelLogs, speedLogs, weightLogs, trips, events, alerts
- `VehicleDriverAssignment` — history table, FK to vehicle + driver
- `VehiclePosition`, `VehicleEvent`, `VehicleFuelLog`, `VehicleSpeedLog`, `VehicleWeightLog` — time-series (no primary key, no timestamps, append-only)
- `Trip` — upserted by `dsco_trip_id`
- `Alert`, `Report` — created by alert engine and AI report generator (Phase 2/3)

---

## 10. Frontend API Layer

### Auth Flow
1. Frontend POSTs `{ username, password }` to `POST /api/auth/login`
2. Backend calls DSCO OAuth2 with those credentials
3. On success: creates/finds `User` by `dsco_username`, stores encrypted DSCO tokens in `dsco_user_tokens`, fetches accessible vehicle IDs from DSCO and stores in `dsco_accessible_vehicle_ids`, issues a Sanctum token
4. Returns `{ token, user }` — frontend stores the Sanctum token and sends it as `Authorization: Bearer <token>` on all subsequent requests
5. DSCO token refresh is transparent — `RefreshDscoToken` middleware runs before every protected request and silently refreshes if expiring within 60s

### API Endpoints
| Method | URL | Description |
|---|---|---|
| POST | `/api/auth/login` | Login with DSCO credentials, get Sanctum token |
| POST | `/api/auth/logout` | Revoke current Sanctum token |
| GET | `/api/auth/me` | Current user info |
| GET | `/api/dashboard` | Fleet summary (counts, today's stats, alert count) |
| GET | `/api/vehicles` | List user's vehicles (filterable by status, search) |
| GET | `/api/vehicles/live` | Latest position per vehicle (for Google Maps) |
| GET | `/api/vehicles/{id}` | Single vehicle detail |
| GET | `/api/vehicles/{id}/positions` | GPS history (from/to params) |
| GET | `/api/vehicles/{id}/trips` | Trip list (from/to params) |
| GET | `/api/vehicles/{id}/fuel` | Fuel log (from/to params) |
| GET | `/api/drivers` | Drivers linked to user's vehicles |
| GET | `/api/drivers/{id}` | Single driver detail |
| GET | `/api/drivers/{id}/trips` | Driver's trip history |
| GET | `/api/geofences` | All active geofences with polygon points |
| GET | `/api/alerts` | Alerts (filterable by from/to/type/vehicle_id) |

### Per-User Vehicle Filtering
- At login, backend calls `vehicle/list-info` with the user's DSCO token and stores the returned DSCO vehicle IDs in `users.dsco_accessible_vehicle_ids`
- All vehicle/driver/alert queries are scoped via `$user->vehicleQuery()` → `Vehicle::whereIn('dsco_vehicle_id', [...])`

### Key Files
- `app/Services/DscoUserAuthService.php` — per-user DSCO auth + token management
- `app/Models/DscoUserToken.php` — encrypted token storage
- `app/Http/Middleware/RefreshDscoToken.php` — silent DSCO token refresh
- `app/Http/Controllers/Api/` — all 6 controllers
- `config/cors.php` — CORS for localhost:8080/3000
- `.env` → `SANCTUM_STATEFUL_DOMAINS=localhost:8080,localhost:3000,127.0.0.1:8080`

---

## 11. Key Decisions

| Decision | Choice | Notes |
|---|---|---|
| Backend | Laravel / PHP | Team already uses it |
| Frontend | Next.js | Existing demo project |
| Database | PostgreSQL + TimescaleDB | Best for telemetry data |
| AI reports | Claude API (`claude-sonnet-4-20250514`) | Natural language summaries |
| Anomaly detection | Isolation Forest → LSTM later | Start simple, improve over time |
| Alert delivery | Twilio (SMS + Voice) | Confirmed in contract |
| Cloud | Alibaba Cloud | Being provisioned |
| Geofence format | Polygon (array of lat/lon points) | Per DSCO API structure |
| Driver profiles | Per-driver baseline, not global threshold | Core differentiator |

---

## 12. Pending / Open Items

### Done (April 2026)
- [x] Scaffold Laravel project + DscoApiService + DscoAuthService
- [x] All 14 database migrations (sites, categories, geofences, drivers, vehicles, vehicle_driver_assignments, vehicle_positions, vehicle_events, vehicle_fuel_logs, vehicle_speed_logs, vehicle_weight_logs, trips, alerts, reports)
- [x] All Eloquent models with relations and scopeActive()
- [x] `dsco:poll` artisan command — full sync pipeline with interval guard + soft-disable
- [x] Scheduler registered in routes/console.php
- [x] Laravel Sanctum installed (`composer require laravel/sanctum`)
- [x] Auth migrations: `add_dsco_fields_to_users_table` (dsco_username, dsco_accessible_vehicle_ids jsonb, password nullable) + `create_dsco_user_tokens_table` (per-user encrypted DSCO tokens)
- [x] `DscoUserToken` model — encrypted casts for access_token + refresh_token, `expiresWithin()` / `isExpired()` helpers
- [x] `User` model updated — HasApiTokens, dsco_accessible_vehicle_ids cast as array, `vehicleQuery()` method
- [x] `DscoUserAuthService` — login (DSCO OAuth2 → Sanctum token), `getValidAccessToken()` (refresh if expiring within 60s), `fetchAccessibleVehicleIds()`
- [x] 6 API controllers: AuthController, VehicleController, DriverController, GeofenceController, AlertController, DashboardController
- [x] `RefreshDscoToken` middleware — silently refreshes DSCO token on every authenticated request
- [x] `routes/api.php` — 15 routes (public: login; protected: logout, me, dashboard, vehicles, drivers, geofences, alerts)
- [x] `config/cors.php` — allows localhost:8080 + localhost:3000, supports_credentials: true
- [x] `bootstrap/app.php` — API routes, HandleCors middleware, statefulApi()
- [x] Fixed empty JSON body bug: PHP `[]` → DSCO server error; fix: `new \stdClass()` for empty bodies so it encodes as `{}`
- [x] Smoke tested: login with bad DSCO credentials returns 401; `php artisan route:list --path=api` shows all 15 routes

### Still Needed
- [ ] Get DSCO API base URL + auth token from client → fill .env
- [ ] Run `php artisan migrate` on Alibaba Cloud RDS
- [ ] Run `SELECT create_hypertable(...)` for time-series tables on TimescaleDB
- [ ] Add crontab entry on server: `* * * * * php artisan schedule:run`
- [ ] Confirm Twilio account + Lebanon SMS support
- [ ] Define idling threshold (minutes) with client → Phase 2 alert engine
- [ ] Define geofence alert rules (which zones are "authorized") → Phase 2
- [ ] Wire React/Vite frontend (`../unifleet`) to these API endpoints — auth context, API client, Google Maps live tracking map → Phase 5
- [ ] Add production frontend URL to `config/cors.php` allowed_origins when deploying
- [ ] Plan mobile app tech (React Native? Flutter?) → Phase 5
- [ ] Set up Claude API key in Laravel `.env` → Phase 3 reports

---

*Project: UNIFLEET | Client: UNIMAC | Dev: Cedars Technology*
*Memory source: Claude.ai project (fleet-ai-project-memory.md + DSCO API PDF + contract scope)*
