# UNIFLEET — Deployment Approval Request

**To:** UNIMAC IT
**From:** Hussein Hammoud, Cedars Technology
**Date:** 2026-10-29
**Target server:** ECS `i-l4vcygds2ltikyfva6ij` (`8.213.39.81`), VPC `192.168.0.197`
**Purpose:** list every change required to bring the UNIFLEET platform live, so
IT can review and approve the whole set in advance. Nothing in this document has
been done. Each item is performed only after approval, and each is recorded in
the server change log (`SERVER-OPS-LOG.md`) with date, approver and rollback.

---

## A. Inbound network access (security group)

| # | Port | Protocol | Source | Purpose |
| --- | --- | --- | --- | --- |
| A1 | 443 | TCP | 0.0.0.0/0 | HTTPS — the dashboard and API. The only port the public needs. |
| A2 | 80 | TCP | 0.0.0.0/0 | HTTP — 301 redirect to 443, and Let's Encrypt certificate validation. Serves no application content. |
| A3 | 22 | TCP | Named admin IP addresses only | SSH administration. **Requested restricted — not world-open.** If IT prefers, we will use a bastion/VPN instead and drop A3 entirely. |

**Not requested, and will not be opened:** the PostgreSQL port to the internet,
Redis, FTP, or any control-panel port.

## B. Database (RDS PostgreSQL)

| # | Change | Purpose |
| --- | --- | --- |
| B1 | RDS whitelist / security group to allow only the ECS private IP `192.168.0.197` | Database reachable from the application server only, never the internet |
| B2 | Create one application database and one application DB user (not the master user) | Least privilege |
| B3 | `CREATE EXTENSION postgis;` | Required — the platform stores vehicle positions, geofences and zones as geometry |
| B4 | Confirm automatic backups are enabled, then **perform one test restore** | An untested backup is not a backup |
| B5 | Run schema migrations (`php artisan migrate --force`) and seed reference settings | Creates the application tables |

## C. DNS and TLS

| # | Change | Purpose |
| --- | --- | --- |
| C1 | A record `fleet.unimac.sa` → `8.213.39.81` | Requested separately |
| C2 | TLS certificate for `fleet.unimac.sa` | Either Let's Encrypt issued on the box (needs A2 open) or a certificate IT supplies — IT's choice |
| C3 | Force HTTPS; HTTP serves only the redirect | No application traffic in clear text |

## D. Software installed on the server

Standard distribution packages from the OS and vendor repositories only. No
third-party agents, no control panels.

| # | Package | Purpose |
| --- | --- | --- |
| D1 | Nginx | Web server / TLS termination |
| D2 | PHP 8.2+ and FPM, with the `pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `gd` extensions | Application runtime |
| D3 | Composer | PHP dependency install (`--no-dev --optimize-autoloader`) |
| D4 | Node.js 20 | Builds the frontend assets; **build-time only, not a running service** |
| D5 | Git | Deploying application code |
| D6 | Certbot | Only if IT chooses Let's Encrypt for C2 |
| D7 | PostgreSQL client tools | Migrations and diagnostics |

Application code lives under `/var/www/unifleet`, owned by a dedicated
non-root service account. No changes are made outside that directory, the Nginx
and PHP configuration, and the single crontab entry in E1.

## E. Scheduled processing

| # | Change | Purpose |
| --- | --- | --- |
| E1 | One crontab entry, running once per minute as the service account: `php artisan schedule:run` | Drives the GPS poller, the health watchdog and log pruning. **The platform collects no data without this line.** |
| E2 | A queue worker under systemd, if required | Prevents background jobs queueing silently |

## F. Outbound connections required

The server initiates these; none of them require an inbound port.

| # | Destination | Purpose |
| --- | --- | --- |
| F1 | `https://tk.alrakeen.sa` | Alrakeen GPS provider API |
| F2 | `https://fms.dimensions-systems.com` | DSCO GPS provider API |
| F3 | Mail service (Alibaba DirectMail or equivalent), port 465/587 | Account-setup and password-reset emails, plus operational alerts |
| F4 | Twilio API | SMS alerts |
| F5 | OS and vendor package repositories | Security updates |

## G. External monitoring

| # | Change | Purpose |
| --- | --- | --- |
| G1 | An external uptime monitor polling `https://fleet.unimac.sa/api/health` every 1–5 minutes | The endpoint returns HTTP 503 when the platform is unhealthy, so the monitor raises the alarm. It must run **outside** this server — that is the whole point: it keeps working when the server is the problem. In September 2026 the data pipeline was dead for 14 days with nothing to report it. The endpoint exposes a pass/fail status only, no fleet or personal data. |

## H. Items needed from UNIMAC IT

| # | Item | Why |
| --- | --- | --- |
| H1 | **Google Maps API key** — the one Faysal sent by email | The live map and all geofence rendering depend on it. Required: a billing-enabled Google Cloud project, and the key **restricted to `fleet.unimac.sa`** rather than unrestricted. An unrestricted key can be copied from the page source and billed to UNIMAC by anyone. |
| H2 | RDS connection endpoint, port, engine version and the application credentials | To configure the application |
| H3 | Confirmation of the ECS region and subscription expiry date | Compliance record; an unrenewed subscription stops the server |
| H4 | Operating system and version on the ECS instance | Determines the package set in section D |
| H5 | IT's choice on C2 (Let's Encrypt or IT-supplied certificate) and on A3 (restricted SSH or bastion/VPN) | Both are IT's call, not ours |

## I. Security commitments

- Credentials are stored in a file readable only by the service account, never in
  source control.
- `APP_DEBUG` is off in production — stack traces and configuration values are
  never shown to users.
- The default seeded administrator password is replaced before the platform is
  made reachable.
- User passwords are stored as one-way bcrypt hashes; provider credentials are
  encrypted at rest; all traffic is over TLS.
- No production data is copied off the server.
- Every change is logged in `SERVER-OPS-LOG.md` and available to IT on request.

---

## Approval

Approving this document authorises sections A through G as listed. Any change
not listed here will be requested separately, in writing, before it is made.

| Section | Approved | Date | Name |
| --- | --- | --- | --- |
| A — inbound ports | | | |
| B — database | | | |
| C — DNS and TLS | | | |
| D — software | | | |
| E — scheduled processing | | | |
| F — outbound | | | |
| G — external monitoring | | | |
