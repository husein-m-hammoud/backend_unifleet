# UNIFLEET — Server Operations & Change Log

**System:** UNIFLEET fleet analytics platform (Laravel API + React dashboard)
**Built by:** Cedars Technology for UNIMAC
**Owner of record:** Hussein Hammoud
**Document purpose:** audit and compliance record of every change made to
UNIMAC-managed infrastructure (Alibaba Cloud ECS / RDS), and the approval trail
for each one.
**Last updated:** 2026-11-08

---

## 1. Current status

**The platform is installed and running, and is publicly reachable over
plaintext HTTP.** As of 2026-11-08 the ECS runs PHP 8.3 / nginx serving the SPA
and API, the RDS schema is migrated and seeded, and the poller and scheduler are
live. Inbound 80 and 443 have been open to `0.0.0.0/0` since the instance was
created on 2026-09-30 (section 4.2), and `fleet.unimaccompany.com` has resolved
to the box since 2026-11-03 — so the dashboard is serving the public internet
without TLS. Issuing the certificate is the next action. Every change made to
date is recorded in section 5.

*(This paragraph previously read "No changes have been made to any
UNIMAC-managed server to date." It was left stale as the change log filled up,
and was corrected on 2026-11-08.)*

## 2. Infrastructure inventory

Recorded from the Alibaba Cloud console on 2026-10-29.

| Item | Value |
| --- | --- |
| ECS instance ID | `i-l4vcygds2ltikyfva6ij` |
| Instance name | `FleetAI` |
| Instance type | `ecs.hfc6.xlarge` — 4 vCPU / 8 GiB |
| Public bandwidth | 5 Mbps |
| Network type | VPC |
| VPC | `vpc-l4vo9dyye94qqyua8m0zx` — **same VPC as the RDS instance, confirmed 2026-10-31** |
| vSwitch | `vsw-l4vx61t964z2rqto2pzuy` |
| ECS auto-renewal | **Manual renewal — NOT auto-renewing** (see risk note below) |
| Public IP | `8.213.39.81` — auto-assigned (not an Elastic IP) |
| Private IP (VPC) | `192.168.0.197` |
| Domain | `fleet.unimaccompany.com` (DNS managed by UNIMAC IT) |
| Billing method | Subscription |
| Region | Saudi Arabia (Riyadh) `me-central-1` — confirmed |
| Subscription expiry | 2027-09-30 18:59:59 |
| RDS instance | `pgm-l4v1rlvsu471844x` — name `FleetAI`, **PostgreSQL 18.6** (console reported 18.0; the running server reports 18.6), Regular Instance, Running |
| RDS network | VPC `vpc-l4vo9dyye94qqyua8m0zx`, Riyadh Zone A |
| RDS billing | Subscription with auto-renewal, created 2026-09-30, expires 2027-09-30 |
| RDS internal endpoint | `pgm-l4v1rlvsu471844x.pgsql.me-central-1.rds.aliyuncs.com:5432` — confirmed by a live connection from the ECS 2026-11-08 |
| RDS whitelist | `192.168.0.197` (ECS private IP) only — set 2026-11-08 |
| RDS backups | TO CONFIRM — automatic backups + one test restore |
| Operating system | Ubuntu 22.04 LTS x64 (image `ubuntu_22_04_x64_20G_alibase_20260810.vhd`) |
| System disk | **50 GiB** (`vda3`, ext4, mounted `/`) — confirmed on the box 2026-11-05. The image name says `20G`, which is the image size, not the provisioned disk. |
| Data disk | `d-l4vcygds2ltikyftravx` — 200 GiB Enterprise SSD (ESSD) PL0, 4200 IOPS, In Use, Subscription to 2027-09-30, **Release Disk with Instance**, no snapshot policy. Formatted ext4 2026-11-06, label `unifleet-data`, UUID `d641b322-6d6f-46a8-88e8-d0c7f8510622`, mounted `/var/www`. **Oversized for the workload** (app uses ~10 GB; the database is on RDS, not here) but `Detachable: No` so it cannot be removed or refunded before the 2027-09-30 renewal — right-size it then. |

