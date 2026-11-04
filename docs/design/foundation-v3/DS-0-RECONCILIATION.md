# DS-0 — Foundation v3 reconciliation

- Date: 2026-11-03
- Decision record: ADR 0082.
- Base: `main` at `f82a75d0` (HR.9).
- Method: every item of the prototype's decision register, every persona and
  every pattern was checked against the code (capability and role catalogue,
  `ModuleCatalogue`, closed reason enums, controllers, migrations), not
  against documentation alone. Where this file cites a value, the value is
  the code's at the base commit.

## 1. Summary

| Area | Finding |
|---|---|
| Visual system | Adoptable. Five contrast failures must be corrected (§5). |
| Markup | Not copyable: 640 inline `style` attributes, which the enforced CSP (`style-src 'self'`) refuses. Translated to tokens and Tailwind classes (ADR 0082 §2.3). |
| Fonts | Loaded from Google Fonts in the export; the CSP (`font-src 'self'`) refuses them. Barlow is self-hosted in DS-1. |
| Personas and capabilities | Invented by the prototype (its D-22). Never used. The repository catalogue decides (§3). |
| Decision register | 33 items: 18 answered by the repository (D-23 also leaves an owner choice), 10 consistent with it, 2 owner decisions, 1 legal decision, 2 deferred by existing contracts (§2). |
| Patterns | All 13 map to existing components or contracts; 2 need a correction (§4). |

## 2. Decision register

Dispositions:
- **REPO** — the repository already answers it; the answer is binding.
- **CONSISTENT** — the design matches the repository.
- **OWNER** / **LEGAL** — needs an owner or legal decision; the DS programme
  builds nothing for it.
- **DEFERRED** — an existing contract defers it.

