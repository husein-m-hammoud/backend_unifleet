# UNIFLEET — AI Fleet Intelligence Platform
## Project Memory for Claude Code (VS Code)

> Auto-loaded by Claude Code. Contains all project context, decisions, scope, and API reference.
> Company: Cedars Technology | Client: UNIMAC | Last updated: July 2026 (session 7 — DSCO→Safee migration)

> **⚠️ Provider migration (July 2026):** DSCO has been **retired**. The platform now
> pulls from the **Safee Tracking REST Service** (the same platform DSCO ran on) via a
> **provider-agnostic layer**. First provider is **Alrakeen** (`https://tk.alrakeen.sa`);
> **saudiX** will be added later. Historical references to "DSCO" below describe the
> original integration; the `dsco_*` DB column names are retained (not renamed) but now
> hold Safee data. See §7, §9, §10 and the "Multi-provider" notes for current behavior.

---

## 1. What We're Building

The **UNIFLEET** platform — an AI-powered fleet analytics system that:

- Pulls real-time data from the **Safee Tracking API** (Alrakeen now, saudiX later; GPS, speed, fuel per vehicle)
- Detects anomalies: geofence violations, excessive idling, unusual patterns
- Sends **SMS + voice call alerts** via Twilio to drivers and managers
- Generates **AI-powered reports** (daily, weekly, monthly) using Claude API
- Builds **per-driver behavioral profiles** and flags deviations from personal baseline
- Provides an **operational heatmap** and **telematic analytics dashboard**
- Includes a **mobile logistics application** (included in agreed price)

---

## 2. Scope of Work (Contract Sections)

### 3.1 Data Integration
- Integration with Safee Tracking APIs (multi-provider: Alrakeen, saudiX)
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
- Heatmaps generated using Safee geofence data (note: Alrakeen currently returns 0 geofences)
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
| Background Jobs | Laravel Queues | Poll Safee API, process data |
| Cache (future) | Redis | Live map updates |
| Mobile | TBD | Logistics app (iOS + Android) |

---

## 4. Architecture

```
Safee API ──┐   (Alrakeen, saudiX — via provider-agnostic layer)
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
- Connect to Safee API (auth token in header)
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

All migrations: `database/migrations/2026_04_24_000001_*.php` through `*_000014_*.php`, plus
`2026_07_07_000001_add_provider_to_safee_entities.php` (multi-provider).

> **Multi-provider (`provider` column):** `sites, categories, geofences, drivers, vehicles, trips`
> each carry a `provider` string (e.g. `alrakeen`, `saudix`; existing rows backfilled as `dsco`).
> Uniqueness is the **composite `(provider, dsco_*_id)`** — not the external id alone — because
> different providers can reuse the same numeric IDs. The poller stamps every row with its
> provider and scopes upsert keys + soft-disable by it. `dsco_*` column names are retained.

### Reference Tables (upserted on every poll, soft-disabled when missing from API)
```sql
sites           (id, provider, dsco_site_id, name, status[active|disabled], dsco_last_seen_at)          -- unique(provider, dsco_site_id)
categories      (id, provider, dsco_category_id, dsco_site_id, dsco_parent_id, name, status, dsco_last_seen_at)  -- unique(provider, dsco_category_id)
geofences       (id, provider, dsco_geofence_id, dsco_uuid, name, code, dsco_company_id, description, points_json, status, dsco_last_seen_at)  -- unique(provider, dsco_geofence_id)
drivers         (id, provider, dsco_driver_id, dsco_uuid, name, mobile, gender, email, license_status, residency_status, access_key, badge_number_1, badge_number_2, status[active|disabled], dsco_last_seen_at, dsco_created_at, dsco_updated_at)  -- unique(provider, dsco_driver_id)
vehicles        (id, provider, dsco_vehicle_id, dsco_uuid, plate_no, type, dsco_company_id, dsco_company_name, dsco_site_id, dsco_category_id, current_driver_id→drivers, vin, vehicle_model, vehicle_make, dsco_device_id, device_sim, device_imei, device_type, device_serial, device_installation_date, status[active|disabled], dsco_last_seen_at)  -- unique(provider, dsco_vehicle_id)
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
trips    (id, provider, dsco_trip_id, vehicle_id, driver_id, start_time, end_time, distance, avg_speed, max_speed, idle_time, driving_time, completed, start_lat/lon/alt, end_lat/lon/alt)  -- unique(provider, dsco_trip_id)
alerts   (id, vehicle_id, driver_id, type, triggered_at, resolved_at, twilio_status, meta jsonb)
reports  (id, type[daily|weekly|monthly], generated_at, content, vehicle_id, driver_id)
```

### Soft-disable logic (scoped per provider)
- Every poll: entities returned by Safee **for that provider** → `status = active`, `dsco_last_seen_at = now`
- Entities of the **same provider** absent from the response → `status = disabled` (never deleted)
- If an entity reappears in a future poll → `status = active` again automatically
- Soft-disable never touches other providers' rows (retired `dsco` rows are left untouched)
- This handles user login/account changes gracefully

---

## 7. Safee API Reference (v2.2.0.0)

**Base per provider:** `config('services.safee.providers.<name>.server_uri')` + `/api/v2/`
(Alrakeen = `https://tk.alrakeen.sa`).
**Auth:** Keycloak/OAuth2 password grant at
`{server_uri}/auth/realms/{realm}/protocol/openid-connect/token` — fetched by
`SafeeAuthService($provider)`, cached 270s per provider (`safee_access_token_{provider}`),
auto-refreshed on 401. Token `expires_in` is 300s (verified). Full spec:
`~/Downloads/Safee_Tracking_REST_Service.md`.