Confirmed from the console 2026-10-31. This closes the earlier open question
about system disk size: the 100–200 GB capacity request was satisfied by UNIMAC
IT as a **separate 200 GiB data disk**, not by enlarging the 20 GiB system disk.
No disk needs resizing.

Two consequences carried into `deploy/README.md`:
- The data disk is **raw** as delivered (no filesystem, not mounted), so the
  200 GiB is billed but unusable until it is formatted and mounted. It is
  mounted at `/var/www` in step 3, before any code is cloned.
- `Release Disk with Instance` plus `0 Policies` means nothing on that disk
  survives an instance release. A snapshot policy is a real gap, not just a
  score item.

Notes:
- **PostGIS is not required.** Verified against the schema 2026-10-31: no
  `geometry`/`geography` column in any of the 51 migrations, no `CREATE
  EXTENSION`, no PostGIS function call in the codebase. Coordinates are plain
  `double precision` and zones carry no boundary geometry. The platform runs on
  stock PostgreSQL with no extensions, which also closes the long-standing
  "TimescaleDB vs PostGIS" question — neither is needed. Section B of the
  approval request has been corrected accordingly.
- **ECS and RDS are in the same VPC** (`vpc-l4vo9dyye94qqyua8m0zx`), confirmed
  from both consoles 2026-10-31. Private connectivity is therefore possible; the
  remaining step is the RDS whitelist entry for `192.168.0.197`.
- ⚠️ **Compounding data-loss risk, worth fixing with three clicks.** The ECS
  subscription is on **manual renewal** while RDS is on auto-renewal, and both
  expire 2027-09-30. If the ECS renewal is missed: the instance is released, the
  200 GiB data disk follows it (set to `Release Disk with Instance`), and there
  is no copy because the disk has `0` snapshot policies. One missed notice takes
  the server, the application, `.env` and the logs. Mitigations, in order of
  effort: enable ECS auto-renewal, set a disk snapshot policy, and reconsider
  `Release Disk with Instance`. This may also account for the unexplained Cost
  Insight critical in ECS Insight, which flags subscription instances without
  auto-renewal.
- The public IP is **auto-assigned, not an EIP**. Acceptable for DNS because a
  Subscription VPC instance retains its public IP across a stop/start. A later
  conversion to an EIP retains the same address, so DNS is unaffected; the
  benefit of converting is being able to move the address to a replacement
  instance. Not done — requires approval.
- 5 Mbps is the public bandwidth cap (~600 KB/s aggregate). Adequate for the
  dashboard and API; may throttle concurrent PDF/Excel report downloads.
- Subscription expiry 2027-09-30 must be tracked — an unrenewed subscription
  stops the instance. Confirm the expiration action is "stop", not "release".
- System disk size is unconfirmed. The image reference is a 20 GB image and the
  original request to IT was 100-200 GB SSD; position history grows continuously,
  so the actual disk size and any attached data disk need checking.

## 3. Approval policy (effective 2026-10-29)

The following are **never** performed without prior written approval from the
system owner (Hussein Hammoud), who obtains client/IT approval where required:

| Class of change | Examples |
| --- | --- |
| Port / firewall enablement | opening any security-group or `ufw` port, changing SSH port, exposing a DB port |
| Network exposure | assigning a public IP / EIP, NAT, VPN or peering changes |
| DNS | adding or changing any record on a UNIMAC domain |
| Instance lifecycle | create, resize, stop, restart, rebuild or delete ECS/RDS |
| Credentials & access | creating OS or DB users, adding SSH keys, IAM/RAM policy changes |
| Data-affecting operations | running migrations on production, bulk deletes, restoring a backup |
| Third-party egress | enabling SMTP/DirectMail, SMS (Twilio), any new outbound integration |
| Encryption / TLS | issuing, installing or replacing certificates |

