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
| 2026-11-03 | DNS A record `fleet.unimaccompany.com` -> `8.213.39.81` created | Cedars Technology (Hussein Hammoud) | UNIMAC IT | Delete the A record. Verified from outside: `dig +short fleet.unimaccompany.com` returns `8.213.39.81`. |

| 2026-11-05 | Imported existing SSH public key as ECS key pair `hussein-mac` (ed25519, fingerprint `SHA256:O+cXkp+Zsgu431dPiXGvTlqhdWF3K1/j1X8LQrqb4KA`) and bound it to instance `i-l4vcygds2ltikyfva6ij` | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Unbind the key pair in the ECS console. Public key imported rather than auto-generated, so no private key was ever downloaded or stored by Alibaba. |
| 2026-11-05 | Instance restarted to apply the key pair binding | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | None required - no data on the instance at the time. |
| 2026-11-05 | Root OS password reset via the ECS console, to apply key injection after the binding alone did not take effect | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Reset again from the console. NOTE: this may re-enable SSH password authentication; `sshd -T` is being checked and `PasswordAuthentication no` restored if so. Port 22 remains open to 0.0.0.0/0 at the time of writing. |

| 2026-11-06 | **SSH hardened to keys-only.** Set `PasswordAuthentication no`, `PermitRootLogin prohibit-password`, `KbdInteractiveAuthentication no` in `/etc/ssh/sshd_config`; validated with `sshd -t` and reloaded. Effective values confirmed via `sshd -T`. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Restore `/etc/ssh/sshd_config.bak.2026-11-06` and `systemctl reload ssh`. The Alibaba browser console is not SSH and still accepts the OS root password, so console access is unaffected either way. |

| 2026-11-06 | Formatted the 200 GiB data disk `/dev/vdb` as ext4 (label `unifleet-data`, **UUID `d641b322-6d6f-46a8-88e8-d0c7f8510622`**) and mounted it at `/var/www` via `/etc/fstab` with `nofail`. Verified: 196G, 186G available. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Remove the `/etc/fstab` line and `umount /var/www`. The disk was raw and empty before this - no data existed to lose, and the format was guarded to abort if any filesystem signature was present. |

| 2026-11-07 | Installed the admin SSH public key into `/root/.ssh/authorized_keys` (mode 600, `.ssh` 700). Key-based login verified from a new session. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Remove the key line from `/root/.ssh/authorized_keys`. Console (VNC) access with the OS root password remains available independently of SSH. |

| 2026-11-07 | Installed application runtime: **PHP 8.3.36** (`php8.3-fpm`, `-cli`, `-pgsql`, `-mbstring`, `-xml`, `-curl`, `-zip`, `-bcmath`, `-intl`) from `ppa:ondrej/php`, plus **nginx 1.18.0**, `git 2.34.1`, `unzip`, `postgresql-client 14.24`. php8.3-fpm and nginx both active. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | `apt remove` the packages and `add-apt-repository --remove ppa:ondrej/php`. Nothing outside the packages' own paths was modified. |

| 2026-11-08 | **RDS prepared and schema created.** Whitelisted ECS private IP `192.168.0.197`; created privileged account `unifleet_app` and database `unifleet` (UTF8); ran `php artisan migrate --force` (41 tables) and `db:seed --force` (21 settings, 1 owner account). Verified: PostgreSQL **18.6**, endpoint `pgm-l4v1rlvsu471844x.pgsql.me-central-1.rds.aliyuncs.com:5432`. | Cedars Technology (Hussein Hammoud) | Hussein Hammoud (owner of record) | Drop the `unifleet` database and the `unifleet_app` account; remove `192.168.0.197` from the whitelist. The database was empty beforehand - no data existed to lose. |

<!-- Append new rows above this line. Newest last. One row per change. -->
