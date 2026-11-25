# Sending mail through UNIMAC's Microsoft 365

**Status:** IT asked on 2026-11-25 what they need to do. Blocked on the port
test in §1 before we can tell them *which* of the two options to set up.
**Background and the final `.env` values:** `.env.example`, the Microsoft 365
section.

---

## 1. Do the port test FIRST — it decides everything

There are two ways in, and they use different ports:

- **Option B (relay connector)** — port **25**. No mailbox, no password, no
  licence. The one we want.
- **Option A (SMTP AUTH)** — port **587**. Needs a licensed mailbox and a
  password stored on the server.

**Alibaba Cloud blocks outbound TCP 25 by default on ECS instances**, and
unblocking it is a support request that is often refused. If 25 is blocked for
us, option B cannot work no matter what IT configures — so asking them to build
a connector before testing risks wasting a week of their time and ours.

Run this on the server before replying to IT:

```bash
# Option B path — port 25
timeout 5 bash -c 'cat < /dev/null > /dev/tcp/unimaccompany-com.mail.protection.outlook.com/25' \
  && echo "25 OPEN -> option B is available" \
  || echo "25 BLOCKED -> option A"

# Option A path — port 587
timeout 5 bash -c 'cat < /dev/null > /dev/tcp/smtp.office365.com/587' \
  && echo "587 OPEN" || echo "587 BLOCKED"
```

Expected on Alibaba Cloud: 25 blocked, 587 open. Hope to be wrong.

---

## 2. Option B — relay connector (preferred, if port 25 is open)

### What IT does

In the **Exchange admin center** (`admin.exchange.microsoft.com`):

1. **Mail flow → Connectors → Add a connector**
2. **Connection from:** `Your organization's email server`
   **Connection to:** `Office 365`
3. Name it, e.g. `UNIFLEET application server`
4. Authenticate by: **verifying that the IP address of the sending server
   matches one of these** → add **`8.213.39.81`**
5. Save

### What IT also needs to do in DNS

Add the server's IP to the SPF record. Current record is:

```
v=spf1 include:spf.protection.outlook.com -all
```

It becomes:

```
v=spf1 ip4:8.213.39.81 include:spf.protection.outlook.com -all
```

Microsoft's own setup guide for relay asks for this. It **does not weaken their
anti-spoofing** — the `-all` hard fail stays, and the only thing being added is
one server they own and control.

*(Correction to what we told them earlier: the first draft said relaying through
M365 needs no DNS change at all. That was wrong for this option.)*

### Our side

```
MAIL_MAILER=smtp
MAIL_HOST=unimaccompany-com.mail.protection.outlook.com
MAIL_PORT=25
MAIL_SCHEME=null
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="no-reply@unimaccompany.com"
```

Nothing secret in the file, which is the main operational advantage.

---

## 3. Option A — SMTP AUTH (fallback, if port 25 is blocked)

### What IT does

1. Create or pick a mailbox — `no-reply@unimaccompany.com`. **It needs a
   licence**; this is the real cost of this option.
2. **Enable SMTP AUTH for that mailbox** — Microsoft disables it tenant-wide by
   default. Microsoft 365 admin center → Users → that user → Mail → Manage email
   apps → tick **Authenticated SMTP**.
3. If **security defaults** or a Conditional Access policy requiring MFA is on,
   basic SMTP AUTH will be refused for that mailbox and needs an exclusion.
   This is the step that usually causes the "it just says 535 authentication
   failed" loop.
4. Send us the mailbox password.

### Our side

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_USERNAME=no-reply@unimaccompany.com
MAIL_PASSWORD=<the password>
MAIL_FROM_ADDRESS="no-reply@unimaccompany.com"
```

Worth stating plainly when asking: this puts a working mailbox password in a
file on an internet-facing server, which is exactly what option B avoids. If
they have a preference between a licence cost and a stored credential, that is
their call to make.

---

## 4. Either way

`MAIL_FROM_ADDRESS` must be an address the tenant is authorised to send as. An
unrecognised sender is the most common reason mail is accepted and then silently
spam-filtered.

Verify after switching:

```bash
php artisan unifleet:mail-test you@example.com
php artisan unifleet:doctor          # the Mail check should go green
```

Then test a real path end to end — a password reset to an external address, not
just an internal one. Internal delivery can succeed while external delivery
fails, and a password reset to a Gmail address is exactly the case that matters.

## 5. What this unblocks

Both of these are currently dead and have been since launch:

- **Account setup and password resets** — `UserController::tryNotify()` swallows
  transport errors, so creating a user reports success while the person never
  receives anything.
- **Ops alerting** — the watchdog that warns us the poller has stopped. It
  stayed silent through the 14-day outage in September; `unifleet:doctor` has
  been failing its `Ops alerting: none` check ever since.

The second is the one with teeth. Until mail works, a dead poller is invisible
until somebody happens to look at the dashboard.
