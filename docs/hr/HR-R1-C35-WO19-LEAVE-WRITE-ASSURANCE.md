# HR.R1-C35 WO-19: Leave write assurance, fresh MFA and fresh authority (LVA-4, D-4, S-7; 2026-11-13)

**HR.R1 stays OPEN.** This implements WO-19, the consequence of owner decision D-C35-3 (`HR-R1-C35-P3-DISPOSITIONS.md` §9): fresh MFA on Leave allocation and allocation runs, and a fresh authority read on every Leave ledger and accrual write entry point.

| | |
|---|---|
| Baseline | `5cbc6abd` = `main` = `origin/main`; `f58b2eeb` the WO17-N1 code; clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | D-C35-3 (2026-11-13, Security owner) and the owner's WO-19 dispatch of the same day: implementation, tests, the isolated test database, an independent Security review, publication through protected `main`. No migration was needed or made |
| Not changed | Leave amounts, formulas, policies, eligibility, the ledger and its database invariants, idempotency (C30), history; consumption, reversal and reconciliation authority; Payroll; WO-20 to WO-22; RG-ID-1; the development database (396 migrations, `2027_02_22_090000` still unapplied there) |

## 1. The finding and the decision

**LVA-4** (`HR-R1-0-AUDIT-REGISTER.md`, P3, Security), verbatim: "Gate register S-7 still reads 'open / HR.10'. Adjustments and openings now need fresh MFA. Allocations (single and run) still have no fresh MFA. Every ledger and accrual write uses the cached `authorizeCapabilityFor` (`LeaveLedgerService:63,176,218`; `LeaveAccrualService:113,131,176`), never `requireCurrent`." Recommendation: "Mark S-7 PARTIAL in the register. Owner decision on fresh MFA for allocation."

**D-4** (`HR-R1-C12-DECISION-DOCKET.md` §7). Options: allocation and runs; allocation only; record no step-up as deliberate; keep S-7 open. The docket recommended **allocation only, with `requireCurrent`**.

**D-C35-3** (the owner, 2026-11-13) chose more: fresh MFA on allocation **and runs**, and a fresh authority read on **every** ledger and accrual write entry point (allocate, allocation run, adjust, opening, accrual run start and continue). Consumption and reversal written inside a leave decision keep that decision's authority. This unit does not narrow it.

## 2. Reproduction (before the fix, at `5cbc6abd`)

A throwaway test, deleted before the candidate:

| Case | Result at `5cbc6abd` |
|---|---|
| Grant revoked behind the warmed 60 s cache: allocate, allocation run, adjust, opening, accrual start, accrual batch, year close | **all seven wrote** |
| Web allocation without `mfa_code` | 1 allocation written |
| Web allocation run without `mfa_code` | 1 allocation written |
| API allocation without `mfa_code` | 1 allocation written |
| API allocation run without `mfa_code` | 1 allocation written |

## 3. The write surface

Every application path that writes `leave_ledger_entries`, `leave_allocation_runs`, `leave_accrual_runs`, `leave_accrual_run_items` or `leave_year_closes`. Search: every class touching those tables or models; `LeaveLedgerService`'s writers and their callers; jobs and commands. No job or console command writes them; `leave:accrual-status` reads only. Retention deletes are the retention-identity category (rule 151), not business writers.

| Mutation | Owner | Entry points | Capability | Fresh MFA | Fresh authority | Transaction |
|---|---|---|---|---|---|---|
| Explicit allocation | `LeaveLedgerService::allocate()` | web `POST /app/leave/allocations`, API `POST …/leave/allocations` | `hr.leave.manage` | **yes (new)**, service-required | **yes (new)** | one; read after the School, before HR rows |
| Annual allocation run | `LeaveLedgerService::executeRun()` | web, API `…/allocation-runs` | `hr.leave.manage` | **yes (new)**, service-required | **yes (new)**, once per run | one for the whole run |
| Adjustment | `LeaveLedgerService::adjust()` | web, API | `hr.leave.manage` | yes (HR.10); **now service-required** | **yes (new)** | one |
| Opening balance | `LeaveLedgerService::recordOpening()` | web, API | `hr.leave.manage` | yes (HR.10); **now service-required** | **yes (new)** | one |
| Accrual run open/resume | `LeaveAccrualService::start()` | web, API `…/accrual-runs` (via `execute()`) | `hr.leave.manage` | no (not decided) | **yes (new)** | one |
| Accrual batch (credits, items, cursor) | `LeaveAccrualService::processBatch()` | the same | `hr.leave.manage` | no | **yes (new)**, once per batch | one per batch |
| Accrual run failure mark | `LeaveAccrualService::markFailed()` (private) | after a batch throws | — | no | no: records that the operator's batch stopped; never credits | one |
| Year close | `LeaveYearCloseService::execute()` | web, API | `hr.leave.manage` | no (not decided) | **yes (new)** | one |
| Consumption | `recordConsumption()` in `LeaveRequestService::decide()` | approve | the decision's | — | yes (ORG.8D, existing) | the decision's |
| Reversal | `recordReversal()` in cancellation | admin cancel / self cancel | the cancellation's | — | admin path yes (existing); self path: ownership | the cancellation's |
| Reconciliation movements | `LeaveYearCloseService::reconcile()` | inside a cancellation | the cancellation's | — | as the cancellation | the cancellation's |
| Accrual credit | `recordAccrual()` | inside `processBatch()` | the batch's | — | the batch's | the batch's |

