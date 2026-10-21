# Approval authority UAT plan (ORG.8, 2026-10-21)

Status: **plan, not evidence.** It defines the fixtures and cases every
future approval phase (ADR 0076 §56.30) must satisfy before it is called
done. Nothing here is built by ORG.8. Cases for a workflow apply only once
that workflow's phase is authorized and implemented. No legal gate is
changed by any outcome here: a case that touches E30–E34, E36/E37, E46,
HRX-L* or PAY-L1 is local/UAT evidence only, never production clearance.

Matrix: `docs/security/APPROVAL-AUTHORITY-MATRIX.md`.

## Rules every tester applies

- **Approval is not permission.** An approval authorizes one item; it never
  makes the approver able to do anything else.
- **Execution re-checks everything current:** School lifecycle, module
  entitlement, capability, Campus scope, ownership, SoD, fresh MFA where
  required, idempotency and domain validity.
- **Refusals are generic.** An out-of-scope or unknown request id is the
  same 404 as a non-existent one; no refusal names another Campus, actor
  or state.
- **Audit is append-only.** Every REQUESTED / APPROVED / REJECTED /
  EXECUTED / REVERSED / CANCELLED event is a new School audit row naming
  the actor and request id; free text, payloads and amounts beyond ids and
  closed codes stay out of audit metadata.

## Personas

| Persona | Grants | Purpose |
|---|---|---|
| Requester A | the domain's `.request` key, whole-School | creates requests |
| Approver B | the domain's `.approve` key, whole-School | decides A's requests |
| Unauthorized C | `.view` only | must be refused everywhere |
| Dual-holder D | `.request` + `.approve` (e.g. school_admin) | self-approval and prior-participation attempts |
| Campus-A approver | `.approve` at Campus A (only where the domain adopted Campus scope) | scope intersection |
| Campus-B approver | `.approve` at Campus B | cross-Campus refusal |
| Whole-School approver | `.approve`, NULL Campus | may decide Campus items where the contract permits |
| Revoked approver R | `.approve`, revoked after deciding | execution after authority loss |
| MFA-less actor M | `.approve`, no enrolled factor | step-up refusal |
| Platform Manager P | `platform_manager` | must never decide tenant business items |

## Fixture

- One active School, two active Campuses A and B, one inactive Campus Z.
- One optional module the workflow depends on, toggleable by root.
- For Finance: two Students placed on A and B, one Charge each with a
  stored Campus, a settlement account, one manual Payment.
- For Payroll: a run created by Requester A, calculated, with one manual
  override recorded by Dual-holder D.
- For Leave: an Employee with a current direct manager, and one without.
- An open legal gate recorded for the domain (no code change; the case only
  checks that no approval path claims to clear it).

## Positive cases

| # | Actor | Precondition | Action | Expected | Invariant |
|---|---|---|---|---|---|
| P1 | A then B | pending request by A | B approves with fresh MFA where required | one APPROVED decision; execution happens once | decision row records B, time, fingerprint, reason code |
| P2 | B | pending request | B rejects | one REJECTED decision; nothing executed | rejection needs a closed reason where the matrix says REASON_REQUIRED |
| P3 | A | own pending request | A withdraws/cancels | CANCELLED; nothing executed | only the requester (or a named administrative path) cancels |
| P4 | Whole-School approver | request for Campus A item | approve | allowed where the domain contract permits | scope fact (NULL = whole School) recorded |
| P5 | Campus-A approver | Campus-adopted domain, Campus A item | approve | allowed | approval never widens scope |
| P6 | B | approved item, unchanged | execute (pattern B domains) | EXECUTED exactly once | execution re-checks current authority |
| P7 | B | executed reversible item | reverse with reason | a new compensating record | the original is never edited |

## Negative / abuse cases (mandatory)

| # | Actor | Attempt | Expected |
|---|---|---|---|
| N1 | D | approve own request | refused (service and database) |
| N2 | D | approve a request on which D recorded an override/delta/recalculation | refused (prior participation) |
| N3 | Campus-B approver | approve a Campus A item | generic 404 |
| N4 | Campus-B approver | probe a Campus A request id | same 404 as an unknown id |
| N5 | R | approve after capability revocation | refused |
| N6 | B | execute after B's scope or capability is revoked | refused; approval stays as history |
| N7 | B | execute after the module is disabled | refused (terminal), no retry loop |
| N8 | B | execute after Campus deactivated | refused; re-activation alone does not revive a changed request |
| N9 | A | change the request's material terms after approval | approval invalidated (fingerprint mismatch) or the edit refused |
| N10 | B | replay the approve call (same idempotency key) | no second execution; replay re-checks current authority first |
| N11 | B and B' | approve concurrently | exactly one terminal decision |
| N12 | B and B' | approve vs reject race | exactly one wins; the other gets a conflict |
| N13 | A and B | cancel vs approve race | deterministic single outcome |
| N14 | M | approve where fresh MFA is required | refused before any write; code not consumed on an earlier refusal |
| N15 | B | approve with a reused TOTP step | refused |
| N16 | B | override without a closed reason | refused |
| N17 | B | use an approval to execute while a legal gate refuses the domain in code | refused; no environment bypass |
| N18 | P | decide any tenant business request | refused; no School context, no tenant data read |
| N19 | C | list the approval queue | refused or empty; no item fields leak |
| N20 | school_admin holding every key | act as maker and checker on one item | refused (holding both never satisfies SoD) |
| N21 | B | reverse a subledger-owned journal entry through the general ledger (after ORG.8-P1) | refused |
| N22 | A | suspended School, approve or execute | refused per the operation's lifecycle rule; no Platform Manager override |

## Queue privacy checks

- A pending-items list shows only items the viewer may decide, filtered in
  SQL by capability, Campus scope and relationship (e.g. Leave: current
  direct reports only).
- Shown fields are minimal: request id, kind, requester display name,
  subject display label, amount where the domain is Finance, Campus label
  where scoped, requested-at. Never a full Student, Guardian or Employee
  profile, contact value or HR-sensitive field.
