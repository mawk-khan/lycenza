# HRX-L4 — Payroll Loss-of-Pay Determination

- **Gate:** HRX-L4 (ADR 0065 §19, §26).
- **Status: MECHANISM IMPLEMENTED / LEGAL ACTIVATION BLOCKED.**
  - No qualified legal or compliance review has occurred.
  - Nothing here is a legal opinion, and **nothing is cleared**.
- **Opened:** HRX.0 (2026-10-03).
- **This record:** HRX.5 (2026-10-04), baseline `2b0b1f5`.
- **Owner authorization** permits building the mechanism. It is **not**
  qualified validation and does not clear this gate.

## 1. What is implemented, and what is not

| Concept (ADR 0065 §26.2) | State |
|---|---|
| HRX absence evidence: approved paid/unpaid leave, recorded absence/presence, unresolved working time, in integer half-day units, versioned and fingerprinted | **Implemented** (`PayrollAbsenceEvidenceReader`, `hrx_payroll_input.v1`) |
| Payroll snapshot of that evidence, frozen with the run; pending-difference detection after approval | **Implemented** (`payroll_run_hrx_inputs`) |
| Payroll "non-payable" input derived by a payroll policy | **Not implemented.** No validated policy; status `pending_hrx_l4` |
| Monetary loss-of-pay deduction | **Disabled.** No code path from HRX evidence to any amount (guarded) |
| EPFO ECR NCP days from HRX evidence | **Not implemented.** The export keeps its legacy default `0`, pending validated mapping (§4) |

**The legacy ECR value `0` is not claimed to be correct.** It is the
pre-existing default, kept so that production export semantics do not
change silently.

## 2. Central baseline: Code on Wages, 2019

### Facts established from official sources
Accessed 2026-10-04.

| Source | What it establishes |
|---|---|
| Ministry of Labour & Employment, *FAQs on Labour Codes*, labour.gov.in `/static/uploads/2026/01/de4758d5bfeffc456d7de97a801891b0.pdf` (FAQ dated 30.12.2025 per the later FAQ) | Q1: during the transition, old rules remain in force under section 6 of the General Clauses Act, 1897, **until new rules under the Code are notified**, to the extent consistent with the Codes. Q2–Q7: the single "wages" definition (section 2(y)), including the 50% allowance rule. Gratuity applies from 21.11.2025, the date the Code was enforced. |
| MoLE, *Additional FAQs on Labour Codes (As on 16.03.2026)*, labour.gov.in `/static/uploads/2026/03/a4ccf4c6d97c4f1f36a6d83f8c64213d.pdf` | Q7: the definition of "wages" came into effect on **21.11.2025**. Q9: wages are fixed by the terms of employment, as distinct from minimum wages. The disclaimer: in case of variance, the Code prevails. |
| MoLE, *Compliance Handbook for Employers Under the Four Labour Codes (Central Government Sphere)*, labour.gov.in `/static/uploads/2026/02/83978455025732b99b0165def80ab171.pdf` (undated; uploaded under 2026/02) | §3.5: no deduction except as authorised under the Code (section 18). Authorised deductions **include deductions for absence from duty**, and total deductions shall not exceed 50% of wages in the wage period. Wage periods may be daily, weekly, fortnightly or monthly (section 6). Attendance and wage registers and the register of fines and deductions are kept for 5 years (sections 19, 21, 50). |
| Notification S.O. 5322(E), 21.11.2025, Gazette of India (Extraordinary) Part II §3(ii) | Appoints 21.11.2025 for provisions of the Code. The official copy (labour.gov.in `/sites/default/files/e-noti-wage.pdf`) **could not be retrieved from this environment (HTTP 403)**. Its existence and number are known from the official site's search index and from secondary reporting. **Which sections it commenced is not verified here.** Secondary sources say only certain provisions of the Wages Code were brought into force, with the rest to follow with the rules. |

### Section 20 (deductions for absence from duty)
- **Wording as reproduced by secondary sources.** The official India Code
  text (indiacode.nic.in, *as on 21 November 2025*) returned HTTP 403 here,
  so it is **not verified against the primary copy**.
- **The rule:** a deduction may be made only for absence from the place of
  work required by the terms of employment, for the whole or part of the
  period of required work. **It shall not exceed the proportion of wages
  corresponding to the period of absence in relation to the total period
  within the wage-period during which the employee was required to work.**
