# UNIFLEET — AI Fleet Intelligence Platform
## Project Memory for Claude Code (VS Code)

> Auto-loaded by Claude Code. Contains all project context, decisions, scope, and API reference.
> Company: Cedars Technology | Client: UNIMAC | Last updated: Oct 2026 (session 13 — observability: unifleet:doctor, /api/health, OpsNotifier (email-only), provider_api_failures, owner-only System page)

> **⚠️ saudiX DECOMMISSIONED (2026-08-24):** saudiX is **no longer a partner** and was
> **fully removed** — its `services.safee.providers.saudix` config + `SAFEE_SAUDIX_*` env,
> and ALL its data (129 vehicles + cascaded positions/trips/telemetry, 865 alerts, 6
> provider_sites, the `Cedars` login user). The poller no longer calls it (the 401 spam
> stopped). **The only live provider now is Alrakeen.** The provider-agnostic layer is
> intact, so a future partner is a config-only add (new `providers` key + `SAFEE_<NAME>_*`).
> Historical "Done" entries below still mention saudiX as history — accurate for when written.

> **⚠️ Provider migration (July 2026):** DSCO has been **retired**. The platform now
> pulls from the **Safee Tracking REST Service** (the same platform DSCO ran on) via a
> **provider-agnostic layer**. The live provider is **Alrakeen** (`https://tk.alrakeen.sa`).
> _(saudiX was also live July–Aug 2026 but was decommissioned 2026-08-24 — see banner above.)_
> Historical references to "DSCO" below describe the original integration; the `dsco_*` DB
> column names are retained (not renamed) but now hold Safee data. See §7, §9, §10.

> **⚠️ Auth model (session 8, 2026-07-23):** login is now a **local account** (username/password
> in the `users` table), NOT per-provider Safee login. The provider picker was removed. An admin
> account (`is_admin`) sees **all providers merged**. Default admin: **`admin` / `admin`**. See §10.

