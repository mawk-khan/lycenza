# E21-L1 — User Identity Erasure: Decision Request

> ## STATUS: AWAITING QUALIFIED LEGAL/COMPLIANCE DECISION
>
> This document is the **decision package** for the qualified reviewer. It
> records repository facts, the current fail-closed behaviour and the
> options to evaluate. It contains **no decision**: every field in §8 is
> blank until the qualified reviewer completes it.
>
> No engineering is authorized by this document. User anonymisation, unlink
> or deletion must not be implemented until §8 is completed.

- **Prepared:** 2026-10-03, at `main` `d645a41` (E21.3F; full isolated
  regression 7,403 tests, 0 failures, 1 skip).
- **Gate:** ADR 0058 row E21 closes on a qualified decision per v1
  category. User-identity erasure is the one category without one
  (`E21-CLOSURE-AUDIT.md` §1, §8 I5).
- **Related:** `E21-RETENTION-DETERMINATION.md` (D1, D6, D10), ADR 0042,
  ADR 0047, ADR 0059, `docs/security/DATA-CLASSIFICATION.md`.
- **Independent of:** E33 / TCH-L1 (not addressed here).

## 1. What a User identity is

`users` (platform table, no `school_id`) holds: `id`, `name`, `email`,
`email_verified_at`, `password`, `remember_token`, `is_disabled`,
`disabled_at`, `credential_version`, timestamps. One User may be a member of
several Schools (`school_memberships`: `user_id`, `school_id`, `status`,
`invited_at`, `joined_at`). The User's personal identity is therefore
**shared across Schools**; School records refer to it by `id` only.

Audit rows do not copy the name or email. `school_audit_events` stores
`actor_user_id` (plus event, subject, request id, metadata);
`platform_audit_events` also stores `ip_address` and `user_agent`.
Identity is reached by joining to `users`.

## 2. Where retained records reference a User (live FK catalog)

87 foreign keys reference `users.id`. By delete action: 47 RESTRICT, 19 NO
ACTION, 16 SET NULL, 5 CASCADE.

| Category (this request §) | References | Delete action | Retention governing the row |
|---|---|---|---|
| 1. Audit actors | `school_audit_events.actor_user_id`, `platform_audit_events.actor_user_id` | SET NULL | D1, 7 y |
| 2. Authority history | `membership_role_assignments.assigned_by/revoked_by` (SET NULL); `platform_role_assignments.user_id/granted_by/revoked_by`, `group_role_assignments.user_id/granted_by/revoked_by`, `school_elevations.actor_user_id`, `teaching_assignments.created_by/ended_by`, `api_clients.created_by/revoked_by` (RESTRICT) | mixed | D6, 7 y |
| 3. Memberships | `school_memberships.user_id` | CASCADE | tenant lifetime (E21.2G I1) |
| 4. Employee link | `employees.user_id` | RESTRICT | D9 (a linked User already blocks Employee expiry) |
| 5. Guardian/Student links | `student_guardian_account_links.linked_by/unlinked_by`; `identity_account_invitations.*` | NO ACTION | E21.2G I2/I4 |
| 6. Erasure cases | `erasure_cases.subject_id` (`subject_type = 'user'`, no FK) | — | D10, 7 y after close |
| 7. Finance/Payroll operators | fees, payments, receipts, concessions, late fees, structures (`*_by_user_id`, RESTRICT); `payroll_runs.prepared/approved/posted_by`, payroll adjustments and postings `actor_user_id` (RESTRICT); `financial_periods.closed_by` (SET NULL) | RESTRICT mostly | D8 / D9 |
| Other operational | Communications authors/recipients (NO ACTION), attendance `submitted_by` (RESTRICT), Documents `uploaded_by` (SET NULL), notifications, settings | mixed | D3, D5, A1 … |
| Authentication only | MFA factors/recovery codes, recovery/activation credentials | CASCADE | technical |

The full list is reproducible with the catalog query in §9.

## 3. Current behaviour (fail closed)

- **No application path deletes, anonymises or unlinks a User.** A
  platform-scope User erasure case plans `user_identity` as
  `dependency_blocked` (an active membership) or `policy_unresolved`
  (`audit_actor_anonymization_undecided`) and executes nothing
  (`UserErasureAdapter`).
- A School-scope case (Student, Guardian, Employee) acts only on that
  School's records; it never reaches another School or the User.
- Legal holds override erasure (`RETENTION_HOLD_*`).
- Retention never unlinks a User to make an expiry possible.

## 4. Finding for the reviewer and for any later implementation

**F1 — the database alone would not preserve history on a raw User delete.**
The runtime role holds DELETE on `users` (no application code uses it). A
raw `DELETE FROM users` is refused wherever a RESTRICT reference exists
(finance, payroll, grants, teaching, elevations …). For a User who has
none, it would **succeed** and, by the FK actions:
- null the actor of that User's audit events (SET NULL) and of role-grant
  history (SET NULL), i.e. irreversible, unplanned anonymisation;
