# HR legal & security gate register (HR.EXP-0)

Status: **register, documentation only** (HR.EXP-0, 2026-10-30, baseline
`c5e3dd23`). It consolidates the existing records. It decides nothing and
changes no gate. Sources are cited per row. The statuses are quoted from the
repository, not from memory.

## 1. Legal and privacy gates

| Id | Subject | Repository status (quoted) | Development | Production | Expiry / re-review |
|---|---|---|---|---|---|
| HRX-L1 | Staff health / medical data | "OPEN — structurally absent (guards); no medical upload" (`docs/modules/HRX-READINESS-AND-CLOSURE.md` §9) | permitted, without any health field (guard tests) | **not refused in code**: absent by construction, guard-tested | none; needs a qualified decision and an ADR 0065 amendment |
| HRX-L2 | Biometric / device / clock attendance | "OPEN — no clock time, device, location, photo or biometric column (guards)" (same §9); Integration Hub lists `attendance.biometric`/`attendance.rfid` as not available (`OrganizationIntegrationCatalogue`) | permitted, without such data | absent by construction | as HRX-L1 |
| HRX-L3 | Statutory leave applicability | "OPEN — LEGAL REVIEW REQUIRED. No jurisdiction is cleared" (`docs/security/HRX-L3-STATUTORY-LEAVE-APPLICABILITY-MATRIX.md`) | entitlements are School policy only | no statutory leave claimed | as HRX-L1 |
| HRX-L4 | Loss of pay / EPFO NCP | "MECHANISM IMPLEMENTED / LEGAL ACTIVATION BLOCKED" (`docs/security/HRX-L4-PAYROLL-LOSS-OF-PAY-DETERMINATION.md`); policy `pending_hrx_l4` | evidence captured, no money effect | no path from HRX evidence to an amount; ECR NCP stays `0` ("not claimed to be correct") | activation needs a decision and an amendment |
| E45 / PAY-L1 | ESI disability wage threshold | "DRAFT REQUEST — NOT SENT, NOT ANSWERED" (`docs/security/PAY-L1-ESI-DISABILITY-THRESHOLD-REVIEW-REQUEST.md`) | ESI-12 test skipped; no disability fact collected | n/a | awaiting the answer |
| Statutory payroll (ADR 0036, checkpoint 9.6) | PF, ESI, PT, LWF, TDS calculation, identifiers, exports | `payroll.statutory.*` classed **legal-gated**; held by **no** production role; calculation and posting have **no route or CLI caller** (ADR 0076 §60.8a) | demo-only role `demo.payroll_statutory` via `LocalCatalogueFixtures` | **gated by "no role holds it" and "not wired", not by an environment refusal** (§3 S-4) | a catalogue release + legal sign-off |
| E33 / TCH-L1 | Teacher processing of **Student** attendance (not staff attendance) | "DETERMINED — APPROVED WITH CONDITIONS" (ADR 0058; 7 Oct 2026, DPO) | permitted | teacher bearer API refused in production (`EnsureTeacherAttendanceApiDevelopmentOnly`); the rest is ready for production-candidate sign-off with re-verification on deployment | re-review triggers listed (TCH-L1 §9, §11); no fixed expiry |
| E47 / SR-L1 | Staff personas (hr_officer, payroll_officer, …) | "QUESTION DRAFTED (2026-10-09, not sent, not answered)… Development is not blocked" (ADR 0058) | permitted | production policy prerequisite | — |
| E21 | Retention (HR evidence D9 8 y; ancillary 2 y) | "PROJECT-ADOPTED FOR IMPLEMENTATION — PENDING FINAL LEGAL/COMPLIANCE RATIFICATION"; closure audit "OPEN / QUALIFIED RATIFICATION PENDING" | implemented | production gate | ratification |
| E16 | Release vulnerability exceptions (not HR-specific) | `OWNER-0O-E16-2026-10-16`, last valid day **2026-11-12** | — | from 2026-11-13 every publication is refused | a fresh-scan owner successor |

**Not yet recorded, and needed before the feature exists:**
- recruitment data retention, candidate consent and public-form privacy
  notice;
- background or police verification checks;
- disciplinary and grievance records;
- employee family or dependant data;
- bank details and salary disbursement files;
- performance-appraisal data.

Each needs its own legal or privacy determination (§2 of the roadmap).

## 2. Production effect summary

- **Production refusal in code exists for:**
  - the teacher bearer attendance API (E33);
  - certificate issuance (SIS, ADR 0081), shown for contrast.
- **Production is held only by absence, guard tests and role non-assignment
  for:**
  - HRX-L1–L4;
  - statutory payroll.

  The HR expansion should prefer an explicit availability gate (the
  `StudentCertificateAvailability` pattern) whenever it builds anything near
  these.

## 3. Security findings (HR.EXP-0 audit, code truth at `c5e3dd23`)

