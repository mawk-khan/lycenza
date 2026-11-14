# HR.R1-C35 WO-21: an unclassified employment's skipped clearance requirements, recorded and named (HRC-8; 2026-11-14)

**HR.R1 stays OPEN.** This implements WO-21, the consequence of owner decision D-C35-4 (`HR-R1-C35-P3-DISPOSITIONS.md` §9): a separation case says when the employment is unclassified and names the staff-type clearance requirements that were not applied.

| | |
|---|---|
| Baseline | `0a867e21` = `main` = `origin/main`; `2c66460c` the WO-20 code; clean tree; the historical stash and `integration/e16-r2` preserved; the development database current at 398 migrations |
| Authorized | D-C35-4 (2026-11-13, HR owner) and the owner's WO-21 dispatch; owner decision D-WO21-1 in this unit (§2) |
| Not changed | Staff type stays optional on the employment API, import and rehire (D-C35-4: "No API change"); the checklist rule; clearance items and their states; completion; Separation reason; R1-09; WO-18; WO-20; WO-22 |

## 1. The finding

**HRC-8** (`HR-R1-0-AUDIT-REGISTER.md`, P3, HR), verbatim: "**Unclassified staff type and clearance.** `staff_type` NULL is still creatable through the API, import and rehire, not only on legacy rows. Clearance requirements scoped to a staff type silently skip those employments." `SeparationCaseService.php:428-430`; `EmploymentController.php:43`. Recommendation: "Show 'unclassified' on the case, or require staff type on the API."

**Corrected meaning.** C27 §7 had summarized HRC-8 as "Separation reason classification on the API". C35 corrected it: the finding concerns the **staff type**. The C27 row keeps its correction note; nothing here touches Separation reasons.

**D-C35-4:** "The case says the employment is unclassified and names the staff-type requirements that were not applied. No API change." The stricter fail-closed alternative (apply every staff-type requirement to an unclassified employment) was recorded and not chosen.

**Reproduced first** at `0a867e21` (a throwaway test, deleted): an unclassified employment's approved case showed no staff type and named nowhere the mandatory Teaching requirement "Lab equipment returned" that it did not get; only the universal "Keys returned" was on the checklist.

## 2. Owner decision in this unit (2026-11-13)

| Id | Question | Decision |
|---|---|---|
| D-WO21-1 | Record the skipped requirements at approval, or compute them when the page is read? | **Record at approval:** two columns written with the checklist and frozen; cases approved before WO-21 say "not recorded", never reconstructed |

## 3. The semantics

- **Staff type.** HR.1A's closed `StaffType` (teaching, non_teaching, outsourced) on the employment: `employment_records.staff_type`, a database projection of the effective-dated `employment_terms` (rule 130). NULL = unclassified. It is never derived from a designation, department, category, Payroll or role (rule 130). The approval reads it from the employment row it holds FOR UPDATE.
- **Requirements.** `separation_clearance_requirements`, School configuration: universal (`staff_type` NULL) or scoped to one staff type; retired, never edited. The checklist, taken at approval, is the active universal requirements plus those of the employment's staff type (unchanged).
- **Skipped.** For an unclassified employment: every active requirement scoped to a staff type. For a classified one: none. A Teaching requirement does not apply to a Non-teaching employment; it is not skipped. Skipped means **not evaluated**: never a clearance item, so never pending, cleared or CLEAR. It is distinct from NOT_INTEGRATED (an owner without integration, R1-09), from owner deferrals, and from an obligation's state.
- **States on the case** (`clearanceBasis.state`):
  - `preview`: a requested case, from today's classification and requirements, labelled as what approving now would do;
  - `recorded`: from the frozen record (approved, finalized, completed, or cancelled after approval);
  - `not_recorded`: approved before WO-21;
  - `none`: rejected, withdrawn or cancelled before approval.
- **Unknown versus empty.** A recorded empty list means the approval evaluated and skipped nothing. `not_recorded` is a different state, never an empty list. A failed read throws; it never yields an empty list.
- **Completion.** Unchanged: skipped requirements are shown, never required (D-C35-4).

