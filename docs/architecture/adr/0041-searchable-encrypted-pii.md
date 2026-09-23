# ADR 0041: Searchable Encrypted PII (Encrypted Value + Keyed Lookup Digest)

- Status: Accepted
- Date: 2026-08-23 (Phase 1A.3)
- Renumbered: originally published as **ADR 0028**. The same number was
  independently assigned, on a parallel branch the same day, to
  `0028-phase-8a-hr-sequencing-and-domain-foundation.md`; both reached
  `main`. On 2026-09-23 this ADR was renumbered to 0041 (the next unused
  number) and every repository reference to it updated. The decision
  text is unchanged. Historical reports that cite "ADR 0028" for
  searchable encrypted PII / Guardian contact lookup digests mean this
  ADR.

## Context

Phase 1A.3 needed to store Guardian contact information (email,
mobile) such that: an authorized user can view the real value; the
system can find "does a contact matching this value already exist in
this School" (duplicate-candidate detection) without ever running a
full-table decrypt-and-compare scan; and the value is never recoverable
by anyone with database access alone (a stolen database backup must not
be a stolen contact list). No prior module in this codebase needed
"exact-match search over a value that must also be encrypted at rest" —
`WebhookEndpoint.secret_encrypted` (Phase 0C.3) is encrypted but never
searched; every `status`-like column is searched but never encrypted.
This is a genuinely new requirement, and the pattern chosen here is
explicitly intended to be reusable by future modules with the same
shape (a future `StudentIdentifier`, an Employee's personal contact
info, etc.) — a cross-cutting decision the ADR record exists to fix, not
a Guardian-contact-specific implementation detail.

## Decision

Every searchable PII value is stored as **two separate, independent
representations**, never one:

1. **`encrypted_value`** — the real value, encrypted via Laravel's
   built-in `encrypted` Eloquent cast (APP_KEY-based, authenticated,
   non-deterministic per encryption call — the same mechanism
   `WebhookEndpoint.secret_encrypted` already uses, not a new
   encryption implementation). Decrypted only in-memory, on demand, by
   application code that needs to display or otherwise reveal the real
   value.
2. **`lookup_hash`** — a keyed HMAC-SHA-256 digest
   (`App\Support\Privacy\ContactLookupHasher`) of the value's
   **normalized** form, domain-separated by a fixed literal prefix,
   School id, and value type:
   `hash_hmac('sha256', "guardian-contact|{school_id}|{type}|{normalized_value}", $key)`.
   The digest is deterministic **within one School** (so an exact-match
   query against `lookup_hash` works) but the identical normalized
   value in two different Schools produces two different digests,
   because `school_id` participates in the hashed domain string. The
   key (`CONTACT_LOOKUP_HMAC_KEY`, `config/privacy.php`) is a secret
   deliberately separate from `APP_KEY`, so encryption and lookup-digest
   keys can be rotated independently; `lookup_key_version` is stored
   per row so a future rotation can identify which rows still need
   re-hashing without a schema change (no rotation workflow is
   implemented yet).

No column, anywhere, ever stores the plaintext or normalized-plaintext
value. `ContactLookupHasher::hash()` fails closed (throws
`ContactLookupKeyNotConfiguredException`) if the key is missing/empty —
it never silently hashes with an empty key.

## Rationale

- **Keyed HMAC, not a plain hash.** A plain `SHA-256(email)` (or worse,
  `MD5`) is reversible in practice for anything as low-entropy as an
  email or phone number — an attacker with the database can
  precompute/rainbow-table every plausible email/phone and match
  digests directly. A *keyed* HMAC requires the secret key (never
  stored in the database) to produce a matching digest at all.
- **Non-deterministic encryption, not deterministic.** The brief
  explicitly required that the identical email stored twice need not
  produce identical ciphertext — this rules out ECB-mode or any
  homegrown deterministic AES scheme, and is exactly what Laravel's
  `encrypted` cast already provides for free (a random IV per
  encryption call), so no new encryption code was written.
- **School id inside the hash domain, not a separate `school_id`
  column check alone.** Even though `lookup_hash` lookups are always
  additionally scoped by RLS/`school_id` in the query, baking the
  School id into the *hashed input itself* means the digest values
  themselves carry no cross-School correlation — School A cannot infer
  anything about School B's contacts even from a hypothetical dump of
  raw `lookup_hash` values with `school_id` columns stripped out. This
  is intentional defense in depth on top of, not instead of, RLS.