| ID | Design said | Repository (at `f82a75d0`) | Disposition |
|---|---|---|---|
| D-01 | Codebase not inspected. | This document is the inspection. | REPO — closed by DS-0 |
| D-02 | No Registrar persona. | No `registrar` role in the 17-role School catalogue (`StaffRoleCatalogueSnapshotTest`). | CONSISTENT |
| D-03 | Register starts unmarked; Save needs every Student marked. | `Attendance/Take.vue` disables Save while `unmarkedCount() > 0`. | CONSISTENT |
| D-04 | "Mark all Present" not built. | Not built. | OWNER — decided 2026-11-04: "Mark remaining Present" (unmarked only, never a default; ADR 0082 §12.2) |
| D-05 | No attendance percentages. | Rules 104, 123, 124: no rate, tally or percentage outside Analytics (ADR 0040). | CONSISTENT |
| D-06 | Year selector only on academic screens. | The shell has no year selector; screens take the year as a filter. | CONSISTENT |
| D-07 | Campus filter only where supported; scoped users see "Your scope". | Rule 101: a selected Campus is a filter, never authority, intersected with `CampusScope`. | CONSISTENT |
| D-08 | Payment correction = request, then another person approves with fresh MFA. | Rule 91/102 (ORG.8C): `PaymentCorrectionRequestService` → `executeApproved()`. | CONSISTENT |
| D-09 | Bulk Section change removed. | **Stale.** SIS 11.13 (rule 129, ADR 0076 §84) ships bulk Change Section and bulk Transfer Campus: signed preview, fresh MFA, all or nothing, max 100. | REPO — design superseded |
| D-10 | Student status and placement state are separate. | `students.status` ∈ `active`, `inactive`; `student_enrollments.status` ∈ `active`, `completed`, `withdrawn`, `transferred`, `cancelled`. The design omits `transferred`. | REPO — add `transferred` |
| D-11 | Closed reasons are placeholders ("Family relocation"…). | `StudentLifecycleReason::CODES` and `LABELS` (e.g. withdraw: `left_school`, `transferred_out`, `guardian_request`); `ChargeCancellationReason`, `StudentCertificateVoidReason`, `DocumentRejectionReason`, `StudentDocumentArchiveReason`, HR and Platform reason enums. "Family relocation" does not exist. | REPO — labels come from the enums, server-supplied |
| D-12 | Four document categories, placeholder names. | `StudentDocumentCategory`: six values (Birth certificate, Previous school record, Enrollment paperwork, Consent or permission form, Family correspondence, Certificate issued by this School); four are requirable (rule 126). | REPO |
| D-13 | Upload limit 10 MB placeholder. | `config('documents.max_file_size_mb')`, default 10 (`DOCUMENTS_MAX_FILE_SIZE_MB`). Pages show the server value, never a constant. | REPO |
| D-14 | Who approves payment corrections. | `finance.payment_corrections.approve` is held by `school_admin`; `accountant` holds `.view` and `.request`. | REPO |
| D-15 | Certificate production gate. | `StudentCertificateAvailability` (rule 121) refuses production until legal review. | LEGAL — gate pattern only |
| D-16 | Movement register refuses Campus-scoped users. | Rule 119: a declared whole-School operation of `enrollments.view`; it never opens on a Campus grant. | REPO |
| D-17 | Which domains block a Campus transfer. | Rule 101 (ORG.7F-3): active Hostel residency, Transport rider assignment, elective (`HostelTransferBlocker`, `TransportTransferBlocker`); refused generically. | REPO |
| D-18 | Prototype asks for MFA on a same-year transfer. | **Single** transfer (`StudentEnrollmentController::transfer`) takes no fresh MFA and no reason; **bulk** moves need fresh MFA (rule 129). The DS programme must not add an MFA step. | REPO — remove the extra MFA |
| D-19 | "Excused" status per School? | `AttendanceRecord::STATUSES` = `present`, `absent`, `late`, `excused` for every School. | REPO |
| D-20 | Core: Organization, Students, Academics, Timetable, Attendance, Finance, HR, Communications. | **Wrong.** `ModuleCatalogue` core = Identity & Access, Organization, Academic Structure, Student Records, Staff Records, Compliance & Audit, Documents. Timetable, Attendance, Finance, Fees, Communications, Leave, Payroll and the operations modules are optional (rule 100). | REPO |
| D-21 | Accent picker limited to swatches. | Rule 115: any `#RRGGBB` with a derived foreground (`BrandColor`). Restricting it is a product change. | OWNER — decided 2026-11-04: keep any colour (ADR 0082 §12.3) |
| D-22 | Role × module matrix from the catalogue. | The School Access Center (rule 114) already renders it from `CapabilityResolver` and the catalogue. | REPO |
| D-23 | Principal finance visibility. | `principal` holds no `finance.*` key. | OWNER — decided 2026-11-04: no change, Principal keeps no finance (ADR 0082 §12.4) |
| D-24 | Assignments in teacher navigation. | Exists: `lms.assignments.view/.manage/.teacher` (LMS module, TCH). Submissions do not. | REPO — Assignments is real |
| D-25 | Student portal removed. | Rule 115: no Student audience exists. | CONSISTENT |
| D-26 | Operations modules as placeholders. | **Stale.** Library, Transport, Hostel, Inventory, Canteen and Visitors are built, entitlement-gated modules. | REPO — migrate their real pages (DS-4+) |
| D-27 | No cross-School Student move. | No such path; Schools are tenants. | CONSISTENT |
| D-28 | No merge; duplicate warnings only. | ADR 0079, rule 122: warnings with acknowledgement, never a merge. | CONSISTENT |
| D-29 | No annual record confirmation. | Not built. | DEFERRED |
| D-30 | Biometric, SMS/WhatsApp, payment gateway shown Not available. | Rule 116 Integration Hub: exactly so. | CONSISTENT |
| D-31 | Deny-only field policy post-v1. | Rule 114: deferred until a central server projection exists. | DEFERRED |
| D-32 | School lifecycle states unknown. | `schools.status` ∈ `provisioning`, `active`, `suspended`, `archived`; transitions provisioning→active, active⇄suspended (`trg_schools_status_transition`). Close/Reopen = a suspension recorded with `closed_at` and a `SchoolClosureReason` (migration `2026_11_08_090000_add_school_closure_columns`). | REPO |
| D-33 | Phase 9 closure unknown. | Phase 9 = non-statutory Payroll, closed and published 2026-09-03 (MASTER-ROADMAP). Payroll pages exist. | REPO |

Open items left for the owner, none blocking DS-1: **D-04**, **D-21**, **D-23** -- all decided 2026-11-04 (ADR 0082 §12).
D-15 stays with legal review. D-29 and D-31 stay deferred.

## 3. Personas

The prototype's 11 personas carry made-up capability sets. The DS programme
never encodes them. A page decides what to render from the props the server
already sends (capability-derived booleans, `NavigationBuilder` entries,
server-resolved links), exactly as today.

