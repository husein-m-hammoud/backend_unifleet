# Installing the AlphaSSL wildcard certificate

**Status:** waiting on files from UNIMAC IT (announced purchased 2026-11-25)
**Config prepared:** `deploy/nginx/unifleet-tls.conf`
**Log the change in:** `docs/SERVER-OPS-LOG.md` after applying

---

## 1. What we need from IT, exactly

Three things. A certificate on its own is not enough, and "the certificate" is
usually what gets sent first.

| # | File | Typical name | Why |
|---|---|---|---|
| 1 | **Leaf certificate** | `fleet_unimaccompany_com.crt`, `certificate.crt` | The certificate itself |
| 2 | **Intermediate / CA bundle** | `ca-bundle.crt`, `intermediate.crt` | AlphaSSL chains to GlobalSign. **Without it the site fails on Android and some corporate proxies while looking fine in desktop Chrome** — the single most common way this install half-works |
| 3 | **Private key** | `*.key`, `private.key` | nginx cannot use the certificate without it |

### The private key is the thing to chase

The key is generated at the same time as the CSR, and **whoever made the CSR has
it.** It is never sent by the certificate authority. So:

- If IT generated the CSR themselves → they have the key, on whichever machine
  they generated it on.
- If the reseller generated it for them → it is in the reseller portal, usually
  only as a **`.pfx` / `.p12` bundle with a password**. That is fine, see §2.
- If nobody can find it → the certificate is unusable and must be **reissued**
  against a new CSR. This is free and takes minutes with any CA, but it is
  better to find out now than after a maintenance window is booked.

### Ask them to confirm what the certificate covers

AlphaSSL *Wildcard* is normally issued for `*.unimaccompany.com`, which does
cover `fleet.unimaccompany.com`. Worth confirming, because:

- `*.unimaccompany.com` covers `fleet.unimaccompany.com` ✅
- A certificate issued only for `unimaccompany.com` + `www.` does **not** ❌
- A wildcard covers one level only: `a.unimaccompany.com` yes,
  `a.b.unimaccompany.com` no. Irrelevant for us, relevant if they reuse it.

### Flag to raise with them about the wildcard

**A `*.unimaccompany.com` private key is a company-wide key.** Copying it onto
an internet-facing application server means that if this box is ever
compromised, the attacker holds a valid certificate for *every* host on
`unimaccompany.com`, not just the fleet app. That is a normal, widely accepted
trade-off and not a reason to refuse — but it is IT's risk to accept knowingly,
not ours to assume on their behalf. If they would rather not, the alternative is
a single-name certificate for `fleet.unimaccompany.com` only. Raise it once, in
writing, and take their answer.

### Transfer

The certificate and intermediate are public — email is fine. **The private key
is not.** Ask for it over something other than plain email if convenient, or at
minimum as a password-protected `.pfx` with the password sent separately (on
WhatsApp rather than in the same mail). Delete the local copies afterwards.

---

## 2. If they send a .pfx / .p12

Converts to the two PEM files nginx wants. Run locally, not on the server:

```bash
# Private key (will prompt for the .pfx password, then set a temporary one)
openssl pkcs12 -in unimac.pfx -nocerts -out privkey-enc.pem
# Strip the passphrase, or nginx will prompt for it on every restart and
# fail to come up unattended after a reboot
openssl rsa -in privkey-enc.pem -out privkey.pem

# Leaf certificate
openssl pkcs12 -in unimac.pfx -clcerts -nokeys -out cert.pem

# Intermediate(s)
openssl pkcs12 -in unimac.pfx -cacerts -nokeys -chain -out intermediate.pem
```

---

## 3. Verify before touching the server

Everything here is local and read-only. Do it first — a mismatched key is the
other classic failure, and it is much nicer to find on a laptop.

```bash
# These two MUST print the same hash. If they differ, the key does not belong
# to the certificate and nothing below will work.
openssl x509 -noout -modulus -in cert.pem | openssl md5
openssl rsa  -noout -modulus -in privkey.pem | openssl md5

# What is it actually for, and when does it expire?
openssl x509 -in cert.pem -noout -subject -issuer -dates
openssl x509 -in cert.pem -noout -ext subjectAltName
```

The SAN line must contain `*.unimaccompany.com` or `fleet.unimaccompany.com`.
Note the `notAfter` date — see §6.

Build the chain file, **leaf first, intermediate second**:

```bash
cat cert.pem intermediate.pem > fullchain.pem
```

---

## 4. Install

Requires approval before running — see `docs/SERVER-OPS-LOG.md` §3. Inbound 443
is **already open** to `0.0.0.0/0` (log §5, port table), so no security-group
change is needed.

```bash
# Upload
scp fullchain.pem privkey.pem intermediate.pem ecs-user@8.213.39.81:/tmp/

# On the server
sudo mkdir -p /etc/ssl/unifleet
sudo mv /tmp/{fullchain,privkey,intermediate}.pem /etc/ssl/unifleet/
sudo chown root:root /etc/ssl/unifleet/*
sudo chmod 644 /etc/ssl/unifleet/fullchain.pem /etc/ssl/unifleet/intermediate.pem
sudo chmod 600 /etc/ssl/unifleet/privkey.pem

# Swap in the TLS config
sudo cp deploy/nginx/unifleet-tls.conf /etc/nginx/sites-available/unifleet
sudo nginx -t          # MUST pass before the reload
sudo systemctl reload nginx
```

`nginx -t` failing here is harmless — the running config is untouched until the
reload. That is the whole reason for the separate test step.

---

## 5. Verify after

```bash
# Chain complete? "Verify return code: 0 (ok)" is the line that matters.
openssl s_client -connect fleet.unimaccompany.com:443 \
  -servername fleet.unimaccompany.com </dev/null 2>/dev/null | grep -E "Verify return code|subject="

# Redirect working?
curl -sI http://fleet.unimaccompany.com | head -3     # expect 301 -> https
curl -sI https://fleet.unimaccompany.com | head -3    # expect 200

# App still healthy over TLS?
curl -s https://fleet.unimaccompany.com/api/health | head -20
```

Then **check it on an Android phone**, not just the laptop. Desktop browsers
carry cached intermediates and will happily show a green padlock on a chain that
is actually incomplete; Android will not. If Android fails and desktop passes,
the intermediate is missing or in the wrong order in `fullchain.pem`.

Rollback at any point:

```bash
sudo cp deploy/nginx/unifleet.conf /etc/nginx/sites-available/unifleet
sudo nginx -t && sudo systemctl reload nginx
```

That returns the site to HTTP-only in seconds. Nothing in the application or
database is touched by any of this.

---

## 6. After it works

- **The frontend needs no rebuild.** `src/lib/api.ts` calls the relative path
  `/api`, so it follows whatever scheme the page was loaded over.
- **Tell IT the expiry date** and agree in writing who renews it. This is a
  purchased certificate, so there is no certbot auto-renewal — somebody has to
  remember. Put the date in the ops log.
- **Leave HSTS off** for now. It is commented out in the config with the reason.
- Record the change in `docs/SERVER-OPS-LOG.md` with the rollback line above.
