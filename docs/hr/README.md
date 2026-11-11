# HR ERP programme: document index

There is one authoritative record per subject. Start with the HR.0A
contract.

| Subject | Record | Status |
|---|---|---|
| Programme contract (baseline, gaps, logical and temporal model, authorization, reporting, tenancy, audit, performance, UI, migration, proposed decisions) | `HR-0A-ARCHITECTURE-RECONCILIATION.md` | HR.0A, 2026-11-03 |
| WMSCB reference capability matrix and gap matrix (361 capabilities) | `HR-REFERENCE-CAPABILITY-MAP.md` | HR.0A; pack 602 PNG `40ed6a83…1ac3` |
| Current-state audit (repository) | `HR-EXP-0-CURRENT-STATE-AUDIT.md` | HR.EXP-0, re-verified unchanged by HR.0A |
| Domain ownership and cross-ERP map | `HR-DOMAIN-OWNERSHIP-MAP.md` | re-issued by HR.0A |
| Legal, privacy and security gates; security findings | `HR-LEGAL-SECURITY-GATE-REGISTER.md` | re-issued by HR.0A |
| OD-HR-1 Payroll checker visibility analysis; earlier pack-gate record | `HR-EXP-0R-REFERENCE-RECONCILIATION.md` | pack gate superseded by HR.0A; §6–§8 remain authoritative |
| Implementation roadmap HR.1–HR.16 | `HR-EXPANSION-ROADMAP.md` | HR.0A; supersedes the HR.EXP waves |
| Test strategy | `HR-TEST-STRATEGY.md` | HR.0A |
| HR.16 remediation matrix, measurements, browser and axe evidence, readiness determination | `HR-16-READINESS.md` | HR.16, 2026-11-05 |
| HR.R1 final cross-ERP closure contract | `HR-R1-CLOSURE-CONTRACT.md` | reserved |
| HR.R1 owner decision docket, HR.4–8 disposition and evidence | `HR-R1-C12-DECISION-DOCKET.md`, `HR-R1-C12-HR4-8-DISPOSITION.md`, `HR-R1-C12-EVIDENCE-APPENDIX.md` | HR.R1-C12, 2026-11-07; every decision pending |
| HR.R1-C13 School check and model hardening | `HR-R1-C13-HARDENING.md` | HR.R1-C13, 2026-11-07 |
| HR.R1-C14 WO-10 residual hardening and the Platform proposal | `HR-R1-C14-RESIDUAL-HARDENING.md` | HR.R1-C14, 2026-11-07 |
| HR.R1-C15 Payroll execution-time School check (C14-N6) | `HR-R1-C15-PAYROLL-SCHOOL-GUARD.md` | HR.R1-C15, 2026-11-07 |
| HR.R1-C16 D-1 owner decision, §2 reading, fingerprint verification | `HR-R1-C16-D1-DECISION.md` | HR.R1-C16, 2026-11-08 |
| HR.R1-C17 S-6 determination and the WO-6 period-lock contract | `HR-R1-C17-S6-DECISION.md` | HR.R1-C17, 2026-11-08 |
| HR.R1-C18 WO-6: the Staff Attendance month lock | `HR-R1-C18-WO6-MONTH-LOCK.md` | HR.R1-C18, 2026-11-08 |
| HR.R1-C19 C15-N1: the retention batch bound | `HR-R1-C19-RETENTION-BATCH-BOUND.md` | HR.R1-C19, 2026-11-08 |
| HR.R1-C20 WO-6 acceptance evidence | `HR-R1-C20-WO6-ACCEPTANCE.md` | HR.R1-C20, 2026-11-08 |
| HR.R1-C21 D-6 calendar history decided, WO-5 contract | `HR-R1-C21-D6-DECISION.md` | HR.R1-C21, 2026-11-08 |
| HR.R1-C22 WO-5: staff calendar history (effective-dated week, holiday lifecycle, as-of reader) | `HR-R1-C22-WO5-CALENDAR-HISTORY.md` + ADR 0065 §33 + CLAUDE.md rule 149 | HR.R1-C22, 2026-11-09 |
| HR.R1-C23 D-2 Payroll to HR boundary decided (register, WO-3 contract) | `HR-R1-C23-D2-DECISION.md` + ADR 0034 item 20 | HR.R1-C23, 2026-11-11 |
| HR.R1-C24 WO-3: Payroll reads HR through the register (22 moves, register pin, 404 body) | `HR-R1-C24-WO3-READER-MIGRATION.md` + ADR 0034 item 20 + CLAUDE.md rule 150 | HR.R1-C24, 2026-11-11 |
| HR.R1-C25 R1-01: what "exactly one writer" means, decided (definition, exception register, WO-11 to WO-15) | `HR-R1-C25-R1-01-DECISION.md` + ADR 0028 HR.R1-C25 amendment + CLAUDE.md rule 151 | HR.R1-C25, 2026-11-11 |
| Demo plan | `HR-DEMO-PLAN.md` | draft |
| HR.1A workforce foundation (implemented contract) | `docs/modules/HR.md` "Workforce foundation (HR.1A)" + ADR 0028 HR.1A amendment + CLAUDE.md rule 130 | implemented 2026-11-03 |
| HR.2A staff profile foundation (implemented contract) | `docs/modules/HR.md` "Staff profile foundation (HR.2A)" + ADR 0028 and ADR 0029 HR.2A amendments + CLAUDE.md rule 132 | implemented 2026-11-03 |
| HR.3A reporting relationships (implemented contract) | `docs/modules/HR.md` "Reporting relationships (HR.3A)" + ADR 0028 HR.3A amendment + CLAUDE.md rule 131 | implemented 2026-11-03 |

**Module contracts** (the runtime source of truth):
- `docs/modules/HR.md`;
- `docs/modules/HRX-READINESS-AND-CLOSURE.md`;
- `docs/modules/PAYROLL.md`;
- `docs/modules/TEACHING-ASSIGNMENTS.md`.

**ADRs:** 0028, 0029, 0034, 0036, 0063, 0065, 0071 and 0076.
