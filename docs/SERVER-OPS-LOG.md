# UNIFLEET — Server Operations & Change Log

**System:** UNIFLEET fleet analytics platform (Laravel API + React dashboard)
**Built by:** Cedars Technology for UNIMAC
**Owner of record:** Hussein Hammoud
**Document purpose:** audit and compliance record of every change made to
UNIMAC-managed infrastructure (Alibaba Cloud ECS / RDS), and the approval trail
for each one.
**Last updated:** 2026-10-31

---

## 1. Current status

**No changes have been made to any UNIMAC-managed server to date.**
An ECS instance has been provisioned by UNIMAC IT (see section 2) and read-only
console inspection has been performed, but nothing on it has been installed,
configured or modified, and no RDS instance exists yet. All development to date
has taken place on the developer's local machine. The change log in section 5 is
therefore empty by design, not by omission.

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
| RDS instance | `pgm-l4v1rlvsu471844x` — name `FleetAI`, **PostgreSQL 18.0**, Regular Instance, Running |
| RDS network | VPC `vpc-l4vo9dyye94qqyua8m0zx`, Riyadh Zone A |
| RDS billing | Subscription with auto-renewal, created 2026-09-30, expires 2027-09-30 |
| RDS internal endpoint | TO CONFIRM |
| RDS whitelist | TO CONFIRM — must allow only `192.168.0.197` |
| RDS backups | TO CONFIRM — automatic backups + one test restore |
| Operating system | Ubuntu 22.04 LTS x64 (image `ubuntu_22_04_x64_20G_alibase_20260810.vhd`) |
| System disk | ~20 GiB (from the image above) |
| Data disk | `d-l4vcygds2ltikyftravx` — 200 GiB Enterprise SSD (ESSD) PL0, 4200 IOPS, In Use, Subscription to 2027-09-30, **Release Disk with Instance**, no snapshot policy |

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

## 4. Ports that will be requested (not yet open, not yet approved)

Listed here so IT can review the full intended surface in advance. Each one
still requires explicit approval before it is enabled.

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

## 5. Change log

| Date | Change | Requested by | Approved by | Rollback |
| --- | --- | --- | --- | --- |
| — | No server changes to date | — | — | — |

<!-- Append new rows above this line. Newest last. One row per change. -->
