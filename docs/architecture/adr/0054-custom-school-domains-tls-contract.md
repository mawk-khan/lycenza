# ADR 0054: Custom School Domains & TLS Contract

- Status: Accepted; repository implementation Phase 0O.8A (see the amendment at the end). Deployment evidence outstanding.
- Date: 2026-09-27 (Phase 0O.8)
- Resolves: **O9** (`docs/architecture/PHASE-0O-READINESS.md` §8)
- Amends:
  - ADR 0049 §10–§12: the host, CORS and HSTS items deferred to O9;
  - ADR 0050 §2: "School custom domains and their certificates remain O9".
- Related: ADR 0044 (elevation), ADR 0047 (School lifecycle), ADR 0051
  (observability), ADR 0052 (supply chain), ADR 0053 (internal service
  routes).

## 1. Context — what exists today (audited 2026-09-27, `origin/main` `c85c680`)

### 1.1 Data model

`school_domains` (migration `2026_08_22_090100`, model `App\Models\SchoolDomain`):

| Column | Today |
|---|---|
| `id` | UUIDv7 |
| `school_id` | FK `schools`, **`cascadeOnDelete`** |
| `domain` | `string`, **unique across all rows**. No normalization or validation exists anywhere, and there is no case folding. |
| `type` | `platform_subdomain` (default) or `custom`; nothing sets or reads it |
| `is_primary` | `boolean`; nothing enforces one primary per School |
| `verified_at` | nullable timestamp — the **only** state |

Other facts:
- `School::domains()` is `HasMany`, so the schema already allows several
  rows per School.
- The table has no RLS, deliberately: it is resolved before any School
  context exists (`DatabaseRoleVerifier::NON_RLS_SCHOOL_TABLES`).
- There are **no** challenge fields, no lifecycle states, no audit events,
  no capability, no route, no controller and no service. Nothing creates,
  verifies, edits or removes a domain; the elevation resolver's docblock
  says so ("nothing yet manages them"). No demo School has a row.

### 1.2 How a request becomes associated with a School

1. **Trusted proxies.** `TrustConfiguredProxies` believes
   `X-Forwarded-For/-Host/-Port/-Proto` only from `TRUSTED_PROXIES` (ADR
   0050 §2). The host Laravel sees is therefore the proxy-forwarded host
   when the peer is trusted, and the raw `Host` header otherwise.
2. **No host validation.** There is no `TrustHosts` middleware and no
   forced root URL. **Any** `Host` value is accepted, and Laravel builds
   in-request absolute URLs from it.
3. **Web and api groups.** `ResolveSchoolContext` runs in both the `web`
   and the `api` middleware group:
   - it resolves `VerifiedSchoolDomain::schoolFor($request->getHost())` —
     an exact, non-normalized `domain = host AND verified_at IS NOT NULL`
     — **before** the session selection;
   - so **a verified domain wins over the session's `active_school_id`**;
   - it sets `TenantContext` only for an active School;
   - since 0O.7A it resolves **nothing** on a `service-auth` route, which
     remains intact.
4. **Development header.** `DevOnlySchoolHeaderResolver` (`X-School-Id`)
   applies only with its config flag **and** in `local`/`testing`. It is
   excluded from the internal AI routes.
5. **School routes.** `RequireSchoolContext` (`school-context`, on every
   School web route) requires the resolved School to be active, the user
   not disabled, and an **active membership**. Otherwise it forgets the
   session selection and sends the user to `/app` ("Select a School"), or
   answers 409 `school_context_required` for JSON and mutations.
6. **Elevation.** `ResolvePlatformElevation` (ADR 0044) discards a
   domain- or header-resolved School for an elevated actor. A conflicting
   one blocks School context.
7. **`/api/v1`.** The School comes from the `{school}` URL parameter,
   verified by `school-membership` (`EnsureSchoolMembershipContext`),
   which sets `TenantContext` itself. The group-level domain resolution
   still ran first.
8. **Elevation target lookup.** `ElevationTargetResolver` also accepts a
   verified domain (lower-cased, LDH regex) as an exact elevation target.

### 1.3 School switching, URLs, sessions, CORS, CSP, HSTS

- **School switching.** `POST /app/schools/{school}/activate`
  (`SchoolSwitchController`) re-checks an active membership and an active
  School, stores `active_school_id`, **regenerates the session**, audits
  `school_context.activated`, and redirects to `/app` on the **same
  host**. The only switcher is the `/app` landing page (Inertia
  `router.post`). No generic unsaved-changes guard exists in the front end;
  the "hard switch" is the session regeneration plus a full `/app` load.
- **URLs.** There is one platform origin (`APP_URL`). The browser never
  calls `/api/*`; every page is a web/Inertia route. Absolute links come
  from `url()`, i.e. from the request host during a request, or `APP_URL`
  in a queued job. **Finding:** `GuardianAccountInvitationMail` (not
  queued) is sent **synchronously during the request**, and its
  token-bearing link uses `url()`. With no host validation, the link's
  host is whatever host the request arrived on.
- **Sessions.** `SESSION_DOMAIN` is `null` (host-only cookies, including
  `XSRF-TOKEN`), `http_only` is true, `same_site` is `lax`, and `secure`
  is required in production (Phase 0O.1 guard). CSRF is Laravel's
  session-token middleware; there is no origin allowlist.
- **Sanctum and CORS.** Sanctum is bearer-token only on `/api/v1`: the
  stateful-SPA middleware is not enabled, so `SANCTUM_STATEFUL_DOMAINS`
  is inert. CORS (ADR 0049 §10) uses exact origins, `paths: ['api/*']` and
  `supports_credentials: false`.
- **CSP and HSTS.** The CSP uses `'self'` everywhere and
  `frame-ancestors 'none'`. HSTS is `max-age=31536000`, production and
  HTTPS only, with no `includeSubDomains` and no `preload`.
- **Surfaces on the one host:**
  - `app/*`: School pages, plus platform `app/platform/*` and Group
    `app/groups/*`;
  - `login`, `login/mfa`, `logout`, `invitations/{school}/{token}`, `/`;
  - `/up` and `/api/health/*`, `api/v1/*` (383 routes), and
    `api/internal/*`;
  - `storage/{path}` (local-disk serving), `sanctum/csrf-cookie`.

  Metrics are on a separate private nginx listener (port 9102, outside
  Laravel routing). nginx listens on 8080 with no `server_name`
  restriction.

### 1.4 Facilities

- **IDN:** the production PHP runtime has **no `intl`**
  (`php-modules.expected`); only the transitive
  `symfony/polyfill-intl-idn` is present.