Routine, non-exposing work proceeds without per-item approval but is still
logged in section 5: application code deploys, log inspection, running
read-only diagnostics (`php artisan unifleet:doctor`, `GET /api/health`).

**Change record requirement:** every entry in section 5 states date, who
requested it, who approved it, what was changed, why, and how to roll it back.

## 4. Ports — the surface we intended to request

> **Superseded in part. See section 4.2.** This section was written on the
> assumption that no inbound port was open. That assumption was wrong: a default
> security group created with the instance on 2026-09-30 has had 80, 443, 3389
> and ICMP open to `0.0.0.0/0` the whole time. Section 4.2 records the actual
> state as read from the console on 2026-11-08. The table below is retained
> because it is still the surface we *want*; it is not a description of reality.

Listed here so IT can review the full intended surface in advance.

| Port | Protocol | Source | Purpose | Status |
| --- | --- | --- | --- | --- |
| 443 | TCP | 0.0.0.0/0 | HTTPS — the dashboard and API | Pending approval |
| 80 | TCP | 0.0.0.0/0 | HTTP — redirect to 443 + ACME/Let's Encrypt validation only | Pending approval |
| 22 | TCP | Named admin IPs only | SSH administration — restricted, never world-open | Pending approval |
| 5432 | TCP | ECS security group only | PostgreSQL — private VPC traffic only, never public | Pending approval |

Intentionally **not** requested: no public database port, no public Redis, no
FTP, no control-panel ports.

### 4.1 Interim HTTP-only phase — risk accepted by the owner of record

**Decision (2026-10-31, Hussein Hammoud):** the platform will be brought up on
the bare public IP `8.213.39.81` with ports 80 and 443 open to `0.0.0.0/0`,
before `fleet.unimaccompany.com` resolves and before a TLS certificate exists.

**Why this is a risk, recorded explicitly:** a publicly trusted certificate
cannot be issued for an IP address (Let's Encrypt will not issue for one), so
during this phase the dashboard is served over **plaintext HTTP**. Login
passwords and API bearer tokens therefore cross the public internet
unencrypted and are readable by anyone on the network path. No application-level
control can compensate for this; the rate limiting, token expiry and account
hardening added on 2026-10-31 all assume the transport is private.

**Conditions attached to the acceptance:**

1. **No UNIMAC end-user accounts are created during this phase.** Only the
   developer's own account exists, and its password is treated as disposable.
2. **Any credential typed into the site over HTTP is considered compromised**
   and is rotated once TLS is live — including the seeded owner password.
3. The phase ends as soon as DNS (C1) and the certificate (C2) are in place;
   HTTP is then reduced to a 301 redirect and ACME validation only.
4. **HSTS is not enabled until TLS is live**, as it would otherwise make the
   interim HTTP service unreachable from browsers that had seen the header.

This entry exists so the interim exposure is a recorded, time-boxed decision
with an owner, rather than an undocumented default.

### 4.2 Actual security-group state — read from the console 2026-11-08

The inbound rules below were **not created by us and were never requested**.
All five are marked `System created rule.` with an identical creation timestamp
of `Sep 30, 2026, 12:10:25` — the moment the instance was provisioned. They are
Alibaba's default security-group template, which is Windows-oriented (hence the
RDP rule). Every rule is priority 100.

| Priority | Protocol | Port | Source | Assessment |
| --- | --- | --- | --- | --- |
| 100 | TCP | 22 (SSH) | `80.83.29.67/32` | **Correct, and better than this log recorded.** Single-host restricted. |
| 100 | TCP | 443 (HTTPS) | `0.0.0.0/0` | Intended. Nothing listens on 443 yet (no certificate). |
| 100 | TCP | 80 (HTTP) | `0.0.0.0/0` | Intended for ACME + redirect. Currently serving the full application. |
| 100 | ICMP | All (-1/-1) | `0.0.0.0/0` | Benign. Keep — needed for `ping`-based monitoring. |
| 100 | TCP | 3389 (RDP) | `0.0.0.0/0` | **Should be deleted.** No RDP service on Ubuntu; pure attack surface. |

