# UNIFLEET — Deployment Approval Request

**To:** UNIMAC IT
**From:** Hussein Hammoud, Cedars Technology
**Date:** 2026-10-29 (revised 2026-10-31)
**Target server:** ECS `i-l4vcygds2ltikyfva6ij` (`8.213.39.81`), VPC `192.168.0.197`,
Ubuntu 22.04, Saudi Arabia (Riyadh)
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
| B3 | Confirm automatic backups are enabled, then **perform one test restore** | An untested backup is not a backup |
| B4 | Run schema migrations (`php artisan migrate --force`) and seed reference settings | Creates the application tables |

**Correction: PostGIS is NOT required, and is no longer requested.** An earlier
draft of this document asked IT to run `CREATE EXTENSION postgis;` and described
it as required because "the platform stores vehicle positions, geofences and
zones as geometry". That was verified against the schema on 2026-10-31 and is
**incorrect**. The application uses no PostGIS whatsoever: there is no
`geometry` or `geography` column in any of the 51 migrations, no `CREATE
EXTENSION` statement, and no PostGIS function call anywhere in the codebase.
Coordinates are stored as ordinary `double precision` columns and zones are
logical groupings with no boundary geometry.

The platform therefore runs on **stock PostgreSQL with no extensions**. This
removes a request rather than adding one, and removes a dependency that could
have constrained the PostgreSQL version.

## C. DNS and TLS

| # | Change | Purpose |
| --- | --- | --- |
| C1 | ~~A record `fleet.unimaccompany.com` → `8.213.39.81`~~ — **COMPLETED 2026-11-03 by UNIMAC IT.** Verified by external DNS lookup. No further action. | IT action — UNIMAC controls the domain |
| C2 | TLS certificate for `fleet.unimaccompany.com` | Installed on this server either way. Option 1: we issue free Let's Encrypt on the box, auto-renewing (needs C1 live and A2 open) — no IT action. Option 2: IT supplies a certificate and private key and we install it, with IT tracking renewal. IT's choice. |
| C3 | Force HTTPS; HTTP serves only the redirect | No application traffic in clear text |

## D. Software installed on the server

Distribution packages from the OS and vendor repositories, with **one
third-party repository**, disclosed and justified at D2 below. No third-party
agents, no control panels.

| # | Package | Purpose |
| --- | --- | --- |
| D1 | Nginx | Web server / TLS termination |
| D2 | **PHP 8.3** and FPM, with the `pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl` extensions — **from the `ppa:ondrej/php` repository** | Application runtime. See the note below. |
| D3 | Composer | PHP dependency install (`--no-dev --optimize-autoloader`) |
| D4 | Git | Deploying application code |
| D5 | Certbot | Only if IT chooses Let's Encrypt for C2 |
| D6 | PostgreSQL client tools | Migrations and diagnostics |

**D2 — why a third-party repository is required.** The application runs on
Laravel 13, which hard-requires PHP 8.3 or newer; Ubuntu 22.04 ships PHP 8.1 and
will never carry 8.3 in its own repositories. There are exactly two ways to meet
that requirement, and we are asking IT to approve the first:

1. **Install PHP 8.3 from `ppa:ondrej/php`** (requested). This is the standard
   source for current PHP on Ubuntu, maintained by the Debian PHP packaging
   maintainer, and is what the large majority of Ubuntu PHP deployments use. It
   supplies PHP packages only — no agents, no services, no kernel modules.
2. **Rebuild the instance on Ubuntu 24.04**, which carries PHP 8.3 in its main
   repository. Technically cleaner, but it means replacing the system disk of an
   instance IT has already provisioned, and it is not necessary to run the
   software.

We considered option 2 and chose option 1 as the lower-risk change. If IT would
rather avoid any third-party repository, option 2 is available and we are happy
to take that route instead — the decision is IT's, and the application is
identical either way.

**Node.js is no longer requested.** An earlier draft of this document listed it
for building the frontend. The frontend is now built on a developer workstation
and only the resulting static files are copied to the server, so the server
needs no JavaScript toolchain at all. This removes a package from the approved
set rather than adding one.

Application code lives under `/var/www/unifleet`, owned by a dedicated
non-root service account. No changes are made outside that directory, the Nginx
and PHP configuration, and the single crontab entry in E1.

## E. Scheduled processing

| # | Change | Purpose |
| --- | --- | --- |
| E1 | One crontab entry, running once per minute as the service account: `php artisan schedule:run` | Drives the GPS poller, the health watchdog and log pruning. **The platform collects no data without this line.** |

**Withdrawn: E2, a queue worker.** An earlier draft requested a systemd queue
worker. Verified against the code: nothing in the application dispatches a
queued job and no notification is queued, so every email and alert is sent
synchronously. No worker process is needed, and none will be installed.