> **⚠️ Request-shape fixes (verified live on Alrakeen, July 2026).** DSCO's deployment
> tolerated the old shapes; Safee v2.2.0.0 does **not**. `get-fuel-data` with the old
> `{vehicleId,...}` body returns **HTTP 404 "Vehicle id is missing."** All of these are
> implemented correctly in `SafeeApiService`:

#### Reference / Utility
| Method | URL | Body | Description |
|---|---|---|---|
| GET  | `api/v2/status` | – | Health check ("Hello World!! …") |
| POST | `api/v2/site/list` | `{}` | All sites |
| POST | `api/v2/category/list` | `{}` | All categories (tree with parentId) |
| POST | `api/v2/geofence/list` | `{}` | All geofences with polygon points |

#### Vehicles
| Method | URL | Body | Description |
|---|---|---|---|
| POST | `api/v2/vehicle/list-info` | `{}` | Full list — device, driver, site, category embedded (+`vin, vehicleModel, vehicleMake, firstTripDate, lastTripDate, deviceInstallationDate`) |
| POST | `api/v2/vehicle/last-state` | `{"live":true,"endDate":null,"vehicles":[id,...]}` | Live GPS + ignition + odometer per vehicle (no `startDate`) |
| POST | `api/v2/vehicle/get-fuel-data` | **`{"id":X}`** | Fuel snapshot |
| POST | `api/v2/vehicle/get-speed-data` | **`{"id":X,"numberOfHours":24?}`** | Speed (last/avg/max + hourly) |
| POST | `api/v2/vehicle/get-weight-data` | **`{"id":X}`** | Weight/load data |
| POST | `api/v2/vehicle/trips` | **`{"pageSize":0,"pageIndex":0,"filter":{"vehicleId":X,"startDate":ts,"endDate":ts}}`** | Trips for one vehicle (pageSize 0 = no pagination) |
| POST | `api/v2/vehicle/positions` | `{"vehicleId":X,"startDate":ts,"endDate":ts}` | GPS history (backfill; 120s timeout) |
| POST | `api/v2/vehicle/events` | `{"vehicleId":X,"startDate":ts,"endDate":ts,"status":"ALL"}` | Vehicle events (was `event/list` in old code) |
| POST | `api/v2/vehicle/trip/path` | **`{"id":X}`** | GPS path points for a trip (was `trip/path` + `{tripId}`) |

