# HR.4–8 capability disposition (HR.R1-C12, proposal)

**A proposal for D-1, Option B. Nothing here is approved, and no row of `HR-REFERENCE-CAPABILITY-MAP.md` changes until the HR owner decides.** Generated from that map's §4 at `0b77270c`: every row whose Phase column names HR.4 to HR.8.

**82 rows:** 61 GATE, 20 with no gate, and 1 held by ADR 0065 §7 (D8-06). The earlier "12 ungated HR.4 rows" are all here, but they are not the only ungated rows: HR.7 has 7 and HR.6 has 1, including the high-risk D8-10.

| Proposed | Rows | Meaning |
|---|---:|---|
| HELD | 61 | Deferred with its gate. The map status stays GATE. A deferral opens nothing |
| ACTIVE | 4 | Stays in scope, with a named slice. These must not be deferred away |
| DEFERRED | 10 | Ungated, deferred by the owner with a re-review trigger. Needs the owner's record |
| KEEP | 5 | Built, or built differently. Nothing to defer |
| KEEP (DEF) | 2 | Already DEF. Unchanged |
| **Total** | **82** | |

**Dependencies that stay visible:** S-16 is D14-09. S-6 is D8-13, and it has no owner determination. D8-10 is stranded until RM:315 stops making HR.7 depend on gated HR.6. HRX-L2 is not split, and OD-HR-14 is undecided. "ADR 0065 §8" in the gate column is a known citation defect: the operative text is §1 and §7.

**Checked against the map at `0b77270c`:** every row's status, phase, owner and gate match, and the proposal column sums to 82.

| Phase | Rows | GATE | Other |
|---|---:|---:|---|
| HR.4 | 27 | 15 | 12 ungated: 8 DEFERRED, 2 ACTIVE, 2 KEEP |
| HR.5 | 15 | 15 | None |
| HR.6 | 8 | 7 | D7-16, KEEP |
| HR.7 | 8 | 0 | 2 ACTIVE, 2 DEFERRED, 2 KEEP, 2 KEEP (DEF) |
| HR.8 | 24 | 24 | None |

**Preserved by every option:** HRX-L2, OD-HR-14 as direction only, Staff Attendance's date and half-day scope, the E33 distinction (teacher attendance concerns Student attendance, never staff), academic ownership, and the approved absence and Leave controls. A deferral authorizes no biometric, clock-time, shift or WorkSchedule processing.