- **Public suffixes:** there is **no** Public Suffix List facility
  (`league/uri` only suggests one).
- **DNS:** the only DNS code is `gethostbyname` in
  `SsrfSafeUrlValidator`. It cannot tell NXDOMAIN from a resolver failure
  and has no timeout control.
- **Fresh MFA:** the step-up pattern is a current MFA code in the request,
  as for partner API clients, human API tokens, elevation and School
  lifecycle.

## 2. Decision summary

- **What a custom domain is.** It is a **browser School surface only**.
  It may serve the School's web pages, login and invitation acceptance;
  never platform, Group, operator, internal, API, health or
  service-to-service routes.
- **When it selects a School.** Only a domain that is **normalized,
  ownership-verified (persistent DNS TXT), routed to the Lycenza edge,
  TLS-verified and `active`** selects a School. It never grants
  membership. Unknown and non-active hosts are refused identically
  (**421**), with no default School.
- **Who does what.** The deployment edge terminates TLS and owns
  certificates. The application proves ownership, routing and TLS
  readiness, never stores a customer private key, and generates every
  absolute URL from stored canonical hosts, never from an incoming Host.

## 3. Hostnames

### 3.1 Canonical form and validation

- **Storage.** A stored hostname is **lowercase ASCII**, one terminal dot
  stripped, **hostname only**: no scheme, port, path, query, fragment or
  userinfo.
- **Syntax.**
  - total length ≤ 253 octets and at least two labels;
  - each label 1–63 octets of `[a-z0-9-]`, with no leading or trailing
    hyphen;
  - the last label is alphabetic (not all-numeric).
- **Refused:**
  - any input with `://`, `:`, `/`, `?`, `#`, `@`, whitespace or `*`
    (wildcards);
  - IPv4 and IPv6 literals, including bracketed ones;
  - `localhost` and names under `localhost`, `local`, `localdomain`,
    `internal`, `lan`, `home.arpa`, `test`, `example`, `invalid`,
    `onion`, `arpa`, and `.ddev.site`;
  - any public suffix (§3.3) and any reserved host (§3.4).
- **Comparison.** Every comparison — uniqueness, lookup, Host
  classification — uses this canonical form, applied to the incoming Host
  the same way (lower-cased, terminal dot and port stripped).

### 3.2 IDN (v1 decision: ASCII only)

The production runtime has no `intl`, and a hand-built or polyfill-only
IDNA path is not a security primitive the owner accepts. **v1 accepts only
ASCII LDH hostnames**, and **labels beginning `xn--` (A-labels) are refused
in v1**. Accepting them safely needs full IDNA2008/UTS #46 validation. Adding
IDN later means adding `ext-intl` to the custom PHP runtime under O16, then
storing A-labels, displaying U-labels only as a presentation aid, and
comparing A-labels. That requires an amendment to this ADR.

### 3.3 Public suffixes

A School may not claim a public suffix itself (`com`, `co.uk`, `github.io`,
…), from both the **ICANN and PRIVATE** sections of the Public Suffix List.
A registrable domain (`school.example`) and any subdomain of one
(`erp.school.example`) are allowed. Registrability is **never** approximated
by counting dots.

0O.8A adds a PSL facility:
- a maintained parser library (candidate `jeremykendall/php-domain-parser`)
  under full O16 lock/audit/SBOM rules;
- a **committed, pinned PSL snapshot** (source URL, date and sha256
  recorded), updated only by a reviewed change;
- **never fetched at runtime**.

A stale snapshot fails closed for a new suffix: the worst case is refusing
a legitimate new registrable domain, never accepting a suffix.

### 3.4 Reserved hosts

The deployment configures an exact reserved set that no School may claim:
- the platform host (the `APP_URL` host), and any configured platform
  aliases;
- the internal hosts (§8.3);
- the edge target name(s) (§5.2), and metrics or admin hosts;
- every name **under** the platform's own registrable domain.

It uses exact names and suffix rules owned by the deployment, never a
School. The committed defaults already refuse local and test names (§3.1).

### 3.5 Uniqueness and concurrent claims

- **One claim per hostname.** A canonical hostname belongs to **at most one
  claiming row at a time**, database-enforced by a partial unique index on
  `hostname` where the state is `pending_verification`, `verified`,
  `tls_pending`, `active` or `suspended`.
- **Revoked and expired rows** stay as history and do not block a new
  claim. The current table-wide `unique(domain)` is replaced in 0O.8A by
  a reversible migration.
- **Pending claims reserve the hostname** until they expire (§4.3) or are
  cancelled. An expired pending row is moved to `expired` in the **same
  transaction** that inserts a competing claim (expiry is checked
  database-side, not by trusting a scheduler).
- **Concurrency.** Two concurrent claims are serialized by the unique
  index. The loser gets `UniqueConstraintViolationException`, mapped to a
  422 "not available" that does not disclose which School holds it
  (CLAUDE.md rule 30 pattern: the constraint is the guarantee, never a
  pre-check).
- **Per-School limit.** At most **3** claiming rows per School (v1).

### 3.6 Primary domain and aliases

- **Primary.** A School has **at most one primary**, and only an `active`
  domain may be primary. This is database-enforced by a partial unique
  index on `school_id` where `is_primary AND state = 'active'`, plus a
  CHECK that no non-`active` row is primary.
- **Aliases.** Other `active` domains are aliases. An alias **308-redirects**
  to the same path on the primary. It never serves pages, so each School
  has exactly one browser origin (one host-only session).
- **Changing the primary** is one transaction (clear the old flag, set the
  new) under a `SELECT … FOR UPDATE` of the School's domain rows. There is
  never a moment with two primaries or none while one was intended.
- **`platform_subdomain`.** v1 has no platform-issued subdomains (they would
  need wildcard DNS and wildcard certificates, which are out of scope). The
  `platform_subdomain` value is retired, and 0O.8A constrains `type` to
  `custom`.

## 4. Lifecycle and ownership proof

### 4.1 States (explicit; database-enforced transitions)

`state` values (repository snake_case): `pending_verification`,
`verified`, `tls_pending`, `active`, `suspended`, `revoked`, `expired`.

| From | To | Trigger |
|---|---|---|
| (new) | `pending_verification` | School admin adds (capability + fresh MFA) |
| `pending_verification` | `verified` | TXT check finds the current challenge within its lifetime |
| `pending_verification` | `expired` | Challenge lifetime passed (terminal) |
| `pending_verification` | `pending_verification` | Challenge regenerated: new generation, old one dead, lifetime restarts |
| `verified` | `tls_pending` | Routing check passes (§5.2); the deployment is signalled to provision the edge |
| `tls_pending` | `active` | TLS readiness probe passes (§6.2) |
| `active` | `suspended` | Confirmed ownership, routing or TLS failure (§7) |
| `suspended` | `active` | Ownership, routing and TLS all pass again for the **same** row and token |
| any non-terminal | `revoked` | School admin removal (fresh MFA), or platform operator revocation (terminal) |

