# ADR 0023: Signed Context Tokens for the Laravel <-> AI Gateway Boundary

- Status: Accepted
- Date: 2026-08-22 (Phase 0B)
- Amends: ADR 0016 (named this exact strengthening as deferred future
  work: "the Laravel<->AI Gateway shared token should be revisited...
  once there's a real multi-instance deployment topology")

## Context

Phase 0A's AI Gateway boundary (ADR 0013, ADR 0014) authenticates
Laravel <-> `services/ai` calls with one shared bearer token -- proving
"only the trusted platform/gateway may call this endpoint," but nothing
about *which School or capability* a given call is entitled to act
within. Phase 0B needs to prove a stronger, specific property: **an
actor with access only to School A can never cause an AI tool
invocation to execute with School B's context** -- and needs to prove
it even under the assumption that `services/ai` itself might be
compromised or buggy, since it is explicitly a lower-trust boundary
than Laravel (ADR 0002, ADR 0008).

## Decision

Every AI tool invocation now carries a **short-lived, HMAC-signed
context token**, minted by `App\Support\Ai\AiContextTokenService` and
verified by the same class on Laravel's inbound side
(`App\Http\Controllers\Api\Internal\AiToolController`):

- **Only Laravel holds the signing key**
  (`AI_GATEWAY_CONTEXT_SIGNING_KEY`, ADR 0016) -- `services/ai` never
  receives it, in config or otherwise.
- The token binds `school_id`, `actor_id`, a specific list of granted
  capabilities, an issued-at/expiry (60 second TTL), and a request id
  into one payload, signed with HMAC-SHA256, base64url-encoded as
  `{payload}.{signature}` -- deliberately not a general JWT library
  (avoids algorithm-confusion and library-surface-area risk entirely
  for what is a narrow, single-purpose internal envelope).
- **`App\Support\Ai\AiGatewayClient::invokeTool()`** is the only place a
  token is minted, and it checks
  `CapabilityResolver::canInSchool($actor, $capability, $school)`
  **before** minting -- so no code path exists that produces a token
  for a capability/School the actor doesn't actually hold, and
  `services/ai` cannot request a wider one because it never mints
  tokens at all, only relays what Laravel already gave it.
- `services/ai`'s tool handler (e.g. `school-echo`) forwards the token
  unchanged to Laravel's AI-tool-contract endpoint; that endpoint
  verifies signature + expiry + the specific capability claim before
  doing anything, then sets `TenantContext` from the *verified* claims
  -- never from any value `services/ai` sends outside the token.

This sits **in addition to**, not instead of, the existing shared
service-bearer-token check (`VerifyAiGatewayServiceToken` /
`services/ai`'s `require_service_token`) -- that proves "this is the
trusted gateway/platform calling at all"; the context token proves
"this specific call is entitled to act as this specific
actor/School/capability." Two different questions, two different
mechanisms, both required.

## Rationale

- This is the concrete implementation of ADR 0014's abstract
  requirement that an AI agent "may not access an unrelated tenant" and
  "cannot arbitrarily... bypass approval requirements" -- Phase 0A
  proved the *capability-gating* half of that chain in isolation
  (`services/ai/tests/test_tool_authorization.py`); Phase 0B proves the
  *tenant-binding* half, end to end, across the real process boundary.
- Keeping the signing key exclusively on the Laravel side means a fully
  compromised `services/ai` process can, at absolute worst, replay a
  token Laravel already issued for an already-authorized action within
  its short TTL -- it cannot mint a new one for a different School or
  capability. This materially changes the blast radius of an AI-service
  compromise, which is exactly the property ADR 0008 argued for by
  running the AI Platform as a separate, lower-trust service in the
  first place.
- A minimal hand-rolled HMAC envelope (not a JWT library) was chosen
  deliberately: this token has exactly one internal consumer (Laravel
  verifying its own signature) and one internal relay
  (`services/ai`, which never inspects it), so none of a general JWT
  library's flexibility (multiple algorithms, `alg: none`, external
  issuers/audiences) is needed, and all of the vulnerability classes
  that flexibility historically enables are structurally absent.
- A 60-second TTL bounds the window in which a leaked/logged token
  (e.g. via an errant debug log) could be replayed, without needing a
  revocation mechanism for Phase 0B's scope.

## Alternatives considered

1. **mTLS between Laravel and services/ai.** Rejected as
   disproportionate for this checkpoint (section 28 explicitly asks for
   a narrow strengthening, not a service mesh): mTLS would authenticate
   the *connection*, not bind *which actor/School/capability* a given
   request over that connection is entitled to -- it solves a different
   problem than the one this ADR addresses, and could still be added
   later without conflicting with context tokens.
2. **A full JWT (RS256/JWKS) issued by Laravel.** Rejected: asymmetric
   signing is the right tool when a token must be *verified* by a party
   that shouldn't be able to *mint* one -- but here Laravel is both the
   sole minter and sole verifier, so asymmetric keys buy nothing over
   HMAC while adding key-rotation/JWKS-endpoint complexity for no
   benefit at this stage.
3. **No token; trust services/ai to echo back whatever school_id
   Laravel originally sent it.** This is roughly Phase 0A's shape.
   Rejected because it makes the tenant-binding guarantee depend
   entirely on `services/ai` behaving correctly (not tampering,
   accidentally mixing up requests under concurrency, or being
   compromised) -- exactly the trust assumption ADR 0002/0008 says the
   architecture should not make of the AI Platform.
4. **Session/database-backed tokens (a `ai_context_tokens` table) instead
   of stateless HMAC.** Rejected for Phase 0B: adds a database
   round-trip and a table to every AI tool call for a property (short
   TTL, signature verification) stateless HMAC already provides;
   revisit only if revocation-before-expiry becomes a real requirement.

## Consequences

- `services/ai` gains no new capability from this change -- it remains
  a blind relay for context tokens, which is the point.
- Clock skew between Laravel and `services/ai` doesn't matter (only
  Laravel evaluates the expiry), but clock accuracy on the Laravel host
  itself does -- a reasonable assumption already required elsewhere
  (session expiry, rate limiting).
- Every future real AI tool (beyond Phase 0B's `school-echo` proof) must
  be built on this same verify-claims-before-acting pattern -- documented
  in `docs/ai/AI-SECURITY.md` and root `CLAUDE.md`.

## Amendment — Phase 0O.7 (ADR 0053, 2026-09-27)

The "shared service-bearer-token check" this ADR sits beside is replaced,
by Phase 0O.7A, with per-request Ed25519 **service assertions** (ADR 0053).
The two remain **separate mechanisms answering separate questions**:
- the assertion proves the calling internal service;
- this context token proves the actor, School and capability.

They are never merged into one token, and the assertion never carries or
sets School/actor context. This token's construction, key, TTL and
verification are unchanged. Observations recorded for later (not defects):
- the context key has no `kid` or rotation ring (ADR 0050 §5);
- the token has no purpose or audience claim;
- `verify()` does not check `iat` sanity.

## Future extraction/evolution path

If `services/ai` is later split into multiple independently-deployed
workers, or a genuine multi-instance/multi-region Laravel deployment
needs key rotation, the signing key moves to a proper secrets
manager/KMS (ADR 0016's already-documented future path) without
changing this ADR's core mechanism -- only how the key is stored and
rotated, not how the token itself is constructed or verified.
