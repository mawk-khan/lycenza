# docs/product

Reserved for product requirements, user research, and feature specs as
business modules are designed in later phases. Empty in Phase 0A —
this checkpoint is architecture-only (see `docs/roadmap/MASTER-ROADMAP.md`).

*(2026-10-14, SWC.0 drift correction: the "Empty in Phase 0A" framing is
historical. Business modules are implemented. Their specifications live in
`docs/modules/`, and the seven post-foundation programmes are recorded in
`docs/roadmap/MASTER-ROADMAP.md` "Post-foundation product programmes". The
staff web-surface programme is SWC, `docs/architecture/adr/0073-staff-web-surface-completion-contract.md`.
This directory still holds no separate product-requirements documents.)*

*(2026-10-15, V11.0: `V11-COMMERCIAL-READINESS-AUDIT.md` is the evidence
record for the post-v1 V11 programme, contract
`docs/architecture/adr/0075-v11-commercial-readiness-programme-contract.md`.)*

*(2026-10-15, V11.7: `V11-CLOSURE-AUDIT.md` is the V11 closure record:
decision register D-01–D-13, stage gates and the UAT matrix.)*

*(2026-10-15, Local UAT: `LOCAL-UAT-2026-10-15.md` is the structured local
UAT evidence record (167 checks, defect register UAT-*, correction sequence
UAT-C1–C5). It records and classifies; it fixes nothing.)*

*(2026-10-16, readiness reassessment:
`LOCAL-UAT-READINESS-REASSESSMENT-2026-10-16.md` re-tests published `main`
after UAT-C1 to UAT-C4: score 89.5, local UAT ready with limitations, one
C4 regression. It records and classifies; it fixes nothing.)*

*(2026-10-16, UAT-C5: `SCHOOL-ADMINISTRATOR-GUIDE.md` is the
operator-facing School Administrator Guide. It covers setup, staff
onboarding, finance corrections, fee-run overlap decisions, rollover and
Data Health, plus the gated areas and known limitations. Written against
`main` after UAT-C6 and browser-checked on the local demo.)*

*(2026-10-16, SIS benchmark delta: `V11-SIS-BENCHMARK-DELTA-AUDIT.md`
classifies the completed external SIS benchmark's business observations
against `main` `d0fe3590`. It holds the full gap matrix and the "Completed
SIS benchmark delta" table, which has four P2 findings and four decisions.
It contains no benchmark material. It records and classifies; it builds
nothing.)*

*(2026-10-17, local completion: `LOCAL-PRODUCT-COMPLETION-AUDIT-2026-10-17.md`
is the local-product completion audit of `main` `f805aef8`. It follows the
owner's direction to develop on local DDEV only, with staging/PPR paused.
Contents:
- a score of 83/100 and a per-domain scorecard;
- five must-complete items (M1–M5), with six 500s confirmed live;
- should-complete and deferral registers;
- the local-completion slice series LC-1 to LC-10.

It records and classifies; it builds nothing.)*

*(2026-10-21, ORG.7H: `CAMPUS-INTEGRATED-UAT.md` is the integrated Campus
authorization UAT plan — personas, fixture, positive and mandatory negative
cases across every Campus-scoped area (ADR 0076 §53). It is a plan, not
evidence.)*

*(2026-10-23, ORG.9A: `REFERENCE-PARITY-MATRIX.md` is the reference-ERP
parity baseline (ADR 0076 §66): the owner's menu workbook mapped to Lycenza
business functions, with `reference-parity/functions.csv`,
`PARITY-V1-BACKLOG.md`, `PARITY-POST-V1-BACKLOG.md`, `PARITY-DO-NOT-BUILD.md`
and `REFERENCE-SCREENSHOT-REGISTER.md`. It contains no reference wording,
layout or screenshot. It records and classifies; it builds nothing.)*

*(2026-10-23, ORG.9A-R1: `REFERENCE-SCREENSHOT-REGISTER.md` now records
the owner's screenshot evidence (ADR 0076 §67). It contains 87 screen
families, with `reference-parity/screen-families.csv`, `screen-filters.csv`,
`screen-columns.csv` and `screen-contradictions.csv`, in Lycenza wording
only. No screenshot, reference layout or personal data is committed.)*

*(2026-10-26, SIS 1.1: `SIS-CURRENT-STATE-AUDIT-2026-10-26.md` is the first
checkpoint of the Student Information System programme, a current-state
audit of `main` `6d1b5640`. It reconciles 49 historical SIS checkpoints (41
complete) and classifies every SIS capability. Score: SIS Local Completion
82/100. It lists 4 Must-Complete items, 9 Should-Complete groups and 13
decision- or legal-blocked items, and sets out the numbered roadmap SIS
1.1–10.1. It records and classifies; it builds nothing.)*

*(2026-10-27, SIS 9.1: `SIS-UX-ACCESSIBILITY-CLOSURE-2026-10-27.md` is the
SIS UX / accessibility findings register: 28 findings reconciled, 13 open
closed, the 390 px and keyboard evidence, and the decisions still open.)*
