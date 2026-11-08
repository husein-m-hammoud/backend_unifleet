# Email draft — SSL certificate for `fleet.unimaccompany.com`

**To:** UNIMAC IT
**From:** Hussein Hammoud, Cedars Technology
**Drafted:** 2026-11-08
**Status:** draft, not sent

**Internal notes — delete before sending, these are not for IT:**

- Addressee unknown. Faysal is the contact who sent the Google Maps key (H1 in
  `DEPLOYMENT-APPROVAL-REQUEST.md`), so he may be the right recipient or can
  forward it. Replace `[Name]` before sending.
- The four questions map to H3 in `DEPLOYMENT-APPROVAL-REQUEST.md`. Question 1
  is the one that actually blocks us: the answer decides whether we install a
  certificate on nginx or configure `TRUSTED_PROXIES` for an upstream
  terminator. Both sides are already prepared.
- **The interim Let's Encrypt offer was removed on request (2026-11-08).** The
  email now asks only for their certificate and offers no alternative, so if
  their timeline slips there is nothing in the thread to fall back on — chasing
  them for a date (question 4) is the only lever left. Reinstating it later is
  fine; it was one paragraph.
- The RDP item at the end is unrelated to SSL but sits in the same security
  group, so it is cheap to raise in the same thread. Cut it if you would rather
  keep the email to a single subject.
- Deliberately **not** included: that ports 80/443 have been world-open since
  the instance was created, and that the dashboard has been publicly reachable
  since 8 Nov. That is our record to keep (`SERVER-OPS-LOG.md` §4.2), not a
  complaint to send IT — the rules came from Alibaba's default template, not
  from them, and raising it adds nothing to what we are asking for.

---

## Copy from here

**Subject:** UNIFLEET (fleet.unimaccompany.com) — four details we need for the SSL certificate

Hi [Name],

Thanks for confirming you'll be adding SSL for `fleet.unimaccompany.com`.

The platform is installed and running on the Riyadh ECS (`8.213.39.81`), and the
DNS record has been live since 3 November, so the certificate is the last step
before we can put the system in front of users. Until it's in place the dashboard
is served over plain HTTP, which means passwords cross the network unencrypted —
so we're holding off on creating any UNIMAC user accounts until TLS is live.

Four things we need from you:

**1. Where will the certificate terminate?** Either approach works for us, we
just need to know which one to build for:

- **(a) On the server** — send us the certificate, the private key and the
  intermediate/chain, and we'll install it on nginx on the ECS.
- **(b) In front of the server** — on an SLB/ALB, CDN or WAF, with TLS ending at
  that layer and traffic reaching the ECS over HTTP.

**2. If (b), two follow-ups:**

- Does the `fleet.unimaccompany.com` A record change, and if so, to what?
- Which source IP ranges should we then allow on ports 80 and 443 in the ECS
  security group? We'd restrict both to the proxy rather than leaving them open
  to the internet.

**3. Does the certificate cover the `fleet.` hostname?** A wildcard
`*.unimaccompany.com` covers it. One issued only for `unimaccompany.com` and
`www.unimaccompany.com` does not, and browsers would show a name-mismatch
warning on every visit. We just need confirmation of which it is before we
schedule the switch.

**4. What's the expiry date, who handles renewal, and roughly when can we expect
it?** We'll record the expiry on our side either way — we just don't want to
assume ownership of the renewal unless you'd prefer we took it.

Separately, one small item in the same security group: there's a default rule
allowing RDP (TCP 3389) from `0.0.0.0/0`. The server is Ubuntu and nothing is
listening on that port, so it isn't a live risk — it came in with Alibaba's
default template rather than from anything we requested. Worth removing while
you're in there.

Happy to get on a short call if that's quicker than email.

Thanks,

Hussein Hammoud
Cedars Technology

## Copy to here
