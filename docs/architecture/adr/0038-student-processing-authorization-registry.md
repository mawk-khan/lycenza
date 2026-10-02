# ADR 0038: Student Processing Authorization Registry

- Status: Accepted
- Date: 2026-09-05

## Context

`docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` (Lead
Privacy Counsel & Data Protection Officer — India Operations,
2026-09-03) approves architecture/backend implementation of the future
StudentMark checkpoint with conditions. One of those conditions: the
platform must be able to record the basis authorizing a specific
Student's data processing before marks entry is enabled for that
Student, distinguishing Guardian consent (minor), direct Student
consent (adult), and statutory/legitimate School purpose, each with
provenance (basis type, when recorded, who recorded it).

This is Phase 0H.4D-P2, the second Students/SIS platform prerequisite
for StudentMark (after Phase 0H.4D-P1's Staff MFA Foundation, ADR
0037). It does not implement StudentMark, P3 (elective historical
eligibility), or any Student/Guardian-facing surface.

## Decision

### Domain ownership: Students/SIS, not Examinations, not Guardians

`docs/architecture/DOMAIN-MAP.md` already establishes Students/SIS as
*"the record most other modules eventually reference; does not depend
on any module that references it"* — Examinations' own row lists its
outward dependencies as Academic Structure/Students/SIS/Academics,
never the reverse. Anchoring processing authorization in Examinations
(because StudentMark is its first consumer) would invert this
established direction. Anchoring it in Guardians was also rejected:
Guardian consent is only one of three approved bases (adult-Student
and statutory bases involve no Guardian at all), and Guardians itself
already depends on Students/SIS, so anchoring there would force
Examinations to gain a second, unnecessary dependency edge. Age/DOB —
the variable determining basis-type eligibility — already lives on
`Student`. All new code lives under `App\Domain\Students`.

### Canonical fact: one append-only event per authorization decision

`StudentProcessingAuthorization` — one immutable, append-only row per
recorded processing-authorization decision for one Student and one
purpose. Mirrors `CommunicationDomainConsentEvent`'s proven shape
(append-only, current state always derived from the latest applicable
row, never a separately stored/mutated status column) — but is **not**
Communications consent, and the two must never substitute for each
other: Communications answers *"may this channel be used"*; this
registry answers *"what basis permits School processing of this
Student's protected data for this purpose."* Unlike Communications'
two-table split (a mutable current-preference table alongside its
append-only ledger, needed because channel preference is checked at
message-send volume), this registry has no comparable high-frequency
read path — deriving current state from the ledger on every read is
sufficient and avoids a second mutable table's drift risk entirely.

### Purpose: one closed value today, not "all processing"

`purpose` is a closed, CHECK-constrained column, seeded with exactly
one value this checkpoint needs: `academic_records`. Not an "all
Student processing" boolean — Communications consent and Guardian-
portal account-linking already separately own their own narrower
facts, and a blanket authorization concept would silently overlap and
conflict with those. The column accepts additional closed values later
without a redesign, but none are invented speculatively ahead of an
actual consumer.

### Basis: three closed values, never a `consent = true` boolean

`basis_type` ∈ `guardian_consent` | `adult_student_consent` |
`statutory_school_purpose` — the three bases the determination
distinguishes. A boolean would destroy the distinction the legal
determination requires. No free-text legal-basis string is ever
accepted from a user; for `statutory_school_purpose`, the School as
Data Fiduciary remains responsible for the underlying legal basis —
this registry records that authorized staff asserted it, it does not
adjudicate the law.

Provider shape, database-CHECK-enforced (`spa_basis_shape_check`):
`guardian_consent` requires `student_guardian_relationship_id` (never
a separately-stored `provider_guardian_id` — Guardian identity is
derived from the referenced relationship, avoiding a second,
potentially-contradictory pointer); `adult_student_consent`'s provider
is the record's own `student_id` (no platform User account required —
the School records that an offline authorization was obtained);
`statutory_school_purpose` carries no natural-person provider at all.

### Guardian-consent eligibility: gated on `is_legal_guardian`, evaluated dynamically

`student_guardian_relationship_id` is a composite FK against
`student_guardian_relationships(id, school_id, student_id)` (an
additive `student_guardian_relationships_context_unique` key, the same
"wider composite unique key on the parent, for one specific child's FK"
pattern `examinations_context_unique`/
`syllabus_units_offering_context_unique` already established) —
proving the referenced relationship belongs to the SAME School and the
SAME Student, structurally. At record time, the relationship must
currently have `is_legal_guardian = true`
(`GuardianRelationshipNotEligibleException` otherwise). A dedicated
Guardian-relationship *verification* workflow (independent evidence
beyond the existing boolean) is explicitly out of scope here — the
recording staff User's own capability+MFA-gated action is the
attestation, consistent with how every other privacy-adjacent fact in
this codebase already works (`StudentGuardianAccountLink` has no
separate verification ledger either).