- **The collective-absence proviso** (10 or more persons acting in concert,
  up to 8 days' wages in lieu of notice) is subject to rules by the
  appropriate Government.

### What this does NOT settle (open questions for qualified review)
1. **Commencement.** Is section 20 (and section 18) in force for this
   School's establishment, or does a predecessor law or rule continue
   (General Clauses Act s.6)?
2. **Appropriate government.** Which State's or Centre's rules apply to
   each School, and are any rules under the Code notified for it?
3. **Service rules.** The School's service and leave rules, or an
   applicable grant-in-aid or board condition. Do they make unpaid leave or
   unauthorised absence non-payable, and on what basis?
4. **Wage basis.** Which components a deduction is proportional to, and how
   section 2(y)'s definition and the 50% rule interact with it.
5. **Denominator.** What counts as "the total period during which the
   employee was required to work" in a month: working days, working
   half-days under the staff calendar, calendar days, or something else.
   It must never be a fixed 30/31 by assumption.
6. **Half-days.** Whether a half-day of required work is a valid absence
   unit for proportionality. HRX keeps exact half-day units precisely so
   that nothing is rounded before this is answered.
7. **Part-month.** The interaction with joining or separation inside the
   period (coverage) and with the existing manual part-month override.
8. **Unpaid leave versus absence.** Whether approved unpaid leave and
   unauthorised absence are treated alike.
9. **The 50% cap** and its order relative to other deductions.
10. **State law.** Any applicable State shops-and-establishments or
    education-service wage rules (none hard-coded; HRX-L3 is a separate
    gate).

## 3. Proportionality in the mechanism (no policy yet)
- **Exact units.** HRX evidence is exact integer half-day units, with the
  required working half-days of the covered period beside it, so a future
  validated policy can compute `period absent / required working period`
  without premature rounding.
- **No policy is coded.** Any future policy must be explicit, versioned
  and independently testable. It must name:
  - jurisdiction / appropriate government;
  - establishment and employee applicability;
  - effective dates;
  - wage basis;
  - denominator;
  - rounding;
  - its legal source and version.
  It must also refuse `input_incomplete` evidence.

## 4. EPFO ECR — NCP days

### Sources
Accessed 2026-10-04.

| Source | What it establishes / status |
|---|---|
| EPFO, *Revamped ECR* page (epfindia.gov.in `/site_en/revamped_ecr.php`, now 301 → epfo.gov.in) | **Primary not retrievable here** (HTTP 403 from EPFO's CDN on both domains). |
| EPFO circular on the revamped ECR (dated 26.09.2025 per secondary reporting; effective from wage month September 2025); PIB release on extending the filing date to 22.10.2025 | Per secondary reporting: return and payment separated, system validations, revision of ECR under conditions, and **no change in the ECR text-file format**. Primary circular **not verified here**. |
| EPFO, *Introduction — Electronic Challan cum Return Version II* (epfindia.gov.in `/site_docs/PDFs/EPFOUnifiedPortal/Introduction_ECR2.0.pdf`) and the *ECR file format (for employers)* PDFs | **Primary not retrievable here (HTTP 403).** The official site's search-index excerpts of these documents state the NCP Days field is numeric with **no decimals**; **"Half day NCP is not permitted and must be in full days"**; range 0 to 31; where wages are 0 because the member earned none, NCP equals the days in the month. |

### Conclusion (HRX.5)
- Current EPFO material, as far as it could be seen, accepts **whole NCP
  days only**. Nothing establishes how a half-day of absence, or a
  half-day of unpaid leave, converts to NCP days (drop, carry, or count),
  or whether attendance absence and unpaid leave are both "non-contributory"
  at all.
- **Therefore no conversion is implemented, and none is guessed.** The
  export's NCP stays at its legacy default `0`. The NCP adapter is
  **blocked pending current-rule validation** by a reviewer who can
  retrieve the primary EPFO material (the file-structure PDF, the revamped
  ECR circular and its FAQs).
- **Guarded:** no expression in Payroll, Leave or StaffAttendance rounds
  half-day units into days.

## 5. Status vocabulary
- **NOT REVIEWED:** nothing assessed.
- **UNDER REVIEW:** a qualified reviewer is assessing it.
- **MECHANISM IMPLEMENTED / LEGAL ACTIVATION BLOCKED:** evidence
  integration exists; no monetary or statutory effect. *(current)*
- **QUALIFIED:** a qualified reviewer recorded a decision for a named
  jurisdiction, population and effective date, with its source.

There is no "legally cleared" status without a qualified decision.

## 6. What activation would require
For each jurisdiction and population:
- a qualified decision on §2's questions 1–10 and on §4;
- a versioned payroll policy (§3) and a versioned EPFO NCP conversion
  policy, each behind an explicit activation, with tests;
- an amendment of ADR 0065 §26 and this record.

Until then HRX evidence is shown and audited only.