#### Drivers  *(return under the `drivers`/`driver` key, NOT `result`)*
| Method | URL | Body | Description |
|---|---|---|---|
| POST | `api/v2/driver/list-info` | `{}` → `drivers[]` | Full driver list w/ uuid, access key, license status |
| POST | `api/v2/driver/trips` | `{"pageSize":0,"pageIndex":0,"filter":{"driverId":X,"startDate":ts,"endDate":ts}}` → `result` | Trips for one driver |

#### Maintenance (available, not yet wired)
| Method | URL | Description |
|---|---|---|
| GET  | `api/v2/maintenance/filters` | Available filter options |
| POST | `api/v2/maintenance/filter` | Maintenance tasks by filter (was `maintenance/tasks`) |

---

## 8. Safee API Data Structures (JSON)

> Alrakeen data quirks handled in `SafeePollCommand::tsToCarbon()`: `odometer` arrives as a
> **string** (`"265.4"`); `firstTripDate`/`lastTripDate` as **scientific-notation strings**
> (`"1.780457369E9"`); `deviceInstallationDate` in **milliseconds** (others in seconds);
> `status` values are `IgnitionOn`/`IgnitionOff` (capitalized).

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

## 9. Poller — `safee:poll` Command

File: `app/Console/Commands/SafeePollCommand.php`

```bash
php artisan safee:poll                 # all configured providers (full sync)
php artisan safee:poll alrakeen        # one provider
php artisan safee:poll --force         # ignore the interval guard
php artisan safee:poll --live-only     # FAST: only live positions + alert detection (~1-2s)
php artisan safee:poll --backfill      # also pull GPS history
```

> **Two cadences (near-real-time map):** the poller runs in two modes so the map
> stays live without waiting on the slow per-vehicle telemetry:
> - **`--live-only`** — only step 4 (live positions) + step's alert engine. ~1-2s
>   for the whole fleet (last-state fetch is ~1.7s/79 vehicles). **Scheduled every 30s.**
>   Bypasses the interval guard entirely (cadence is set by the scheduler).
> - **full poll** (no flag) — reference data + trips + fuel/speed/weight. Slow
>   (~3 min for 79 vehicles). **Scheduled every 5 min.**

### How it works
0. **Provider loop** — runs for the `{provider?}` argument, or every key under
   `config('services.safee.providers')`. Each provider gets its own `SafeeApiService`,
   cache namespace (`safee_{provider}_*`), and provider-scoped queries.
1. **Interval guard** — checks `SAFEE_POLL_INTERVAL_MINUTES` (.env, default 2) via cache key `safee_{provider}_last_poll_ran_at`. `--force` bypasses. **`--live-only` skips the guard AND steps 2/3/5/6/7** — it runs only step 4 (live state) + the alert engine, then returns.
2. **Sync reference data** — sites → categories → geofences → drivers → vehicles (upsert keyed by `(provider, dsco_*_id)` + provider-scoped soft-disable). Alrakeen returns 0 categories/geofences/drivers.
3. **Track driver changes** — if a vehicle's driver changed, closes the old `vehicle_driver_assignments` row and opens a new one.
4. **Live state** — `vehicle/last-state` in chunks of 50 → one `vehicle_positions` row per vehicle **only when the Safee `date` is newer than last stored** (dedup via `last_pos_ts_{vehicle_id}` cache, 1h TTL). **Skips `lat=0, lon=0`** (offline/no-GPS). Accumulates new-position vehicle IDs across **all** chunks for the alert engine (a prior bug only passed the last chunk). Log: `stored: X, skipped (no new fix): Y`.
5. **Per-vehicle telemetry** — for each active vehicle: trips (since `safee_{provider}_last_sync_timestamp`, fallback `SAFEE_LOOKBACK_HOURS`), then fuel/speed/weight (last snapshot, `{id}` shape — no date range).
6. **Backfill** (`--backfill`) — GPS history from `api/v2/vehicle/positions` **day-by-day** (86400s chunks, 120s timeout). Controlled by `SAFEE_BACKFILL_DAYS` (default 7).
7. **Updates cache** — stores `safee_{provider}_last_poll_ran_at` and `..._last_sync_timestamp`.