## 4. What was built

- **Migration** `2027_02_24_090000_record_separation_clearance_staff_type_basis`:
  - the columns `clearance_staff_type varchar(20)` and `clearance_skipped_requirement_ids uuid[]`;
  - `trg_separation_clearance_items_staff_type_basis` → `hr_separation_clearance_item_basis_guard()` (review LOW 3, rule 102 ORG.8F): once a case records its basis, a clearance item is inserted only for a universal requirement or one of the recorded staff type, so a requirement named "not applied" can never also be an item;
  - a shape CHECK: a closed staff type; a classified basis has an empty list;
  - `trg_employment_separation_cases_staff_type_basis` → `hr_separation_clearance_basis_guard()` (INVOKER, `search_path = pg_catalog, public, pg_temp`, schema-qualified). At the requested → approved transition it re-derives both values from the employment and the School's active requirements and requires them exactly. It refuses them at insert, outside the approval, and any later change or erasure. Named to fire after the case guard.
  - `down()` drops exactly these; no existing row is changed.
- **Service:** `approve()` reads the School's active requirements once, records the basis with the transition, and builds the checklist from the same collection. The audit carries `clearanceStaffType` and `skippedRequirementIds` (ids only). A database refusal becomes `SEPARATION_REQUIREMENTS_CHANGED`.
- **Read model:** `clearanceBasis` gives the state, the staff type and its label, and each skipped requirement: code, label, staff type, mandatory or optional, retired since. `employment.staffTypeLabel` is the current staff type.
- **Page:** in Clearance:
  - "Staff type used for this checklist: Unclassified" (or the type); a preview reads "Staff type now (the checklist is taken at approval)".
  - Today's staff type is shown when it differs.
  - A labelled list: "Not applied because the employment was unclassified (n)", each item "not evaluated, not cleared", with a warning glyph and text, never colour alone.
  - "Not recorded" for a legacy case.
- **Verifier:**
  - both triggers in `HR_FAMILY_TRIGGERS` (111 triggers) and `HR_SEPARATION_GUARDS`;
  - a new check, `hr_separation_clearance_basis`: both columns' types, the CHECK by table and whole definition, and both guard bodies (md5), INVOKER mode and path.
- **Contracts:** the ADR 0028 WO-21 amendment, `docs/modules/HR.md`, CLAUDE.md rules 137 and 151, and `PAY-15-ROLLBACK-GUARD-INVENTORY.md`.

## 5. Evidence