Qualification is re-evaluated on every read against the relationship's
**current** `is_legal_guardian` value, never a value frozen at grant
time: if staff later correct a relationship to no longer be a legal
guardian, the historical grant remains untouched evidence, but stops
qualifying for future processing immediately, with no write to the
ledger required.

### Age: School-local, dynamically evaluated, never frozen

`students.date_of_birth` is `date`, NOT NULL, correctable via ordinary
`StudentService::update()` with no prior downstream reaction. This
checkpoint introduces `App\Domain\Students\Domain\StudentAge` — the
first canonical age-computation helper in this codebase — evaluating
age via explicit School-local (`SchoolTimezone`) year/month/day
comparison, never approximate month-count arithmetic or server
UTC/PHP-local time. A Student becomes an adult (age ≥ 18) on their
18th birthday, inclusive, in the School's configured calendar date.
`guardian_consent` qualifies only while age < 18; `adult_student_consent`
only while age ≥ 18; `statutory_school_purpose` is age-independent.
Because qualification is evaluated against the Student's **current**
DOB at read time (never captured/frozen at grant time), a DOB
correction that moves a Student across the 18-year boundary changes
future qualification automatically, with no write to the ledger and no
background job.

### Lifecycle: append-only, self-referencing termination, single-termination invariant

`status` ∈ `recorded` (a grant; `terminates_authorization_id IS NULL`)
| `withdrawn` (the consent provider ended it) | `revoked`
(administrative invalidation — deliberately distinct from provider-
initiated withdrawal) | `superseded` (a new, different valid record
explicitly replaces this one). A terminal row never mutates the grant
it ends; it inserts a new row pointing at it via
`terminates_authorization_id`. Two database-enforced invariants, never
application-check-then-insert:

- `student_processing_authorizations_one_termination_per_grant`, a
  partial unique index on `terminates_authorization_id` WHERE NOT
  NULL — one recorded grant cannot be terminated twice; a race between
  e.g. a withdrawal and a revocation against the same grant leaves
  exactly one winner (`UniqueConstraintViolationException` translated
  to `ProcessingAuthorizationAlreadyTerminatedException`), proven under
  real two-process concurrency
  (`ProcessingAuthorizationConcurrencyTest`).
- The self-referencing composite FK on
  `(terminates_authorization_id, school_id, student_id, purpose)`
  (`spa_terminates_context_foreign`, against this table's own
  `spa_context_unique` key) proves a terminal event can only terminate
  a prior grant for the exact same School, Student, and purpose —
  structurally, not by application validation alone.

"Current" qualifying state is always **derived** by
`StudentProcessingAuthorizationReadService` — a `recorded` row with no
later row terminating it, filtered by the basis-type's own current-time
rule — never a stored `is_active`/`current_status` column.

### Append-only enforcement: triggers, not bare privilege revocation

Unlike `communication_domain_consent_events` (append-only via
`TenantRls::makeAppendOnly()`, i.e. `REVOKE UPDATE, DELETE`), this
table cannot use bare revocation: PostgreSQL requires UPDATE-or-DELETE
privilege to acquire a row lock at all, and `SELECT ... FOR UPDATE` is
required both by the atomic termination claim
(`StudentProcessingAuthorizationService::terminate()`) and the lock-
capable read seam (below) — revoking the privilege and needing to lock
rows are in direct conflict under Postgres' actual privilege model
(discovered empirically while writing this checkpoint's concurrency
tests). The fix: privileges stay granted; a `BEFORE UPDATE` trigger
unconditionally rejects any update, and a `BEFORE DELETE` trigger
rejects any DELETE at `pg_trigger_depth() = 1` (i.e. a directly-issued
DELETE) while allowing depth > 1 (a delete reached via an already-in-
progress cascade — specifically, this table's own
`schools.school_id ON DELETE CASCADE`, which must continue to work when
a School itself is hard-deleted). An equivalent, arguably more
explicit (named-reason rather than bare "permission denied") database-
enforced immutability guarantee.

### Deterministic lock order across both write and read-lock paths

Both `StudentProcessingAuthorizationService::terminate()` and
`StudentProcessingAuthorizationReadService::
lockQualifyingAuthorizationIdForProcessing()` lock the Student row
before locking any `StudentProcessingAuthorization` row for that
Student — a real PostgreSQL deadlock between the two methods racing
the same grant row from opposite lock orders was discovered and fixed
empirically during this checkpoint's concurrency-test development.
This is the same "establish one documented lock order across every
code path touching the same rows" discipline this codebase already
uses elsewhere (Attendance's Section-before-Enrollment order, Hostel's
Student-then-Bed order).