### Scheduler
Registered in `routes/console.php` — two cadences, both polling all configured providers:
```php
// Live positions + alert detection — near-real-time map (~1-2s per run).
Schedule::command('safee:poll --live-only')->everyThirtySeconds()->withoutOverlapping();
// Heavy sync: reference data, trips, fuel/speed/weight (~3 min per run).
Schedule::command('safee:poll')->everyFiveMinutes()->withoutOverlapping();
```
Sub-minute (`everyThirtySeconds`) scheduling requires the long-running `schedule:work`
process (Laravel 11+). Restart `schedule:work` after changing these cadences.
End-to-end map lag ≈ 30-60s (30s backend poll + up to 30s frontend refetch) + the
device's own reporting interval — down from ~3-4 min when positions were bundled
into the full poll.

#### Local development (no crontab needed)
```bash
# Terminal 1 — API server
php artisan serve

# Terminal 2 — Scheduler loop (with log output)
php artisan schedule:work >> storage/logs/scheduler.log 2>&1
```
Then watch the log live:
```bash
tail -f /Users/husseinhammoud/Desktop/my-project/unifleet/backend/storage/logs/scheduler.log
```
One-shot manual run (bypasses interval guard):
```bash
php artisan safee:poll --force
```

#### Production — add to server crontab:
```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

#### Logs
- `storage/logs/scheduler.log` — all poller console output (`stored: X, skipped: Y`)
- `storage/logs/laravel.log` — errors and warnings only (`Log::error`, `Log::warning`)

### .env variables
```
SAFEE_DEFAULT_PROVIDER=alrakeen       # provider used by login + as poll default
SAFEE_POLL_INTERVAL_MINUTES=2         # how often to actually poll (default 2)
SAFEE_LOOKBACK_HOURS=24               # fallback window when no prior sync timestamp
SAFEE_BACKFILL_DAYS=7                 # --backfill history window