- delete every membership of that User in every School (CASCADE).

No path does this today, and this request does not change it. Whatever the
decision, the implementing checkpoint should replace these implicit FK
actions with an explicit, reviewed mechanism (and likely revoke runtime
DELETE on `users`). Recorded here so the decision does not assume the
database already guarantees Option A.

## 5. Cross-School position (facts)

- The `users` row is global; each School holds only references.
- Example: School A approves an erasure request; School B keeps an active
  membership. Today: the User stays (active membership blocks), School A's
  own Employee/Guardian/Student records follow School A's case and periods,
  School B is untouched.
- Any minimisation of the global row (name, email) is visible to every
  School at once. A per-School pseudonym is technically possible (a School
  never needs another School's actor identity), but the global row would
  still exist while School B depends on it.

## 6. Options to evaluate (technical implications only)

| Option | What changes | Preserves | Technical cost / risk |
|---|---|---|---|
| **A. Retain while any dependency exists** | Nothing now; the User is minimised or deleted only after the longest retaining period (D1 7 y, D6 7 y, D8/D9, tenant-lifetime memberships) ends | full evidence | Smallest change, but memberships are tenant lifetime, so in practice the User is retained for the School's lifetime unless memberships policy changes. Needs F1 hardening |
| **B. Pseudonymous historical actor** | Actor references point to a durable, non-identifying actor key | sequence, same-actor linkage, authority provenance | Touches ~87 columns or adds an actor indirection; append-only ledgers need a narrow privileged rewrite path (E21.2B pattern) or the indirection must exist before history is written |
| **C. Tombstoned User** | `users` row kept, non-login; name/email/password/remember token/MFA removed or replaced | referential integrity, all FKs unchanged | Simplest minimisation; the `id` (a UUID) remains a stable pseudonymous key; email uniqueness and login paths need a defined tombstone shape; audit `ip_address`/`user_agent` need a separate decision |
| **D. Category-specific** | e.g. C for the identity row, A for authority evidence, B for audit | per category | Each category needs its own rule in §8 |

Questions the options leave to the reviewer (from the E21-L1 brief): whether
the historical actor is part of required audit/authority evidence for the
whole D1/D6 period; whether a reversible mapping under legal hold is
permitted; whether treatment changes after the period ends; whether
operator identity on financial records (`created_by`, `approved_by`,
`posted_by`, `reversed_by`, `closed_by`) may be pseudonymised while the
transaction itself is retained; whether ended memberships may keep the
person's identity indefinitely.

**Jurisdiction.** The repository fixes no jurisdiction. If production
deployments differ materially, the decision should name a jurisdiction
profile rather than one universal rule.

## 7. Outcomes and what follows each

- **APPROVED (Option A, current model):** no new erasure engineering;
  record the decision, still implement the F1 hardening as a small bounded
  change, then move E21 to final ratification and production configuration.
- **APPROVED WITH CONDITIONS:** one bounded checkpoint, provisionally
  **E21.4 — User Identity Minimization**, implementing exactly §8's
  conditions. Not started until §8 is complete.
- **REQUIRES REDESIGN:** record the required architecture; E21 stays OPEN.

Even after a decision, production clearance also needs: final D0–D13
ratification, production retention settings, backup/object lifecycle,
log/metric retention, scheduler and alerts, legal-hold review, and an
explicit tenant-purge authorization before any destructive School purge
(`E21-CLOSURE-AUDIT.md` §9–§10).

## 8. Decision record (to be completed by the qualified reviewer)

```text
Decision date:
Qualified reviewer / function:
Jurisdiction or deployment scope:
Decision status:            APPROVED | APPROVED WITH CONDITIONS | REJECTED / REQUIRES REDESIGN

Treatment (IDENTITY MAY REMAIN | MUST BE MINIMIZED | MAY BE PSEUDONYMIZED |
           MUST BE REMOVED WHEN PRACTICABLE | OTHER — conditions):
- audit actor:
- authority-history actor:
- membership identity:
- Employee account link:
- Guardian/Student account link:
- compliance-case identity:
- Finance/Payroll operator identity:
- platform audit IP address / user agent:

Legal-hold behaviour:
Cross-School behaviour:
Required implementation conditions:
Historical pseudonymization irreversible?:
Reversible legal-hold mapping permitted?:
Required production documentation:
Re-review trigger:
```

## 9. Reproducing the inventory

```sql
SELECT c.conrelid::regclass, a.attname, c.confdeltype
  FROM pg_constraint c
  JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
 WHERE c.contype = 'f' AND c.confrelid = 'users'::regclass
 ORDER BY 1, 2;
-- confdeltype: r RESTRICT, a NO ACTION, n SET NULL, c CASCADE
```