| Proof | Test | Result |
|---|---|---|
| Unclassified: the checklist is unchanged (universal only); the record names every active staff-type requirement (label, staff type, mandatory flag); audited as ids | `SeparationClearanceBasisTest` | pass |
| A skipped requirement is never an item (never pending, never CLEAR); completion is not blocked (D-C35-4); it is still named after completion | same | pass |
| Each staff type (teaching, non-teaching, outsourced): its checklist unchanged, nothing skipped, its label shown | same (3 cases) | pass |
| Unclassified with no staff-type requirement: still "Unclassified", an evaluated empty list (not "not recorded") | same | pass |
| Before approval: a labelled preview that follows a reclassification; it records nothing; the approval then records the new type | same | pass |
| After approval: a requirement retired or added, a reclassification: the record unchanged; retired shown as retired, later ones not added; today's type shown beside the recorded one | same | pass |
| A requirement added under the approval (between the service's read and the write): refused (`SEPARATION_REQUIREMENTS_CHANGED`), nothing approved | same | pass |
| Cancelled after approval: the recorded basis still shown | same | pass |
| Viewer without `hr.separations.view`: forbidden; another School: not found | same | pass |
| Runtime role: an omitted, empty, partial or padded list; a classified basis with a list; a wrong staff type; a rewrite, an erasure, a basis outside the approval: refused; an item for a skipped requirement: refused; the exact basis and a universal item accepted | same | pass |
| Two processes: a reclassification before the approval (the new type recorded, nothing skipped) and the approval before the reclassification (unclassified recorded, the record kept) | `SeparationClearanceBasisConcurrencyTest` | pass |
| A case approved before WO-21 (committed, below the guards): "not recorded", never back-filled | same | pass |

**Planted bypasses** (each restored; triggers re-enabled):
- **Detected:**
  - "Unclassified" never shown (4 failures);
  - the skipped list removed (9);
  - skipped turned into items (13);
  - today's type shown as the record (1);
  - the case trigger disabled (2);
  - the item trigger disabled (1).
- **Not detected, by design:** the School filter removed from the requirement reader, because forced RLS already returns only the School's rows. The filter is defence in depth.

**Scale** (one run, 62 active requirements, 47 skipped): approval 48 ms (50 queries, mostly the universal items it inserts); case page 29 ms (21 queries). The skipped names are one query, never one per requirement.

**Suites** (each run alone): HR 1,537, Identity 199, Authorization 236, Postgres 1,259, Operations 15, App 961, Leave 238, Payroll 583, Staff Attendance 76, Retention 287 (4 local skips: object storage), Organization 97. `HrSeparationAndTaskRawIsolationTest` failed twice until its copied-row fixture also nulled the new basis columns (§5 review 2).

**Rollback proof:** IDENTICAL schema after rollback and re-apply (88 schema lines removed); `platform:verify-database` failed=0.

**Independent reviews:**
1. **First review:** no HIGH, no MEDIUM. Four LOW, all fixed:
   - LOW 1: import order;
   - LOW 2: two misplaced docblocks;
   - LOW 3: items not bound to the basis in the database (now a guard);
   - LOW 4: a case cancelled after approval hid its basis.

   INFO fixed: a legacy case now shows today's staff type; a CLAUDE.md comma.
2. **Final-state recheck** of those fixes: no HIGH, no MEDIUM. Its LOW (the import order not yet applied) is fixed. It confirmed:
   - the item guard's logic, firing order, RLS behaviour and rollback order;
   - the cancelled state;
   - that the isolation-test fixture change (nulling the basis on a copied row, as for `decided_*`) is legitimate.

## 6. Dispositions

| Item | Status |
|---|---|
| **HRC-8** (P3) | **CLOSED for development on publication** |
| WO21-N1 (LOW) | A requirement retired between the approval's read and the item insert (a classified employment's own type) surfaces as the items guard's raw error, not `SEPARATION_REQUIREMENTS_CHANGED`. Pre-existing window, fails closed |
| WO21-N2 (INFO) | `hr.separations.view` holders now see the labels of other staff types' requirements that were not applied (previously only under `hr.separations.configure`); low sensitivity (School configuration labels) |
| WO21-N3 (INFO) | `requirementsNamed()` would silently drop an id it cannot find; requirements have no runtime DELETE, so this is theoretical |
| WO21-N4 (INFO) | The School filter in the requirement reader is defence in depth behind forced RLS (a probe removing it is not detected) |
| WO21-N5 (INFO) | No browser walkthrough, axe run or screen-reader test; the page change uses text and a glyph (`aria-hidden`), never colour alone; vue-tsc, ESLint and Prettier pass |
| WO-20 `main` run 43537113592 (cancelled) | Not a pass, and not claimed. Publication requires the green exact-SHA `CI gate` on the candidate (rule 96, enforced by `publication-check` and the ruleset), which `2c66460c` had (integration run 43534600597); the exact-SHA remote full suite is the canonical regression (ADR 0072 §28.8). The cancelled `main` push run was superseded by the evidence push, whose `main` run 43537394916 is green (documentation lane). No further CI is required |
| Development MAJOR inventory | None open |
| WO-22 | Not started |
| Seven P3 decisions, WO20-N1..N7 and other P3/LOW findings, RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, Legal/DPO gates, 361 capability rows, 61 audit identifiers | Unchanged; no baseline selected |
| Development database | 398 migrations; WO-21's `2027_02_24_090000` is not applied there (D-WO20-3 covered only the earlier two). Forward-migrating it needs the owner's word |