## F. Outbound connections required

The server initiates these; none of them require an inbound port.

Revised 2026-11-05, and now **shorter than first requested**. IT asked that
these be "as specific as possible to minimise DMZ", so each entry below is the
exact host, and anything not currently in use has been removed rather than
reserved.

| # | Destination | Port | Purpose |
| --- | --- | --- | --- |
| F1 | `fms.dimensions-systems.com` | 443 | GPS provider API — the only active provider |
| F2 | `unimaccompany-com.mail.protection.outlook.com` | 25 | Outbound email via UNIMAC's own Microsoft 365 (see section H) |
| F3 | `github.com` | 443 | Deploying application code |
| F4 | `archive.ubuntu.com`, `security.ubuntu.com`, `ppa.launchpadcontent.net`, `keyserver.ubuntu.com` | 80/443 | OS and PHP security updates |

**Removed from the original request:**

- `tk.alrakeen.sa` — the Alrakeen provider is paused; only one GPS provider is
  active. If it resumes we will request it then.
- **Twilio** — SMS alerting is not implemented. It is not a dependency of the
  application and needs no rule.
- **Alibaba DirectMail / third-party mail** — replaced by UNIMAC's own
  Microsoft 365. See section H.

**Not required, worth stating explicitly:** Google Maps is loaded by the
*user's browser*, not by this server, so it needs no outbound rule here.

## G. External monitoring

| # | Change | Purpose |
| --- | --- | --- |
| G1 | An external uptime monitor polling `https://fleet.unimaccompany.com/api/health` every 1–5 minutes. **Service: Better Stack (free tier), hosted by them, not on any UNIMAC system. IT access: yes — we add your address to the account, so you see the same checks, alert history and uptime record we do.** If 443 is restricted to named IPs, the monitor's published IP ranges must be allowed, or it cannot reach the endpoint. | The endpoint returns HTTP 503 when the platform is unhealthy, so the monitor raises the alarm. It must run **outside** this server — that is the whole point: it keeps working when the server is the problem. In September 2026 the data pipeline was dead for 14 days with nothing to report it. The endpoint exposes a pass/fail status only, no fleet or personal data. |

## H. Items needed from UNIMAC IT

| # | Item | Why |
| --- | --- | --- |
| H1 | **Google Maps API key** — the one Faysal sent by email | The live map and all geofence rendering depend on it. Required: a billing-enabled Google Cloud project, and the key **restricted to `fleet.unimaccompany.com`** rather than unrestricted. An unrestricted key can be copied from the page source and billed to UNIMAC by anyone. |
| H2 | RDS connection endpoint, port, engine version and the application credentials | To configure the application |
| H3 | IT's choice on C2 (Let's Encrypt or IT-supplied certificate) and on A3 (restricted SSH or bastion/VPN) | Both are IT's call, not ours |
| H4 | **Permission to send email through UNIMAC's Microsoft 365**, so account-setup and password-reset emails come from `no-reply@unimaccompany.com`. Preferred: an **Exchange Online connector authorising this server's IP `8.213.39.81`** to relay — no mailbox, no licence and no password stored on the server. Alternative: a licensed mailbox with SMTP AUTH enabled. | `unimaccompany.com` publishes `v=spf1 include:spf.protection.outlook.com -all` with `DMARC p=quarantine`. Any outside mail provider sending as your domain therefore fails SPF and is quarantined by your own policy, so credential emails would land in spam. Sending through your M365 needs no DNS change — your SPF, DKIM and DMARC are already correct. |

## I. Security commitments

- Credentials are stored in a file readable only by the service account, never in
  source control.
- `APP_DEBUG` is off in production — stack traces and configuration values are
  never shown to users.
- The default seeded administrator password is replaced before the platform is
  made reachable.
- User passwords are stored as one-way bcrypt hashes (bcrypt, cost 12).
- API session tokens expire after 12 hours.
- Login and password-reset endpoints are rate limited, so neither can be used
  for password guessing or to trigger bulk email.
- **Stated precisely, because two of these are not yet true:**
  - **TLS:** all traffic will be over HTTPS **once a certificate is in place
    (C2)**. If the platform is brought up on the IP address before then, that
    interim period is plain HTTP. We will not create accounts for UNIMAC staff
    until TLS is live, so no UNIMAC password is ever sent in clear text.
  - **Provider credentials** are currently stored in a configuration file
    readable only by the service account, **not yet encrypted at rest.**
    Application-level encryption of them is planned and not yet implemented. An
    earlier draft of this document claimed otherwise; that was incorrect and is
    corrected here.
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