### Tenant isolation: composite FK + RLS, no cross-School reference possible

`school_id` + composite FK `(student_id, school_id) → students(id,
school_id)` (the pre-existing `unique(id, school_id)` on `students`);
`TenantRls::enable()`. Cross-School authorization is structurally
impossible, proven with real-Postgres raw-SQL tests
(`ProcessingAuthorizationRlsAndAppendOnlyTest`).

### Read-service seam: Students/SIS decides, Examinations only consumes

`StudentProcessingAuthorizationReadService` is the ONE place "is this
Student currently authorized for this processing purpose" is decided.
`assertAuthorizedForProcessing()`/`qualifyingAuthorizationIdForStudent()`
are the ordinary read seam; `lockQualifyingAuthorizationIdForProcessing()`
is the lock-capable seam a future StudentMark creation path must use
instead (caller must already be inside `DB::transaction()` — it locks
the Student row, then the deterministic qualifying grant row, and for
`guardian_consent` the relied-upon relationship row, re-evaluating
qualification under those locks before returning) so its own mark-
creation transaction cannot race a concurrent withdrawal/revocation
committing in between the check and the write — the identical
discipline the Phase 0H.4D-P1 replay-security correction already
established for TOTP verification. A future StudentMark row is
expected to snapshot the returned id as its own provenance FK, so a
later withdrawal never retroactively invalidates an already-created
historical mark (core academic records are retained per the
supersedes-decision).

**Freshness correction (Phase 0H.4D-P2 lock-freshness correction):**
the caller may pass any `Student` instance it already holds, but the
method uses that instance for its `->id` ONLY — every mutable field
the qualification decision depends on (`date_of_birth`) is read from
the row this method itself locks under `FOR UPDATE`, never from the
caller-supplied snapshot. The closure audit found the original
implementation locked the fresh row and then discarded it, continuing
to evaluate age against whatever `Student` object the caller happened
to be holding — meaning a concurrent DOB correction landing between
the caller's own fetch and this method's call would not be reflected
in the qualification decision, even though the row lock itself was
genuinely held. This made the ordinary read methods and the
lock-capable seam inconsistent with each other in an important way:
`relationshipStillLegalGuardian()` was always a fresh, un-cached
lookup, while age was not. Both mutable qualification inputs (age via
the locked Student row; legal-guardian status via the relationship
row, already re-fetched fresh) are now treated symmetrically — see
`StudentProcessingAuthorizationReadService`'s class docblock for the
explicit statement of which methods carry which concurrency guarantee.
The ordinary (non-locking) read methods
(`isAuthorizedForProcessing()`/`assertAuthorizedForProcessing()`/
`qualifyingAuthorizationIdForStudent()`/`qualifyingGrants()`) are
unaffected and unchanged: they still evaluate age against whatever
`Student` the caller supplies, with no row lock and no transactional
serialization guarantee — correct for an ordinary point-in-time read,
but never suitable for a future check-and-write consumer, which must
use `lockQualifyingAuthorizationIdForProcessing()` instead.

**Documented future StudentMark usage sequence** (not implemented by
this ADR — recorded here so the future consumer follows the contract
this seam was built for, rather than re-deriving it):

1. Begin a `DB::transaction()`.
2. Obtain the current Student identity (route-bound/freshly queried,
   as every real caller already does).
3. Call the Students-owned
   `StudentProcessingAuthorizationReadService::lockQualifyingAuthorizationIdForProcessing()`
   — never query `student_processing_authorizations` directly.
4. Receive the exact qualifying authorization id, decided against the
   freshly locked Student (and, for `guardian_consent`, relationship)
   row — never a pre-lock snapshot.
5. Perform whatever remaining StudentMark-specific eligibility checks
   and locks that future checkpoint needs, inside the SAME
   transaction.
6. Insert the StudentMark row with the returned authorization id as
   its own provenance FK.
7. Commit.

Examinations code must never query a P2 model directly at any step.

Deterministic provenance selection (never PostgreSQL's unspecified row
order): among every currently-qualifying active grant, the most
recently `recorded_at`, ties broken by `id` descending. This ordering
exists only to pick one stable id for provenance — it is **not** a
legal precedence ranking between basis types; any qualifying active
basis is independently sufficient, and withdrawing one never affects
another.

Examinations/StudentMark is expected to call this service exclusively
and never query `student_processing_authorizations` directly — the
same discipline Attendance's `StudentEnrollmentRosterReadService`
consumption already established.

### Classification, audit, capability, MFA, feature flag

Classified **Highly Sensitive** — more sensitive than the Sensitive
tier previously assigned to historical StudentMark-eligibility
provenance, because this record IS the legal-basis assertion for
processing a minor's data, not merely a derived eligibility fact (see
`docs/security/DATA-CLASSIFICATION.md`).

