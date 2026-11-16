# Email draft — platform live + follow-up on SMTP, Maps key and SSL

**To:** UNIMAC IT
**From:** Hussein Hammoud, Cedars Technology
**Drafted:** 2026-11-09 (rewritten to the content Hussein specified)
**Status:** draft, not sent
**Supersedes:** `IT-EMAIL-SSL.md` (SSL-only draft, 2026-11-08) — **never sent**, so
this is the first written contact with IT about SSL.

**Internal notes — delete before sending:**

- Placeholders: **`[Name]`**, **`[username]`**, **`[password]`**.
- **The account must be `admin`, not `support`.** The email tells them they can
  create users, and only owner/admin can. A read-only support account cannot.
- **The Maps section now answers his "should it be a corporate account?"
  question** (yes — and that it needs no Workspace licence), and adds two things
  the earlier version was missing: the **Geocoding API** must be enabled too, or
  every place name in the app falls back to raw lat/lon; and the referrer
  restriction needs **both** the `http://` and `https://` entries, or the map
  breaks either now or the day the certificate lands. It also states plainly
  that IT cannot apply the key themselves — it is compiled into the bundle at
  build time, so they send it and we redeploy.
- **The Maps section changed meaning.** Production is currently running on
  **your own** Google Maps API key, so UNIMAC's map usage is billed to you right
  now. The ask is therefore for *their* key on *their* billing-enabled project —
  not merely to restrict the existing one. Worth chasing: every week they take is
  a week of your card paying for their fleet map.
- **You asked for the password to be in the email, so it is in**, with the
  change-on-login line you wanted. Noting once without labouring it: that
  password crosses the network in clear at every login until TLS lands, and now
  also sits in two mailboxes. The change-on-login instruction is what limits the
  damage — keep that line.
- Still open but deliberately **not** in this email, to keep it to your points:
  the world-open RDP rule (TCP 3389) on the security group. Add it as a footnote
  if you want it raised now.

---

## Copy from here

**Subject:** UNIFLEET is live — please take a look, and three things we still need

Hi [Name],

**UNIFLEET is live.** You can open it now at **http://fleet.unimaccompany.com**

- **Username:** `[username]`
- **Password:** `[password]` — please change this once you log in (menu under your
  name, top right → Change Password)

It's running on the Riyadh server, pulling live data for all 218 vehicles into the
RDS database. The dashboard, live map, alerts, fuel, reports and analytics pages
are all working against real fleet data — not samples.

**What I'd like from you:** please go through the site and tell me what you think.
Anything that looks wrong, missing or confusing is useful at this stage. You can
also create users yourself if you want to add your own people — just note that
**no emails will be delivered yet** (see item 1), so you'd need to pass new
passwords on manually until that's sorted.

Your browser will warn the site is "Not secure". That's expected until the
certificate is in, which is item 3.

---

**1. Email sending (SMTP)**

UNIFLEET cannot send email at all right now. Everything it generates — new-account
setup, password resets, scheduled report digests, and the alerts that warn us the
data pipeline has stopped — is written to a log file and reaches nobody.

What we need is **permission to relay through UNIMAC's Microsoft 365**, so mail
comes from `no-reply@unimaccompany.com`. Preferred: an **Exchange Online connector
authorising this server's IP `8.213.39.81`** — no mailbox, no licence, and no
password stored on the server. Alternative: a licensed mailbox with SMTP AUTH.

It does have to be your M365 rather than an outside service.
`unimaccompany.com` publishes `v=spf1 include:spf.protection.outlook.com -all`
with `DMARC p=quarantine`, so any third-party sender using your domain fails SPF
and gets quarantined by your own policy — password-reset emails would go straight
to spam. Relaying through M365 needs no DNS change at all; your SPF, DKIM and
DMARC records are already correct.

**2. Google Maps API key**

The map is currently running on **our own Google Maps API key** as a temporary
measure, which means UNIMAC's map usage is being billed to us. That was fine to
get the system up, but it shouldn't stay that way.

To answer your question: **yes, this should sit on a UNIMAC account rather than
an individual's.** Not for licensing reasons — Google Cloud is free to sign up
for and this needs no Google Workspace subscription — but because whoever owns
the project owns the billing and is the only one who can rotate the key or raise
quota later. If it's created on a personal Google account and that person moves
on, nobody can get back into it and the map eventually stops. Two ways to do it,
both fine by us:

- **Quickest:** sign up at `console.cloud.google.com` using a UNIMAC address —
  Google accepts non-Gmail addresses, so `unimaccompany.com` works directly, and
  it doesn't affect your Microsoft 365 mail in any way. Worth using a shared
  address rather than one person's, so the billing and verification mail isn't
  sitting in a single inbox.
- **If you'd rather manage it properly:** **Cloud Identity Free** (no licence
  cost) plus a DNS TXT record to verify `unimaccompany.com` gives you a real
  Google Cloud organisation, with the project owned by the company rather than
  by a user account. More setup, but it's the version that survives staff
  changes.

Whichever you pick, what the key needs:

- **Billing enabled** on the project — without it Google serves the map degraded
  and watermarked rather than failing outright, so it's easy to miss. Usage at
  our scale is small, but a monthly budget alert on the billing account is worth
  setting while you're in there.
- **Two APIs enabled: "Maps JavaScript API" and "Geocoding API".** The second is
  easy to forget and isn't optional — it's what turns coordinates into place
  names across the alerts, trips and vehicle screens. Without it those screens
  fall back to showing raw latitude/longitude.
- The key **restricted to our hostname** (Credentials → the key → Application
  restrictions → Websites). This one matters: an unrestricted key can be lifted
  straight out of the page source by anyone who visits the site, and billed to
  your project. Please add **both** of these entries:
  - `http://fleet.unimaccompany.com/*`
  - `https://fleet.unimaccompany.com/*`

  Both, because the site is on HTTP until the certificate lands (item 3). With
  only the HTTPS entry the map breaks now; with only the HTTP entry it breaks the
  day SSL goes in.
- Also under **API restrictions**, limit the key to those same two APIs, so it
  can't be used against anything else on the project.

Send me the key and I'll swap it in — it's compiled into the site when we build
it, so it's a one-line change and a redeploy on our side, about five minutes.
There's nothing for you to install or configure on the server. And to be clear
the key isn't a password — it's visible in the page source of any site that uses
it, which is exactly why the hostname restriction above is the thing that
protects it. Email is fine.

Once yours is live I'll disable ours.

**I've written the whole thing up click-by-click** — account setup, the billing
form, the two APIs, the restriction, and what the failure modes look like if a
step gets missed — so it can be handed to whoever ends up doing it. It's
attached. About 30 minutes end to end.

**3. SSL / HTTPS — how would you like to do this?**

Thanks for confirming you'll be adding SSL. I just need to know the mechanics,
because the two options need different things from us:

- **You send us the certificate** — the certificate file, the private key and the
  intermediate/chain. We install it on nginx on the server, and HTTP becomes a
  redirect to HTTPS. Nothing else changes.
- **You terminate it at your end** — on a load balancer, CDN or WAF in front of
  the server. In that case let me know whether the `fleet.unimaccompany.com` DNS
  record changes and which addresses should then be allowed to reach the server,
  and I'll adjust our side to sit behind it.

Either is fine by us. Two things to check whichever way you go:

- **Does the certificate cover `fleet.unimaccompany.com`?** A wildcard
  `*.unimaccompany.com` does. One issued only for `unimaccompany.com` and
  `www.unimaccompany.com` does **not**, and browsers would warn on every visit.
- **Who tracks the expiry and renews it?** Happy to take that on if you'd prefer
  — I just don't want to assume it.

**4. Optional — a "coming soon" page in the meantime**

If you'd rather the site weren't publicly visible until SSL and email are
finished, we can put a simple holding page on the address so nobody outside can
see it or log in, while you and I keep full access. About fifteen minutes to set
up, and removed the day the certificate goes in.

Worth saying clearly though: **the data collection is already running and is
unaffected either way.** The system has been recording positions, trips and alerts
continuously, and that history keeps building whether or not a holding page is up.
That's the part I'd most like you to look at now — the live data, and whether it
matches what you'd expect from your fleet.

Your call, happy either way.

Thanks,

Hussein Hammoud
Cedars Technology

## Copy to here
