# TCH-L1 — Teacher Attendance Processing: Determination

This document records, as project governance, the formal outcome of register
item **TCH-L1** (ADR 0058 row E33; ADR 0063 §26, §39, §40). It is the formal
determination needed for the architecture and legal trail, as communicated to
the project by the product owner. It contains no private correspondence. It
is not broader than stated below.

**This determination has no effect on teacher StudentMark processing** (§10).

- **Request:** `docs/security/TCH-L1-TEACHER-ATTENDANCE-REVIEW-REQUEST.md`
  (drafted at `91450ea`), completing the ADR 0063 §39.4 decision record.
- **Facts reviewed:** ADR 0063 §39.2 (data, actions, controls of TCH.4).
- **Engineering contract:** ADR 0063, amended 7 October 2026 (§42).

## Approving authority

Lead Privacy Counsel & Data Protection Officer.

## Determination date

7 October 2026.

## Outcome

**APPROVED WITH CONDITIONS** — teacher Attendance processing.

## ADR 0063 §39.4 decision record

```text
Decision:          Teacher Attendance processing — APPROVED WITH CONDITIONS
Scope:             assigned teachers only, where the Platform establishes
                   (1) authenticated individual teacher identity and
                   (2) a verified, current authorised teaching/Attendance
                   relationship to the relevant controlled scope (§1-§2)
Data:              identifiable Student attendance needed for the authorised
                   Attendance activity only (§7)
Actions:           assignment-scoped roster viewing, attendance recording,
                   teacher correction where separately permitted, viewing
                   the outcome of the teacher's own authorised submission (§2)
Technical controls reviewed:  ADR 0063 §39.2, plus the conditions below
Jurisdiction / policy basis:  not separately stated; a new jurisdiction with
                   materially different requirements is a re-review trigger
Conditions:        §1-§7 (binding); production only after §9 is evidenced
Approving authority:          Lead Privacy Counsel & DPO
Decision date:     7 October 2026
Outcome:           APPROVED WITH CONDITIONS
Does not approve:  anything outside the stated scope; any StudentMark
                   processing (§10)
```

## 1. Basis of teacher authority

- Authorised only where the Platform establishes **both** authenticated
  individual teacher identity **and** a verified, current authorised
  teaching/Attendance relationship to the relevant controlled scope.
- The teacher role alone is insufficient. Same-School membership alone is
  insufficient.
- Deny by default outside the verified assignment.

## 2. Assignment / ownership

- Teacher Attendance authority derives from an authoritative,
  School-maintained current assignment or ownership relationship.
- **Permitted (assignment-scoped):**
  - relevant roster viewing;
  - attendance recording;
  - teacher correction where separately permitted by School policy and the
    architecture;
  - access necessary to perform the authorised Attendance activity;
  - viewing the outcome or status of the teacher's own authorised submission.
- **Not authorised:**
  - School-wide Attendance browsing;
  - bulk export;
  - unrelated classes or Students;
  - unrestricted historical access;
  - School-wide analytics;
  - administrative override;
  - administrative capabilities.
- **End of assignment.** When an assignment or ownership ends, expires, is
  replaced or is revoked:
  - future authority derived from it ends;
  - Attendance lawfully recorded while the authority existed is not
    invalidated merely because the assignment later ends.

## 3. School / tenant controls (binding)

- Teacher Attendance processing is strictly School-scoped.
- Authorization is established independently per School.
- No cross-tenant fallback, and no implicit global teacher authority.
- A multi-School identity needs independent authorization in each School.

## 4. Authentication and MFA

- **Production teacher Attendance access requires MFA**, or a formally
  approved equivalent control providing materially comparable assurance.
- Individual teacher accounts are required. Shared credentials are not
  acceptable.
- MFA recovery and reset must be secured, and must not bypass ownership or
  School authorization.
- Development and test authentication mechanisms must never become
  production bypasses.
- Stricter existing project MFA requirements are not weakened.

## 5. Audit

- Relevant teacher Attendance reads and writes, and material changes, must be
  auditable.
- The audit gives sufficient accountability, where appropriate, for:
  - the actor;
  - the School / tenant;
  - the affected Attendance record or scope;
  - the class / Section / subject or equivalent context;
  - the action and the time;
  - whether the action created, modified or corrected Attendance.
- Material changes must not silently destroy accountability.
- Ordinary teacher permissions must not permit modification of audit
  evidence.

## 6. Privileged and exceptional access

- Administrative or support privilege does not derive from teacher Attendance
  authority.
- Exceptional or override access requires separate authorization, and is
  restricted and audited.
- Unrelated capabilities must not bypass teacher ownership unless they
  independently and expressly authorise the Attendance action.

## 7. Data minimisation

- Expose only information reasonably necessary for Attendance processing.
- Do not expose unrelated highly sensitive Student information.
- Logs, telemetry and errors avoid unnecessary Student personal data.

## 8. Development effect

**Development is permitted** for the approved teacher Attendance scope,
provided the implementation conforms to ADR 0063 and this determination.
Permitted development includes:
- ownership and assignment verification;
- School / tenant controls;
- teacher capabilities;
- MFA enforcement;
- Attendance read and write restrictions;
- auditing;
- assignment-revocation behaviour;
- related security controls.

It does not broaden authority beyond this determination.

## 9. Production effect

**Production enablement is permitted only after the stated controls are
implemented and verified.** Before production teacher Attendance is enabled,
the repository / project record must evidence:
1. individual teacher authentication;
2. authoritative teacher assignment / ownership verification;
3. School / tenant isolation;
4. deny-by-default behaviour;
5. MFA for applicable production teacher accounts;
6. auditable relevant reads, writes and material changes;
7. assignment revocation ending future authority;
8. exceptional access not bypassing the controls;
9. no authority beyond Attendance.

Once these controls are implemented and verified, this determination does not
require another privacy approval solely for the approved Attendance scope.
All other non-privacy release, security and operational requirements still
apply (including E21 and the ADR 0058 platform checklist).

## 10. Independence from StudentMark

This determination **has no effect on teacher StudentMark processing.** It does
not:
- authorise teacher marks entry or teacher StudentMark reads;
- modify or satisfy RES-L2 (ADR 0058 E37);
- satisfy the RES-L0 re-review for teacher processing (E35);
- establish any StudentMark processing basis;
- imply that Attendance authority equals marks authority;
- allow any marks capability to inherit from an Attendance capability.

**RES-L2 and the teacher-scope RES-L0 re-review remain unresolved. RES.4
remains NOT AUTHORISED** (ADR 0068 §22.9).

## 11. Validity and re-review

**No fixed expiry.** Re-review is required if:
- the ownership / assignment model materially changes;
- teacher Attendance widens to School-wide access;
- cross-School or cross-tenant access is proposed;
- production MFA is materially weakened or removed;
- audit requirements are materially weakened;
- Attendance is introduced to a materially new external integration or
  recipient;
- Attendance is used for materially different analytics, profiling,
  automated decision-making or AI;
- a new jurisdiction introduces materially different requirements;
- applicable law or regulatory guidance materially changes;
- a material privacy or security incident shows the model is insufficient;
- ADR 0063 materially changes the assumptions underlying the approval.

Ordinary implementation fixes that preserve these conditions do not
independently trigger privacy re-approval.

**Do not reinterpret.** Engineering must not treat this determination as
broader than stated.
