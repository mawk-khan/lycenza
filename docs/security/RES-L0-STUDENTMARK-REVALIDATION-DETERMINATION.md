# RES-L0 — StudentMark Determination Revalidation: Determination

This document records, as project governance, the formal outcome of register
item **RES-L0** (ADR 0058 row E35). It is the formal determination needed for
the architecture and legal trail, as communicated to the project by the
product owner. It contains no private correspondence. It is not production
approval, and it must not be read as broader than stated below.

- **Request:** `docs/security/RES-L0-STUDENTMARK-REVALIDATION-REQUEST.md`
  (as amended at `99b8f67`).
- **Prior determination revalidated:**
  `docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` (3 September
  2026).
- **Engineering contract:** ADR 0068, amended 7 October 2026 (§19).

## Approving authority

Lead Privacy Counsel & Data Protection Officer — India Operations.

## Determination date

7 October 2026.

## Outcome

**CURRENT WITH CHANGES.**

The 3 September 2026 StudentMark determination **remains current only** for
the narrowly defined internal administrative processing scope below, and
**only subject to the conditions** in this document.

## 1. Scope covered

RES-L0 covers only:
- School-internal StudentMark recording and maintenance;
- by specifically authorised administrative / internal staff;
- of StudentMark as **Highly Sensitive** educational data;
- with ADR 0038 processing-authorization provenance;
- with auditable access and changes.

It does **not** cover, and nothing here authorises:
- Student access;
- Guardian access;
- result publication;
- report-card generation;
- transcript generation;
- teacher marks-entry processing;
- production enablement.

**Any material scope expansion requires further review.**

## 2. ADR 0038 processing-basis model

ADR 0038 remains an acceptable processing-basis and processing-authorization
model for this StudentMark scope, **provided that:**
- authorization is explicit and attributable;
- authorization provenance is retained;
- processing is constrained to its authorised purpose and scope;
- School, role and capability controls apply;
- relevant reads and writes are auditable;
- privileged or exceptional access is separately restricted and audited;
- withdrawal, expiry or invalidation prevents new processing that depends on
  that authorization, unless another valid basis exists.

**The historical existence of a StudentMark must not be treated as authority
for expanded or unrelated processing.**

## 3. Withdrawal of an authorization (qualified)

- Withdrawal does not by itself erase, invalidate or rewrite lawfully recorded
  historical marks.
- The validity of a historical record is distinct from the authority to
  continue processing it.
- Continued retention, access, use, disclosure or other processing after
  withdrawal requires an independently valid basis.
- Historical marks must not be automatically deleted or invalidated merely
  because the original authorization was withdrawn.

**RES-L0 does not determine:** retention periods; statutory or regulatory
retention; post-withdrawal use; archival requirements; deletion or
anonymisation; continued historical availability. Those remain subject to
**RES-L8** and any other applicable review.

## 4. Mandatory conditions for development

Binding on RES.2 (and every later StudentMark slice):
1. StudentMark remains **Highly Sensitive educational data**.
2. Access is denied by default.
3. Access is only through explicitly authorised roles and capabilities.
4. School / tenant boundaries are enforced.
5. Relevant StudentMark creation, modification and sensitive access are
   audited.
6. Audit accountability identifies the actor, action, affected record and
   time, as appropriate.
7. Changes to marks preserve history and auditability; material history is
   never silently destroyed.
8. StudentMark is not exposed through generic search, reporting, analytics,
   exports, APIs or integrations without independent authorization.
9. StudentMark is not reused for unrelated purposes merely because the data is
   technically available.
10. Development and test environments avoid identifiable production
    StudentMark data unless specifically authorised and appropriately
    protected.
11. Data minimisation applies to fields, logs, exports and derived data.
12. Errors, telemetry and application logs do not unnecessarily disclose mark
    values or sensitive context.
13. Bulk access or export requires separate privileged authorization.
14. Exceptional administrative override is restricted and auditable.
15. Authorization provenance is preserved as ADR 0038 requires.
16. Nothing implies that withdrawing an authorization resolves retention
    obligations.

**Production remains blocked by RES-L1.** This determination does not
authorise production.

## 5. Validity and re-review

**No fixed calendar expiry.** A periodic privacy review may occur under
ordinary governance.

RES-L0 must be **revalidated** upon any of:
- a material change to StudentMark purpose or scope;
- Student access;
- Guardian access;
- result publication;
- report cards;
- transcripts;
- teacher marks entry or assigned-teacher processing;
- analytics;
- AI / ML;
- automated decision-making;
- profiling;
- a new external integration;
- API disclosure;
- a new third-party recipient;
- a material change to ADR 0038 or the processing-authorization model;
- a material change in applicable law, regulatory guidance or binding School
  obligations;
- a new jurisdiction with materially different requirements;
- a material privacy or security incident exposing a weakness in the approved
  model;
- RES-L8 retention requirements materially changing the architectural
  assumptions;
- the RES-L1 production review identifying a material change relevant to this
  determination.

No other jurisdictional restriction is stated. A new jurisdiction with
materially different requirements is a re-review trigger.

## 6. Staff scope and teacher processing

- RES-L0 covers administrative / internal staff processing only.
- It makes **no determination about teacher marks entry**. Teacher StudentMark
  processing remains governed by **RES-L2** (and, for production, ADR 0063
  §40 / E33).
- Administrative permission must not be inherited by, or bootstrapped into,
  teacher permission.

## 7. Effect on the project

- **RES-L0 (E35):** satisfied for the limited design and development scope
  above.
- **RES.2** (administrative StudentMark entry, ADR 0068) may start in
  development **once ADR 0068 incorporates these conditions** (done: ADR 0068
  §19, 7 October 2026). It is not production authorization.
- **Still open, unchanged:**
  - RES-L1 (production);
  - RES-L2 (assigned teachers);
  - RES-L3 (withdrawal): partly informed by §3 above, but post-withdrawal
    use and retention stay open;
  - RES-L4 – RES-L7 (results, report cards, transcripts, Student and Guardian
    access);
  - RES-L8 (retention);
  - RES-L9 (statutory academic rules).

**Do not reinterpret.** Engineering must not treat this determination as
broader than stated. Any expansion listed in §5 requires a new determination
before implementation begins.