| Prototype persona | Repository equivalent |
|---|---|
| School Administrator | `school_admin` |
| Principal | `principal` (no `finance.*`, see D-23) |
| Campus Coordinator | No such role. A staff role granted on one Campus (rule 101); roles holding a Campus-incompatible key cannot be. Its reach is far narrower than the prototype's: Campus Student writes are name edits and onboarding only (ORG.7F-2). |
| Accountant | `accountant` (never Campus-scopable) |
| Teacher | `teacher` (ownership-scoped `*.teacher` keys) |
| Guardian | `guardian` (Guardian portal audience) |
| Platform Admin / Manager / Auditor | root `platform_super_admin`, `platform_manager`, `platform_auditor` (rule 85, 99) |
| Network Manager / Viewer | `network_manager`, `network_viewer` (rule 114) |

## 4. Patterns

| Pattern | Repository mapping | Note |
|---|---|---|
| Why an action is unavailable (not enabled / permission / legal / assurance) | Module entitlement state, Gate refusal, legal gates (`StudentCertificateAvailability`), `MfaCodeField` | Four distinct messages, as designed. |
| Status families (symbol + word, never colour alone) | `StatusBadge.vue` | Adopt; DS-3 adds the glyph. |
| Fresh MFA | `MfaCodeField.vue`, `FreshMfaRequirement` | Only where the server already requires it (D-18). |
| Closed reason | Server-supplied enum labels | Never a client-side list (D-11). |
| Consequential confirmation | `ConfirmDialog.vue`, `useConfirmation.ts` | Adopt the "seven questions" and the verb-repeating button. |
| Maker / checker | Approval workflows (rule 102) | Copy shows the server's refusal, never a client-side guess. |
| Stale write | 409 `refusal` with version tokens (rule 127) | Adopt "Reload latest". |
| Possible duplicate | `DuplicateStudentWarning.vue` (ADR 0079) | Already shows the same fields. |
| Entity selector | `SearchCombobox.vue`, `StudentPurposeLookup` | Name + number, never name alone. |
| Campus scope | Scope labels on access and filtered pages | "Your scope" text only; never a selector that implies authority. |
| Activity timeline | Movement register, audit-backed histories | **Correction:** the prototype prints "By Rahul Verma". Rules 119 and 123 forbid an actor on these surfaces (one exception: Attendance reports, OD-SIS-10A). The timeline shows date, event and closed reason only. |
| Feedback | `FlashMessage.vue`, `RefusalMessage.vue` | Name the object and outcome; known refusals never become "Something went wrong". |
| System states (12) | `EmptyState.vue`, `RefusalMessage.vue`, error pages | **Correction:** "Offline" has no web contract; the staff web app is online-only. 11 states are adopted. |

## 5. Accessibility corrections to the palette

Measured with the WCAG 2.x relative-luminance formula against the ground
`#f2f2f3`.

| Use in the design | Pair | Ratio | Needs | Correction |
|---|---|---|---|---|
| Primary button label | ground on accent `#5980a6` | 3.71 | 4.5 | Fill `accent-700` `#416180` (6.47 with white); hover `accent-800`. |
| Helper / secondary text (today's `text-slate-500`, ~1,200 uses) | `neutral-600` `#7a7a7d` | 3.82 | 4.5 | `neutral-700` `#5d5d60` (5.87). |
| Input border | `--color-divider` (16 % ink) | ≈1.3 | 3.0 (1.4.11) | `neutral-600` (3.82). Card and table rules may stay hairline: they are not control boundaries. |
| Card kicker (10 px, accent) | accent on ground | 3.71 | 4.5 | `accent-700` and at least 12 px. |
| Card meta (50 % ink) | ≈ `neutral-600` | ≈3.8 | 4.5 | `neutral-700`. |

The accent itself (3.71) stays valid for icons, focus rings and large
headings (3:1). A School accent (rule 115) is checked the same way in DS-1:
`BrandColor` already derives a readable foreground; DS-1 adds the deep step
used for text.

## 6. What is not adopted

- The prototype's persona, state and environment pickers, walkthroughs,
  relationship map and register views (presentation aids).
- The Mobile tab: the Flutter client (`apps/mobile`) is out of scope; its SDK
  is unverified in this environment.
- The bundled "modernist" design system (unused by the prototype).
- Duotone photography: no governed media pipeline exists (rule 115).
