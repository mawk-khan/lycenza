# PAY-L1 — ESI Disability Wage Ceiling: Review Request

**Status at drafting (2026-10-07): DRAFT REQUEST — NOT SENT, NOT ANSWERED.**
This is the request the product owner sends to the approving authorities. It
records no determination, and nothing in it may be read as one.
- The answer, when received, is recorded verbatim in a separate dated
  document (`docs/security/PAY-L1-ESI-DISABILITY-THRESHOLD-DETERMINATION.md`)
  and in ADR 0058 register row **E45**.
- Until then:
  - ADR 0036 §9 stands: the ESI disability special threshold is `DEFERRED —
    ADDITIONAL LEGAL CLARIFICATION REQUIRED`;
  - the golden fixture ESI-12 stays a recorded skip;
  - no disability fact is collected anywhere.

- **To:**
  - the payroll statutory-compliance adviser (labour law / ESI) — questions
    Q1–Q7;
  - the Lead Privacy Counsel & Data Protection Officer — India Operations —
    questions Q8–Q9.
- **From:** the product owner, Lycenza School OS
- **Subject:** The ESI wage ceiling for employees with a disability, and the
  collection of the disability-status fact it requires
- **Register item:** PAY-L1 (ADR 0058 row E45)
- **Engineering contract:**
  - ADR 0036 (Payroll statutory deductions), decision §9 and "Explicitly
    deferred";
  - `docs/modules/PAYROLL.md` (9.6B golden fixtures, 9.6J payslip
    disclosure).

---

## Request text

Dear colleagues,

Lycenza School OS calculates Employees' State Insurance (ESI) contributions
for School employees. The general rule is implemented and tested:
- coverage while monthly wages do not exceed ₹21,000, determined at the start
  of each contribution period (1 April – 30 September, 1 October – 31 March);
- an employee covered at the start of a period who later crosses the ceiling
  continues to contribute until the period ends;
- employee contribution 0.75%, employer contribution 3.25%.

One branch was deliberately **not** implemented (ADR 0036 §9): the **higher
wage ceiling for employees with a disability**, commonly reported as ₹25,000
per month. Today the system treats every employee under the general ceiling.
It holds no disability information, and every payslip states that disability
provisions were not evaluated. We would rather leave this gap visible than
encode a statutory rule we have not had confirmed.

Before we build it, please confirm the points below. **Secondary sources we
found (not relied upon):**
- general summaries stating a ₹25,000 ceiling for persons with disabilities
  as defined in the Rights of Persons with Disabilities Act, 2016;
- reports of proposed changes to the general ceiling in 2026.

We need answers grounded in the primary instruments.

### Statutory questions (Q1–Q7)
1. **Basis and amount.** What ceiling applies today to an employee with a
   disability, under which primary instrument (notification or rule, its
   date and effective date)? Is the **general ₹21,000 ceiling** still the
   current rule, or has a later notification changed it?
2. **Who qualifies.** Which definition governs:
   - a "person with disability" under the RPwD Act 2016;
   - a "person with benchmark disability" (40% or more);
   - or an ESIC-specific definition?

   Does the employee have to be registered with, or declared to, ESIC as
   such?
3. **Evidence.** What must the employer hold before applying the higher
   ceiling? For example: a disability certificate, a UDID card, or a
   self-declaration. Must the employer verify it? What does it do with a
   certificate that has an expiry date?
4. **Timing at the contribution-period boundary.** Coverage is decided at the
   start of each contribution period.
   - Is disability status also judged at the period start?
   - If status is certified **mid-period**, does the higher ceiling apply
     from the next period, from the certification date, or retroactively
     (with arrears of contributions)?
5. **Continuation.** Does an employee covered under the higher ceiling who
   later exceeds ₹25,000 within the period continue to contribute until the
   period ends, as under the general rule?
6. **Loss of status.** If a certificate lapses or status ends, from which
   period does the general ceiling apply again?
7. **Rates and schemes.** Are the 0.75% / 3.25% rates unchanged for these
   employees? Does any scheme alter the employer's share for employees with
   disabilities (for example, the government bearing it for a period)? Must
   the payroll system reflect it, or does it settle outside payroll?

### Privacy questions (Q8–Q9)
8. **Collecting the fact.** Disability status is sensitive personal data.
   We propose to store only the following, and never the nature or
   percentage of the disability:
   - "eligible for the ESI disability ceiling", with effective from and to
     dates;
   - an evidence reference (document type, issuing authority, number,
     expiry).

   Please confirm or correct each of:
   - the lawful basis and notice;
   - minimisation;
   - who may record and see it (a dedicated payroll-statutory capability,
     audited, with fresh MFA to record);
   - retention (with the payroll record, or shorter);
   - whether a payslip may say that the higher ceiling was applied.
9. **Production.** Once implemented to your conditions, may it be used with
   real employee data? Are there production conditions?

We will record your answers verbatim, implement only what they permit, and
leave the gap disclosed until then.

Kind regards,
Product owner, Lycenza School OS

---

## Appendix A — implementation contract (pending the answers; nothing built)
Built only after the determination, and only as it allows. Each element
names the question it waits on.

**Data**
- **An Employee-level, dated eligibility fact** — for example
  `employee_esi_disability_eligibilities` (Q2, Q3, Q8):
  - School, Employee, `effective_from`, nullable `effective_to`;
  - evidence reference fields only;
  - no condition type or percentage;
  - forced RLS, append-only history (ended, never edited), retention per
    Q8.

**Authority**
- A dedicated `payroll.statutory.disability.*` capability (Q8).
- Recording needs fresh MFA and an IDs-only audit. No other module reads the
  fact.

**Rule data**
- `statutory_rule_versions` gains the disability ceiling as a versioned
  value with its effective date (Q1).
- `StatutoryRuleStatusController` then reports it, instead of "DEFERRED".

**Calculation**
- `EsiCoverageDeterminationService` decides coverage at the period start
  with the ceiling that applies to the employee then (Q4).
- Mid-period, continuation and lapse behaviour follow Q4–Q6 exactly.
- Rates follow Q7.

**Golden fixtures**
- ESI-12 becomes a set (for example ESI-12a…f), each with the expected
  value the determination implies.
- The skip is removed only then.

**Payslip**
- `esiDisabilityProvisionsEvaluated` becomes true only where the fact was
  evaluated. Disclosure wording follows Q8.

**Documents**
- ADR 0036 amendment, PAYROLL.md, DATA-CLASSIFICATION.md, ADR 0058 E45.

**Out of scope**
- Any other disability-related benefit.
- Statutory return filing.
- Any automated inference of disability.