- **Enforcement.** A database trigger enforces the transition table, like
  `trg_schools_status_transition`.
- **Only `active` is live.** `active` is the only state that serves
  traffic, and it is **never** inferred from `verified_at`, which becomes a
  timestamp of the verification event only.
- **Deletion.** Rows are never deleted by the runtime role (`DELETE`
  revoked, CLAUDE.md rule 86 style). 0O.8A changes `school_id` from
  `cascadeOnDelete` to restrict.

### 4.2 DNS TXT record (frozen syntax)

| Part | Value |
|---|---|
| Record name | `_lycenza-verification.<hostname>` (the canonical hostname) |
| Record type | `TXT` |
| Record value | `lycenza-domain-verification=<token>` |
| `<token>` | 32 random bytes (256 bits, CSPRNG), base64url without padding: **43 characters** `[A-Za-z0-9_-]` |

**Matching rule:**
1. Take each TXT RR at the name.
2. Join its character-strings with no separator (DNS splits long values).
3. Accept if **any** RR equals `lycenza-domain-verification=<token>`
   **exactly**: case-sensitive, no surrounding whitespace, no prefix or
   suffix.

Substring and prefix matches are refused. Other TXT RRs at the name are
ignored.

**Binding.** The token belongs to exactly one row, and so to one School, one
canonical hostname and one **generation** (regeneration increments it).
Because the record name embeds the hostname, a token cannot prove another
hostname.

**Storage.** The token is stored with Laravel's `encrypted` cast
(Confidential while pending, §10). It is shown only to authorized School
administrators and operators. It is never logged, audited or put in a
metric.

### 4.3 Challenge lifetime

- A new or regenerated challenge must verify within **24 hours**; otherwise
  the row becomes `expired`.
- Regeneration is allowed only in `pending_verification`. It replaces the
  token, and the old one can never verify.
- A School re-adds an expired hostname as a **new** row with a new token.

### 4.4 Persistent ownership proof

The TXT record **must stay in place** for as long as the domain exists: the
School is never told to remove it. The daily re-verification (§7.1) checks
that it still equals the row's token.

After verification the token is public by design (it sits in DNS), so it is
not a secret. It **does not rotate** while the row lives in v1: a transfer
or re-add is a new row with a new token, so continuity never depends on a
rotation.

### 4.5 DNS lookup safety