| Id | Feature | Status | Domain | Implemented | Gate | Phase | Proposed | Reason | Owner | Acceptance | Next |
|---|---|---|---|---|---|---|---|---|---|---|---|
| D1-04 | Biometric identity | GATE | StaffAttendance | none (guards) | HRX-L2 (L2b) | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D2-07 | Shift change | GATE | WorkSchedule | none | ADR 0065 §8, HRX-L2 (L2a) | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-02 | Work calendars | PART | WorkSchedule (proposed) | `staff_working_weekdays` (School-wide) | — | HR.4 | **DEFERRED** | One calendar per School meets current scope. No timing data, so no legal gate, but no contract needs it either | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D5-03 | Branch / Campus calendars | MISS | WorkSchedule | none | — | HR.4 | **DEFERRED** | As D5-02. A Campus calendar changes working-day answers for Leave, so it needs its own contract | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D5-05 | Optional holidays | MISS | WorkSchedule | none | — | HR.4 | **DEFERRED** | Low risk, no contract depends on it | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D5-06 | Week-offs | PART | WorkSchedule | weekday pattern (full / first half / off) | — | HR.4 | **DEFERRED** | Nth-week patterns extend the weekday pattern. History first (D-6), or they add to what cannot be reconstructed | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D5-07 | Shift definitions | GATE | WorkSchedule | none | ADR 0065 §8, L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-08 | Shift start / end | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-09 | Cross-midnight shifts | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-10 | Breaks / sessions | GATE | WorkSchedule | none (half-day halves only) | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-11 | Grace periods | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-12 | Late thresholds | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-13 | Early-leaving thresholds | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-14 | Minimum working duration (work-hours shift) | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-15 | Half-day thresholds | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-16 | Shift assignment | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-17 | Bulk shift assignment | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-18 | Temporary shift change | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-19 | Effective dates on shifts | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D5-20 | Historical shift lookup | GATE | WorkSchedule | none | L2a | HR.4 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-01 | Biometric device source | GATE | StaffAttendance | none (guards) | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-03 | Facial recognition | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-04 | GPS / mobile self check-in | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-05 | Provider API / punch import | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-07 | Raw evidence preserved (source → normalized → evaluated) | GATE | StaffAttendance | none | L2a/L2b | HR.5 | **HELD** | Both timing and biometric or device data | HR owner, Legal, Security | Stays GATE, and stays refused (R1-10) | OD-HR-14 then HRX-L2b |
| D6-08 | Provider event id | GATE | StaffAttendance | none | L2b | HR.5 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-09 | Idempotent ingestion | GATE | StaffAttendance | none | L2b | HR.5 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-10 | Duplicate punches | GATE | StaffAttendance | none | L2b | HR.5 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-11 | Event timestamp | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-12 | Timezone handling | GATE | StaffAttendance | School timezone exists (`SchoolTimezone`) | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-13 | Campus on event | GATE | StaffAttendance | none | L2b | HR.5 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-14 | Device on event | GATE | StaffAttendance | none | L2b | HR.5 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-15 | Source on event | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-16 | First-in | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-17 | Last-out | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-18 | Multiple punches | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-19 | Cross-midnight events | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D6-20 | Late-arriving imports | GATE | StaffAttendance | none | L2b | HR.5 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D6-21 | Missing punches | GATE | StaffAttendance | none | L2a | HR.5 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-07 | Late | GATE | StaffAttendance | none | L2a | HR.6 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-08 | Early leaving | GATE | StaffAttendance | none | L2a | HR.6 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-09 | No punch | GATE | StaffAttendance | none | L2b | HR.6 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D7-10 | Incomplete punch | GATE | StaffAttendance | none | L2a | HR.6 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-11 | Working duration | GATE | StaffAttendance | none | L2a | HR.6 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-12 | Permitted late arrival | GATE | StaffAttendance | none | L2a | HR.6 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-13 | Permitted early departure | GATE | StaffAttendance | none | L2a | HR.6 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D7-14 | Attendance exceptions | ALT | StaffAttendance | write-time conflict refusal (leave × presence, both ways) | — | HR.7 | **KEEP** | Write-time refusal stands in for a queue. A queue only makes sense once evaluation exists | HR owner | Row stays ALT | None |
| D7-16 | Deterministic historical re-evaluation | ALT | StaffAttendance | records + corrections; payroll fingerprint difference | — | HR.6 | **KEEP** | Records, corrections and the Payroll fingerprint stand in. An evaluation version only makes sense once evaluation exists | HR owner | Row stays ALT | None |
| D8-01 | Manual marking | IMP | StaffAttendance | daily register (`hr.staff_attendance.manage`) | — | HR.7 | **KEEP** | Built. Must not be swept into a deferral. Its Campus or Department filter gap stays recorded on the row | HR owner | Row stays IMP | None |
| D8-03 | Regularization request | MISS | StaffAttendance | none | — | HR.7 | **DEFERRED** | Self-service needs a decision. HR-011 is `OWNER_DECISION_REQUIRED` in `docs/product/reference-parity/functions.csv`, with no legal gate. A timing-based form would also need L2a | HR owner | An owner answer to HR-011; row DEF with a trigger | After HR-011 |
| D8-04 | Regularization approval | MISS | StaffAttendance | none | — | HR.7 | **DEFERRED** | Follows D8-03. Would sit on the shared approval foundation (rule 102) | HR owner | As D8-03 | After HR-011 |
| D8-06 | Manager correction | DEF | StaffAttendance | none (manager recording is out of v1, ADR 0065 §7) | ADR 0065 §7 | HR.7 | **KEEP (DEF)** | Already DEF. Needs ADR 0065 §7 reopened, which a deferral cannot do | HR owner | Row stays DEF | ADR 0065 §7 reopening |
| D8-10 | Locking / finalization | MISS | StaffAttendance + Payroll | none (Payroll detects post-approval change) | — | HR.7 | **ACTIVE** | High risk, with D8-13: no period lock, and Payroll only detects later change. No timing data. HR.7 depends on gated HR.6 (RM:315), so that dependency needs fixing or it is stranded | HR owner, Payroll | A lock with controlled reopening, Payroll tests, audit | Attendance lock slice (WO-6) |
| D8-13 | Prevention of unauthorized historical edits | PART | StaffAttendance | future refused; no window, MFA or second person (S-6) | — | HR.7 | **ACTIVE** | This is S-6: no window, MFA or second person, and no owner determination exists. High risk | HR owner, Security | A recorded S-6 determination, then its tests | With S-6, WO-6 |
| D8-14 | Work-from-home assignment and marking | DEF | StaffAttendance | none | — | HR.7 | **KEEP (DEF)** | Already DEF. HR-004 is `OWNER_DECISION_REQUIRED` in `functions.csv` and would reopen the ADR 0065 evidence model | HR owner | Row stays DEF | After HR-004 |
| D9-01 | Biometric code mapping | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-02 | Biometric identity uniqueness | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-03 | Staff / device relationship | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-04 | Device / provider registry | GATE | StaffAttendance | none; Integration Hub lists biometric as unavailable | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-05 | Imports | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-06 | Import audit | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-07 | First-in | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-08 | Last-out | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-09 | Working hours | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-10 | Late classification | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-11 | Early leaving | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-12 | Permitted late / early flags | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-13 | Branch-wise daily report | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D9-14 | Department filtering (biometric views) | GATE | StaffAttendance | none (the register has no department filter) | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D14-02 | School / Campus | PART | WorkSchedule | School only | — | HR.4 | **DEFERRED** | Same decision as D5-03 | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D14-03 | Leave-category applicability | MISS | WorkSchedule | none | — | HR.4 | **DEFERRED** | Low risk, no contract depends on it | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D14-04 | Department applicability | MISS | WorkSchedule | none | — | HR.4 | **DEFERRED** | As D14-03 | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D14-05 | From / to date | ALT | WorkSchedule | one row per date (a range = several rows) | — | HR.4 | **KEEP** | Built differently and accepted. Nothing to defer | HR owner | Row stays ALT | None |
| D14-07 | Optional holiday | MISS | WorkSchedule | none | — | HR.4 | **DEFERRED** | Follows D5-05 | HR owner | Map row DEF with a re-review trigger | Calendar applicability, after D-6 |
| D14-08 | Effective publication | MISS | WorkSchedule | immediate | — | HR.4 | **ACTIVE** | Publication is what gives a calendar change a date. Belongs with calendar history | HR owner, D-6 | Test: a change has a recorded effective date, and past dates keep their answer | Calendar-history slice (WO-5) |
| D14-09 | Modifications | PART | WorkSchedule | remove + add (hard delete, S-16) | — | HR.4 | **ACTIVE** | This is S-16: `staff_holidays` is hard-deleted at runtime. A deferral would strand it | HR owner, D-6 | Runtime DELETE revoked, a versioned change, a test | Calendar-history slice (WO-5) |
| D14-12 | No silent retroactive change | ALT | Leave | request days frozen; payroll fingerprint differences | — | HR.4 | **KEEP** | Built differently: request days frozen, Payroll fingerprint differences. Re-read once D-6 is decided | HR owner | Row stays ALT; R1-08 limit stays recorded | Re-read with WO-5 |
| D20-19 | Biometric reports | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D20-20 | Late / early reports | GATE | StaffAttendance | none | L2a | HR.8 | **HELD** | Timing evidence, and HRX-L2 is not split | HR owner, with a privacy note | Stays GATE, and stays refused (R1-10) | OD-HR-14 (L2a) |
| D20-27 | Roaming staff | GATE | StaffAttendance | none (a punch at another branch's device) | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D20-28 | GPS / self check-in report | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
| D20-38 | FR attendance report | GATE | StaffAttendance | none | L2b | HR.8 | **HELD** | Biometric, device, GPS or FR processing | Legal, Security | Stays GATE, and stays refused (R1-10) | HRX-L2b |