- **`unique(school_id, guardian_id, type, lookup_hash)`, not
  `unique(school_id, lookup_hash)`.** Two different Guardians
  legitimately sharing a household email/phone is expected and must
  remain possible — the uniqueness invariant only prevents a redundant
  duplicate row on the *same* Guardian, never de-duplicates across
  Guardians. See `docs/modules/STUDENT-GUARDIAN-IDENTITY.md`.
- **A dedicated secret (`CONTACT_LOOKUP_HMAC_KEY`), not a reuse of
  `APP_KEY` or database credentials.** Rotating the lookup key (e.g.
  because it may have leaked) should never require also rotating
  `APP_KEY` (which would re-encrypt every `encrypted` cast column in
  the whole application), and vice versa. Reusing a database
  credential for a cryptographic key would also conflate an
  operational secret with a cryptographic one for no benefit.

## Alternatives considered

1. **Plaintext `normalized_email`/`normalized_phone` columns for
   lookup, with `encrypted_value` for display.** Rejected outright —
   this is precisely the design the accepted Phase 1A.3 brief forbids;
   it would make the "encrypted" column theater, since the searchable
   plaintext twin leaks everything the encryption was meant to protect.
2. **Deterministic/reversible encryption used directly as the lookup
   key (e.g. AES-ECB or a fixed-IV scheme).** Rejected: deterministic
   ciphertext of a low-entropy value is itself dictionary-attackable
   offline (an attacker can encrypt every guessed value with the known
   algorithm and compare ciphertexts) — the security property gained
   over plaintext is minimal.
3. **Unsalted/unkeyed `SHA-256`/`MD5` of the normalized value.**
   Rejected explicitly per the brief — a plain hash of a low-entropy
   value is reversible via precomputation; there's no meaningful
   distinction from storing plaintext once an attacker has any
   reasonably-sized dictionary of candidate emails/phone numbers.
4. **PostgreSQL `pgcrypto`/native column encryption.** Rejected for the
   same reason ADR 0016/0021 already avoid adding new PostgreSQL
   extensions/roles beyond what's proven necessary — Laravel's
   `encrypted` cast already satisfies every requirement (authenticated,
   non-deterministic, APP_KEY-based) without a new database extension
   or a second encryption implementation to keep in sync with
   Laravel's.
5. **A general-purpose, provider-parameterized "PII vault" abstraction
   usable for any future sensitive field, built now.** Rejected as
   premature (root `CLAUDE.md` rule 2, "no speculative frameworks"):
   `ContactLookupHasher`'s domain-separation prefix (`guardian-contact`)
   is deliberately a fixed literal, not a generalized "namespace"
   parameter, because there is exactly one caller today. If/when a
   second genuinely distinct exact-match-PII need arises (a future
   `StudentIdentifier`), that is the point to decide whether to
   generalize this class or give the new need its own — not before a
   second real caller exists to inform the right shape.

## Consequences

- Every future exact-match sensitive-identifier need (a government ID,
  a future Employee personal contact field, etc.) has a proven pattern
  to follow: `encrypted` cast for the real value, a keyed
  School/type-separated HMAC digest for lookup, a dedicated rotation-
  ready secret, and a `unique(school_id, owner_id, type, lookup_hash)`-
  shaped constraint scoped to the *owning* entity, never a global
  uniqueness across all owners.
- This ADR does **not** authorize collecting new categories of
  sensitive data merely because a technical pattern now exists to store
  them safely — `docs/security/DATA-CLASSIFICATION.md`'s
  [LEGAL REVIEW REQUIRED] flags (government identifiers, health data,
  etc.) remain in force regardless of the storage mechanism available.
- A real key-rotation workflow (re-hashing every row under a new
  `CONTACT_LOOKUP_HMAC_KEY`, tracked via `lookup_key_version`) is not
  implemented yet — this ADR's "Future extraction/evolution path"
  below is what a future checkpoint implements, not a currently
  working feature.
- `encrypted_value`/`lookup_hash` are `$hidden` on
  `App\Domain\Guardians\Infrastructure\GuardianContact` so an
  accidental `toArray()`/`toJson()` never serializes ciphertext or the
  lookup digest, even though no controller/API exists yet to make that
  mistake in this checkpoint.

## Future extraction/evolution path

A key rotation would: (1) introduce a new `CONTACT_LOOKUP_HMAC_KEY`
value and bump `hmac_key_version`; (2) run a backfill job that
re-computes `lookup_hash` for every row whose `lookup_key_version` is
older than the current version, using the OLD key to decrypt/re-derive
the normalized value's identity only from `encrypted_value` (never from
a plaintext column, since none exists) and the NEW key to compute the
new digest; (3) once every row is backfilled, retire the old key. No
schema change is required for this — `lookup_key_version` already
exists specifically to make it possible.
