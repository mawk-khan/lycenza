# HR expansion roadmap (HR.EXP) — DRAFT

Status: **DRAFT, documentation only** (HR.EXP-0, 2026-10-30).
- This draft is built from the repository audit
  (`HR-EXP-0-CURRENT-STATE-AUDIT.md`).
- **The wave order and P1 list are provisional** until the reference map is
  built from the complete HR pack (owner decision: wait for the pack).
- Nothing is authorised. Each wave needs the owner's explicit authorisation,
  and decision-gated waves need their decision recorded first.

## 1. Principles

These are the SIS.EXP rules, unchanged:
- **One outcome per wave.**
- **Domain ownership** (`HR-DOMAIN-OWNERSHIP-MAP.md`).
- **No new capability, role, Campus adoption, export or schema** unless the
  wave's brief names it.
- **Extend the canonical Organization demo**, never a separate HR world.
- **Owner walkthrough** on a fresh reset.
- **Independent security review.**
- **Tenancy tests.**
- **Exact scope and regression level.**
- **Reference-map update.**
- **Publication** through `integration/*` → exact-SHA `CI gate` →
  `publication-check`.

**Prefer an explicit availability gate.** For anything near a legal gate, use
the `StudentCertificateAvailability` pattern rather than relying on "no role
holds it".

## 2. Prerequisites (before feature waves)

| Id | Prerequisite | Why | Gate |
|---|---|---|---|
| **OD-HR-1** | Payroll checker visibility (S-1). The options are (a) approve requires sensitive view, or (b) an approval-only review projection: totals, per-person change against the previous run above a threshold, every override and delta with amounts, under fresh MFA | the only P0: the checker approves what it cannot see | **owner decision**, then HR.HARDEN-0 |
| OD-HR-2 | Re-review compensation maker-checker (S-2, ADR 0076 §60.8a) together with OD-HR-1 | S-2 compounds S-1 | owner decision |
| OD-HR-3 | HR self-access scope (S-3; ADR 0071 §26.13, extended to qualifications verification, personal details, addresses, contacts) | SoD on one's own HR record | owner decision |
| — | E16 successor | every publication from 2026-11-13 | owner (release) |

## 3. Proposed waves (provisional order)

| Wave | Name | Outcome | Gate | Schema |
|---|---|---|---|---|
| **HR.HARDEN-0** | HR & Payroll integrity hardening | Implements OD-HR-1/2/3, plus: S-5 (proof required at the service layer); S-8 (closed create statuses, no future `/end`); S-9 (locked reparent); S-10 (DB exclusion for overlapping employments); S-11 (`Employee` fillable + number trigger); S-12 (closed `employment_type`; document category catalogue); S-13 (posting service authority + demo); ORG10-D01 (pre-joining presentation by date); the doc drift | OD-HR-1..3 | small (an exclusion constraint, a trigger) |
| HR.EXP-1 | Staff 360 & directory | Whole-School Staff profile projecting each owner's summary: Leave balance and requests, attendance month, payslip list (own-tier), teaching assignments, documents count, credentials. Plus a stale-form token on profile edits, the account-kind indication in the link picker (S-15), and directory status by date | owner authorisation | none |
| HR.EXP-2 | Organization structure | Department head (named Employee, never authority), designation grade/band if the pack shows a real need, a reporting-line history read | owner decision (head ≠ approver) | small |
| HR.EXP-3 | Staff self-service expansion | Own profile view; a contact-change **request** reviewed by HR, never a direct write of identity; own documents (view) | owner decision on scope (OD-HR-4) | small |
| HR.EXP-4 | Leave & calendar expansion | Accrual/proration, executed carry-forward expiry, Campus or staff calendars and shifts only if the pack proves need; Leave adjustment assurance (S-7) | owner decision; shifts and statutory leave are legal (HRX-L2/L3) | moderate |
| HR.EXP-5 | Recruitment / applicant tracking | Vacancies, candidates, applications, interviews, offers, and a canonical candidate → Employee conversion through `EmployeeOnboardingService` | **owner decision** + **legal** (candidate retention, consent, public-form privacy, abuse protection) | new sub-domain |
| HR.EXP-6 | Staff documents & credentials | Category catalogue, expiry reminders (no external send without the email layer), verification queue | owner decision | small |
| HR.EXP-7 | Payroll expansion | Fix the run-page N+1; then, only with legal sign-off: statutory activation (S-4 first), bank details and disbursement files (new legal record) | legal (ADR 0036, E45, HRX-L4) + owner | moderate |
| HR.EXP-8 | Performance / training | Only if the pack and the owner name a v1 need | owner decision; appraisal data privacy review | new |
| HR.EXP-9 | HR reports & closure | Transactional HR reports in HR; workforce analytics to the Analytics gate; re-audit | after the chosen waves | none |

**Not proposed:**
- disciplinary records (legal first);
- biometric or RFID attendance (HRX-L2);
- a generic skills ontology;
- a Staff-import rewrite (the JSON import exists; a CSV adapter only if the
  owner asks, and never a copy of the SIS importer's contract);
- HR exports (D-11).

## 4. Test and regression plan (per wave)

| Wave | Required |
|---|---|
| HR.HARDEN-0 | payroll approval allow/deny with and without review visibility; two-process race for any new lock; DB constraint tests; self-administration deny cases; **full regression** (Payroll, HR and Leave cross-domain) |
| HR.EXP-1 | section absent without its own capability; Campus-scoped viewer sees only allowed sections; foreign-School 404; query budget constant with data; stale-form token race; scoped suites |
| HR.EXP-2 | hierarchy cycle and lock tests; head ≠ authority guard; RLS |
| HR.EXP-3 | ownership (another Employee's id is a 404); request-review authority; no identity write by self |
| HR.EXP-4 | ledger invariants unchanged (never negative); accrual determinism; calendar fail-closed; **full regression** |
| HR.EXP-5 | public endpoint abuse (rate limit, idempotency, upload sniffing); candidate ≠ Employee until conversion; conversion atomic and audited; retention; **full regression** |
| HR.EXP-6 | category closed list; expiry job tenant-scoped (rule 21) |
| HR.EXP-7 | the N+1 budget test; legal-gated paths refused in production by an explicit gate; **full regression** |
| HR.EXP-8 / 9 | per scope; HR.EXP-9 ends with a closure re-audit and the full regression |

## 5. Owner decisions (open)

| Id | Decision |
|---|---|
| OD-HR-1 | Payroll checker visibility |
| OD-HR-2 | Compensation maker-checker |
| OD-HR-3 | HR self-access to one's own record |
| OD-HR-4 | Staff self-service scope |
| OD-HR-5 | Manager authority: should line managers approve Leave (today only `school_admin`/`principal` hold `hr.leave.approve`)? |
| OD-HR-6 | Recruitment in v1 |
| OD-HR-7 | Performance/appraisal and training in v1 |
| OD-HR-8 | Disciplinary workflow (legal first) |
| OD-HR-9 | Staff import format (keep the JSON importer, or add a CSV adapter) |
| OD-HR-10 | Contracts and e-signature (metadata only; no contract engine from a menu label) |
| OD-HR-11 | Headcount and capacity features (Analytics gate) |
| OD-HR-12 | Employee family and dependant data (legal) |
| OD-HR-13 | Ancillary profile hard delete vs archive (S-16) |
| M-1 | The unlinked staff parent (recorded production prerequisite) |