Audited on `SchoolAuditEvent` (School-scoped — never
`PlatformAuditEvent`), four closed actions
(`students.processing_authorization.{recorded,withdrawn,revoked,superseded}`),
metadata bounded to ids/purpose/basis_type/status — never DOB,
Guardian/Student names, or `note` text.

`students.processing_authorizations.view`/`.manage` capabilities
(namespace `school`, matching the existing `students.view`/
`students.manage` convention), granted initially to `school_admin`/
`principal` only — deliberately NOT implied by `students.manage` alone,
since this is a privacy/legal control, not routine SIS data entry.

Every route composes the relevant capability with `mfa` — the first
genuine production consumer of ADR 0037's capability+MFA seam, not a
demonstration route: a compromised password-only administrative
session must not be able to falsely establish or destroy a processing-
authorization record.

A default-off `students.processing_authorizations` feature flag
(`FeatureFlagResolver::isEnabledForSchool()`) gates the surface's
visibility per School — never a substitute for capability/MFA
authorization, per that resolver's own documented rule.

### No DELETE, no outbound events, no Student/Guardian-facing surface

No DELETE route exists or ever will (matching every other "reference/
history entity has no delete route" precedent in this codebase).
"Retention is indefinite by default" means precisely this, no more and
no less (Phase 0H.4D-P2 lock-freshness correction §19 — this wording
replaces an earlier, unqualified version the closure audit flagged as
ambiguous): every *ordinary application path* provides no deletion or
purge of a `StudentProcessingAuthorization` row — there is no DELETE
route, no scheduled purge job, and no TTL, and the composite FKs from
this table to `students`/`student_guardian_relationships` are all
`ON DELETE RESTRICT`, so a Student or StudentGuardianRelationship
referenced by a P2 row cannot itself be deleted while the reference
exists. The ONE way P2 history can disappear is as part of a top-level
School (tenant) hard destruction — `student_processing_authorizations`
cascades from `schools.school_id ON DELETE CASCADE`, the same as every
other School-owned table. This is inherited platform-wide tenant-
destruction behaviour, not a P2-specific retention mechanism, and no
production route performs it today (no Student or School DELETE
endpoint exists anywhere in this codebase). Retention here is NOT a
cryptographic or otherwise-enforced permanence claim independent of
that tenant-destruction model — it is "safe from every ordinary
application action," not "unconditionally indestructible." An explicit
future retention *policy* (e.g. a bounded retention window with its
own deletion path) remains future work; none is invented here. Zero
domain events are emitted; this registry is never externally webhook-
subscribable (`App\Support\Webhooks\WebhookEventRegistry`'s closed
catalog, unless explicitly and separately added later). No Student-
facing or Guardian-facing route exists — staff records what was
obtained through approved School procedure.

## Consequences

- A future StudentMark creation path has a single, authoritative,
  lock-capable seam to call — it never re-derives eligibility itself
  and never becomes this registry's system of record.
- Phase 0H.4D-P3 (elective historical eligibility) remains fully
  independent: this registry has no `SubjectOffering`/elective/Paper-
  date reference, and P3 has none of this registry's concerns.
- `docs/security/DATA-CLASSIFICATION.md`'s blanket children's-data
  `[LEGAL REVIEW REQUIRED]` marker has been narrowed (in this same
  Phase 0H.4D-P2 change) to reflect that architecture/backend is
  approved with conditions, while still-open gates (production
  enablement, Student-facing, Guardian-facing, result publication,
  report cards, transcripts) remain visible and distinct.
- The `School OS` runtime role's UPDATE/DELETE privileges on this table
  remain granted (unlike every other append-only ledger in this
  codebase) — this is a deliberate, documented exception to the
  `TenantRls::makeAppendOnly()` convention, not a drift from it; a
  future append-only table that also needs row-locking should follow
  this same trigger-based pattern rather than bare privilege
  revocation.

## Amendment — E21.3B retention (2026-10-02)

The registry's "indefinite pending an explicit future retention policy" is
replaced by the adopted policy (E21.2G P1, project-adopted, pending legal
ratification): a Student's processing authorizations are kept **with the
Student core record**, 25 calendar years after the Student's final exit,
and removed in the core purge's one-Student transaction.
- Still no DELETE route, and still append-only for every runtime path: the
  guard trigger admits a delete only inside
  `retention_expire_student_processing_authorizations` (transaction-local
  flag AND the table owner's privileges), which re-proves the core floor
  in the database.
- A guardian-consent authorization's relationship goes with it.
- An authorization of a current Student is never eligible.
See `docs/security/E21-RETENTION-DETERMINATION.md` §5.6.