**Completeness.** `LeaveWriteAssuranceTest::every_write_method_is_declared_and_takes_its_assurance` requires every public method of the three classes that opens a write transaction to be a declared write method, and each to contain its proof check and its fresh read. The registry test admits `ApprovalDecisionAuthority` only in registered classes. A source scan cannot see a write built at run time; the runtime role can still write these tables directly (§6).

## 4. What was built

- **Fresh MFA.** Web and API allocation and run call `FreshMfaRequirement::requireForAction()` after the capability check and outside the transaction (rule 95), as adjustment and opening already did. The four `LeaveLedgerService` writers now take the actor's own `FreshMfaProof` and refuse one minted for another User (`LogicException`, before any write). The existing contract is used unchanged: a current TOTP step (single-use step claim) or an unused recovery code; no new interval, binding or store. The proof is minted per request for the request's User and is never serialized from input, so it cannot cross Users, Schools or requests.
- **Fresh authority.** Each declared write method calls `ApprovalDecisionAuthority::requireCurrent($actor, 'hr.leave.manage', $school)` inside its write transaction: the School FOR SHARE (lifecycle), the active membership and its active whole-School grants FOR SHARE until commit, the module entitlement and the disabled flag read fresh. The cached floor (`authorizeCapabilityFor`) stays as the cheap first check.
- **One read per transaction.** A run and an accrual batch are each one transaction; the locks taken by the read are held to commit, so a per-employment read would add queries and nothing else.
- **Accrual mid-run.** A batch refused for authority leaves the run `failed` with `LEAVE_ACCRUAL_AUTHORITY_REFUSED`; WO-9 shows it as failed, never complete; an authorized operator resumes the same run.
- **Registry.** `LeaveLedgerService` joins `ApprovalWorkflowRegistry::DIRECT_ASSURED` (fresh authority and fresh MFA, `writeMethods`). Accrual and the close are declared in the new `FRESH_AUTHORITY_WRITES` (fresh authority, no step-up), which the registry test pins.
- **Audit.** The four assured events carry `freshMfa: true`; never a code.
- **Surfaces.** The Entitlements page asks for a code on the allocation form and before executing a run (labelled field, `role="alert"` error, keyboard order unchanged). OpenAPI: `mfa_code` required on allocation, run and adjustment inputs; 422s documented; summaries name the fresh read. Shared types regenerated.
- **Contracts.** ADR 0065 §34; CLAUDE.md rule 134.

## 5. Concurrency and the guarantee

Two OS processes, holder held open, contender observed blocked (`LeaveWriteAuthorityConcurrencyTest`):

| Holder | Contender | Result |
|---|---|---|
| grant revocation | allocation | refused (`AuthorizationException`); nothing written |
| allocation | grant revocation | the allocation commits first; the revocation lands after; the credit stands |
| grant revocation | accrual run | refused; no run opened |
| School suspension | allocation | refused (`SchoolNotOperationalException`) |

The guarantee is the existing `holdsInSchoolNow()` one: membership and grant changes are ordered strictly before or after the write. The disabled flag and the module entitlement are read fresh at the write but not locked, so a change committing during the write lands after it. Concurrent duplicate allocations and runs keep their earlier proofs (`LeaveConcurrencyTest`), as does single-use TOTP under concurrency (`MfaTotpReplayConcurrencyTest`).

## 6. Database boundary

No migration, function, trigger, grant or verifier change. PostgreSQL cannot know that a human passed MFA, nor who acted: `school_os_app` serves every request. Both stay application boundaries (ADR 0076 §64). A database check of the recorded `actor_user_id` would trust a caller-supplied id, and was not added. The runtime role can still write these tables directly; every structural invariant stays enforced there (one allocation per account and year, funding exclusivity, non-negative balance, closed-year seal, one accrual per month).

## 7. Evidence