- **DNS only.** Ownership proof and routing checks query DNS only. There is
  **never** an HTTP fetch of a customer URL for ownership (the TLS probe of
  §6.2 is pinned to the deployment's own edge addresses).
- **Resolver.** 0O.8A adds a `DomainDnsResolver` abstraction:
  - the production implementation uses the smallest audited DNS client
    that exposes the response code, with timeouts and TCP fallback (O16
    applies);
  - it queries **deployment-configured recursive resolvers**
    (`DOMAIN_DNS_RESOLVERS`, public-DNS recursors), never an internal
    split-horizon resolver;
  - there is no shelling out.
- **Bounds:**
  - 2 s per query, 2 attempts, 10 s total per check;
  - CNAME chain depth ≤ 8, with loops refused;
  - UDP with EDNS ≤ 4096 bytes, TCP fallback ≤ 65 535 bytes;
  - ≤ 32 TXT RRs considered.
- **Outcomes** (closed): `match`, `mismatch` (records present, none equal),
  `absent` (NXDOMAIN or NODATA), `indeterminate` (timeout, SERVFAIL,
  REFUSED, truncated beyond bounds, malformed). Only `match`, `mismatch`
  and `absent` are conclusive.

## 5. Routing readiness

### 5.1 Ownership is not routing

A CNAME, A or AAAA record pointing at Lycenza **is never ownership proof**
(§4). Routing readiness is a second, independent check, required before
`tls_pending`.

### 5.2 Edge target (deployment-configured, never hard-coded)

- **Configuration.** The deployment configures
  `DOMAIN_EDGE_CNAME_TARGET` (a hostname) and, for apex domains,
  `DOMAIN_EDGE_ADDRESSES` (the edge's public IPv4/IPv6 set). A provider
  "ALIAS/ANAME" appears as A/AAAA and is covered by the address set. No IP
  address appears in application logic.
- **Passing.** The check passes when **either**:
  - the hostname's CNAME chain ends at `DOMAIN_EDGE_CNAME_TARGET`, **or**
  - its complete A/AAAA answer set is non-empty and a subset of
    `DOMAIN_EDGE_ADDRESSES`.
- **Refusal.** Any resolved address in private, loopback, link-local,
  CGNAT, multicast, documentation or reserved ranges (the
  `SsrfSafeUrlValidator` classification) means **never activate**. The
  domain must be a public DNS name that resolves to the public edge.

## 6. TLS

### 6.1 Boundary

The **deployment edge** terminates TLS and owns:
- certificate issuance (ACME or other) and renewal;
- private-key custody;
- the HTTP → HTTPS redirect;
- protocol and cipher policy.

The application **never** terminates customer TLS, stores or receives a
customer private key, accepts an uploaded certificate (out of scope for
v1), or renews anything. No edge, CDN, ACME or certificate vendor is
chosen.

**Signalling the edge.** Entering `tls_pending` is the signal. Deployment
automation reads the list of hostnames to provision (and to deprovision on
revocation) through a read-only operator command,
`platform:domains-edge-desired`. It prints canonical hostnames and desired
states only. There is **no public "mark TLS ready" endpoint**, and no School
administrator can assert certificate readiness.

### 6.2 TLS readiness evidence (the probe)

`tls_pending → active` (and `suspended → active`) requires a successful
**domain probe**, run by the scheduler or by an operator
(`platform:domain-probe {hostname}`). It is provider-neutral: it reads what
the edge actually serves.

1. **Resolve** the hostname through §4.5. The answers must satisfy the
   routing check (§5.2).
2. **Connect over TLS** to one of those edge addresses (IP-pinned, SNI =
   the hostname), with:
   - TLS ≥ 1.2;
   - chain validation against the **system public CA bundle**
     (publicly trusted only);
   - hostname verification;
   - a valid period.

   Any self-signed, expired, mismatched or untrusted certificate fails.
   There is no verification bypass flag in any environment except the
   DDEV fake of §11.
3. **Request**
   `GET https://<hostname>/.well-known/lycenza-domain-probe?n=<fresh nonce>`
   over that connection. Redirects are not followed.
4. **Check the response.** The application answers this path only for a
   Host whose row is `tls_pending`, `active` or `suspended` (otherwise the
   ordinary 421, §8.5). The body is `HMAC-SHA256(DOMAIN_PROBE_KEY,
   hostname | nonce)`. A matching value proves the edge routes this
   hostname **to this deployment**.
5. **Record** the certificate's `notAfter`, the issuer name and a
   public-certificate fingerprint as evidence. The certificate is public
   material; nothing private is recorded.

`DOMAIN_PROBE_KEY` is a new dedicated secret (not `APP_KEY`, not a service
key; ADR 0053 §3.2 rule) in the `app_runtime` group.

### 6.3 Protocol, certificate, HTTP, HSTS

- **Protocols and ciphers.** TLS 1.2 and 1.3 only, with no SSL or TLS
  1.0/1.1. Ciphers are the deployment's contemporary secure defaults; no
  cloud-specific suite is prescribed.
- **Certificates.** A publicly trusted certificate valid for the exact
  hostname. It is never self-signed, expired or mismatched in production.
- **HTTP.** The edge redirects HTTP to HTTPS with a permanent,
  method-preserving redirect (308, or 301 for GET/HEAD where 308 is
  unavailable). ACME HTTP-01 challenge handling is the edge's own and never
  reaches the application. Business traffic is HTTPS only.
- **HSTS.** Unchanged: `max-age=31536000` on production HTTPS responses,
  including on custom domains. **Never `includeSubDomains` or `preload`**:
  Lycenza has no authority over a School's other subdomains.

### 6.4 Renewal and failure

- **Renewal** is the edge's job. The daily probe records the certificate's
  expiry and **alerts at ≤ 21 days** (§9).
- **Failure.** When the probe finds a certificate that is invalid, expired
  or mismatched, after the confirmation of §7.2, the domain becomes
  `suspended`.
- **Suspension behaviour.** A suspended domain stops resolving Schools and
  stops being generated in any URL; links fall back to the platform host
  (§8.6).
- **History** is never deleted.

## 7. Drift, revocation and transfer

### 7.1 Daily re-verification (scheduler)

- **Schedule.** Every `active` and `suspended` domain gets an ownership
  check (§4), a routing check (§5.2) and a TLS probe (§6.2) **once a day**.
  It is spread over the day, bounded by a per-run cap, runs School by
  School, and re-checks the School at execution time (ADR 0047).
- **Non-active School.** For a non-active School the checks are skipped;
  the domain does not serve traffic anyway (§8.7).

### 7.2 Confirmation policy (frozen)

| Observation | Action |
|---|---|
| Ownership `mismatch`: a TXT record exists at the name, but none equals the token | **Suspend on the second consecutive conclusive `mismatch` at least 1 hour apart.** An explicit changed value is security-relevant, so there is no longer grace. |
| Ownership `absent` | Suspend after **3 consecutive conclusive `absent`** results spanning **≥ 48 hours** |
| `indeterminate` DNS or probe network failure | **Never suspends** by itself. After 3 consecutive days it raises an alert (§9). |
| Routing points elsewhere (conclusive) | Suspend after **2 consecutive** results **≥ 1 hour apart**. Routing suspension never revokes ownership. |
| Certificate invalid, expired or mismatched | Suspend after **2 consecutive** probe results **≥ 1 hour apart** |
| All three checks pass for a `suspended` row | Back to `active` (same row, same token) |

After a first failing observation the scheduler re-checks sooner, for
example after 1 hour. The counters live on the row and are reset by a pass.

### 7.3 Revocation

A School administrator (capability plus fresh MFA) or a platform operator
revokes. In one transaction:
- the state becomes `revoked` (terminal) and `is_primary` becomes false;
- a new primary is **never** auto-picked; URLs fall back to the platform
  host.

Effects:
- Host → School resolution stops **immediately**, because the database is
  authoritative (§8.8);
- no canonical URL uses the domain;
- no new session can be established through it (it is 421 now);
- existing host-only sessions on that host become useless.

The deployment learns of the revocation through `platform:domains-edge-desired`
and removes the edge configuration. Audit history is kept.

### 7.4 Reassignment and transfer

- **Reclaiming.** A revoked or expired hostname may be claimed again (by any
  School) only as a **new row**, requiring a fresh challenge,
  verification, routing check and TLS probe. An old token or an old row
  never transfers.
- **While claimed.** While another School's claiming row exists, the
  hostname is unavailable (§3.5). A School that lost control of a domain it
  still holds gets it suspended by drift (§7.2), then must revoke it before
  the new owner can claim.
- **Operators** can revoke a stuck row (`platform:domain-revoke`, audited
  as a platform event) to unblock a legitimate owner.

## 8. Request handling

### 8.1 Host classification (new `ClassifyRequestHost`, earliest middleware after proxies)

After trusted-proxy processing (unchanged ADR 0050 rules: forwarded
headers only from `TRUSTED_PROXIES`), the canonical request host is
classified as exactly one of:

| Class | How | May serve |
|---|---|---|
| `platform` | Equals the `APP_URL` host (or a configured exact platform alias) | Everything the platform serves today: School pages by session selection, `app/platform/*`, `app/groups/*`, login, invitations, `/api/v1/*` |
| `school` | Canonical hostname of an **`active`** `school_domains` row whose School is active | The browser School surface only (§8.2) |
| `school-alias` | An `active` non-primary row | 308 to the same path on the School's primary |
| `internal` | Equals a configured `INTERNAL_HOSTS` entry (the private web service name, ADR 0053 §10) | `/api/internal/ai/*` and the health endpoints only |
| `probe` | `/.well-known/lycenza-domain-probe` for a row in `tls_pending`, `active` or `suspended` | That path only (§6.2) |
| `health` | `/up`, `/api/health/live`, `/api/health/ready` on any host that is not `school` (orchestrators probe by IP) | Health only |
| `unknown` | Anything else, **including every non-`active` domain** | **421 Misdirected Request**, a fixed body, before sessions, cookies, CSRF or URL generation |

Additional rules:
- **Unknown and non-active hosts look identical.** They get the same 421
  and body, so there is no disclosure of domain registration or failure
  reasons, and no default or "first" School.
- **No framework fallback.** Laravel's `TrustHosts` is not used with a
  regex. Classification is this exact, bounded set; there is no `.*`.
- **Development.** `local`/`testing` additionally accept `localhost`,
  `127.0.0.1` and `*.ddev.site`. This is environment-gated exactly like
  `DevOnlySchoolHeaderResolver` and never available in production.

### 8.2 The browser School surface

On a `school` host only these are served:
- the School's web routes (`app/*` **except** `app/platform/*` and
  `app/groups/*`);
- `login`, `login/mfa` and `logout`;
- `invitations/{school}/{token}`, **only for that host's School**;
- `/`.

Everything else answers **404**, a fixed body with no redirect to the
platform login:
- platform (`app/platform/*`), Group (`app/groups/*`) and elevation pages;
- `/api/v1/*`, `/api/internal/*`, `/api/health/*`, `/up`;
- `storage/*` and `sanctum/csrf-cookie`;
- any other future non-School surface.

This way a custom domain can never be a copy of the platform or root admin
surface, or a phishing-capable one. Route membership in the surface is a
closed allowlist enforced by an architecture test, like
`SchoolContextRouteGuardTest`.

### 8.3 Internal and service routes

ADR 0053 is unchanged. `/api/internal/ai/*`:
- resolves no School from any Host;
- requires a service assertion;
- is served only on `internal` (and, in `local`/`testing`, development)
  hosts.

A custom domain can neither route nor authenticate service traffic.

### 8.4 `/api/v1` (model B: browser-only custom domains)

- **The API stays on the platform host.** It keeps its own
  School-in-the-URL selection (`school-membership`). The browser never
  calls it.
- **No domain resolution on the API.** 0O.8A removes verified-domain
  resolution from the `api` middleware group entirely: `ResolveSchoolContext`
  performs domain resolution only for `web` routes on a `school` host. A
  Host header can therefore never pre-set API tenancy.

### 8.5 Pre-authentication, login and membership

- **Before login.** On a `school` host the domain identifies the intended
  School for branding, the login destination and the post-login target. It
  sets `TenantContext` **only after** authentication, and then only
  through the existing membership check.
- **After login.** `RequireSchoolContext` requires an **active membership**
  in the host's School. Domain ownership grants nobody membership.
- **No membership.** A user without it (including a platform-only or
  Group-only user) gets a fixed "no access to this School" page on that
  host (403). There is no platform surface, no silent switch to another
  School, and no redirect that carries the session elsewhere. The user may
  follow a plain link to the platform host, where the separate host-only
  session means signing in there.
- **Elevation.** It never applies on a `school` host (ADR 0044 elevation
  stays a platform-host flow, rule 83). `ElevationTargetResolver`'s
  domain form uses the same canonicalization and **`active`**-only lookup
  (today it matches any `verified_at` row).

### 8.6 Host/session agreement (frozen)

- **The host decides.** On a `school` host the host **decides** the School.
  The session's `active_school_id` is written to equal it; it never selects
  another School there.
- **Session mismatch.** Sessions are host-only, so a session that
  arrived on host B carrying a different `active_school_id` indicates
  tampering or a bug. The request is refused (409
  `school_context_required` / landing), and the stale selection is
  forgotten.
- **Platform host.** School selection stays session-based, as today.
- **No ambiguity.** The request never proceeds with host School ≠ session
  School.

### 8.7 School switching

`POST /app/schools/{school}/activate` keeps its checks (active membership,
active School, not elevated, session regenerated, audited). It then sends
the browser to the **target School's canonical origin**:
- `https://<primary active domain>/app` when the School has one;
- otherwise `https://<platform host>/app`, with the selection stored in
  the platform-host session.

A cross-host target uses `Inertia::location()`, a full navigation; no SPA
state crosses hosts. The target host's session is independent: the user
authenticates there if needed. The existing hard switch (session
regeneration, full `/app` load) is preserved.

### 8.8 School lifecycle, and domain lookup and cache

- **School not active.** A `provisioning`, `suspended` or archived School's
  domains classify as `unknown` (421). Its domain rows are **not** revoked
  or re-activated by School lifecycle; the domain returns to `school` only
  when the School is active again and the row is still `active`.
- **Lookup.** Host → School lookup reads PostgreSQL (the index on the
  canonical hostname).
- **Cache.** A cache may hold it for **at most 60 s** (`TenantCache`
  rules do not apply: this is platform data), invalidated explicitly on
  every transition, primary change and revocation. Losing Redis only
  costs a lookup, because the database is authoritative. The only
  staleness is the ≤ 60 s bound on another replica.
- **Revocation** also deletes the cache entry in the same request after
  commit.

### 8.9 URL generation (Host-header poisoning closed)

- **Never from the incoming Host.** No absolute URL is built from an
  incoming Host.
- **Canonical origin.** Each School's is `https://<primary active custom
  hostname>` or else `https://<APP_URL host>`, and platform surfaces
  always use `APP_URL`. A `school` request may keep same-origin relative
  links, because its host equals the stored primary.
- **Queued work, email, notifications and future O14 recovery links** all
  use the School's stored canonical origin, read at generation time. An
  `APP_URL` plus a School slug is never used when a primary custom domain
  exists.
- **Consequences:**
  - `GuardianAccountInvitationMail`'s `url()` is replaced by the canonical
    origin in 0O.8A;
  - an architecture test forbids `url()`/`request()->getHost()`-based
    absolute links in Mail, Notification, Job and Application code;
  - production sets the root URL from `APP_URL` for non-School requests.

## 9. Security of the surrounding browser contract

- **Cookies.** Cookies stay **host-only** (`SESSION_DOMAIN` must be `null`;
  0O.8A's production guard refuses any value), with `Secure`, `HttpOnly`
  and `SameSite=Lax` unchanged. There is never a parent-domain cookie, and
  each custom host has its own session and `XSRF-TOKEN`.
- **CSRF.** Laravel's same-origin session token, unchanged. There is no
  origin allowlist, so no wildcard trusted origins are needed.
- **Sanctum.** It stays bearer-only for `/api/v1`. Stateful-SPA mode is not
  enabled, and `SANCTUM_STATEFUL_DOMAINS` never lists custom domains.
- **CORS.** ADR 0049's exact-origin list is unchanged and **never**
  automatically includes custom domains. Browser traffic is same-origin; no
  cross-origin API use is added.
- **CSP.** `'self'` is the exact current origin. No domain lists, no
  wildcard, and `frame-ancestors 'none'` is kept.

## 10. Management, authorization, audit and classification

### 10.1 Capabilities and MFA

- **Capabilities.** New School capabilities `school.domains.view` and
  `school.domains.manage` (namespace `school`), granted to `school_admin`
  only by default. The generic `school.settings.manage` is not reused.
- **Fresh MFA.** Adding, regenerating a challenge, setting the primary and
  revoking require a **fresh MFA code** in the request (the partner-API
  pattern).
- **No School-side activation.** "Check now" requests verification.
  Activation itself is never a School action (it follows the probe).
- **Platform side.** There is no platform bypass for ordinary
  administrators and no platform web UI for domains in v1. Operators act
  only through console commands on the operator console
  (`platform:domain-revoke`, `platform:domain-probe`,
  `platform:domains-edge-desired`), like the other `platform:*` operator
  commands. Revocation is audited as a platform event; the other two are
  read or check-only.

### 10.2 Audit and logs

- **School audit events:** `school.domain.claimed`,
  `.challenge_regenerated`, `.verified`, `.tls_pending`, `.activated`,
  `.suspended`, `.reactivated`, `.primary_changed`, `.revoked`, `.expired`.
  - **Recorded:** canonical hostname, old and new state, action, actor (or
    system), and a closed outcome code.
  - **Never recorded:** the challenge, DNS responses or certificate data
    beyond the public fingerprint.
- **Platform audit events** record operator revocations.
- **Logs** carry the domain id, a closed outcome code and a DNS/probe
  class. They never carry the challenge or raw resolver output, and the
  hostname appears only in operator-classified logs.

### 10.3 Classification (`DATA-CLASSIFICATION.md`)

| Item | Class |
|---|---|
| Custom hostname and its state | Internal (School configuration) |
| Pending DNS verification challenge | **Confidential** (encrypted at rest, shown only to authorized admins and operators). After publication it is public by design. |
| `DOMAIN_PROBE_KEY` | Highly Sensitive (secret) |
| Public TLS certificate, fingerprint, `notAfter` | Public |
| TLS private key | Highly Sensitive; **deployment edge only**, never application data |
| DNS/TLS operator evidence (probe records, drills) | Confidential |

### 10.4 School-facing management (0O.8A scope)

- **Allowed:** request a domain; see the DNS instructions (exact record
  name and value, and the routing target); run "check now"; see the safe
  status; choose the primary among active domains; revoke.
- **Statuses shown:** Waiting for DNS verification, DNS verified, Preparing
  secure connection, Active, Attention required, Removed, Expired.
- **Never exposed:** raw DNS or resolver output, edge or provider errors,
  certificate private material, or other Schools' domains.

### 10.5 Rate limits

- **Manual checks.** A new `domain-checks` limiter allows **6 per minute
  and 30 per hour per School**, and 3 per minute per domain (the
  `webhook-admin`/`credential-management` pattern). It keys by the
  route-bound School, never `TenantContext` (rule 61).
- **Scheduled checks** are separately bounded (§7.1). The ERP is never an
  open DNS query engine.

## 11. Local, DDEV and tests

- **No real DNS or TLS.** DDEV and tests never need them.
- **Fakes.** 0O.8A binds a fake `DomainDnsResolver` and a fake prober
  **only** in `local`/`testing` (config flag plus environment, the
  `DevOnlySchoolHeaderResolver` double guard). Demo fixtures can walk a
  domain through every state deterministically. Production code paths and
  checks are never weakened.
- **Tests (0O.8A):**
  - hostname rules: normalization, the refusal set, IDN (`xn--`)
    rejection, the PSL, reserved hosts;
  - claims: uniqueness and concurrent claims (real OS processes, like the
    ADR 0053 jti test); challenge generation, expiry and regeneration;
  - DNS: TXT parsing (split strings, near-misses); DNS outcome
    classification;
  - activation: routing/edge-target checks, including private-IP refusal;
    probe success and failure matrices; activation prerequisites;
  - lifecycle: drift and suspension with injected time; revoke,
    re-add and transfer; the primary change race; School lifecycle;
  - request handling:
    - trusted-proxy spoofing of `X-Forwarded-Host`;
    - unknown and non-active host 421;
    - alias 308;
    - surface allowlist (platform, Group, API, internal and health
      refused on School hosts);
    - Host/session mismatch;
  - browser contract: host-only cookies; CSRF and CORS unchanged;
    switching navigation; URL generation (request and queued);
  - audit and log redaction; cache invalidation.

## 12. Observability (ADR 0051 extension)

- **Metrics** (closed labels, never hostname or School):
  - `lycenza_school_domains{state}` (a gauge by state);
  - `lycenza_domain_checks_total{check=ownership|routing|tls,outcome=match|mismatch|absent|indeterminate|pass|fail}`;
  - `lycenza_domain_transitions_total{to}`;
  - `lycenza_domain_certificate_min_days_remaining` (the minimum over
    active domains).
- **Alerts** (the next free IDs after OBS-27):

  | Alert | Condition | Severity |
  |---|---|---|
  | OBS-28 | An active domain suspended for ownership, routing or TLS | SEV-3 |
  | OBS-29 | Certificate ≤ 21 days from expiry | SEV-3 |
  | OBS-29 | Certificate ≤ 7 days from expiry | SEV-2 |
  | OBS-30 | Domain checks `indeterminate` for 3 consecutive days | SEV-3 |

  None pages as SEV-1: one School's domain is not the platform.
- **Readiness** stays global and independent. A School's domain failure
  never makes the ERP unready (rule 55/56 spirit). Domain health is
  operator status, metrics and alerts only.

## 13. Boundaries with other decisions

- **O13 email.** An application hostname authorizes **nothing** about
  email. `school.example` being active says nothing about sending from
  `@example`: SPF, DKIM, DMARC and sending domains are O13, with separate
  ownership proofs. *Frozen by ADR 0055 §8.4 (Phase 0O.9):* v1 email is
  sent only from a Lycenza-controlled sending domain, and an `active`
  School web domain grants no From, DKIM or return-path at that domain.
- **O14 recovery.** Recovery links (future) use §8.9's canonical origin
  and never a request host. O14 remains open.
- **Phase 0N governance.** Domain ownership grants no platform, Group or
  elevation authority. Platform-only users gain no School session by
  visiting a custom host.

## 14. Definition of done

**Repository (Phase 0O.8A):**
1. Normalization, validation, the PSL, reserved hosts and uniqueness are
   enforced (database constraints).
2. The TXT ownership proof is implemented with the frozen syntax, lifetime
   and generation binding.
3. The explicit lifecycle and transitions are database-enforced.
4. Only `active` domains of active Schools resolve Schools.
5. Routing and the TLS probe gate activation.
6. Host classification covers platform, `active` custom, internal, probe
   and health hosts; everything else is 421.
7. Sessions and cookies are host-only, with a production guard.
8. School switching and URL generation use canonical origins (request and
   background).
9. Platform, Group, internal and API surfaces are refused on School hosts.
10. Drift, revocation and transfer are safe; cache invalidation holds.
11. Logs, metrics and OBS-28–30 are bounded and sanitized.
12. Concurrency and security tests pass.
13. Both images requalify under O16 (the PSL library and DNS client).

**Deployment evidence (deploy-gated, rule 16):**
- the real edge target configured;
- real ownership verification of at least one non-production domain;
- an edge certificate issued and renewed;
- HTTP → HTTPS enforced;
- private-key custody proven outside the application;
- DNS/TLS drift monitoring active;
- one revoke/re-add drill completed.

## 15. Findings recorded for 0O.8A

1. **No host validation.** Any Host is accepted, and in-request absolute
   URLs follow it. The synchronous `GuardianAccountInvitationMail` builds
   its token link from the request host (§8.9). Exploitation is bounded
   today (admin-initiated, behind the edge), but it must be fixed with
   O9.
2. **Domain resolution on API routes.** `ResolveSchoolContext` performs
   domain resolution on `/api/v1` requests before `school-membership`
   (§8.4).
3. **Domain over session.** A verified domain silently overrides the
   session School, and `SchoolSwitchController` stays on the same host
   (§8.6–§8.7).
4. **Weak domain model.** `school_domains` uses `verified_at` as its only
   state, allows unlimited primaries, is unique across history, and has no
   normalization or cascade-safe deletion (§3–§4).

## 16. Alternatives considered

1. **CNAME/A to Lycenza as proof.** Rejected, because pointing DNS is not
   control proof: dangling-record takeovers.
2. **HTTP-file ownership proof.** Rejected: it is an application fetch of a
   customer URL (SSRF-prone) and conflates routing with ownership.
3. **Remove the TXT record after verification.** Rejected, because
   continuous proof detects loss of control.
4. **Customer-uploaded certificates.** Out of scope for v1: private-key
   custody would enter the application.
5. **Parent-domain or shared cookies across School hosts.** Rejected:
   cross-School session leakage.
6. **Custom domains also serving `/api/v1`.** Rejected (model A). There is
   no need, since the browser uses web routes, and it would add a second
   API tenancy input.
7. **`TrustHosts` regex or wildcard.** Rejected in favour of exact
   classification.
8. **Serving aliases in place.** Rejected: one origin per School keeps one
   session.
9. **IDN via the polyfill.** Deferred (§3.2).

## 17. Consequences

- O9 is **resolved as a contract**; implementation is Phase 0O.8A.
- The ERP gains a hard Host boundary (421 for everything unexpected),
  which also closes the Host-header URL issue independently of custom
  domains.
- O1 remains open. Phase 0O cannot close while production would serve
  customer domains without ownership proof, TLS, Host validation,
  revocation and host-safe sessions; after 0O.8A plus its deployment
  evidence, none of these remains.
- Still open: **O1, O2, O13, O14, O15**.

## Amendment — Phase 0O.8A implementation (2026-09-27)

The repository portion of O9 is **implemented**. The deployment evidence of
§14 is still outstanding (rule 16). Decisions made during implementation,
each within this contract unless it says it amends a section:

1. **Schema and history** (`2026_10_24_090000`). `school_domains` evolves in
   place (no parallel table):
   - `domain` becomes `hostname` (CHECK: lowercase LDH, ≤ 253, no `xn--`
     label), `type` is CHECK-constrained to `custom`, and `state` is the only
     lifecycle truth;
   - evidence columns (ownership/routing/TLS outcome and time, certificate
     `notAfter`, SHA-256 fingerprint and issuer name), drift counters, and
     terminal timestamps (`revoked_at` with `revocation_source`,
     `expired_at`) are added;
   - `school_id` is **`ON DELETE RESTRICT`**: the runtime role can delete
     neither a School (ADR 0047) nor a domain row (DELETE revoked), so history
     survives; only an administrative School deletion
     (`TestCase::deleteSchoolAsAdmin()`) removes the School's domain rows
     first. No orphan is possible;
   - rows that existed before (never managed, never ownership-proven) become
     terminal `revoked` history with `revocation_source = legacy`.

2. **Primary invariant — the exact mechanism.** Three database layers plus
   the service:
   - a row CHECK: only an `active` row may be primary;
   - a partial unique index: at most one primary per School;
   - a `DEFERRABLE INITIALLY DEFERRED` constraint trigger
     (`trg_school_domains_primary_invariant`, only when `state` or
     `is_primary` changed) that checks **at COMMIT** that a School with any
     active domain has exactly one primary — the cross-row "at least one"
     half no CHECK or index can express — under the School's transaction
     advisory lock, so two concurrent transactions cannot each commit half of
     a swap;
   - **lock order:** every writer takes the School's advisory lock
     (`SchoolDomainService::lockSchool()`) **before** any row lock, and several
     Schools in sorted order, so the commit-time re-acquisition is re-entrant.
     The first ordering (row lock, then the commit-time advisory lock)
     deadlocked in the real-process test of a check racing a regeneration;
     that is how the order was found and fixed.

   **Amends §3.6 and §7.3 (who picks the next primary):**
   - a School revoking its **primary while another domain is active must name
     the replacement** (the page asks; the service refuses
     `replacement_required` otherwise) — never a silent primary-less state;
   - a **drift suspension** or an **operator revocation** of the primary
     hands it to the **oldest active alias** (audited
     `school.domain.primary_changed`, outcome `primary_promoted`);
   - with no other active domain the primary simply ends and links fall back
     to the platform host.

3. **Claims.** One transaction: lock the claiming School and the holder of a
   stale pending claim (sorted), expire that stale claim (compared with the
   application clock that wrote it), insert. The partial unique index decides
   a same-hostname race (the loser gets "That domain is not available." — no
   School named); the INSERT trigger's advisory lock plus count decides the
   3-per-School limit. Both proven with two real, overlapping OS processes.

4. **DNS client and PSL** (both under O16):
   - **`mikepultz/netdns2` v2.0.8** (MIT, no dependencies, no shelling out):
     2 s per query, 2 attempts (the second to the next resolver), 10 s per
     check, CNAME depth ≤ 8, ≤ 32 TXT RRs and ≤ 32 addresses. v1 sends plain
     512-byte UDP with the library's automatic TCP retry on truncation
     (within the §4.5 bound) instead of EDNS. NXDOMAIN and NODATA are
     `absent`; SERVFAIL, REFUSED, timeouts, loops and floods are
     `indeterminate`, never `absent`. Proven against a local wire-format DNS
     responder.
   - **`jeremykendall/php-domain-parser` 6.4.0** (MIT, no `intl` needed for
     ASCII), with the **committed snapshot**
     `resources/public-suffix-list/public_suffix_list.dat` (version
     2026-09-24_13-26-36_UTC, commit `a179a48c`, sha256 `257b298d…503e`,
     recorded in `snapshot.json` and **verified before every parse**, so an
     edited list fails closed). A name under an unknown TLD is refused too.

5. **TLS probe.** `StreamDomainProber` (PHP OpenSSL streams): IP-pinned to at
   most two routing-validated addresses, SNI + peer and peer-name
   verification against the system CA bundle, TLS 1.2/1.3 only, a fresh
   256-bit nonce, `HTTP/1.0` (no chunking), ≤ 8 KiB read, redirects never
   followed. Outcomes: `pass`, `tls_invalid`, `proof_mismatch`,
   `indeterminate`. Proven against a local TLS "edge" with throwaway
   certificates (valid, expired, not yet valid, wrong name, untrusted CA,
   TLS < 1.2, wrong proof, 404, redirect, silent). No switch relaxes
   verification.

6. **Probe host class.** A `tls_pending`, `active` or `suspended` hostname of
   an active School may reach **only** `/.well-known/lycenza-domain-probe`
   before it is active (421 for everything else). The route belongs to no
   middleware group (no session, cookie or CSRF), and the production nginx
   passes that one dot-path to the application.

7. **Health (amends §8.1 `health`).** Health paths are served on the
   platform, internal and development hosts and on **IP-literal** Hosts
   (orchestrators probe by address). On **any other name** they answer a
   fixed 404 **without a domain lookup**, so liveness never depends on
   PostgreSQL (CLAUDE.md rule 55); a School host therefore still never
   serves health.

8. **Internal hosts.** `INTERNAL_HOSTS` (exact, multi-label names) serve
   `/api/internal/ai/*` and health only. The platform host serves the
   internal AI routes only in `local`/`testing` on a development host.
   Production refuses to boot with the AI Gateway configured but no internal
   host (`internal_hosts_missing`).

9. **Cross-host sign-in handoff (new; extends §8.7).** Host-only cookies
   cannot follow a switch to another origin. The switch therefore issues a
   **one-time ticket** (`App\Support\Auth\CrossHostHandoff`):
   - 256 random bits (43 base64url characters), opaque; the record lives
     server-side in the handoff store (Redis in production,
     `SESSION_HANDOFF_STORE`, guard `session_handoff_store_not_redis`) under
     sha256(ticket), for **60 s**;
   - bound to the user, the **source session**, the exact target School (or
     the platform), the exact target hostname and the purpose
     `school-switch`;
   - redeemed exactly once (an atomic `SET NX` of a `used` marker — 8 real
     processes on real Redis, exactly one redeems);
   - `GET /session/handoff?ticket=`: consumed before anything renders, then an
     immediate 303 to `/app`; `no-store` and `Referrer-Policy: no-referrer`;
     the ticket never reaches a log (LogSanitizer redacts `ticket=`; the
     production nginx access log records the path only, never a query
     string).

   Redemption is **not** authority. It re-checks the exact Host (and, for a
   School, that this Host is still its canonical origin), that the source
   session is still signed in as the same user, the user, the School's
   status and the membership. It refuses when a **different** user is signed
   in on the target host (the login-CSRF / session-confusion defence). It
   invalidates the target host's session, signs in with a fresh id (a session
   id is never taken from the URL or copied between hosts), sets the School
   selection and carries the source's MFA assurance time unchanged (a stale
   one is not carried). A store outage refuses cross-host switches only;
   same-host work never touches the store.

