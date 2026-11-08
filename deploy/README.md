# UNIFLEET — deployment

Everything needed to stand the platform up on the UNIMAC ECS box, and to deploy
to it afterwards. Replaces the hand-typed checklist that used to live in the
project-root `TODO.md`.

**Target:** ECS `8.213.39.81`, Ubuntu 22.04, Saudi Arabia (Riyadh).
**Decided 2026-10-31:** stay on **22.04** and install **PHP 8.3 from the
`ondrej/php` PPA**. Laravel 13.4 hard-requires PHP `^8.3` and 22.04 ships 8.1,
so a newer PHP is mandatory; the OS version is not. Rebuilding to 24.04 (which
carries 8.3 in `main`) was considered and rejected as not worth an instance
rebuild and a lockout risk. The PPA is disclosed in
`docs/DEPLOYMENT-APPROVAL-REQUEST.md` section D.

## Files

| File | Runs where | Purpose |
| --- | --- | --- |
| `nginx/unifleet.conf` | server | Site config. SPA + API on one origin. |
| `php/unifleet-pool.conf` | server | PHP-FPM pool. Sets the user that must match the deploy account. |
| `crontab` | server | The scheduler line. Nothing polls without it. |
| `deploy.sh` | server | Routine backend deploy. Idempotent. |
| `push-frontend.sh` | workstation | Builds the SPA locally, rsyncs the output. |

The server has **no Node toolchain** by design — it is build-time-only software,
so it stays off an internet-facing box and out of the IT change set.

## Needs IT approval first

Per `docs/SERVER-OPS-LOG.md`, these are not ours to do unilaterally. Log each
one in section 5 when it happens.

1. Inbound 80 and 443 from `0.0.0.0/0`; **22 from named admin IPs only**.
2. **Close the world-open high-risk ports that already exist.** ECS Insight
   flags a security group allowing `0.0.0.0/0` to ports in the 22 / 3389 / 5432
   / 3306 / 6379 set. Combined with root SSH login this is the live risk on the
   box today, ahead of anything in this directory. Reducing exposure, but still
   a firewall change — tell IT and log it.
3. RDS: reachable from `192.168.0.197` only, an app database and app user (not
   master), `CREATE EXTENSION postgis;`, backups on, **one test restore**.
4. DNS `fleet.unimaccompany.com` → `8.213.39.81`, then the certificate.

Until (4), the site is HTTP-only on the bare IP. That is a recorded, accepted
risk with conditions attached — see `SERVER-OPS-LOG.md` section 4.1. The most
important condition: **do not create UNIMAC end-user accounts until TLS is
live**, and rotate anything typed over HTTP.

## First install

