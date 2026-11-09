# Email draft — platform live + follow-up on SSL, Maps key and SMTP

**To:** UNIMAC IT
**From:** Hussein Hammoud, Cedars Technology
**Drafted:** 2026-11-09
**Status:** draft, not sent
**Supersedes:** `IT-EMAIL-SSL.md` (SSL-only draft, 2026-11-08). If that one was
already sent, cut section 2 down to "following up on my email of 8 November".

**Internal notes — delete before sending:**

- Replace `[Name]`, `[your number]` and the two `[…]` placeholders.
- **Do NOT put the password in this email.** The draft deliberately omits it and
  says it follows separately. Reason: the site has no TLS yet, so that password
  crosses the network in clear text at every login — putting it in an email as
  well means it is sitting in two mailboxes and a mail server indefinitely. Send
  it by WhatsApp or read it over the phone.
- **Decide what kind of account IT gets.** If they are only reviewing the system,
  give them a **support** (read-only) account: sees every page, every vehicle and
  every report, changes nothing. If they need to configure zones or create their
  own users, they need **admin**. The draft says "read-only" — change it if you
  give them admin. Note the Support option is currently hidden from the Add-user
  dropdown, so create it via the API or unhide it first.
- **Creating this account is the §4.1 condition-1 decision**, the one that said no
  UNIMAC end-user accounts before TLS. You are choosing to go ahead; the draft
  handles it by marking the password temporary and asking them to change it once
  HTTPS is live. They can now do that themselves from the user menu.
- The **coming-soon page** in section 5 is offered as a question, not announced.
  Technically it is an nginx change: serve a holding page to everyone except a
  short IP allowlist, with the real dashboard reachable from their office and
  ours. It needs no certificate and it is the one measure that actually reduces
  the plaintext-login exposure while TLS is pending. It also needs your approval
  and an ops-log row before I touch the server.
- The RDP item is carried over from the superseded draft. Cut it if you would
  rather keep this email to three topics.

---

## Copy from here

**Subject:** UNIFLEET is live — and four things we need from IT

Hi [Name],

**The platform is live.** UNIFLEET is running on the Riyadh ECS at
`fleet.unimaccompany.com`, pulling live data for all 218 vehicles, with the
database on the RDS instance. The dashboard, live map, alerts, fuel view and the
reporting and analytics pages are all working against real fleet data.

I'd like to get you into it so you can see where we are — login details are at the
end of this email.

Four things are outstanding on your side, and all four now block the same thing:
handing this over to UNIMAC's users.

---

**1. SSL / HTTPS — where are we?**

This is the one holding everything else up. Until the certificate is in place the
dashboard is served over plain HTTP, which means every password typed into it
crosses the network readable. That is why we have not created any UNIMAC user
accounts yet.

Four details we need before we can install it:

- **Where does the certificate terminate** — on the server (send us the
  certificate, the private key and the intermediate/chain and we'll install it on
  nginx), or in front of it on an SLB/CDN/WAF?
- **If in front:** does the `fleet.unimaccompany.com` A record change, and which
  source IPs should we then allow on ports 80 and 443?
- **Does the certificate cover the `fleet.` hostname?** A wildcard
  `*.unimaccompany.com` does; one issued only for `unimaccompany.com` and
  `www.unimaccompany.com` does not, and browsers would warn on every visit.
- **Expiry date, who renews it, and roughly when can we expect it?**

**2. Google Maps API key — it can now be locked down**

We have the key and the map is working, so nothing is blocked here. But the key
still needs restricting, and until last week it couldn't be: Google's Maps
JavaScript restrictions work on an HTTP referrer, which needs a real hostname,
and `fleet.unimaccompany.com` only started resolving on 3 November. That hostname
now exists, so the restriction can finally be applied.

Two things to confirm:

- **Restrict the key to `fleet.unimaccompany.com`** in the Google Cloud console
  (Credentials → the key → Application restrictions → Websites). An unrestricted
  key can be lifted straight out of the page source by anyone who visits the site
  and billed to UNIMAC's project.
- **Confirm billing is enabled** on the project the key belongs to. Without it
  Google serves the map watermarked and degraded rather than failing outright, so
  it is easy to miss.

**3. Sending email — the platform currently cannot**

Right now every email UNIFLEET generates is written to a log file and delivered to
nobody. That affects new-account setup emails, password resets, scheduled report
digests, and the alerts that tell us the data pipeline has stopped.

What we need is **permission to relay through UNIMAC's Microsoft 365**, so mail
comes from `no-reply@unimaccompany.com`. Preferred: an **Exchange Online connector
authorising this server's IP `8.213.39.81`** — no mailbox, no licence, and no
password stored on the server. Alternative: a licensed mailbox with SMTP AUTH
enabled.

To be clear about why it has to be your M365 and not a third-party sender:
`unimaccompany.com` publishes `v=spf1 include:spf.protection.outlook.com -all`
with `DMARC p=quarantine`. Any outside provider sending as your domain therefore
fails SPF and gets quarantined by your own policy — so password-reset emails would
land in users' spam folders. Relaying through M365 needs no DNS change at all;
your SPF, DKIM and DMARC records are already correct.

**4. Would you like a "coming soon" page in the meantime?**

A suggestion rather than a request. Since the address is publicly reachable but
the certificate and email aren't ready, we can put a simple holding page on
`fleet.unimaccompany.com` and keep the live dashboard reachable only from your
office and ours. Nobody outside would see a login form until everything is
finished.

It needs no certificate, takes about fifteen minutes, and is easy to remove the
day SSL goes in. Happy either way — tell me if you'd prefer it.

---

**Your login**

- **Address:** http://fleet.unimaccompany.com
- **Username:** `[username]`
- **Password:** I'll send this separately by WhatsApp rather than email.

The account is **read-only** — you'll see every page, every vehicle and every
report, and you won't be able to change anything by accident.

Two things worth knowing:

- Please treat the password as **temporary**. It will cross the network
  unencrypted until HTTPS is live, so once the certificate is in you should change
  it — there's a **Change Password** option in the menu under your name, top right.
- Your browser will warn that the site is "Not secure". That is expected until the
  certificate is installed, and it is exactly what item 1 above is about.

Happy to walk you through it on a call if that's easier than clicking around.

Thanks,

Hussein Hammoud
Cedars Technology

---

*One small separate item, same security group: there's a default rule allowing RDP
(TCP 3389) from `0.0.0.0/0`. The server is Ubuntu and nothing listens on that
port, so it isn't a live risk — it came in with Alibaba's default template rather
than from anything we requested. Worth removing while you're in there.*

## Copy to here