10. **Logout.** Sessions are host-local: logout ends the session of the host
    it runs on. There is no cross-domain global logout in v1 (no shared
    cookie, by design); a session on another School host ends on its own
    logout or expiry. Redemption requires the **source** session to be
    signed in still, so signing out before redeeming voids a pending ticket.

11. **URLs.** `App\Support\Domains\CanonicalOrigin` is the one source of
    absolute School URLs (switching, invitation links, future O14 recovery):
    the ACTIVE primary of an active School, else `APP_URL`. In production the
    request root URL is forced to the canonical origin. An architecture test
    forbids request-Host readers anywhere but the Host boundary and
    `url()`/`route()` in mail, notifications, jobs and Application services.

12. **Checks.** "Check now" (per School 6/min and 30/h, per domain 3/min) and
    the scheduler (`platform:domains-check`, every minute, bounded by
    `DOMAIN_CHECKS_PER_RUN`) **queue** `CheckSchoolDomainJob` (tenant-scoped,
    1 try, 60 s timeout); no DNS or TLS work runs in a web request or in the
    scheduler. A check never starts another step after 15 s. A non-active
    School's domains are skipped, never changed.

13. **Disabled mode.** `CUSTOM_DOMAINS_ENABLED=false` (the default) is a
    complete, safe mode: nothing is claimed or resolved, and the Host
    boundary still applies. Only enabled production needs the edge target,
    a probe key (≥ 32 characters, no development prefix) and public DNS
    resolvers.