| # | Severity | Finding | Status | Destination |
|---|---|---|---|---|
| **S-1** | **MEDIUM (P0)** | **The Payroll checker approves what it cannot see.** The only default approver, `school_admin`, holds `payroll.runs.approve`/`.post` but not `payroll.compensation.sensitive.view`, so `PayrollRunController::renderShow()` hides per-employee results, overrides and correction deltas from it. Maker-checker is structurally enforced (ORG.8B), but the checker has nothing to check. A `payroll_officer` can enter an inflated manual override for a colleague, and the admin approves it blind. Not recorded in `APPROVAL-AUTHORITY-MATRIX.md` (Payroll row "COMPLETE for v1") or ADR 0071 §26 | **OPEN — owner design decision**: (a) the approver must also hold sensitive view, or (b) an approval-only review projection (totals, per-person change against the prior run above a threshold, every override and delta with amounts) under fresh MFA | **HR.HARDEN-0**, before any Payroll wave or Payroll production |
| S-2 | MEDIUM-adjacent, **recorded residual** | Compensation changes have no maker-checker. One `payroll_officer` with fresh MFA sets any colleague's pay; a second officer can calculate it. Accepted in ADR 0076 §60.8a, but it **compounds S-1** | recorded; **re-review recommended together with S-1** | HR.HARDEN-0 decision |
| S-3 | LOW, **recorded open owner decision** (ADR 0071 §26.13: "an `hr_officer` reads and changes their own HR notes and documents") | The HR self-administration guard does not cover notes, documents, personal details, addresses, emergency contacts or qualification/certification **verification**. An HR officer can hard-delete notes about themselves (the audit keeps no body) and verify their own credentials | open owner decision. This audit **extends** it to qualifications, personal details, addresses and contacts, which §26.13 does not name | HR.HARDEN-0 (after the decision) |
| S-4 | LOW (latent) | Statutory identifier reveal and the ECR/TDS exports (unmasked UAN/PAN) carry no MFA assurance or no-store header; `identifiers.manage` has no fresh MFA or self-guard. Unreachable today: no role holds the keys | latent | fix before any statutory grant (payroll wave) |
| S-5 | LOW | Fresh-MFA proof is optional or absent at the Payroll service layer (`approve(?FreshMfaProof)`; post, reverse and assign take none); only the transport enforces it | open | HR.HARDEN-0 |
| S-6 | LOW | Staff attendance correction has no time window, MFA or second person, and uses a cached capability check. Recorded as "NO_APPROVAL; keep (self refused)" (`APPROVAL-AUTHORITY-MATRIX.md`) | recorded decision; **re-review at HRX-L4 activation** (it feeds loss-of-pay evidence) | HRX-L4 activation |
| S-7 | LOW | Leave adjustments and allocations need no fresh MFA and use the cached check | open | Leave wave |
| S-8 | LOW | Employment create (API) accepts any status, including terminal or `pre_joining` with no end; `/end` accepts a future date that `separate()` refuses | open | HR.HARDEN-0 |
| S-9 | LOW | Department reparent checks for a cycle outside the transaction, without locks (a concurrent opposite reparent can create a cycle) | open | HR.HARDEN-0 |
| S-10 | LOW | "One current employment" and indirect reporting cycles are application-only (no DB exclusion constraint) | open | HR.HARDEN-0 (DB backstop for employment overlap) |
| S-11 | LOW | `Employee::$fillable` includes `school_id`/`record_status` (the rule 118 pattern SIS fixed); `employee_number` immutability is Eloquent-only | open | HR.HARDEN-0 |
| S-12 | LOW | `employment_type` and document `category` are free text despite documented closed catalogues | open | HR.HARDEN-0 / Documents wave |
| S-13 | LOW | `PayrollPostingService::post()` performs no capability check; the demo posts the August run as `hr.payroll`, who lacks `payroll.runs.post` | open | HR.HARDEN-0 (demo + service authority) |
| S-14 | LOW | G-9: the `setManager` / manager-path Leave decision deadlock returns a 500 (no corruption) | recorded, HR post-v1 | HR post-v1 |
| S-15 | LOW | A Guardian-only account can be linked as an Employee and then counts as current Staff for Staff audiences. This is an explicit `hr.employees.manage` act and grants no School capability (it is also the legitimate staff-parent case); a mis-link exposes Staff announcements only | open | HR.EXP-1 (picker shows account kind) |
| S-16 | LOW | Six ancillary HR profile tables and `staff_holidays` are hard-deleted at runtime (audited, content lost before the retention window) | open | owner decision with the profile wave |
| M-1 | recorded production prerequisite | A staff parent who never links their Guardian account is not detected by beneficiary refusals | recorded (ORG.8F §64.7) | owner policy decision |
| INFO | — | HR tables cascade on School delete (mitigated: the runtime cannot delete a School, rule 86); statutory rule-version tables are platform reference (no RLS, like `education_boards`); `hr.leave.approve` is held by `school_admin` and `principal` only, so line managers cannot approve in practice; documentation drift (HRX closure says `staff_self_service` has 3 capabilities, the seeder has 5) | — | doc fix with the next HR change |

**HIGH: none.**