```bash
# 1. PHP 8.3 (22.04 ships 8.1)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring \
                    php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl
# pgsql is the database driver; mbstring/xml/curl/zip are required by the
# dependency tree; bcmath/intl are used by the analytics and report formatting.

# 2. Everything else
sudo apt install -y nginx git unzip postgresql-client

# Composer: NOT from apt. The Ubuntu package depends on php-cli, which can pull
# in PHP 8.1 alongside 8.3 and leave `php` pointing at the wrong version. Use
# the official installer, with its published hash verified before executing it.
cd /tmp
curl -sS https://getcomposer.org/installer -o composer-setup.php
HASH=$(curl -sS https://composer.github.io/installer.sig)
php -r "if (hash_file('sha384','composer-setup.php') === trim('$HASH')) { echo 'verified'.PHP_EOL; } else { unlink('composer-setup.php'); exit(1); }"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f composer-setup.php

# NOTE: the distro postgresql-client is 14, but RDS runs PostgreSQL 18. psql 14
# can query an 18 server, but pg_dump 14 REFUSES to dump it ("server version
# mismatch"). Install postgresql-client-18 from apt.postgresql.org before
# setting up database backups.

# 3. Mount the 200 GiB data disk at /var/www.
#
#    The box has TWO disks: a 50 GiB system disk (vda3, ext4, mounted /) and a
#    200 GiB ESSD data disk (vdb). Confirmed on the box 2026-11-05 - the image
#    name says 20G, which is the image size, not the provisioned disk.
#    (d-l4vcygds2ltikyftravx). IT delivered the capacity request as a separate
#    data disk rather than a larger system disk.
#
#    An Alibaba data disk arrives RAW - no partition, no filesystem, not
#    mounted. Until these steps are done the 200 GiB is billed but unusable,
#    and `df -h` shows only the system disk. The application, its logs and
#    storage/ all belong here, not on the 20 GiB system disk.
#
#    DO THIS BEFORE STEP 4. Mounting over a directory that already has files
#    HIDES them - clone first and the code vanishes the moment you mount.

lsblk                       # confirmed 2026-11-05: vdb is 200G with NO FSTYPE (raw)

#    Confirm the target is the empty 200 GiB disk and NOT the system disk.
#    mkfs ERASES it. If FSTYPE is already set, it is formatted - skip mkfs.
sudo mkfs.ext4 /dev/vdb

sudo mkdir -p /var/www
#    Mount by UUID, not /dev/vdb: device names can change across a reboot.
#    nofail so a missing disk cannot block boot into an unreachable box.
echo "UUID=$(sudo blkid -s UUID -o value /dev/vdb) /var/www ext4 defaults,nofail 0 2" \
  | sudo tee -a /etc/fstab
sudo mount -a
df -h /var/www              # must show ~200G before continuing

# 3b. Service account. Not root, and no login shell - it exists to own files and
#     run php-fpm, never to be logged into.
sudo adduser --system --group --home /var/www/unifleet --shell /usr/sbin/nologin unifleet
sudo mkdir -p /var/www/unifleet/{backend,frontend}
sudo chown -R unifleet:unifleet /var/www/unifleet

# 4. Code. Both repos are PRIVATE, so give the box a read-only deploy key per
#    repo (GitHub > repo > Settings > Deploy keys). Never put a personal token
#    or personal SSH key on a shared server.
#    -b main is NOT optional. The remote still carries a `master` branch holding
#    a single orphan "Initial commit", and GitHub's default branch was set to it
#    - so a plain `git clone` succeeds and produces an almost empty directory,
#    which then fails in confusing ways. State the branch explicitly.
sudo -u unifleet git clone -b main git@github-backend:husein-m-hammoud/backend_unifleet.git /var/www/unifleet/backend

# 5. Config
cd /var/www/unifleet/backend
sudo -u unifleet cp .env.example .env
sudo -u unifleet php artisan key:generate
sudo -u unifleet nano .env
```

`.env` must have, at minimum:

```
APP_ENV=production
APP_DEBUG=false          # doctor FAILS on debug in prod: it leaks stack traces and env values
APP_URL=http://fleet.unimaccompany.com
FRONTEND_URL=http://fleet.unimaccompany.com   # https:// once the cert is in
DB_CONNECTION=pgsql      # host/port/database/username/password from RDS
ADMIN_PASSWORD=          # leave EMPTY - the seeder generates one and prints it once
ADMIN_EMAIL=             # needed for password resets to reach you
LOG_STACK=daily
LOG_DAILY_DAYS=14
```

Leave `DB_TIMEZONE` alone — the pgsql connection must stay UTC.

**`FRONTEND_URL` is not cosmetic.** The new-account email's "Sign in" button and
every password-reset link are built from it. Left at the `.env.example`
localhost value, users are emailed links to their own machine — the mail sends,
looks right, and they cannot log in. `unifleet:doctor` fails on this in
production (`Frontend URL` check) rather than letting it through quietly.

**Do not create user accounts until mail works.** `MAIL_MAILER=log` writes
emails to `storage/logs/laravel.log` and sends nothing, and
`UserController::tryNotify()` swallows transport errors — so creating a user
reports success while the person receives no credentials at all. DNS is now
live, so Alibaba DirectMail is finally possible: it needs SPF/DKIM/DMARC on
`unimaccompany.com`, which is a DNS request to UNIMAC IT.

```bash
# 6. Schema + first account. The seeder prints a generated owner password ONCE;
#    store it in a password manager before you close the terminal.
sudo -u unifleet php artisan migrate --force
sudo -u unifleet php artisan db:seed --force
sudo -u unifleet php artisan storage:link

# 7. Services
sudo cp deploy/php/unifleet-pool.conf /etc/php/8.3/fpm/pool.d/unifleet.conf
sudo rm -f /etc/php/8.3/fpm/pool.d/www.conf
sudo systemctl restart php8.3-fpm

sudo cp deploy/nginx/unifleet.conf /etc/nginx/sites-available/unifleet
sudo ln -sf /etc/nginx/sites-available/unifleet /etc/nginx/sites-enabled/unifleet
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# 8. THE SCHEDULER. Without this one line nothing polls and nothing watches.
sudo crontab -u unifleet /var/www/unifleet/backend/deploy/crontab

# 9. Caches (last, so they capture the finished .env)
sudo -u unifleet php artisan config:cache route:cache view:cache event:cache

# 10. deploy.sh reloads php-fpm, so allow just that one command
echo 'unifleet ALL=(root) NOPASSWD: /bin/systemctl reload php8.3-fpm' \
  | sudo tee /etc/sudoers.d/unifleet-deploy
sudo chmod 440 /etc/sudoers.d/unifleet-deploy
```

Then, from your workstation:

```bash
cd ~/Desktop/my-project/unifleet/backend && ./deploy/push-frontend.sh
```

## Routine deploys

```bash
# backend, on the server
cd /var/www/unifleet/backend && ./deploy/deploy.sh

# frontend, from your workstation
./deploy/push-frontend.sh
```

`deploy.sh` puts the app in maintenance mode while it migrates. **Every route
returns 503 during that window, including `/api/health`, so the uptime monitor
will alert.** Expected during a deploy, not an incident.

## Verification

```bash
sudo -u unifleet php artisan unifleet:doctor     # exits non-zero on any failure
curl -i http://8.213.39.81/api/health            # from OFF the box: expect 200
```

- Log in, then open `/dashboard/system` as the owner and confirm Overview green.
- The `Scheduler` check must read `<1 min ago`. If it does not, step 8 is wrong.
- Expect two standing warnings until SMTP exists: `Mail: log` and
  `Ops alerting: none`. See below.

Optional on a first deploy: `DELETE FROM vehicle_positions WHERE lat = 0;`
(legacy junk). **Do not** run the historical timezone correction on a fresh
database — data is written correct from the start, so it would double-shift.

## Still open

- **No queue worker is needed.** Verified: nothing dispatches a job and no class
  in `app/Notifications/` implements `ShouldQueue`, so notifications send
  synchronously. Earlier checklists called for a supervisor unit; they were
  wrong. Revisit only if something starts queueing.
- **No ops alert channel.** `MAIL_MAILER=log`, so a dead pipeline is detected and
  logged but reaches nobody. DirectMail needs the domain first for SPF/DKIM, so
  until then **the external uptime monitor on `/api/health` IS the alert
  channel** — it returns 503 on failure, and it lives off the box, which is the
  part that still works when the box is the problem. Set it up; it is currently
  the only thing that would tell anyone.
- **No automatic disk snapshots.** ECS Insight flags this, but the real reason
  it matters is the data disk's `Release Disk with Instance` setting: if the
  instance is ever released, the 200 GiB disk goes with it, and `0 Policies`
  means there is no copy of anything on it. Set a policy once the app is
  installed. Note this is separate from RDS backups (section B) - RDS covers
  the database, a disk snapshot covers the app, `.env` and logs.
- **No CI.** `deploy.sh` is the whole pipeline.