| Proof | Test | Result |
|---|---|---|
| Web allocation and run refuse a missing, stale (TOTP step outside the window), another User's, invalid and just-spent code; nothing written; a current code writes; the audit says `freshMfa` and never holds the code | `LeaveWriteAssuranceTest` | pass |
| A viewer is refused on the capability before any code is read: a real recovery code stays unspent | same | pass |
| API allocation and run: 422 without or with an invalid code, 403 for a viewer, 201 with a fresh code | same | pass |
| The service refuses a proof minted for another User at all four writers, before any Leave statement is attempted (statement capture) | same | pass |
| All seven entry points refuse behind a revoked grant the cache still shows, write nothing, and a current holder's identical write succeeds | same (7 cases) | pass |
| Each way of losing authority (grant revoked, role replaced, membership suspended, account disabled, Leave module disabled, School suspended) refuses an allocation and an accrual batch; no credit, no item | same (6 cases) | pass |
| An accrual batch refused mid-run: run `failed` with `LEAVE_ACCRUAL_AUTHORITY_REFUSED`, nothing credited, WO-9 says failed; a holder resumes the same run, one credit | same | pass |
| A completed allocation stands after its author loses authority | same | pass |
| The read paths (run preview, accrual preview, WO-9 reader and `leave:accrual-status`) take no code and write nothing | same | pass |
| One authority read per run (21 grants) and per accrual batch | same | pass |
| Every public method opening a write transaction in the three classes is declared and carries its checks; the registry admits the new category only for declared classes | `LeaveWriteAssuranceTest`, `ApprovalWorkflowRegistryTest` | pass |
| Two-process races (§5) | `LeaveWriteAuthorityConcurrencyTest` | pass |
| Existing proofs, updated only to pass a proof: duplicate allocation/run/debit races, replay with the identical body, key conflict | `LeaveConcurrencyTest`, `LeaveApiTest` | pass |

**Planted bypasses** (each restored and diffed): the fresh read removed from allocate (7 failures), from the accrual batch (9), from the year close (2); the service proof check removed (1); web allocation MFA replaced by a minted proof (1); API run MFA likewise (1).

**Scale** (DDEV, once, 1,001 employments): the annual run reads authority once in a 6.5 s transaction (the existing per-employment locking dominates, 9,027 queries); a five-batch accrual reads it six times (start plus one per batch), 4.4 s; one `requireCurrent()` averages 6.8 ms (p50 5.9, max 19.4). No batch limit changed.

**Suites:** Leave 238, Authorization 236, App 961 (one failure, §8 WO19-E1, then green), Staff Attendance 76, Staff Self-Service 28, Identity 199, HR 1,501, Payroll 583, Postgres 1,259, Retention 287 (4 local skips: object storage), Operations 15, Organization 97. Pint, Larastan, whitespace, gitleaks, shared-types, vue-tsc, ESLint, Prettier pass.

**No migration**, so no rollback proof; `platform:verify-database` is unaffected.

**Independent Security review** of the working tree before commit: **no HIGH, no MEDIUM.** Four LOW and some INFO, all recorded in §8; the documentation LOW was fixed; one test was strengthened (the spent-code check).

## 8. Dispositions

| Item | Status |
|---|---|
| **LVA-4** (P3) | **CLOSED for development on publication** |
| **S-7** | **CLOSED for development on publication** |
| **D-4** | Decided (D-C35-3), **built** by this unit |
| WO19-N1 (LOW) | Self-service cancellation writes its reversal (and any reconciliation) behind the cached `hr.leave.self` plus a freshly locked ActingEmployee. Within D-C35-3's "keep that decision's authority"; recorded as a residual in ADR 0065 §34 and rule 134. Owner: HR, Security |
| WO19-N2 (LOW) | An operator revoked between `start()` and their batch marks the month's run `failed`, which also stops a second operator running the same run concurrently. Truthful (WO-9 shows failed), resumable, audited under the actor who failed it; nothing is marked completed |
| WO19-N3 (LOW) | The idempotency fingerprint hashes the whole JSON body, `mfa_code` included (unsalted SHA-256). Pre-existing on every MFA + `idempotent` route; WO-19 adds two. A code that never reached verification (a validation failure) leaves a hash an attacker with database read access could search. Cross-cutting Platform follow-up (exclude `mfa_code` from the fingerprint), not changed here |
| WO19-N4 (LOW) | A long run or close holds the operator's membership and grants FOR SHARE to commit, so revoking or off-boarding that operator waits behind it (and the School's access administration queues behind the School access lock). Not a deadlock: same order as `StaffAccessService` |
| WO19-N5 (INFO) | The Access Center now shows `hr.leave.manage` as MFA-assured (`AccessCatalogueReader` reads `DIRECT_ASSURED`), although its decisions, assignments, accrual and close take no code. `DIRECT_ASSURED`'s "closed reason and database-required evidence" fits the Leave entry loosely (ledger rows, `freshMfa` in audit). The disabled flag and the module entitlement are read fresh, not locked (existing contract) |
| WO19-N6 (INFO) | Two-process races cover allocation, accrual start and suspension; the batch, run, adjustment and close share the same read and are proven sequentially. The registry test counts call sites; `LeaveWriteAssuranceTest` checks each method body |
| WO19-E1 (test defect, fixed) | `StaffCalendarUiTest` recorded a full day on the School's real today, which fails when the School-local day is a weekend (half Saturday, no Sunday in the fixture). Found on Saturday 2026-11-14 IST; it now uses the latest weekday. Test-only |
| Development MAJOR inventory | None open |
| WO-20 to WO-22 | Not started |
| Development database | Unchanged: 396 migrations, `2027_02_22_090000` not applied (needs the owner's word); WO-19 has no migration |
| Seven P3 decisions, other P3/LOW findings, RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |
