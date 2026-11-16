# Guide for UNIMAC IT — creating the Google Maps API key

**Drafted:** 2026-11-16 · **Status:** draft, not sent
**Companion to:** `IT-EMAIL-FOLLOWUP.md` item 2 — send this as the detailed
version, either pasted below that item or attached.

**Internal notes — delete before sending:**

- Console wording drifts. Every label below was right as of late 2026, but if a
  button has been renamed the step is still findable from the menu path given.
  Phrasing is deliberately loose where Google has been changing things.
- **Pricing is the one thing in here to re-check before sending.** Google moved
  Maps Platform off the flat $200/month credit to per-product free monthly
  allowances in 2025, and has adjusted the tiers since. The numbers in §7 are
  stated as approximate and the guide tells them to confirm on Google's page —
  keep it that way rather than quoting a figure as fact.
- The key is **not** a secret (it ships in the page source of every site that
  uses it). Said twice on purpose: IT people often try to handle it as a
  credential, which leads to them refusing to email it and nothing moving for a
  week.
- What we actually need back is in §8. Everything else is their side.

---

## Copy from here

# Setting up the Google Maps key for UNIFLEET

This is the detailed version of item 2. It's written click-by-click so it can be
handed to whoever ends up doing it.

**What we need back at the end:** one line of text — the API key. It looks like
`AIzaSy...` and is about 39 characters.

**Time:** about 30 minutes, most of it the billing form. Nothing to install, and
nothing to do on the server.

**Who should do it:** someone who can put a company payment method on a Google
Cloud billing account, or who can sit with whoever can.

---

## 1. First — why this is on your side and not ours

The map in UNIFLEET is Google's. Google requires every site that shows one of
their maps to identify itself with an API key tied to a billing account, and
they bill the owner of that account for usage.

Right now UNIFLEET is running on **our** key, so your fleet map is billed to us.
That was fine for getting the system up, but the key should belong to UNIMAC —
whoever owns the project is the only one who can rotate the key, raise its
limits, or see what it costs. If it stays with us, you're dependent on us for
something you should control.

**To answer your question: yes, it should be a UNIMAC account rather than an
individual's.** Not for licensing reasons — see §2 — but because an API key
created on someone's personal Google account disappears with that person. When
they leave, nobody can get into the project, the billing is attached to their
card, and the first time the key needs changing the map stops working with no
way in. Same reasoning as any other company-owned credential.

---

## 2. Step 1 — decide which account owns it

**This does not require Google Workspace, and it does not affect your Microsoft
365 email in any way.** Google Cloud is a separate product with its own free
sign-up. Nothing below changes your mail, your DNS (except one optional TXT
record in route B), or anything else you currently run.

Two ways to do it. Either is fine by us — pick on how much you want to manage.

### Route A — quickest (about 5 minutes)

Create a Google account **using a UNIMAC email address**. Google accepts
non-Gmail addresses, so `unimaccompany.com` works directly; you are not creating
a Gmail mailbox, just a Google login that happens to use your address.

1. Go to **accounts.google.com/signup**
2. Choose **"Use my existing email instead"** (the link is below the username
   field — Google moves it around, but it is always on that step)
3. Enter a **shared** UNIMAC address, e.g. `gcp-admin@unimaccompany.com` or
   `it@unimaccompany.com`
4. Google emails a verification code to that address — enter it

**Please use a shared address, not a person's.** All the billing notices, quota
warnings and recovery mail go there. In a personal mailbox it becomes invisible
to everyone else the moment that person is on leave or leaves the company.

### Route B — properly IT-managed (about 45 minutes, including DNS)

**Cloud Identity Free** gives you a real Google Cloud *organisation* for
`unimaccompany.com`: projects are owned by the company rather than by a user
account, and you can add and remove admins centrally. No licence cost, up to 50
users, and no mail service — it will not conflict with M365.

1. Go to **workspace.google.com/gcpidentity/signup** (Cloud Identity Free)
2. Enter company details and `unimaccompany.com` as the domain
3. Verify the domain by adding the **TXT record** Google gives you to
   `unimaccompany.com` DNS — this is the only DNS change involved, and it does
   not affect mail routing
4. Once verified, go to **console.cloud.google.com** and sign in with the admin
   account you just created

Route B is the better long-term answer if you want this managed like your other
infrastructure. Route A gets the map onto your billing today and can be migrated
into an organisation later.

---

## 3. Step 2 — create the project

A "project" is just a container for the key and its usage.

1. Go to **console.cloud.google.com**
2. First time in, it will ask you to accept the terms and pick a country —
   choose **Saudi Arabia**
3. At the top of the page, click the **project selector** (the dropdown next to
   "Google Cloud", which may say "Select a project")
4. Click **New Project**
5. **Project name:** `UNIFLEET` — or anything recognisable; it's only a label
6. **Location / Organisation:** on route A this will say *No organisation*,
   which is correct. On route B, pick `unimaccompany.com`
7. Click **Create**, then make sure the project selector at the top now shows
   that project before continuing — it's easy to do the rest in the wrong one

---

## 4. Step 3 — enable billing on the project

Google will not serve a working map without billing enabled, even when usage is
inside the free allowance (see §7 — the expected cost here is small or nil).
This is the step that needs someone with a company card.

1. Open the menu (**☰**, top left) → **Billing**
2. If there is no billing account yet: **Manage billing accounts** → **Create
   account**
3. Give it a name (e.g. `UNIMAC`) and set the **country to Saudi Arabia**

   ⚠️ **The country cannot be changed afterwards** — it fixes the currency and
   the tax treatment. Getting it wrong means creating a second billing account
   later.

4. **Account type: Business** (not Individual), then the company name, address
   and VAT details if asked
5. Add the payment method — a company card. Google may place a small temporary
   authorisation on it to verify; it is refunded
6. Back in **Billing**, confirm the `UNIFLEET` project is **linked** to that
   billing account. If it shows "This project has no billing account", click
   **Link a billing account** and pick it

New Google Cloud accounts are usually offered a free trial credit as well, which
you can accept or skip — the map works either way.

---

## 5. Step 4 — enable the two APIs

**Both are required.** The second one is the one people forget, and the symptom
is subtle: the map still works, but every screen that should name a location
shows raw GPS coordinates instead.

1. Menu (**☰**) → **APIs & Services** → **Library**
2. Search for **"Maps JavaScript API"**, open it, click **Enable**
3. Go back to **Library**, search for **"Geocoding API"**, open it, click
   **Enable**

| API | What breaks without it |
|---|---|
| **Maps JavaScript API** | The live map, the heatmap and every vehicle-location view render blank |
| **Geocoding API** | Place names across the alerts, trips and vehicle screens fall back to raw latitude/longitude |

Please don't enable anything beyond these two. Each extra API is another thing
that can be billed if the key ever leaks.

---

## 6. Step 5 — create the key, then restrict it

### Create it

1. Menu (**☰**) → **APIs & Services** → **Credentials**
2. **+ Create credentials** → **API key**
3. Google shows the key in a dialog. **Copy it** — you can always read it again
   later from this same page, so don't worry if you lose the dialog

At this point the key works but is **unrestricted**, which means anyone who
visits UNIFLEET can copy it out of the page source and run their own usage on
your billing. The next part is what prevents that, and it's the most important
step in this guide.

### Restrict it to our site

4. On the **Credentials** page, click the key's name to open it
5. Under **Application restrictions**, select **Websites**
6. Under **Website restrictions**, click **Add** and enter these — **both of
   them**:

   ```
   http://fleet.unimaccompany.com/*
   https://fleet.unimaccompany.com/*
   ```

   Both, because the site is on **HTTP** until the certificate is installed
   (item 3 of the email). With only the `https://` entry the map breaks today;
   with only the `http://` entry it breaks the day SSL goes in. Adding both now
   means nobody has to remember to come back.

   *If your console only accepts a hostname without the `http://` prefix, enter
   `fleet.unimaccompany.com/*` — newer versions of the page match both schemes
   from a single entry. Either form is fine.*

7. Under **API restrictions**, select **Restrict key**, and in the dropdown tick
   exactly:
   - Maps JavaScript API
   - Geocoding API
8. Click **Save**

Google notes that restriction changes can take **up to 5 minutes** to take
effect, so if you test immediately and see an error, wait and retry before
assuming something is wrong.

---

## 7. Step 6 — optional but recommended: a budget alert

Takes two minutes and means you find out about unexpected usage by email rather
than on the invoice.

1. Menu (**☰**) → **Billing** → **Budgets & alerts**
2. **Create budget**, scope it to the `UNIFLEET` project
3. Set a monthly amount — something like **SAR 200** is far above what this
   should ever use, so if it ever triggers, something is genuinely wrong
4. Keep the default alert thresholds (50% / 90% / 100%) and add your own address
   as a recipient

**On cost, honestly:** Google gives a free monthly allowance per product —
currently in the region of 10,000 map loads a month, though they have changed
the structure twice recently, so please take the current terms from
**mapsplatform.google.com/pricing** rather than from me. UNIFLEET is used by a
small number of internal users on a single site, so we'd expect usage to sit
inside the free allowance or just over it, i.e. nil to a few dollars a month.
It is not a system that generates public traffic. The budget alert is there so
that if that assumption is ever wrong, you hear about it early.

---

## 8. Step 7 — send it to us

Email us:

1. **The key** — the `AIzaSy...` string
2. **Confirmation that both website entries were added** (the `http://` and the
   `https://` one)
3. **Confirmation that both APIs are enabled** (Maps JavaScript + Geocoding)

**The key is not a password, and email is the right way to send it.** An API key
for a web map is designed to be public — it is visible in the page source of
every site that uses one, including ours today, and Google's own documentation
treats it that way. What makes it safe is the website restriction in §6, not
secrecy. So please don't hold it back looking for a secure channel; there's
nothing to protect in transit.

### What we do with it

The key is compiled into the UNIFLEET site when we build it, so it isn't
something that can be dropped into a config file on the server — there's no file
there to edit. We put it into the build, rebuild and redeploy. About five
minutes, no downtime, and nothing for you to do.

Once yours is live we'll disable ours, and from that point the map runs entirely
on UNIMAC's account.

---

## 9. If something doesn't look right

| What you see | Almost always means |
|---|---|
| Map loads but is **greyed out with "For development purposes only"** watermarked across it | Billing isn't enabled on the project, or the project isn't linked to the billing account (§4) |
| **Blank grey area** where the map should be | Maps JavaScript API not enabled (§5), or the key is restricted to a hostname that doesn't match (§6) |
| Map works, but locations show as **numbers like `24.71035, 46.67528`** | Geocoding API not enabled (§5), or it was left out of the API restrictions list (§6, step 7) |
| Worked, then stopped after a change | Restriction changes take up to 5 minutes — wait, then retry |

Send us a screenshot of anything unexpected and we'll tell you which step it
points at — the failure modes above are distinctive enough that we can usually
identify it from the picture.

## Copy to here
