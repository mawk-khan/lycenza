# HR.R1-C29: credential verification names the revision it verified (C14-N5) (2026-11-12)

**HR.R1 stays OPEN, and this is not the closure audit.** C29 closes the second half of C14-N5 for development under owner decisions C29-A, C29-B and C29-C (HR with Security, 2026-11-12).

| | |
|---|---|
| Baseline | `5be4f9ad` = `main` = `origin/main`; `bf627ae4` (C28 code) an ancestor; clean tree, one worktree, the historical stash and `integration/e16-r2` preserved; nothing published since C28 |
| Last full suite before C29 | `bf627ae4`: 11,691 cases, 465,650 assertions, 0 failures, 1 skip (ESI-12); counter 0/5 |
| Authorized | Owner, 2026-11-12: C29-A/B/C as recommended; implement and publish. Scope: qualification and certification verification only |
| Not changed | Who may verify (`hr.employees.qualifications.manage`, whole-School); no new MFA, independence rule or consumer prerequisite; Documents; Employee, Employment, Payroll, Leave, Staff Attendance; the shared runtime role |

## 1. The finding, verbatim

C14-N5 (`HR-R1-0-AUDIT-REGISTER.md`): "An edit racing a verification left the edited credential verified, and a verification names no version, so one landing after an edit attests content nobody may have read." C14 closed the first half: every writer decides on the locked row. It left the second half open: "a verification landing after an edit attests content the verifier may never have seen, because verify and reject name no version. [...] Binding it to what was viewed needs a version token and a screen change, which is a new verification policy this checkpoint may not introduce. Owner: HR with Security."

## 2. What a credential is here, and who owns it

"Credential" in C14-N5 means an Employee's **qualification** (`employee_qualifications`) or **certification** (`employee_certifications`). These are Restricted-tier HR facts recorded by HR and attested by an HR verifier. They are not login passwords, MFA factors, Documents or role grants. Neither table references a Document. HR document evidence is the separate `employee_documents` register (rule 132), and attaching a file never verifies anything.