14. **Fakes.** `FakeDomainDnsResolver` / `FakeDomainProber` are bound only with
    `DOMAIN_FAKES_ENABLED` **and** `local`/`testing`; production refuses the
    flag. DDEV scripts them with `platform:domain-fake-dns` (records live in
    the cache store, so the CLI and web agree). The fake prober still computes
    and checks the real HMAC, so a missing probe key fails there too.

15. **Findings of §15 — all closed.** (1) Host validation: the 421 boundary,
    and the invitation link now uses the canonical origin. (2) `/api/*`
    never takes a School from a Host. (3) A domain never overrides the
    platform-host session, and a School host refuses a session naming another
    School. (4) The weak model is replaced (above). Each was reproduced by a
    test on the pre-fix code before it was fixed.

**Qualification:** `main` `e981f25` — both images **VERIFIED** (application
`sha256:49b9be95…9d37`, AI Gateway `sha256:d89f24f1…35ef`;
`PHASE-0O-READINESS.md` §31). PUBLISHED = NONE, PROMOTED = NONE.

**Deployment evidence still outstanding (§14):** the real edge target; a
real non-production domain verified; a certificate issued and renewed;
HTTP → HTTPS at the edge; private-key custody outside the application; drift
monitoring active; one revoke/re-add drill. Runbook:
`docs/operations/CUSTOM-DOMAINS.md`.
