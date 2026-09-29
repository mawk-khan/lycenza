# Runbook: custom School domains (ADR 0054, OBS-28–30)

A School can serve its web pages on a hostname it owns, such as
`erp.northfield.org`. The application proves ownership (DNS TXT), routing (the
hostname points at the Lycenza edge) and TLS readiness (an IP-pinned probe);
the **deployment edge** issues and renews certificates and holds their private
keys. No edge, CDN, ACME or DNS vendor is chosen, and none of the deployment
evidence below exists yet (rule 16).

## What operators configure

| Setting | Meaning |
|---|---|
| `CUSTOM_DOMAINS_ENABLED` | `false` (default) is a complete, safe mode: nothing can be claimed or resolved; the Host boundary still applies. |
| `DOMAIN_EDGE_CNAME_TARGET` | The hostname Schools CNAME to (never an IP in code). |
| `DOMAIN_EDGE_ADDRESSES` | The edge's public IPv4/IPv6 set, for apex domains (A/AAAA or a provider ALIAS/ANAME). |
| `DOMAIN_PROBE_KEY` | Highly Sensitive (O4, `app_runtime`): the probe HMAC key. At least 32 characters; never `APP_KEY` or a service key. |
| `DOMAIN_DNS_RESOLVERS` | Public recursive resolver IPs. Never an internal split-horizon resolver. |
| `DOMAIN_PLATFORM_ALIASES` | Extra exact platform hostnames. |
| `INTERNAL_HOSTS` | The private hostname(s) the AI Gateway calls (`/api/internal/ai/*`). Required when the Gateway is configured. |
| `DOMAIN_RESERVED_HOSTS` / `DOMAIN_RESERVED_SUFFIXES` | Extra exact names / subtrees no School may claim. The APP_URL host's registrable domain, the aliases, the internal hosts and the edge target are always reserved. |
| `SESSION_HANDOFF_STORE` | The Redis cache store holding one-time cross-host sign-in tickets (default: the default store, which must be Redis in production). |

`SESSION_DOMAIN` must stay empty: every cookie is host-only.
`ProductionConfigurationGuard` refuses to boot on any violation (codes only).

## The lifecycle

`pending_verification → verified → tls_pending → active ⇄ suspended`, with
`revoked` and `expired` terminal. The database enforces every transition; no
one can "force activate".

1. A School administrator adds a hostname (fresh MFA) and publishes
   `_lycenza-verification.<hostname>  TXT  "lycenza-domain-verification=<token>"`
   within 24 hours. The record must **stay published**.
2. "Check now" or the scheduler verifies the TXT (→ `verified`), then that the
   hostname CNAMEs to the edge target or resolves only to the edge addresses
   (→ `tls_pending`).
3. **`tls_pending` is the edge's provisioning signal.** Deployment automation
   reads `php artisan platform:domains-edge-desired --json` and provisions a
   certificate and routing for exactly the listed hostnames (and removes any
   other custom hostname it holds).
4. The probe connects to an edge address with SNI = the hostname, requires a
   publicly trusted, valid, exact-name certificate on TLS 1.2+, and fetches
   `/.well-known/lycenza-domain-probe?n=<nonce>`, which must return
   `HMAC-SHA256(DOMAIN_PROBE_KEY, hostname|nonce)` (→ `active`).

## Operator commands (console only; no platform web UI)

- `platform:domains-edge-desired [--json]` — read-only desired edge state.
- `platform:domain-probe <hostname>` — run the checks for one domain now;
  prints the domain id, state and closed outcome codes.
- `platform:domain-revoke <hostname>` — terminal revocation (retype the
  hostname or `--force`), audited as `platform.school_domain.revoked` and in
  the School's audit log. Use it to unblock a disputed or stuck hostname.
- `platform:domains-check` — the scheduler's run (every minute; queues due
  checks, bounded by `DOMAIN_CHECKS_PER_RUN`).

## OBS-28 — a domain was suspended (SEV-3)

Suspension follows confirmed drift: the TXT value changed (2 results ≥ 1 h
apart), the TXT record disappeared (3 results over ≥ 48 h), routing moved away
from the edge (2 results ≥ 1 h apart), or the certificate/proof failed (2
results ≥ 1 h apart). The domain stops resolving at once (421); links fall back
to the platform address; a primary hands over to the oldest active alias.

1. `platform:domain-probe <hostname>` to see which check fails.
2. Ownership or routing: the School must restore the TXT record or the
   CNAME/A records (the School page shows "Attention required" and which area).
3. TLS: check the edge's certificate for the hostname (renewal, name coverage).
4. The domain returns to `active` by itself once all three checks pass again.
   If the School has lost the domain, revoke it (`platform:domain-revoke`).

## OBS-29 — a certificate is close to expiry (SEV-3 at ≤ 21 days, SEV-2 at ≤ 7)

Renewal is the edge's job and is failing. Check the edge's ACME/renewal logs for
the hostname; confirm DNS still routes to the edge. After renewal the next daily
probe records the new expiry and the alert clears.

## OBS-30 — checks indeterminate for 3 days (SEV-3)

DNS timeouts/SERVFAIL or probe network failures. Indeterminate results never
suspend a domain. Check `DOMAIN_DNS_RESOLVERS` reachability from the workers,
outbound UDP/TCP 53 and TCP 443 to the edge addresses.

## Custom-domain problems never affect readiness

One School's domain is never the platform: domain state is visible in
metrics (`lycenza_school_domains`, `lycenza_domain_checks_total`,
`lycenza_domain_transitions_total`, `lycenza_domain_certificate_min_days_remaining`,
`lycenza_domain_indeterminate_max_age_seconds`) and OBS-28–30 only.

## Deployment evidence still outstanding (ADR 0054 section 14)

None of the following has been done; no deployment exists (rule 16):
- the real edge target configured;
- ownership of at least one real non-production domain verified;
- an edge certificate issued and renewed;
- HTTP → HTTPS enforced at the edge;
- private-key custody proven outside the application;
- the routing/TLS probe (`platform:domain-probe`) passing against the real
  edge (ADR 0058 §4.10; added 2026-09-29);
- DNS/TLS drift monitoring active;
- one revoke/re-add drill completed.

**O1 (ADR 0058 §4.10):**
- **Production enablement is conditional.** Production may launch with
  `CUSTOM_DOMAINS_ENABLED=false`. If it enables custom domains, the whole
  list above is required there **before** enablement.
- **One real non-production exercise is mandatory for O1** (row E22),
  because the account-recovery drill must sign out a session on a custom
  School host. That exercise covers every item above, using a non-production
  domain.