No inbound rule exists for 5432, which is correct — PostgreSQL is reached over
the VPC via the RDS whitelist (`192.168.0.197`), never from the internet.

**Three corrections to earlier entries in this document:**

1. **The claim "no inbound ports are open / nothing is publicly reachable",
   recorded twice against 2026-11-08 in section 5, is false.** Ports 80 and 443
   have been open to the world since instance creation. Combined with the DNS A
   record live since 2026-11-03 and the nginx site enabled on 2026-11-08, the
   dashboard and API have been **publicly reachable over plaintext HTTP at
   `http://fleet.unimaccompany.com` since 2026-11-08**. This was an unplanned
   exposure — the interim HTTP-only phase of section 4.1 went live without
   anyone deciding it had.
2. **The 2026-11-05 note "Port 22 remains open to 0.0.0.0/0 at the time of
   writing" is false.** SSH has been restricted to `80.83.29.67/32` since
   2026-09-30. The SSH hardening done on 2026-11-06 was therefore defence in
   depth on an already-restricted port, not a fix for a world-open one.
3. **The Let's Encrypt blocker recorded as unresolved does not currently
   exist.** Port 80 is open to `0.0.0.0/0`, so HTTP-01 validation will succeed
   today with no IT action and no DNS-01 fallback. This is a window, not a
   settled state: IT has expressed an intent to IP-restrict all ports including
   80 and 443, and if port 80 is narrowed, both issuance *and every 60-day
   renewal* break. Either 80 stays world-open for ACME, or renewal moves to
   DNS-01 and IT must supply a DNS API credential.

**Consequence for section 4.1's accepted risk.** Condition 2 of that acceptance
("any credential typed into the site over HTTP is considered compromised") is now
live rather than hypothetical. The seeded owner password has been exposed to
plaintext transport on a publicly reachable host and must be rotated once TLS is
in place. Condition 1 holds — no UNIMAC end-user accounts exist yet.

### 4.3 TLS — IT will supply it (stated 2026-11-08)

UNIMAC IT has said they will add "global SSL". This resolves the C2 choice in
`DEPLOYMENT-APPROVAL-REQUEST.md` in favour of an IT-supplied certificate, so
**Let's Encrypt is not being issued** — two renewal mechanisms with no clear
owner is worse than one. The open questions are recorded against H3 there.

**Until that certificate is live the plaintext exposure in section 4.2 continues,
and "will add" carries no date.** Two ways to close it in the meantime, both
requiring approval:

- **Restrict 80 and 443 to `80.83.29.67/32`** — one console change, no
  certificate needed, and it matches IT's own stated preference for
  IP-restricted ports. The platform has no end users yet, so the only cost is
  that nobody else can reach the site, including for a client demo.
- **Issue Let's Encrypt anyway as a stopgap** and replace it when IT's
  certificate arrives. Keeps the site publicly reachable and encrypted today.
  Requires port 80 to stay world-open for HTTP-01 validation.

**Decision (2026-11-08, Hussein Hammoud, owner of record): leave 80 and 443 open
and wait for IT's certificate.** Neither interim measure is taken. The platform
therefore continues to serve a plaintext login to the public internet until IT
delivers TLS, and the §4.1 interim-phase acceptance remains in force for that
whole period rather than the days it was originally scoped for.

What this decision does **not** change — the conditions attached to the §4.1
acceptance still bind:

1. **No UNIMAC end-user accounts until TLS is live.** Every password this
   platform issues is emailed and then typed into a plaintext login form. The
   existing guidance in `deploy/README.md` ("do not create user accounts until
   mail works") now has a second, independent reason behind it.
2. **Rotate the seeded owner password once TLS is live**, and treat anything
   typed into the site before then as compromised.
3. **Chase IT for a date.** The risk here is not the exposure itself so much as
   it being open-ended; "they will add it" with no date is how an interim phase
   becomes the deployment. If it slips beyond a few days, revisit the two
   options above — restricting 80/443 to `80.83.29.67/32` is a one-click change
   that can be made at any point.

Mitigations already in place, for the record: the login endpoint is rate-limited
(`AppServiceProvider::registerRateLimiters`), SSH is restricted to a single
address, and the only account on the system is the developer's. The residual
exposure is transport confidentiality, which no application-level control can
compensate for.

## 5. Change log

| Date | Change | Requested by | Approved by | Rollback |
| --- | --- | --- | --- | --- |
| 2026-11-03 | DNS A record `fleet.unimaccompany.com` -> `8.213.39.81` created | Cedars Technology (Hussein Hammoud) | UNIMAC IT | Delete the A record. Verified from outside: `dig +short fleet.unimaccompany.com` returns `8.213.39.81`. |

| 2026-11-05 | Imported existing SSH public key as ECS key pair `hussein-mac` (ed25519, fingerprint `SHA256:O+cXkp+Zsgu431dPiXGvTlqhdWF3K1/j1X8LQrqb4KA`) and bound it to instance `i-l4vcygds2ltikyfva6ij` | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Unbind the key pair in the ECS console. Public key imported rather than auto-generated, so no private key was ever downloaded or stored by Alibaba. |
| 2026-11-05 | Instance restarted to apply the key pair binding | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | None required - no data on the instance at the time. |
| 2026-11-05 | Root OS password reset via the ECS console, to apply key injection after the binding alone did not take effect | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Reset again from the console. NOTE: this may re-enable SSH password authentication; `sshd -T` is being checked and `PasswordAuthentication no` restored if so. Port 22 remains open to 0.0.0.0/0 at the time of writing. |

| 2026-11-06 | **SSH hardened to keys-only.** Set `PasswordAuthentication no`, `PermitRootLogin prohibit-password`, `KbdInteractiveAuthentication no` in `/etc/ssh/sshd_config`; validated with `sshd -t` and reloaded. Effective values confirmed via `sshd -T`. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Restore `/etc/ssh/sshd_config.bak.2026-11-06` and `systemctl reload ssh`. The Alibaba browser console is not SSH and still accepts the OS root password, so console access is unaffected either way. |

| 2026-11-06 | Formatted the 200 GiB data disk `/dev/vdb` as ext4 (label `unifleet-data`, **UUID `d641b322-6d6f-46a8-88e8-d0c7f8510622`**) and mounted it at `/var/www` via `/etc/fstab` with `nofail`. Verified: 196G, 186G available. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Remove the `/etc/fstab` line and `umount /var/www`. The disk was raw and empty before this - no data existed to lose, and the format was guarded to abort if any filesystem signature was present. |

| 2026-11-07 | Installed the admin SSH public key into `/root/.ssh/authorized_keys` (mode 600, `.ssh` 700). Key-based login verified from a new session. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Remove the key line from `/root/.ssh/authorized_keys`. Console (VNC) access with the OS root password remains available independently of SSH. |

| 2026-11-07 | Installed application runtime: **PHP 8.3.36** (`php8.3-fpm`, `-cli`, `-pgsql`, `-mbstring`, `-xml`, `-curl`, `-zip`, `-bcmath`, `-intl`) from `ppa:ondrej/php`, plus **nginx 1.18.0**, `git 2.34.1`, `unzip`, `postgresql-client 14.24`. php8.3-fpm and nginx both active. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | `apt remove` the packages and `add-apt-repository --remove ppa:ondrej/php`. Nothing outside the packages' own paths was modified. |

| 2026-11-08 | **RDS prepared and schema created.** Whitelisted ECS private IP `192.168.0.197`; created privileged account `unifleet_app` and database `unifleet` (UTF8); ran `php artisan migrate --force` (41 tables) and `db:seed --force` (21 settings, 1 owner account). Verified: PostgreSQL **18.6**, endpoint `pgm-l4v1rlvsu471844x.pgsql.me-central-1.rds.aliyuncs.com:5432`. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Drop the `unifleet` database and the `unifleet_app` account; remove `192.168.0.197` from the whitelist. The database was empty beforehand - no data existed to lose. |

| 2026-11-08 | **Platform brought up.** Installed the PHP-FPM pool (runs as `unifleet`) and the nginx site; `nginx -t` passed. Ran the first full provider poll (218 vehicles, 215 positions, 2m08s). Installed the scheduler crontab for `unifleet` (`* * * * * php artisan schedule:run`). `GET /api/health` returns JSON with only `ops_alerts` failing. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | `crontab -r -u unifleet` stops all polling; remove `/etc/nginx/sites-enabled/unifleet` and `/etc/php/8.3/fpm/pool.d/unifleet.conf` and reload both services to take the site down. No ports are open to the internet yet, so nothing is publicly reachable. **[CORRECTED 2026-11-08 — this was wrong; 80/443 were open. See section 4.2.]** |

| 2026-11-08 | **Install complete.** Cached config/routes/views/events; granted the service account a single sudo rule (`systemctl reload php8.3-fpm`); built the dashboard on a workstation and rsynced it to `/var/www/unifleet/frontend`; relaxed directory traversal so nginx can read static files. Verified locally: homepage 200, SPA deep link 200, `/api/health` 503 with only `ops_alerts` failing. `unifleet:doctor` green on 14 of 15 checks. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Each step is individually reversible; see `deploy/README.md`. Nothing is publicly reachable - no inbound ports are open. **[CORRECTED 2026-11-08 — this was wrong; 80/443 were open. See section 4.2.]** |

| 2026-11-08 | System timezone changed from `Asia/Shanghai` (the Alibaba image default, UTC+8) to **`Asia/Riyadh`** (UTC+3); `cron` and `php8.3-fpm` restarted to pick it up. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | `timedatectl set-timezone Asia/Shanghai`. No application behaviour depends on this: Laravel stores in UTC via `config('app.timezone')`, the pgsql connection stays UTC, and `routes/console.php` sets `->timezone('Asia/Riyadh')` explicitly for report schedules. Only log and filesystem timestamps change. |

| 2026-11-09 | **Application deploy — self-service password change + `support` role.** Backend `3845fe9`, `2cc6814`, `b850832` deployed via `deploy/deploy.sh` (composer `--no-dev`, `config:cache`, `route:cache`, `view:cache`, `event:cache`, php8.3-fpm reload). Frontend bundle `index-BZurUkbB.js` rsynced to `/var/www/unifleet/frontend`. Adds `POST /api/auth/password` (any role changes its own password) and a read-only `support` role for the UNIMAC helpdesk; also adds the inert `TRUSTED_PROXIES` config key for a future upstream TLS terminator. **No migrations — no schema or data change.** Verified after deploy: `route:list` shows the new endpoint, server HEAD matches `origin/main`, `unifleet:doctor` green on 13 of 15 (the two exceptions are the pre-existing `Mail: log` warning and `Ops alerting: none` failure, both unrelated to this deploy and both blocked on H4/SMTP). | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | `git revert` the three commits, re-run `deploy/deploy.sh`, and re-push the previous frontend bundle. Nothing to undo in the database. The `support` role is a plain string value in `users.role`, so reverting the code simply stops anything honouring it — demote or deactivate any support account first. |

| 2026-11-09 | Frontend rsync performed as **root** (`SSH_USER=root`) because the admin SSH key exists only in `/root/.ssh/authorized_keys`; the `ecs-user` account the deploy script defaults to has no key. Ownership of `/var/www/unifleet/frontend` normalised back to `unifleet:unifleet` afterwards. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | None needed — static assets only, no service config touched. **Follow-up, not yet done:** set `ecs-user` up properly for frontend deploys (install the admin key for it, add it to the `unifleet` group, `g+w` on the frontend directory) so static deploys stop needing root. That is a credentials/access change and needs its own approval. |

<!-- Append new rows above this line. Newest last. One row per change. -->