> **⚠️ User roles & permissions (session 10, 2026-08-07 — Phase 3 BUILT):** `users.role` = **owner** |
> **admin** | **user** (source of truth; `is_admin` kept synced = owner|admin so existing manager gates
> still work). **owner** = super-account (only owners manage owner/admin rows; last active owner protected;
> the seeded `admin` account is now owner). **admin** = manages regular users, sees all vehicles, manages
> zones/sites. **user** = scoped: sees only vehicles in granted zones — `vehicleQuery()` →
> `whereHas('zones', in effectiveZoneIds())` where effective = direct `user_zone` grants ∪ all zones under
> granted `user_site` (incl. future zones); **no grants ⇒ sees nothing**; unrestricted/no-zone vehicles stay
> hidden. Legacy provider users (provider + `dsco_accessible_vehicle_ids`, no zone grants) fall back to old
> provider scoping. Per-user: `allowed_pages` (jsonb; grantable = dashboard/map/vehicles/fuel/alerts/ai/reports),
> `can_edit_vehicles` bool, `status` active|disabled (soft-delete; login rejects disabled → 403). `UserController`
> CRUD at `/api/users` (manager-gated). Migrations `2026_08_07_000003/000004/000005`. **NB:** scoped users see 0
> vehicles until `vehicle_zone` assignments exist (empty since the 2026-08-06 cleanup) — assign in the Zones UI.
> **Password/email flows:** create with `send_email` (default true) generates a strong password (`Str::password(14)`)
> + emails `AccountCredentials`, returning `generated_password` so the admin can relay it. `POST /api/users/{id}/password`
> {mode: link|generate|manual}. Public `POST /api/auth/forgot-password` + `/reset-password` (Laravel password broker;
> `User::sendPasswordResetNotification` overridden → `ResetPasswordLink` to `config('app.frontend_url')/reset-password`).
> **`MAIL_MAILER=log`** for now (no SMTP) — emails go to `storage/logs/laravel.log`; sends are wrapped so they never
> break the request. `config('app.frontend_url')` ← `FRONTEND_URL` (default http://localhost:8089).

---

## 1. What We're Building

The **UNIFLEET** platform — an AI-powered fleet analytics system that:

- Pulls real-time data from the **Safee Tracking API** (Alrakeen; provider-agnostic so more partners can be added; GPS, speed, fuel per vehicle)
- Detects anomalies: geofence violations, excessive idling, unusual patterns
- Sends **SMS + voice call alerts** via Twilio to drivers and managers
- Generates **AI-powered reports** (daily, weekly, monthly) using Claude API
- Builds **per-driver behavioral profiles** and flags deviations from personal baseline
- Provides an **operational heatmap** and **telematic analytics dashboard**
- Includes a **mobile logistics application** (included in agreed price)

---

## 2. Scope of Work (Contract Sections)

### 3.1 Data Integration
- Integration with Safee Tracking APIs (multi-provider capable; currently Alrakeen — saudiX decommissioned 2026-08-24)
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
| Database | PostgreSQL + TimescaleDB | Relational + time-series in one (⚠️ but RDS was requested PostGIS-only — see §13) |
| Cloud | Alibaba Cloud ECS + RDS | Deployment target — not yet provisioned (see §13) |
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
provider_sites  (id, site_id→sites, provider, dsco_site_id, name, status[active|disabled], dsco_last_seen_at)  -- unique(provider, dsco_site_id); raw per-provider mirror (was `sites`)
categories      (id, provider, dsco_category_id, dsco_site_id, dsco_parent_id, name, status, dsco_last_seen_at)  -- unique(provider, dsco_category_id)
geofences       (id, zone_id→zones, provider, dsco_geofence_id, dsco_uuid, name, code, dsco_company_id, description, points_json, status, dsco_last_seen_at)  -- unique(provider, dsco_geofence_id); raw per-provider mirror
drivers         (id, provider, dsco_driver_id, dsco_uuid, name, mobile, gender, email, license_status, residency_status, access_key, badge_number_1, badge_number_2, status[active|disabled], dsco_last_seen_at, dsco_created_at, dsco_updated_at)  -- unique(provider, dsco_driver_id)
vehicles        (id, provider, dsco_vehicle_id, dsco_uuid, plate_no, type, dsco_company_id, dsco_company_name, dsco_site_id, dsco_category_id, current_driver_id→drivers, vin, vehicle_model, vehicle_make, dsco_device_id, device_sim, device_imei, device_type, device_serial, device_installation_date, status[active|disabled], dsco_last_seen_at)  -- unique(provider, dsco_vehicle_id)
```

### Assignment History
```sql
vehicle_driver_assignments  (id, vehicle_id→vehicles, driver_id→drivers, started_at, ended_at[null=current])
-- A new row is inserted every time the poller detects a driver change on a vehicle
```

### Canonical Zone / Site layer (name-based, our-side)
```sql
sites   (id, name, slug UNIQUE, status)                       -- one row per distinct site NAME
zones   (id, name, slug UNIQUE, site_id→sites[null], status)  -- one row per distinct geofence NAME; grouped under a site
vehicle_zone (id, vehicle_id→vehicles, zone_id→zones)         -- assignment ("can enter"); NO rows = unrestricted (all zones)
```
The raw `provider_sites` / `geofences` are per-provider mirrors (same physical place appears in BOTH providers with different ids but the same name). `ZoneSiteSyncService` (run via `zones:sync` **and** inside the full poll) merges them into canonical `sites`/`zones` **by slug(name)** and back-links `provider_sites.site_id` / `geofences.zone_id`. Key on **name/slug, never provider id**. `zones.site_id` is set later (admin UI / import). Vehicle→zone assignment is authoritative; fall back to the provider's `dsco_site_id → provider_sites.name` where a vehicle has none.
**API (`ZoneController`)** — reads open to any authenticated user, **writes admin-only** (403 otherwise):
`GET /api/zones` (zones + site + geofence/vehicle counts) · `PUT /api/zones/{id}` (set `site_id`/`status`) · `GET /api/zones/sites` (canonical sites + zone counts) · `POST /api/zones/sites` (create, merges on slug) · `PUT /api/zones/sites/{siteId}` (rename/status) · `GET /api/vehicles/{id}/zones` (`{unrestricted, zones}`) · `PUT /api/vehicles/{id}/zones` (`{zone_ids:[]}` sync; empty = unrestricted) · `POST /api/zones/import` (bulk assign). Routes register `zones/sites`/`zones/import` before `zones/{id}` (whereNumber) to avoid collision.

**Provenance** (`zones` & `sites` both carry `source` = `provider`|`manual` + `created_by` FK→users, added 2026-08-03): `ZoneSiteSyncService` sets `source='provider'` on `firstOrCreate`; `storeZone`/`storeSite` set `source='manual'` + `created_by` = current user. Existing rows defaulted to `provider`. `formatZone`/`sites()` expose `source`, `created_at`, `created_by` (creator name). **NB (2026-08-06):** `source` alone was unreliable — the migration defaulted *all* rows to `provider`, but only zones with a backing geofence (`geofences_count ≥ 1`) truly come from a provider. A one-off cleanup **deleted the 35 non-provider zones** (leftovers from the old import-creates-zones behaviour), leaving the 10 real provider zones; the detached vehicle assignments were logged to `vehicle_zone_changelogs` (`source='system'`). The UI shows only a **manual** badge (no "provider" string/logo — a zone's name can merge geofences from *both* providers, so a single provider logo would mislead).

**Contacts / zone responsibles** (`contacts` + `contact_zone`, added 2026-08-06; admin-only). People to notify about an issue in a zone. `contacts`: `name`, `phone` (**both required**), `email`, `description`, `note` (optional), `all_zones` bool, `created_by`. `all_zones=true` ⇒ responsible for **every** zone incl. ones added later — resolved at query time by **`Zone::responsibleContacts()`** (`where all_zones OR whereHas zones`), so **no pivot rows are written** for all-zones contacts and future zones are auto-covered. `ContactController` CRUD: `GET/POST /api/contacts`, `PUT/DELETE /api/contacts/{id}`, plus **`GET /api/zones/{id}/contacts`** (who to notify for a zone). **Pending:** wiring `responsibleContacts()` into the alert/notification flow (surface the responsible person when a zone alert fires) is future work.

**Vehicle zone assignment audit** — `vehicle_zone_changelogs` (vehicle_id, plate_no, old_zones/new_zones JSON, `source` = `manual`|`import`, user_id, ip_address, changed_at). Written by `logZoneChange()` (skips no-ops) from `setVehicleZones` (`manual`) and the `import` apply loop (`import`). Read via **`GET /api/vehicles/{id}/zone-log`** (last 50, with changer name).

**`POST /api/zones`** (admin, `storeZone`) — create a canonical zone by name (optional `site_id`); merges on slug (`firstOrCreate`). Zones otherwise arrive from the providers (geofence canonicalization).

**`POST /api/zones/import`** (admin) — bulk vehicle→zone assignment from a spreadsheet the client parses (SheetJS). Body: `{rows:[{plate,zone,site?}], apply:bool}`. Matches vehicles by `normalizePlate()` (uppercase, strip non-alphanumerics — so "3281 SXA" == " 3281sxa ") and zones by slug against **existing** zones only — **never creates zones**. A zone name with no match is reported under `unmatched_zones` (add the zone first, then re-import). `apply=false` = dry-run. `apply=true` **replaces** each matched vehicle's zone set via `sync()` (only vehicles that matched ≥1 existing zone). Returns `matched_count`, `unmatched_plates`, `unmatched_zones`, `zones_matched`, `assigned_vehicles`, `matched[]`.

_Phase 2 complete: `zone_unauthorized` alert rule + admin UI + Excel import all shipped. Phase 3 (todo): `user_site`/`user_zone` permissions → visibility scoping._

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
reports  (id, created_by→users, type[daily|weekly|monthly|driver_safety], title, generated_at, period_from, period_to, content jsonb-snapshot, vehicle_id, driver_id)  -- created_by/title/period_* added 2026-08-23; content holds the full AnalyticsInsights JSON
vehicle_blocklist (id, provider, dsco_vehicle_id, plate_no, blocked_by→users)  -- unique(provider, dsco_vehicle_id); vehicles a manager DELETEd — the poller skips these so they're never re-imported (2026-08-24)
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
2. **Sync reference data** — sites → categories → geofences → drivers → vehicles (upsert keyed by `(provider, dsco_*_id)` + provider-scoped soft-disable). Alrakeen returns 0 categories/geofences/drivers. **`syncVehicles` skips any `(provider, dsco_vehicle_id)` in `vehicle_blocklist`** (manager-deleted vehicles) so they're never re-imported.
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
# To onboard another partner: add SAFEE_<NAME>_* vars here + a new key under
# config/services.safee.providers. (saudiX was removed 2026-08-24.)
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

### Auth Flow (LOCAL accounts — session 8, 2026-07-23)
1. Frontend POSTs `{ username, password }` to `POST /api/auth/login` (no provider — the login screen's provider picker was removed)
2. `AuthController::login` looks up a **local** `User` by `dsco_username` and verifies the password with `Hash::check` (bcrypt). No Safee call at login.
3. On success: issues a Sanctum token; returns `{ token, user }` where `user` includes `is_admin` and a live `vehicles_count` (from `vehicleQuery()->count()`).
4. Frontend stores the Sanctum token and sends it as `Authorization: Bearer <token>` on all subsequent requests.
5. `RefreshSafeeToken` middleware still runs but is a **no-op for local/admin users** (no `dscoToken`). The background poller — not per-user login — is what pulls provider data.

> **Accounts are provisioned by us.** Default admin: **`admin` / `admin`** (seeded in `DatabaseSeeder`
> via `updateOrCreate` on `dsco_username='admin'`, `provider=null`, `is_admin=true`). A proper
> user-management UI is deferred. The old per-provider Safee login (`SafeeUserAuthService`) is no
> longer wired to the UI but the class remains. Removed route: `GET /api/auth/providers`.

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
| GET | `/api/analytics/insights` | AI Insights payload — health score, KPIs, findings, sections (query: `from`, `to`, `zone`, `type`) |
| GET | `/api/reports` | Caller's saved reports (scoped by `created_by`) |
| POST | `/api/reports` | Generate + persist a report snapshot, returns full JSON (body: `type`, `from?`, `to?`, `title?`) |
| GET | `/api/reports/{id}` | Fetch a saved report snapshot (owner only) |
| GET | `/api/vehicles/device-conflicts` | Vehicles sharing a tracker IMEI (data-integrity flag), grouped by IMEI |
| DELETE | `/api/vehicles/{id}` | **Delete a vehicle + related data** (manager-only, no-data-only; block-lists it — see §12 session 11) |

### Vehicle Scoping — `User::vehicleQuery()`
Every vehicle/driver/site/alert/dashboard query funnels through `$user->vehicleQuery()`, so scoping is defined in one place:
- **Admin (`is_admin = true`):** returns `Vehicle::query()` — **ALL vehicles across every provider, merged** into one fleet (dashboard, alerts, sites, live map all merge automatically). `provider = null`.
- **Provider user (legacy):** `Vehicle::whereIn('dsco_vehicle_id', dsco_accessible_vehicle_ids)->where('provider', $user->provider)` — scoped by BOTH provider and accessible IDs (vehicle IDs are only unique *within* a provider, so the provider filter prevents cross-provider ID collisions).
- Vehicle payloads (`formatVehicle` + `live`) now include `provider` so the frontend can badge each vehicle with its source (Alrakeen / saudiX).

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
| Tracking source | Safee Tracking (multi-provider capable) | DSCO retired; **Alrakeen** live; saudiX decommissioned 2026-08-24; provider-agnostic layer |

---

## 12. Pending / Open Items

### Done (session 13 — 2026-10-01, observability / diagnostics)
- [x] **`SystemHealthService`** (`app/Services/`) — THE single source of truth for "is UNIFLEET healthy?". Read by all three consumers so they can never disagree: `unifleet:doctor`, `GET /api/health`, `GET /api/admin/diagnostics`. 14 checks: `app_env` (fails on APP_DEBUG=true in production), `php`, `database`, `migrations` (dir-vs-table diff, cheaper than `migrate:status`), `last_position` (vs `poll_stale_threshold_hours`), `last_trip`, `scheduler` (heartbeat), `providers`, `queue`, `disk`, `logs`, `mail`, `ops_alerts`, `open_alerts` — plus `counts` (vehicles/positions/trips/alerts/zones/users). **Design rule: every check is individually wrapped in `guard()`** so one broken subsystem degrades to a single `fail` row instead of taking down the whole report — a diagnostics tool that crashes is useless exactly when needed. Status vocabulary: `ok` | `warn` (works but wrong for prod) | `fail`.
- [x] **`php artisan unifleet:doctor`** (`DoctorCommand`) — human-readable table (only non-OK rows print their remedy, so a clean run is scannable), `--json` for machines, `--prune --days=30` for failure-log retention. **Exits 1 when any check fails** → usable from cron/CI.
- [x] **`GET /api/health`** — PUBLIC (unauthenticated), for an external uptime monitor. **Terse by design** (`status`, `checked_at`, `failing[]` — names only, no paths/versions/config) so it can't fingerprint the deployment. Returns **HTTP 503** when failing, which is what monitors alert on. `OPS_HEALTH_TOKEN` via `?token=` or `X-Health-Token` (compared with `hash_equals`) unlocks the full report.
- [x] **`OpsNotifier`** (`app/Services/`) — the DELIVERY half of monitoring. `safee:health` used to write an `alerts` row and stop; detection without delivery is not monitoring, and that is why Sep 2026 lasted 14 days. **Email-only** (Telegram was built 2026-10-01 then removed the same day at the client's request — "don't need it for now"). Email is **ignored while `MAIL_MAILER=log`** (a log file nobody watches cannot page anyone), so `configuredChannels()` returns `[]` today and the `ops_alerts` check reports `fail`. **⚠️ Net effect until SMTP is configured: a dead pipeline is detected and logged but reaches nobody.** Adding a channel back is a `match` arm + a config key — the dedupe/cooldown/best-effort semantics are channel-agnostic. Every send is try-caught: a notifier that throws would kill the watchdog calling it. `send()` takes a `dedupeKey` + `cooldownMinutes` using atomic `Cache::add` so concurrent runs can't double-send.
- [x] **`PollHealthCommand` now notifies** — `flagStale()` calls `OpsNotifier::send(..., dedupeKey: 'poll_stale', cooldownMinutes: 360)` so an ongoing outage pages every 6h, not hourly; `recover()` sends an all-clear **only if** an alert was actually open (otherwise every healthy hourly run would announce itself) and forgets the cooldown key so the next genuine outage notifies immediately.
- [x] **Scheduler heartbeat** — `Schedule::call(...)->everyMinute()` in `routes/console.php` stamps `SystemHealthService::HEARTBEAT_KEY`. **This is the check that would have caught Sep 2026:** it distinguishes "the poller ran and found nothing" from "nothing is running at all". Missing or >5 min old ⇒ `fail` with the exact remedy (prod crontab vs dev `schedule:work`).
- [x] **`provider_api_failures` table + `ProviderApiFailure` model** (migration `2026_10_01_000001`) — durable record of every Safee failure: `provider`, `kind` (auth|request), `endpoint`, `status_code`, `message` (trimmed to 500 chars — raw bodies can be a whole HTML error page), `occurred_at`. **Only failures are stored** (the live poll succeeds every 30s, so successes would be ~2 rows/sec); last success lives in a cache key (`safee_last_success_{provider}`, 30-day TTL) via `markSuccess()`/`lastSuccessAt()`. `record()` is fully try-caught — diagnostics must never break the poller they observe. Wired into **`SafeeAuthService::fetchToken`** (auth path, incl. connect failures where no HTTP status exists) and **`SafeeApiService::request`** (request path; skips `RequestException` so an auth failure isn't double-counted), both also calling `markSuccess()` on success. `provider` check computes `failing_now` = recent failures AND no success since the newest one. **"When did Safee fail?" is now a query, not a grep.** 30-day retention, pruned weekly.
- [x] **`DiagnosticsController` + `EnsureOwner` middleware** (alias `owner`, registered in `bootstrap/app.php`) — **owner-only**, stricter than `manager` (which admins pass). `GET /api/admin/diagnostics` (full report), `/logs` (file list + tail), `/failures` (history + hourly buckets + last_success), `POST /test-alert` (proves delivery works instead of assuming it; 422 + guidance when no channel is configured).
- [x] **Log reading is the risky part, so it is defended twice:** the client passes a bare filename (never a path), it must match `/^[A-Za-z0-9._-]+\.log$/`, AND the `realpath` must still sit under `storage/logs` — blocks `../` traversal and symlinks. Verified: `?file=../../.env` and the URL-encoded form both 404. Tail reads backwards in 8 KB chunks with a 4 MB ceiling (laravel.log was already 8 MB). `parseEntries()` groups Laravel's `[ts] env.LEVEL: msg` format with following stack-trace lines as one entry, then filters by level/search, newest first.
- [x] **Daily log rotation** — `LOG_STACK=daily` + `LOG_DAILY_DAYS=14` in `.env`/`.env.example`. The unrotated 8 MB `laravel.log` was a disk risk AND unsearchable; the System page's Logs tab reads these files, so rotation makes it usable. Old `laravel.log` is left in place and still listed.
- [x] **New env** (`.env` + `.env.example`, documented inline): `OPS_ALERT_EMAIL`, `OPS_HEALTH_TOKEN`. Config block `services.ops.*`.
- **VERIFIED LIVE (not just linted):** doctor renders + exits 1 on fail; JSON parses (14 checks); public `/api/health` 503 + terse; token gate returns full report for the right token and terse for a wrong one; all 4 admin endpoints **200 for owner / 403 for a `role=user` account** (tested with a real temporary token, since deleted) while `/api/auth/me` stayed 200 for that user; path traversal blocked; log parsing + level filter + search return real entries; **failure recording proven by pointing `SAFEE_ALRAKEEN_SERVER_URI` at an unresolvable host** — both auth and request paths recorded rows, the provider check flipped to `fail`, a real poll flipped it back to `ok`, and the 3 synthetic rows were deleted.
- **What it found immediately:** the local pipeline had been dead **~7 days** (newest fix 7 days old) purely because no scheduler was running — Alrakeen itself was reachable, and a manual `safee:poll --live-only` stored 105 positions at once. Also: disk 84%, `MAIL_MAILER=log`, and `Ops alerting: none`.
- **STILL REQUIRED FROM US (the code is built, the config is not):** ops alerting has **no working channel** — configure real SMTP (`MAIL_MAILER` != `log`) + `OPS_ALERT_EMAIL`, or add a push channel back to `OpsNotifier`. Until then the watchdog detects a stale pipeline and only writes it to the log. Also: point an external uptime monitor at `/api/health` (it must live OUTSIDE the box — that's the part that still works when the box is the problem). Sentry + request IDs remain open (see `TODO.md`).
- **Pre-existing bug found, NOT fixed:** unauthenticated API requests **without** `Accept: application/json` return **HTTP 500 instead of 401** — affects every authenticated route (`/api/dashboard`, `/api/settings`), not just the new ones. Laravel is trying to redirect to a non-existent `login` route. The React app always sends the header, so the app is unaffected. Fix in `bootstrap/app.php` `withExceptions`.

### Done (session 12 — 2026-09-24, real-ML anomaly detection + poller resiliency)
- [x] **Real machine learning in AI Insights** (`FleetAnomalyService`) — genuine on-server ML via **Rubix ML** (`composer require rubix/ml`, pure PHP, $0, PDPL-safe). **Isolation Forest** scores each vehicle's unusualness vs. the fleet + **K-Means** clusters behaviour profiles; robust median/MAD z-score names the deviating feature. Features (idle_pct, distance_km, trips, engine_min, max_speed, over_speed) come from `FleetMetricsService::perVehicle` — the ML never touches raw GPS, so numbers stay exact. Returns DATA ONLY; `AnalyticsService::anomalyInsights()`/`anomalyLine()` templates turn findings into the existing `insights[]` shape, prepended ahead of the rule-based narrative, and added to `sections.anomalies`. Fails closed (`available:false`) on too-few vehicles/any error → deterministic narrative still renders. Admin-tunable settings (code defaults): `anomaly_min_vehicles`(8), `anomaly_contamination`(0.1), `anomaly_trees`(120), `anomaly_max_findings`(4). **Why ML not LLM:** client wanted real AI at no recurring cost — Rubix runs locally (no API/GPU bill) and matches contract §3.2. LLM (Claude API / local Ollama) deliberately deferred as optional prose polish. **NB:** needs `composer install` on deploy (new dep). Frontend renders via the existing insights panel (icon `ai`→Sparkles); a dedicated "AI Anomalies" UI section is still TODO.
- [x] **Fixed the Sep 2026 silent-outage class of bug.** Root cause: Alrakeen went unreachable 2026-09-10; the auth-token fetch (`SafeeAuthService::fetchToken`) had **no timeout** and `SafeeApiService::dispatch` no *connect* timeout, so requests hung on cURL's ~300s default and wedged the every-30s live poll; the scheduler then died and nothing polled for 14 days with **no alert**. Fixes: `connectTimeout(10)`+`timeout(20)` on the token fetch, `connectTimeout(10)` on dispatch (fail fast in ~10s). Backfilled the gap (`SAFEE_LOOKBACK_HOURS=360 php -d memory_limit=512M artisan safee:poll --force alrakeen` after `cache:forget safee_alrakeen_last_sync_timestamp`); trips 3,155→4,150, continuous again.
- [x] **Poller freshness watchdog** — `safee:health` (`PollHealthCommand`) raises a **self-resolving `poll_stale` Alert** (vehicle_id null) when the freshest active-vehicle position is older than `poll_stale_threshold_hours` (default 6); resolves + stamps `back_online` when data returns. Scheduled **hourly** in `routes/console.php`. Exit 1 when stale (usable from cron/CI). **Reminder:** the pipeline is only alive while `schedule:work` runs — keep it under a process manager (dev) or the `* * * * * schedule:run` crontab (prod).

### Done (July 2026 — DSCO → Safee/Alrakeen migration)
- [x] **Provider-agnostic layer** — `SafeeAuthService`, `SafeeApiService`, `SafeeUserAuthService`, `RefreshSafeeToken`, `SafeePollCommand`; `config/services.safee.providers.*` (saudiX stub commented)
- [x] **Corrected request shapes** vs Safee v2.2.0.0 — fuel/speed/weight `{id}`, trips/driver-trips `{pageSize,pageIndex,filter}`, driver endpoints read `drivers` key, `vehicle/trip/path`, `vehicle/events` (verified live; old `{vehicleId}` fuel shape 404s)
- [x] **`provider` migration** — added to sites/categories/geofences/drivers/vehicles/trips; unique `(provider, dsco_*_id)`; existing rows backfilled `dsco`; models updated
- [x] **Data-quirk handling** (`tsToCarbon`) — ms-vs-s timestamps, scientific-notation date strings, string odometer
- [x] **Retired DSCO** — deleted DscoApiService/DscoAuthService/DscoUserAuthService/RefreshDscoToken/DscoPollCommand + `services.dsco` block. Kept `DscoUserToken` + `dsco_*` columns (reused)
- [x] **Verified live** — Alrakeen: 2 sites, 79 vehicles, 0 drivers/categories/geofences, 373 trips; login issues Sanctum token + 79 accessible vehicles (company UNIMAC)
- [x] **saudiX live (2026-07-21)** — `SAFEE_SAUDIX_*` in `.env`, config stub uncommented; `provider` col on users; `vehicleQuery()` provider-scoped. saudiX: 2 sites (Qiddiya, "Tracking + Weight Sensor"), 76 vehicles, 244 trips. IDs are huge (e.g. 132849103110) — no collision with Alrakeen's small IDs.
- [ ] **Note:** Alrakeen returns 0 geofences → AlertEngine `geofence_exit` + heatmap are no-ops there; raise with client

### Done (session 8 — 2026-07-23, local admin auth + merged fleet)
- [x] **Local auth** — `AuthController::login` now checks `users` table with `Hash::check` (no Safee call). Removed provider from the login request + the `GET /api/auth/providers` route/method. `userPayload` returns `is_admin` + live `vehicles_count`.
- [x] **`is_admin` column** (migration `2026_07_23_000001_add_is_admin_to_users`) — `User::vehicleQuery()` returns ALL vehicles (all providers merged) for admins; otherwise provider+id scoped.
- [x] **Default admin `admin`/`admin`** seeded idempotently in `DatabaseSeeder` (`updateOrCreate`, `provider=null`, `is_admin=true`). Sees all 155 vehicles (79 alrakeen + 76 saudix).
- [x] **`provider` in vehicle payloads** (`formatVehicle` + `live`) → frontend provider badges.
- [x] **Both providers polled** — no-arg `safee:poll` (and scheduler) loops all providers; verified alrakeen+5/saudix+7 in one live run.
- [ ] **Follow-up:** replace `admin`/`admin` with a strong password + real user-management screen before any non-local deploy.

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
- [x] **Migrations + seeder** — `settings` and `setting_changelogs` tables; default settings seeded (incl. `unauthorized_zone_threshold`, `no_signal_threshold_hours`)
- [x] **AlertEngineService** — `over_speed`, `geofence_exit`, `low_fuel`, `idle`, `no_signal`, `zone_unauthorized` detection; cooldown per vehicle+type; geofence ray-casting PIP; runs after every `dsco:poll` cycle
  - **`idle`**: per-vehicle-type threshold — `idleThresholdFor(vehicle)` = `vehicle_type_idle_thresholds[type]` (minutes) ?? global `idle_threshold`. Managed via `GET/PUT /api/settings/idle-thresholds` (`SettingController`; routes registered before `settings/{key}`).
  - **`over_speed`** (site- & type-aware, 2026-08-07): `resolveSpeedLimit(vehicle,lat,lon)` picks the effective km/h limit by priority **site > type > default**. *Site*: if the vehicle is physically inside a geofence whose canonical `zone.site_id` points to a `sites` row with a non-null `speed_limit`, that limit applies to EVERY vehicle inside regardless of type (most restrictive site wins on overlap) — e.g. Qiddiya=50 flags a truck whose type limit is 120. *Type*: else `vehicle_type_speed_limits[type]` (km/h) if set. *Default*: else the global `speed_limit` setting. Meta adds `limit_source` (site|type|default) + `site_name` (site only). Managed via `GET/PUT /api/settings/speed-limits` (`SettingController`, returns `{default, types[], sites[]}`; PUT body `{types?:{TYPE:kmh|null}, sites?:{SITE_ID:kmh|null}}`; routes before `settings/{key}`). New models: `VehicleTypeSpeedLimit`; `sites.speed_limit` column. **Gotcha:** the site limit only fires once a zone is linked to the site via `zones.site_id` (set in the Zones admin UI) — most zones are currently unlinked, so assign Qiddiya's zones to the Qiddiya site before its 50 km/h limit takes effect.
  - **`zone_unauthorized`** (dwell-based, reworked 2026-08-03): a vehicle inside a geofence whose canonical `zone_id` is NOT in its assignment is tracked via a provisional `zone_presences` row (`entered_at`/`last_seen_at`) — **not** alerted immediately (passing through is fine). Promoted to a real alert only once dwell ≥ `unauthorized_zone_threshold` setting (default 15 min) OR the vehicle goes offline inside the zone. Meta: `zone_id`, `zone_name`, `entered_at`, `dwell_minutes`, `threshold`, `idle`, `offline`, `lat`, `lon`; the alert's dwell meta keeps refreshing while it stays. Skipped when: no assignment (unrestricted), or the zone is flagged `is_open` (open to all). Presence is deleted on leaving; the raised alert persists. `reconcileZonePresence()` runs per-fix in the poll; `sweepZonePresences()` runs on the full poll (via `runZonePresenceSweep`) to catch vehicles that went dark inside a zone and dropped out of the incremental poll.
  - **`no_signal`** (signal lost / restored): a `checkSignalState()` **sweep over ALL active vehicles** (not just ones that reported) runs on the **full poll (every 5 min)** — a dead tracker has no new fix so the per-fix rules never see it. State transition tracked via `Cache` key `signal_state_{id}`: `live → no_signal` opens a `no_signal` alert (`meta.last_seen` = last fix ISO, `meta.threshold_hours`); `no_signal → live` resolves the open alert (sets `resolved_at`) **and** stamps `meta.back_online=true` + `meta.restored_at` so the UI shows "back online". First observation seeds state silently (no alert burst for already-dark vehicles). **Threshold is admin-configurable in hours** via the `no_signal_threshold_hours` setting (default **24h**, group `alerts`; loaded in the constructor as `$noSignalThresholdMin`, floored at 5 min). Below it a vehicle is just "offline"; above it → "No signal" (with last-seen). Keep in sync with frontend `NO_SIGNAL_DEFAULT_HOURS` / runtime `setNoSignalThresholdHours()` + the `DashboardController` summary count (also reads the setting). **NB:** the old fixed `NO_SIGNAL_AFTER_MIN=30` const was renamed `ZONE_OFFLINE_AFTER_MIN` and now only powers the short "went quiet inside a zone" detector in `zone_unauthorized` — unrelated to the no-signal threshold.
- [x] **ContactController** (admin-only, 2026-08-06) — zone responsibles CRUD (`/api/contacts`, `/api/zones/{id}/contacts`); `all_zones` flag auto-covers future zones via `Zone::responsibleContacts()`. Notify-on-zone-issue wiring is pending.
- [x] **AlertController::resolve()** — `POST /api/alerts/{id}/resolve` persists `resolved_at` to DB
- [x] **`VehicleController::fuelOverview()`** — `GET /api/vehicles/fuel` returns latest fuel snapshot per vehicle via `DISTINCT ON (vehicle_id)` query
- [x] **`DscoUserAuthService::fetchAccessibleVehicleIds()` fallback** — if DSCO `vehicle/list-info` returns empty (API outage or permission issue), falls back to all `dsco_vehicle_id` values in the local `vehicles` table. Prevents the dashboard showing zero vehicles when DSCO is unreachable.

### Done (session 11 — 2026-08-24, saudiX decommissioned)
- [x] **Removed saudiX entirely** (client dropped the partner). Deleted `services.safee.providers.saudix` + `SAFEE_SAUDIX_*` env; `config:clear`+`cache:clear` → poller only loops `alrakeen` (401 spam stopped). Deleted all data in one transaction: 129 vehicles (CASCADE → 51,767 positions, 9,458 speed logs, 1,537 weight logs, 2,240 trips, 22 zone-changelogs), 865 alerts (FK was SET NULL), 6 provider_sites, the `Cedars` login user (id 3) + token. 0 orphans; **80 alrakeen vehicles remain**. Frontend: removed `saudiex-logo.png`, `ProviderBadge` saudix entry, narrowed `Provider` type to `'alrakeen'`. Provider-agnostic layer untouched — a new partner is a config-only add. Historical migration files still reference saudix (correct as history; not edited).

### Done (session 11 — 2026-08-24, Vehicle delete + data-integrity)
- [x] **Duplicate-tracker detection** — `GET /api/vehicles/device-conflicts` (`VehicleController@deviceConflicts`): groups the caller's vehicles by `device_imei` (the globally-unique hardware id) and returns any IMEI shared by 2+ vehicles. Scoped via `vehicleQuery()`. No DB constraint is added (a device swap creates a brief legitimate overlap — a hard UNIQUE would crash the poller); this is a display-only flag. Surfaced on the Vehicles page (banner + red "Duplicate device" badge).
- [x] **Delete a vehicle** — `DELETE /api/vehicles/{id}` (`VehicleController@destroy`). Guards: **manager-only** (403 otherwise); **no-data-only** — rejects with 422 unless the vehicle has NO valid position rows (`vehicle_positions` where `lat != 0`), mirroring the frontend `!vehicle.position` rule (a live/stale "No signal" vehicle still has old data → NOT deletable); scoped via `vehicleQuery()` (404 if not visible). In a transaction it: deletes the vehicle (CASCADE wipes positions/trips/speed-weight-fuel logs/driver-assignments/zone links/presence), **deletes its alerts** (their FK is SET NULL by default; removed for a clean delete), and **keeps saved reports** as historical snapshots (their `vehicle_id` FK is SET NULL). Not reversible.
- [x] **Block-list so the poller can't re-create it** — migration `2026_08_24_000001_create_vehicle_blocklist_table` (`vehicle_blocklist`: id, provider, dsco_vehicle_id, plate_no, blocked_by→users, unique(provider,dsco_vehicle_id)). `destroy` inserts the deleted `(provider, dsco_vehicle_id)`; `SafeePollCommand::syncVehicles` loads the provider's block-list up front and `continue`s past any row whose `id` is block-listed, so a deleted vehicle is never re-imported from the provider API. (Without this, the next successful poll would re-insert it as a fresh empty record.)
- **FK on-delete map (for reference):** CASCADE — vehicle_positions, vehicle_events, vehicle_fuel_logs, vehicle_speed_logs, vehicle_weight_logs, trips, vehicle_driver_assignments, vehicle_zone, zone_presences, vehicle_zone_changelogs; SET NULL — alerts, reports.

### Done (session 11 — 2026-08-23, AI Insights + Reports BUILT — Phase 3, no LLM)
- [x] **Deterministic "AI Insights" engine (no Claude API, $0 recurring).** The customer sees "AI" but nothing is sent off-server — the `claude` config stays **unused**. Client requirement: show AI, no recurring third-party cost. Fuel deliberately excluded this version.
- [x] **`FleetMetricsService`** (`app/Services/`) — shared aggregation from an already-scoped vehicle-id set + date range. `summary` / `perVehicle` (incl. `used` flag) / `zoneActivity` (via `vehicle_zone` pivot) / `idleRanking` / `unusedVehicles`. Reads `trips` (distance metres→km, idle/driving seconds→min, avg/max_speed km/h) + `alerts`. `SAFETY_ALERT_TYPES = over_speed/idle/zone_unauthorized/geofence_exit`. **This is the same trips-summing logic the dashboard uses — the report never calls the Safee API, it reads the local `trips` table.**
- [x] **`AnalyticsService`** — `healthScore` (0–100 = weighted safety 0.4 + utilization 0.3 + movement 0.3; weights from `settings` `health_weight_*`), `rankByRisk` (**per-VEHICLE** risk 0–100, Low≥80/Med60-79/High<60; `risk_weight_overspeed`/`risk_weight_idle` settings), `insights()` returns `{health, summary_line, kpis (vs previous equal window), insights[] narrative, sections{safety,utilization,zones,idle_ranking}, totals}`. **Graceful trend degradation:** if the previous window has 0 trips, `trend=null` + "first tracked period" (trip data has gaps, so this fires often).
- [x] **`AnalyticsController@insights`** `GET /api/analytics/insights?from&to&zone&type` — scoped via `vehicleQuery()`, applies zone (`whereHas zones`) + vehicle-type filters.
- [x] **`ReportController`** `GET/POST /api/reports`, `GET /api/reports/{id}` — reuse `Report` model; snapshot stored in `reports.content` (JSON, cast `array`). Migration `2026_08_23_000001_add_owner_to_reports` (created_by/title/period_from/period_to); list/show scoped by `created_by` (owner-only).
- [x] **Verified** — php -l all files, HTTP smoke of insights + reports store/list/show as a manager, zone/type filters. See frontend CLAUDE.md for the UI (AIReportsPage/ReportsPage un-blurred, shared `InsightsReport` component, PDF/Excel export). Plan: `docs/ai-reports-plan.md` (v2).
- **IMPORTANT data reality:** 0 Driver rows / 0 trips with `driver_id` → safety is **per-vehicle not per-driver** (auto-upgrades when driver data lands). `vehicle_zone` only ~5 rows → zone activity is sparse.
- **Deferred:** harsh-braking (no source column → safety = over_speed + idle only); admin UI for the health/risk weight settings; driver-level reporting; fuel pillar.

### Critical DB notes
- **DB timezone (`timestamptz`):** the pgsql connection forces `timezone => UTC` (`config/database.php`, override `DB_TIMEZONE`). Required so the time-series `time` column (`timestamptz`) stores true UTC instants. Without it the session inherits the server tz (dev machine = `Asia/Beirut`) and Laravel's naive datetime strings get reinterpreted, storing `time` −3h off. Only the 5 `time` columns are `timestamptz`; every other column is `timestamp` (without tz) and is unaffected. A one-time correction already fixed existing dev rows (`UPDATE … SET time = (time AT TIME ZONE 'Asia/Beirut') AT TIME ZONE 'UTC'`). **Production (fresh RDS) needs only the config — do NOT run that correction there** (data is written correct from the start, so it would double-shift).
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
- [ ] ~~Set up Claude API key in Laravel `.env` → Phase 3 reports~~ — **not needed**: AI Insights are computed on-server (deterministic, no LLM, no recurring cost). Only wire the `claude` config later if the client explicitly wants generative text (pay-per-use).

---

## 13. Deployment Target (Alibaba Cloud — requested from IT client)

Deployment infra was requested from the UNIMAC IT client (original email ~May 2026; deploy stage confirmed **2026-08-28**). **Nothing is provisioned yet** — these are the specs we asked IT to set up:

**Region — Saudi Arabia (Riyadh) `me-central-1`** for BOTH ECS + RDS (same region + same VPC). Chosen over UAE for data residency (Saudi PDPL — a Saudi client's fleet/GPS/driver data stays in-Kingdom), latency, and client optics. ⚠️ Riyadh is a **partner region** operated by SCCC (Saudi Cloud Computing Company) — onboarding/billing may go through the **SCCC / alibabacloud.sa** account, not the global alibabacloud.com console; and being a partner region, confirm the 4 vCPU/8 GB ECS family + RDS-for-PostgreSQL-with-PostGIS are actually offered there before committing. (UAE Dubai = `me-east-1`; only fall back to it if IT already has a UAE account and there's no residency requirement.)

**Server — Alibaba Cloud ECS** (hosts Laravel backend + APIs)
- Instance: 4 vCPU / 8 GB RAM
- OS: Ubuntu 22.04
- Storage: 100–200 GB SSD
- Billing: monthly

**Database — Alibaba Cloud RDS** (managed)
- Engine: PostgreSQL, **PostGIS** enabled
- Memory: 2–4 GB · Storage: 50–100 GB SSD
- Automatic backups on · same region as ECS
- Est. ~$30–50/month

**Account access:** full/super access requested for `hussein.m.hammoud.ct@gmail.com`.

**Also needed for prod:** a domain/subdomain (e.g. `fleet.unimac.sa`) + SSL cert; Google Maps API key tied to a billing-enabled project and restricted to the prod domain; production frontend URL added to `config/cors.php`.

> **⚠️ TimescaleDB vs PostGIS discrepancy — UNRESOLVED.** This doc assumes **TimescaleDB** for the
> time-series hypertables (§6, §11), but the RDS request to IT is **PostGIS-only**. Alibaba RDS for
> PostgreSQL may not offer the TimescaleDB extension. **Decide before provisioning:** either confirm
> TimescaleDB is available on Alibaba RDS, or accept plain PostgreSQL + PostGIS (the time-series tables
> already work as plain tables — TimescaleDB is an optimization, not a hard requirement; the
> `create_hypertable` step in "Still Needed" would then be skipped).

**Outdated in the original email:** it requested "DESCO/DSCO platform access" — obsolete, DSCO was retired and replaced by Safee (Alrakeen + saudiX); creds already in hand.

---

*Project: UNIFLEET | Client: UNIMAC | Dev: Cedars Technology*
*Memory source: Claude.ai project (fleet-ai-project-memory.md + Safee_Tracking_REST_Service.md + contract scope)*