# Per-provider credentials (auth URL is derived: {server_uri}/auth/realms/{realm}/...)
SAFEE_ALRAKEEN_SERVER_URI=https://tk.alrakeen.sa
SAFEE_ALRAKEEN_REALM=alrakeen
SAFEE_ALRAKEEN_CLIENT_ID=api
SAFEE_ALRAKEEN_CLIENT_SECRET=…
SAFEE_ALRAKEEN_USERNAME=C.Tech
SAFEE_ALRAKEEN_PASSWORD=…
# SAFEE_SAUDIX_* … (add when saudiX onboards; also uncomment the stub in config/services.php)
```
(Old `SAFEE_*` fall back to the retired `DSCO_POLL_INTERVAL_MINUTES` / `DSCO_LOOKBACK_HOURS` / `DSCO_BACKFILL_DAYS` if still present.)

### Models
- `Site`, `Category`, `Geofence` — reference data, `scopeActive()`
- `Driver` — soft-disable, `scopeActive()`, relations: vehicles, assignments, trips
- `Vehicle` — soft-disable, `scopeActive()`, relations: currentDriver, driverAssignments, positions, fuelLogs, speedLogs, weightLogs, trips, events, alerts
- `VehicleDriverAssignment` — history table, FK to vehicle + driver
- `VehiclePosition`, `VehicleEvent`, `VehicleFuelLog`, `VehicleSpeedLog`, `VehicleWeightLog` — time-series (no primary key, no timestamps, append-only)
- `Trip` — upserted by `(provider, dsco_trip_id)`
- `Alert`, `Report` — created by alert engine and AI report generator (Phase 2/3)
- All 6 synced models (`Site, Category, Geofence, Driver, Vehicle, Trip`) have `provider` in `$fillable`.

---

## 10. Frontend API Layer

### Auth Flow
1. Frontend POSTs `{ username, password }` to `POST /api/auth/login`
2. Backend (`SafeeUserAuthService`) calls the **Safee** Keycloak token endpoint of the default provider (`SAFEE_DEFAULT_PROVIDER`, currently Alrakeen) with those credentials
3. On success: creates/finds `User` by `dsco_username`, stores encrypted Safee tokens in `dsco_user_tokens`, fetches accessible vehicle IDs and stores in `dsco_accessible_vehicle_ids`, issues a Sanctum token
4. Returns `{ token, user }` — frontend stores the Sanctum token and sends it as `Authorization: Bearer <token>` on all subsequent requests
5. Safee token refresh is transparent — `RefreshSafeeToken` middleware runs before every protected request and silently refreshes if expiring within 60s

> **saudiX follow-up:** login is hardcoded to the default provider and `User` has no provider
> column yet. Storage columns keep the `dsco_*` names (reused, not renamed).

### API Endpoints
| Method | URL | Description |
|---|---|---|
| POST | `/api/auth/login` | Login with Safee credentials, get Sanctum token |
| POST | `/api/auth/logout` | Revoke current Sanctum token |
| GET | `/api/auth/me` | Current user info |
| GET | `/api/dashboard` | Fleet summary (counts, today's stats, alert count) |
| GET | `/api/vehicles` | List user's vehicles (filterable by status, search) |
| GET | `/api/vehicles/live` | Latest position per vehicle (for Google Maps) |
| GET | `/api/vehicles/fuel` | Latest fuel snapshot per vehicle — `VehicleController::fuelOverview()`, DISTINCT ON query |
| GET | `/api/vehicles/{id}` | Single vehicle detail |
| GET | `/api/vehicles/{id}/positions` | GPS history (from/to params) |
| GET | `/api/vehicles/{id}/trips` | Trip list (from/to params) |
| GET | `/api/vehicles/{id}/fuel` | Fuel log history (from/to params) |
| GET | `/api/drivers` | Drivers linked to user's vehicles |
| GET | `/api/drivers/{id}` | Single driver detail |
| GET | `/api/drivers/{id}/trips` | Driver's trip history |
| GET | `/api/geofences` | All active geofences with polygon points |
| GET | `/api/alerts` | Alerts (filterable by from/to/type/vehicle_id) |
| POST | `/api/alerts/{id}/resolve` | Mark alert as resolved |
| GET | `/api/settings` | All settings grouped by category |
| PUT | `/api/settings/{key}` | Update a setting + write changelog entry |
| GET | `/api/settings/changelog` | All setting change history |
| GET | `/api/settings/{key}/changelog` | Change history for one setting |

### Per-User Vehicle Filtering
- At login, backend calls `vehicle/list-info` with the user's Safee token and stores the returned vehicle IDs in `users.dsco_accessible_vehicle_ids`
- All vehicle/driver/alert queries are scoped via `$user->vehicleQuery()` → `Vehicle::whereIn('dsco_vehicle_id', [...])`
- ⚠️ **saudiX caveat:** `vehicleQuery()` filters on `dsco_vehicle_id` only (not `provider`). Fine while one provider handles login; must add provider scoping before saudiX users exist (IDs could collide).

### Key Files
- `app/Services/SafeeAuthService.php` — per-provider service-account auth + cached token
- `app/Services/SafeeApiService.php` — Safee API client (correct request shapes), scoped to a provider
- `app/Services/SafeeUserAuthService.php` — per-user login + token management
- `app/Models/DscoUserToken.php` — encrypted token storage (name retained)
- `app/Http/Middleware/RefreshSafeeToken.php` — silent Safee token refresh
- `config/services.php` → `safee.providers.*` — per-provider credentials
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
| Geofence format | Polygon (array of lat/lon points) | Per Safee API structure |
| Driver profiles | Per-driver baseline, not global threshold | Core differentiator |
| Tracking source | Safee Tracking (multi-provider) | DSCO retired; Alrakeen live, saudiX later; provider-agnostic layer |

---

## 12. Pending / Open Items

### Done (July 2026 — DSCO → Safee/Alrakeen migration)
- [x] **Provider-agnostic layer** — `SafeeAuthService`, `SafeeApiService`, `SafeeUserAuthService`, `RefreshSafeeToken`, `SafeePollCommand`; `config/services.safee.providers.*` (saudiX stub commented)
- [x] **Corrected request shapes** vs Safee v2.2.0.0 — fuel/speed/weight `{id}`, trips/driver-trips `{pageSize,pageIndex,filter}`, driver endpoints read `drivers` key, `vehicle/trip/path`, `vehicle/events` (verified live; old `{vehicleId}` fuel shape 404s)
- [x] **`provider` migration** — added to sites/categories/geofences/drivers/vehicles/trips; unique `(provider, dsco_*_id)`; existing rows backfilled `dsco`; models updated
- [x] **Data-quirk handling** (`tsToCarbon`) — ms-vs-s timestamps, scientific-notation date strings, string odometer
- [x] **Retired DSCO** — deleted DscoApiService/DscoAuthService/DscoUserAuthService/RefreshDscoToken/DscoPollCommand + `services.dsco` block. Kept `DscoUserToken` + `dsco_*` columns (reused)
- [x] **Verified live** — Alrakeen: 2 sites, 79 vehicles, 0 drivers/categories/geofences, 373 trips; login issues Sanctum token + 79 accessible vehicles (company UNIMAC)
- [ ] **saudiX follow-ups:** per-user provider (User has no provider col); provider-scope `User::vehicleQuery()`; add `SAFEE_SAUDIX_*` + uncomment config stub
- [ ] **Note:** Alrakeen returns 0 geofences → AlertEngine `geofence_exit` + heatmap are no-ops there; raise with client

### Done (April 2026)
- [x] Scaffold Laravel project + DscoApiService + DscoAuthService
- [x] All 14 database migrations
- [x] All Eloquent models with relations and scopeActive()
- [x] `dsco:poll` artisan command — full sync pipeline with interval guard + soft-disable
- [x] `dsco:poll --backfill` — day-by-day position history fetch (120s timeout/chunk, dedup by timestamp)
- [x] Scheduler registered in routes/console.php
- [x] Laravel Sanctum + auth flow (DSCO OAuth2 → Sanctum token)
- [x] 6 API controllers: AuthController, VehicleController, DriverController, GeofenceController, AlertController, DashboardController
- [x] `RefreshDscoToken` middleware — silently refreshes DSCO token on every authenticated request
- [x] `config/cors.php` — allows localhost:8089 (Vite dev server)
- [x] Fixed empty JSON body bug (`[]` → `new \stdClass()` for DSCO API)
- [x] **Fixed OOM in `VehicleController::live()`** — replaced `->get()->unique('vehicle_id')` with `DISTINCT ON (vehicle_id)` PostgreSQL query; also filters `lat != 0` so (0,0) rows don't pollute the live map
- [x] **Fixed OOM in `DashboardController::index()`** — same DISTINCT ON fix
- [x] **Fixed `positions()` endpoint** — added `where('lat', '!=', 0)` filter before limit so valid GPS rows are never cut off by the 2000-row limit
- [x] **Fixed live poll storing (0,0) rows** — `syncLiveState()` now skips insert when `lat=0 && lon=0`
- [x] **`DscoApiService::post()`** — accepts optional `$timeoutSeconds` param (default 30); `getVehiclePositions()` defaults to 120s
- [x] **Position history dedup in live poll** — `syncLiveState()` now deduplicates by DSCO `date` timestamp using per-vehicle cache key (`last_pos_ts_{id}`, 1h TTL). Parked/unchanged vehicles are skipped instead of inserting duplicate rows. Each stored row has `speed` for future speed-at-location analysis (e.g. Phase 2 over-speed detection).
- [x] **SettingController** — `GET /api/settings` (grouped), `PUT /api/settings/{key}` (typed update + changelog), `GET /api/settings/changelog`
- [x] **Setting + SettingChangelog models** — `Setting::get(key, default)` helper; changelog stores old/new value, user, IP
- [x] **Migrations + seeder** — `settings` and `setting_changelogs` tables; 8 default settings seeded
- [x] **AlertEngineService** — `over_speed`, `geofence_exit`, `low_fuel` detection; cooldown per vehicle+type; geofence ray-casting PIP; runs after every `dsco:poll` cycle
- [x] **AlertController::resolve()** — `POST /api/alerts/{id}/resolve` persists `resolved_at` to DB
- [x] **`VehicleController::fuelOverview()`** — `GET /api/vehicles/fuel` returns latest fuel snapshot per vehicle via `DISTINCT ON (vehicle_id)` query
- [x] **`DscoUserAuthService::fetchAccessibleVehicleIds()` fallback** — if DSCO `vehicle/list-info` returns empty (API outage or permission issue), falls back to all `dsco_vehicle_id` values in the local `vehicles` table. Prevents the dashboard showing zero vehicles when DSCO is unreachable.

### Critical DB notes
- Local DB holds both retired `provider='dsco'` rows (old test data — left untouched by Alrakeen polls) and live `provider='alrakeen'` rows. All entity queries/upserts are provider-scoped.
- `vehicle_positions` has legacy `lat=0` rows from before the fix. All queries filter `where lat != 0` — harmless but waste space. Clean with `DELETE FROM vehicle_positions WHERE lat = 0` on production.
- Safee `vehicle/last-state` returns `lat=0, lon=0` for offline/parked vehicles. Do NOT store these.
- Safee `api/v2/vehicle/positions` backfill works per-day; one API call per vehicle per day. Some vehicles return large payloads — 120s timeout per chunk is sufficient.
- `users.dsco_accessible_vehicle_ids` is populated at login (holds Safee vehicle IDs). If Safee is down, the fallback in `SafeeUserAuthService::fetchAccessibleVehicleIds()` uses all `dsco_vehicle_id` for the provider so it's never `[]`. Reset example: `UPDATE users SET dsco_accessible_vehicle_ids = (SELECT json_agg(dsco_vehicle_id) FROM vehicles WHERE provider='alrakeen') WHERE id = <uid>;`

### Still Needed
- [ ] Run `php artisan migrate` on Alibaba Cloud RDS
- [ ] Run `SELECT create_hypertable(...)` for time-series tables on TimescaleDB
- [ ] Add crontab entry on server: `* * * * * php artisan schedule:run`
- [ ] Confirm Twilio account + Lebanon SMS support
- [ ] Define idling threshold (minutes) with client → Phase 2 alert engine
- [ ] Define geofence alert rules (which zones are "authorized") → Phase 2
- [ ] Add production frontend URL to `config/cors.php` allowed_origins when deploying
- [ ] Plan mobile app tech (React Native? Flutter?) → Phase 5
- [ ] Set up Claude API key in Laravel `.env` → Phase 3 reports

---

*Project: UNIFLEET | Client: UNIMAC | Dev: Cedars Technology*
*Memory source: Claude.ai project (fleet-ai-project-memory.md + Safee_Tracking_REST_Service.md + contract scope)*