| Fact | Owner | Canonical source | Writer | Version identity (after C29) | Verification evidence (after C29) | Readers |
|---|---|---|---|---|---|---|
| Qualification | HR | `employee_qualifications` | `EmployeeQualificationService` | `revision` (moved only by the database) | `employee_credential_verifications` (one row per decision) + projection `verification_status`/`verified_at`/`verification_revision` | Profile workspace, API presenter |
| Certification | HR | `employee_certifications` | `EmployeeCertificationService` | the same | the same | the same |
| Decision | HR | `employee_credential_verifications` | `CredentialVerificationDecisions` (called by the two services' verify/reject) | names `revision` | the row itself, append-only | Profile workspace (history, without the decider) |

**Consumers.** Nothing outside the profile reads verification status: no HR, Payroll, Leave, Staff Attendance, Teaching or other-domain decision depends on it (searched: `verification_status`/`verified_at` readers are the two services, the workspace and the two API presenters). So no prerequisite is added or changed: C29 fails closed only on the decision itself.

**Mutation paths that exist.** Create (`add`), edit (API `PATCH` only; the web has no edit form), archive (`remove`, closed reason), verify, reject. No replacement, renewal, revocation, expiry action or attachment exists. An archived row is frozen by `hr_profile_record_archive_guard`.

## 3. Reproduction on `5be4f9ad`

- **The race.** The committed pin `HrCredentialVerificationRaceTest::a_verification_landing_after_an_edit_attests_the_row_as_it_stands` passed on `5be4f9ad`. Two real sessions: the edit held open, the verification waiting on the row. The contender ended `ok:verified` and the row read `['Edited in a race', 'verified']`.
- **The stale page, without a race.** `verify()` and `reject()` took no input from the page. A verifier opens revision 1, an API `PATCH` lands, and the stale Verify attests the edited content. This is the same code path; C29's `a_verification_from_a_page_opened_before_an_edit_is_refused` is its regression test.
- **History lost.** An edit cleared `verified_at`, and the audit event named ids only, so nothing recorded which content had been verified.

## 4. The owner decisions (HR with Security, 2026-11-12)

Recorded through the owner's answers in this session. No other approval is claimed.

| | Decision | Rejected alternative |
|---|---|---|
| **C29-A** | **Version identity is a database revision.** Each credential row carries `revision`. Only the database moves it: any actual change to a verifiable field bumps it, and must reset verification. Every such change is material; there is no carve-out (the HR.2A conservative rule, unchanged). Verify and Reject name the revision their decision-maker saw. Any other revision is refused: API 409 `HR_CREDENTIAL_CHANGED`, missing 422, web refusal in the section. A credential reads verified or rejected only while `verification_revision` equals its `revision`, enforced in the database | A content digest (re-saving equal content keeps the verification): weaker to enforce in the database |
| **C29-B** | **Append-only decision rows.** `employee_credential_verifications`: credential, revision, decision, decider, database time. The runtime role inserts only; nothing updates a row; forced RLS; it leaves with its credential. The insert is the only thing that sets the projection. It records which revision was decided, never a copy of the Restricted fields | A content snapshot per decision (duplicates Restricted data); audit metadata only (no database binding) |
| **C29-C** | **Legacy statuses are kept, labelled unbound.** A credential verified or rejected before C29 keeps its status with `verification_revision` NULL, shown as "before versions were recorded". No decision row or revision is fabricated. Any edit resets it as before; a new Verify records the first bound decision. Nothing can write that state again | Reset legacy rows to unverified (destroys the shown state) |

**Version versus content.** C29-A chose version identity. Re-saving identical values is not a change: the database does not move the revision and, since the delta fix, the service does not reset the status. Any actual value change, of any verifiable column, is material.

## 5. What was built

**Migration `2027_02_18_090000_bind_credential_verification_to_a_revision`** (forward; `down()` refuses while any decision exists, after lifting FORCE so the check and the DROP are atomic, rule 143):

- **Columns.** `revision bigint NOT NULL DEFAULT 1` and `verification_revision bigint NULL` on both credential tables, with CHECKs:
  - the status domain;
  - `revision >= 1`;
  - unverified ⇒ no `verification_revision`, and a decided status names the current revision or none (legacy).
- **Key.** A unique `(id, employee_id, school_id)` on each credential table for the decision's same-Employee, same-School key.
- **`hr_credential_revision_guard`** (INVOKER, `search_path = pg_catalog, public, pg_temp`), BEFORE INSERT OR UPDATE on both tables:
  - **Insert:** a credential is born unverified at revision 1.
  - **Revision:** the column is the database's own, and a writer cannot set it.
  - **Edits:** any change outside the projection, archive marks and timestamps moves the revision, and must reset the status. A future column counts as content (fail closed).
  - **Setting a decided status:** allowed only when it equals the latest decision (by `sequence`) on the current revision. That decision also fixes `verified_at` exactly.
  - **Resetting:** a decided revision is never reset without an edit (delta fix, review LOW-2).
- **`employee_credential_verifications`.** School RESTRICT; decider RESTRICT (rule 97); composite keys to its own Employee's credential in its own School, ON DELETE CASCADE. Its CHECKs pin the kind/column shape, the decision domain and `revision >= 1`. Forced RLS; the runtime role holds SELECT, INSERT (`'SI'`).
- **`employee_credential_verifications_guard`** (INVOKER), BEFORE INSERT:
  - sets the database time;
  - takes the credential FOR UPDATE, and refuses another Employee's credential, an archived one, or a revision other than the current one;
  - refuses a decision the revision already carries, read from both the projection and the latest decision, so one statement cannot record it twice (review LOW-1);
  - assigns `sequence` itself after the lock, discarding any value a writer supplied, `OVERRIDING SYSTEM VALUE` included (review MEDIUM-1, LOW-3).
- **`employee_credential_verifications_apply`** (INVOKER), AFTER INSERT: sets the credential's status, `verified_at` and `verification_revision` from the decision, on that revision only.
- **`trg_employee_credential_verifications_immutable`** (`hr_history_row_immutable`): no UPDATE, for any role.

**Application.**

- **The one decision writer.** `CredentialVerificationDecisions` compares the revision on the row the service holds FOR UPDATE, after `requireOperational()` and the self-administration refusal. A different revision throws `CredentialRevisionChangedException` (409) and writes nothing. The same decision on a revision that already carries it is a no-op, with no row and no audit.
- **Services and controllers.** `verify()`/`reject()` take `int $revision`. The audit adds `revision` and `decisionId` (ids only). `update()` resets only on an actual change. The API presents `revision` and `verificationRevision`.
- **Workspace.** It adds `revision`, `verification_revision` and `verification_history` (decision, revision, time, newest first; never who decided), loaded in one query.
- **Page.** It sends the revision shown. It labels each status "verified (version N)", "unverified (version N)" or "verified before versions were recorded". It lists the history, marking an earlier version as such, and shows a changed-record refusal in its own section. Verify stays offered on a legacy status, so it can be bound. A date sent with a time of day is compared as the day the column stores.
- **Factories.** The unused `verified()`/`rejected()` factory states were removed: the database refuses a credential born decided.

## 6. Registers and verifier

- **Allowlist and registers.** `HR_FAMILY_RUNTIME_PRIVILEGES` now has 57 tables (`employee_credential_verifications` = `SI`). The other registers it joins:
  - `NO_RUNTIME_DELETE`;
  - `HR_FAMILY_TRIGGERS`: 108 triggers, the 5 new ones pinned by `tgfoid` and definition;
  - `HrWriterBoundaryTest::WRITERS` (`CredentialVerificationDecisions`, 57 family models);
  - `HrModelMassAssignmentCatalogueTest` (CLOSED, `$guarded = ['*']`; inventory 91);
  - `HrSchoolGuardCoverageTest` (checked by the caller);
  - retention `hr_ancillary`; `UserReferenceCatalog` (`decided_by_user_id` retained);
  - `EmployeeRetentionClassificationTest`; forced-RLS count 256;
  - `RollbackProductionShapeTest` evidence (its sentinel builder now leaves identity columns to the database).
- **`hr_credential_verification_invariants`** (new `platform:verify-database` check) pins:
  - the three function bodies (md5, comments stripped), the C28-N3 discipline for new guards;
  - the nine CHECKs;
  - both keys (columns, target, ON DELETE CASCADE);
  - the identity column;
  - forced RLS.
  Bindings, modes, owners and paths stay with `hr_family_trigger_functions`.

## 7. Evidence

| Proof | Test | Result |
|---|---|---|
| Stale page refused (the C14-N5 reproduction); forged revisions 0, 2, 999 refused; nothing decided | `HrCredentialVerificationRevisionTest` | pass |
| Race, both orders: decision waiting on an edit is refused (4 cases: verify/reject × qualification/certification); verification first, then edit: reset, revision 2, decision on revision 1 kept | `HrCredentialVerificationRaceTest` | pass (two real sessions, contender observed blocked) |
| History kept across an edit; re-verification binds revision 2; workspace newest first, no decider | `HrCredentialVerificationRevisionTest` | pass |
| Duplicate decision is a no-op (no row, no audit); a changed mind on one revision is a second recorded decision | same | pass |
| Legacy status shown unbound, then bound by a new verification; an edit of a legacy status resets it; no row fabricated | same | pass |
| API: 422 missing/invalid, 409 stale with no exception text, 200 with revision fields; other School 404; viewer 403 before validation | same | pass |
| Web: missing revision and changed record shown in the section; success redirects; page props carry revision and history | same | pass |
| Raw runtime role: projection without a decision, revision write, edit keeping status, decision on another revision/Employee/School/kind, born-decided insert, UPDATE/DELETE/TRUNCATE of a decision, owner UPDATE, restore of an earlier decision, supplied `sequence`, multi-row duplicate, reset without edit; cross-School read and missing context; cascade with the credential | `CredentialVerificationRawRoleTest` | pass; each refusal matched on its own guard's words |
| Verifier: passes on the real schema; eight breaks each detected and named, restored in `finally` | `HrCredentialVerificationVerifierTest` | pass |
| Suspended School, self-administration, authorization, archived row | existing `HrProfileSchoolCheckTest`, `HrMutationAuthorizationTest`, `HrCredentialVerificationRaceTest`, revision test | pass |

Suites, static checks, rollback proof, CI and publication: §9.

## 8. Reviews

1. **Independent Security review** of the candidate, read-only. **No HIGH.**
   - **MEDIUM-1:** a supplied identity value could pin a credential's "latest" decision or collide across Schools. Fixed.
   - **LOW-1:** a multi-row duplicate. Fixed.
   - **LOW-2:** the projection could be reset without an edit and later restored. Fixed in the database and the services.
   - **LOW-3:** sequence order not lock order (raw only). Fixed by MEDIUM-1's change.
   - **INFO, fixed:** the verifier docblock placement, the error key per section, the list key.
   - **INFO, recorded, not changed:**
     - the history list is unbounded (it grows only by authorized decisions);
     - two verifiers can override each other on one revision (no independence rule is in scope).
2. **Independent delta review** of the fixes. **No HIGH or MEDIUM remains.** It confirmed each fix:
   - PostgreSQL 16 stores the identity value a BEFORE ROW trigger assigns.
   - The runtime role has USAGE but not UPDATE on the sequence.
   - No legitimate path is blocked: verify, reject, verify; legacy rows; an edit after a reject; archive.
   - It found no remaining way to make the status disagree with the latest decision.

   It found two items, both fixed:
   - **LOW-A:** a date sent with a time of day counted as a change in the application but not in the database. On a decided revision that became a 500. Dates are now compared as the day stored (`the_same_day_with_a_time_of_day_is_no_edit`).
   - **INFO-B:** the verifier now also requires USAGE and refuses UPDATE on the decision sequence. One more verifier break detects this.

   Not re-reviewed: those two last fixes, which are small and each covered by a test.

## 9. Publication evidence

- **First candidate `90ddf328`, CI run 43264296121: FAILED on attempt 1.** Shards 2 and 4 passed. Shard 1 failed one test, `UserReferenceCatalogTest` (the decider column is a new `users` reference, 193 → 194). Shard 3 failed one test, `ApprovalWorkflowRegistryTest` (`decided_by_user_id` is decision-shaped).
- **Cause:** registers outside the HR, Postgres and Retention suites run locally. Not a code defect.
- **Fix:** the decider is declared in `ApprovalWorkflowRegistry::NOT_WORKFLOWS` as a direct HR attestation (no request, no second decider, C29-N1). The reference counts move by one.

The final candidate, its CI and the publication are recorded in the evidence commit.

## 10. Open after C29

| Item | Status |
|---|---|
| C14-N5 | **CLOSED for development** (§3–§8). Production use of a verification as evidence that a person checked a document remains a staging/production matter, as C14 said |
| C29-N1 (INFO) | Two verifiers may decide the same revision differently, the latest standing; no maker/checker rule (not in C29 scope). Owner: HR |
| C29-N2 (INFO) | The verification history is unbounded per credential; it grows only through authorized decisions. Owner: HR |
| C28-N1, C28-N3, C28-N4 | Unchanged, open (Platform, before staging) |
| C14-N2, D-3 (LVA-1/TRR-2), person-level Identity MEDIUM | Unchanged, open, blocking under C27-C |
| Seven P3 (HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8) | Unchanged, each needs its own disposition |
| R1-02 | PARTIAL, unchanged |
| Legal, DPO, Security gates (E21-D9, E45/ESI-12, E47, HRX-L1–L4, S-4, S-7, X-1, ADR 0036, ADR 0040, D-11) | Unchanged |
| Accessibility | Automated checks for the changed page in §9; manual screen-reader review not performed (C18-A12, WO-5 and C20-N1 carry-forwards unchanged) |
