# UNIFLEET — Server Operations & Change Log

**System:** UNIFLEET fleet analytics platform (Laravel API + React dashboard)
**Built by:** Cedars Technology for UNIMAC
**Owner of record:** Hussein Hammoud
**Document purpose:** audit and compliance record of every change made to
UNIMAC-managed infrastructure (Alibaba Cloud ECS / RDS), and the approval trail
for each one.
**Last updated:** 2026-10-29

---

## 1. Current status

**No changes have been made to any UNIMAC-managed server to date.**
As of 2026-10-29 no ECS instance or RDS instance has been provisioned, and all
development has taken place on the developer's local machine. The change log in
section 4 is therefore empty by design, not by omission.

## 2. Approval policy (effective 2026-10-29)

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
logged in section 4: application code deploys, log inspection, running
read-only diagnostics (`php artisan unifleet:doctor`, `GET /api/health`).

**Change record requirement:** every entry in section 4 states date, who
requested it, who approved it, what was changed, why, and how to roll it back.

## 3. Ports that will be requested (not yet open, not yet approved)

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

## 4. Change log

| Date | Change | Requested by | Approved by | Rollback |
| --- | --- | --- | --- | --- |
| — | No server changes to date | — | — | — |

<!-- Append new rows above this line. Newest last. One row per change. -->
