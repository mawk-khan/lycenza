# ADR 0027: SSRF Destination Policy for Webhook Endpoints

- Status: Accepted
- Date: 2026-08-23

## Context

A webhook endpoint URL is submitted by an authenticated School
administrator through the management API — user-controlled input, by
definition. Without validation, School OS's own server would happily
make an outbound HTTP request to anywhere that URL points, including
the platform's own internal network or a cloud provider's metadata
endpoint (`169.254.169.254`), turning the webhook feature into an SSRF
proxy usable by any account with `integrations.webhooks.manage`. This
must be decided once, deliberately, before any real integration
depends on webhook URLs being "just a URL."

## Decision

**Reject at both creation/update time and at every delivery attempt**
(`App\Support\Webhooks\SsrfSafeUrlValidator`, called from both
`WebhookEndpointService` and `App\Jobs\DeliverWebhookJob`):

- Non-`http`/`https` schemes.
- Credentials embedded in the URL.
- Unresolvable hosts.
- Any resolved address in a private, loopback, link-local, or
  otherwise reserved range (RFC 1918, `127.0.0.0/8`, `169.254.0.0/16`
  — which covers the cloud metadata address — `fc00::/7`, and PHP's
  broader `FILTER_FLAG_NO_PRIV_RANGE`/`FILTER_FLAG_NO_RES_RANGE`
  coverage), including IPv4-mapped IPv6 forms.

**The validated IP is pinned for the actual connection** (via
`CURLOPT_RESOLVE`, TLS hostname validation still against the original
hostname) to narrow the DNS-rebinding window between validation and
connection — accepted as a narrowing, not a perfect elimination, since
a genuinely adversarial DNS answer racing our own immediate connect
attempt is not fully closable without infrastructure this checkpoint
does not add (e.g. a dedicated forward-proxy with its own resolution
control).

**Redirects are never followed** — `allow_redirects: false`
unconditionally. A 3xx response is classified as a permanent delivery
failure. Re-validating and following a redirect was considered and
explicitly rejected (see below).

**Exactly one, narrowly-scoped test override exists**:
`WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS=true` permits **loopback addresses
only** (`127.0.0.0/8`, `::1`) — not the full set of otherwise-blocked
ranges — and only when the environment is also `local`/`testing`. This
double guard mirrors `App\Http\Middleware\DevOnlySchoolHeaderResolver`'s
existing pattern exactly. There is no generic
`disable_ssrf_protection` flag anywhere in the codebase, in any
environment.

## Rationale

- Validating once at creation time and never again is insufficient:
  DNS is not static, and a URL that resolved to a public address at
  registration time can be repointed at a private address before its
  next delivery attempt (classic TOCTOU). Re-validating at send time is
  the only way to actually close that window, at the cost of a resolve
  call on every attempt (cheap relative to the HTTP request itself).
- IP pinning via `CURLOPT_RESOLVE` is the strongest protection
  achievable with Laravel's existing Guzzle-based HTTP client without
  writing custom socket-handling code (explicitly out of scope per this
  checkpoint's brief — "do not implement unsafe custom socket logic").
- A test-mode override was unavoidable: Proof B's live cross-container
  proof (ADR 0026) genuinely needs to deliver to a real local receiver
  process at `127.0.0.1`. Scoping the override to loopback specifically
  — not "every blocked range" — means a mistakenly-set test flag in a
  misconfigured environment still cannot be used to reach the platform's
  own internal services or cloud metadata endpoint; only the specific
  address class Proof B actually needs is exempted.

## Alternatives considered

1. **A generic `disable_ssrf_protection` config flag, gated only by
   environment.** Rejected outright by this checkpoint's own brief and
   by this ADR: too broad, and a single flag disabling ALL protection
   (rather than narrowly permitting loopback) is exactly the kind of
   escape hatch that eventually leaks into a shared config template and
   reaches a real environment.
2. **Following redirects, re-validating each hop.** Rejected for this
   checkpoint: adds meaningful complexity (bounded hop count, re-running
   the full validation-and-pin sequence per hop) for a feature no real
   integration currently needs; a receiver that wants to redirect its
   webhook traffic can update its registered URL directly. Revisit if a
   real integration partner requires it.
3. **DNS resolution via a dedicated, sandboxed resolver/proxy service**
   (eliminates DNS rebinding entirely). Rejected as infrastructure this
   phase doesn't need yet (root CLAUDE.md rule 2: no speculative
   infrastructure) — the IP-pinning narrowing is judged sufficient for
   the current threat model; revisit if a specific incident or a
   higher-value integration's threat model demands it.

## Consequences

- Every future outbound HTTP feature this codebase adds (not just
  webhooks) that accepts a user-supplied destination should reuse
  `SsrfSafeUrlValidator` rather than inventing a parallel check —
  it is the one sanctioned SSRF validation primitive.
- The residual DNS-rebinding window is a documented, accepted risk, not
  a claimed non-issue — `docs/security/INTEGRATION-SECURITY.md` states
  this explicitly so a future security review doesn't have to
  rediscover it.
- Any future need to relax this policy (e.g. an on-premises School
  deployment genuinely needing to reach a private-network endpoint) is
  a new, explicit, reviewed decision — not a config toggle added
  quietly to unblock one customer.
