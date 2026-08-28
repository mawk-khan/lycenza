# School OS — Data Classification

> **This document establishes an architectural classification model to
> drive engineering controls (access, storage, logging, AI exposure). It
> is written by engineering, not legal counsel, and makes no claim about
> compliance with any specific law or regulation.** Anywhere this
> document says a legal/compliance decision is required, that means
> exactly that — the product must not represent itself as compliant with
> India's Digital Personal Data Protection Act (DPDP Act, 2023), any
> education-sector-specific regulation, or any other law until a
> qualified legal review has actually happened. Flagged explicitly below
> as **[LEGAL REVIEW REQUIRED]**.

## Classification tiers

| Tier | Definition | Handling baseline |
|---|---|---|
| **Public** | Safe for anyone to see, including unauthenticated visitors (e.g. a school's public admissions-inquiry page copy). | No special controls. |
| **Internal** | Not secret, but not meant for the public — internal operational data (e.g. a non-sensitive configuration value, a public holiday calendar). | Requires authentication; no special encryption/retention beyond normal system data. |
| **Confidential** | Business-sensitive; disclosure would harm the school or the product, but is not personal data (e.g. internal financial summaries, vendor contract terms). | Requires authentication + capability check; excluded from routine debug logging. |
| **Sensitive** | Personal data of an identifiable individual, not in the highest-risk categories below (e.g. a guardian's phone number, an employee's address). | Requires authentication + capability check, tenant-scoped by construction (`docs/architecture/TENANCY.md`); excluded from logs and error messages by default; access is audited (ADR 0017). |
| **Highly Sensitive** | Data whose exposure carries the highest harm potential: **children's personal data**, health records, government identifiers, authentication secrets, and financial account details. | Everything in Sensitive, plus: minimized default visibility (fetched only when a specific capability needs it, not included in broad list/summary views by default), and — for the categories below — explicit additional controls. |

## Data categories mapped to tiers

| Category | Tier | Notes |
|---|---|---|
| **Student data (general)** — name, class/section, enrollment status | Sensitive | Elevated to Highly Sensitive in combination with health, government-ID, or biometric data. |
| **Children's data specifically** | Highly Sensitive | Students are, for most of this product's user base, minors. **[LEGAL REVIEW REQUIRED]**: India's DPDP Act, 2023 imposes specific obligations around processing children's data (including consent-related requirements) that must be reviewed by qualified counsel before any module processing student data ships to real schools — this document does not determine how those obligations are met, only that the architecture must support whatever controls legal review requires (e.g. verifiable parental consent flows, data minimization, purpose limitation). |
| **Guardian data** | Sensitive | Contact details, relationship to student. |
| **Visitor data** (name, optional phone, visit purpose, Campus, host Employee, check-in/check-out timestamps, optional gate-pass identifier) | Sensitive | Ordinary personal data of a non-Student/non-Employee individual, the same tier as Guardian contact data — **conditional on excluding** government-ID numbers/scans, biometrics/facial recognition, and retained photographs, each of which would independently elevate this to Highly Sensitive (government identifiers and biometrics also separately trigger the `[LEGAL REVIEW REQUIRED]` gates below). Visit `purpose` is free text and must never be treated as a Safety/incident record — see `docs/modules/VISITOR.md` §18/§24; a Safety/incident-management module, if built, is a separate future classification decision, not covered by this row. |
| **Hostel residency data** (Hostel/Room/Bed structural identifiers, a Student's Bed assignment and its start/end dates) | Sensitive | Ordinary personal data linking a Student to a physical residence within a School, the same tier as ordinary Student personal data — **conditional on excluding** health/medical data, government-ID numbers, and biometric data, each of which would independently elevate this to Highly Sensitive (government identifiers and biometrics also separately trigger the `[LEGAL REVIEW REQUIRED]` gates below). No Fees/billing/payment-status data is collected by this module — see `docs/modules/HOSTEL.md` §16/§21; a future Fees/Finance integration would introduce its own Highly Sensitive financial data, not covered by this row. |
| **Inventory data** (Item catalogue, stock levels, Location directory, operational StockMovement history) | Confidential | Business/operational data, not personal data — every read requires a specific capability (`inventory.directory.view`/`inventory.stock.view`), not merely an authenticated session, which is what distinguishes this from the `Internal` tier above. **Conditional on excluding** costing/valuation/Finance data and Employee/Student custody, neither of which this module collects — see `docs/modules/INVENTORY.md` §4/§12/§14; a future costing or custody extension would introduce its own Highly-Sensitive/Sensitive data respectively, not covered by this row. |
| **Canteen catalogue/recipe/outlet data** (Outlet directory, menu Item catalogue, recipe requirements) | Confidential | Business/operational data, not personal data — every read requires a specific capability (`canteen.directory.view`), not merely an authenticated session, matching the same `Confidential`-vs-`Internal` distinction the Inventory row above draws (this tier's own worked example: "internal financial summaries, vendor contract terms" — operational data). No Student/Guardian personal data appears in this row — see `docs/modules/CANTEEN.md` §4. |
| **Canteen Order / Order line data** (which Student ordered what, when, for how much) | Highly Sensitive | Links an identifiable Student — Highly Sensitive per the "Children's data specifically" row below, Students are minors — to a financial transaction — Highly Sensitive per the "Financial data" row below. The combination of a child's identity and a monetary amount is exactly this tier's own "highest harm potential" criterion, not merely ordinary Student personal data (the "Student data (general)" row above). This is why the Order list/summary endpoint deliberately excludes every money field and the line array — this tier's "minimized default visibility" handling baseline applied literally. See `docs/modules/CANTEEN.md` §4/§14. |
| **CanteenBillingConfiguration** (which two LedgerAccounts Canteen order fulfillment posts a Charge against) | Highly Sensitive | The same tier as the "Financial data" row below (financial account/configuration data), not merely `Confidential` like the catalogue row above — gated by its own `canteen.settings.view`/`.manage` capability pair, deliberately narrower than `canteen.directory.*`/`canteen.orders.*`. See `docs/modules/CANTEEN.md` §4/§9/§15. |
| **Employee (HR) data** | Sensitive | Elevated to Highly Sensitive for government identifiers, bank details, and any health data collected. |
| **Financial data** (fees, invoices, payroll, payment instrument references) | Highly Sensitive | Never store raw payment-card data in School OS's own database — see the financial-correctness rules in `docs/architecture/ARCHITECTURE.md` §10 and PCI-DSS scope considerations, which are **[LEGAL/COMPLIANCE REVIEW REQUIRED]** once a real payment gateway integration (ADR 0018) is designed. |
| **Health data** | Highly Sensitive | Medical conditions, allergies, incident records. **[LEGAL REVIEW REQUIRED]**: may separately qualify as a sensitive personal data category under applicable law; a dedicated review is required before the Health module (`docs/architecture/DOMAIN-MAP.md`) is implemented. |
| **Government/statutory identifiers** (e.g. Aadhaar-linked references, PAN, other ID numbers collected for admissions/HR/compliance) | Highly Sensitive | **[LEGAL REVIEW REQUIRED]**: collection, storage, and masking requirements for government identifiers are subject to specific Indian regulatory requirements (including sector-specific Aadhaar-handling rules) that must be reviewed before any module collects them — do not assume storing a raw identifier is acceptable without that review. |
| **Documents/files** (birth certificates, photos, transfer certificates, medical records, HR documents) | Classification inherited from what the document contains | A birth certificate scan is Highly Sensitive; a public event photo may be Public — the Documents module (ADR 0012) must tag each stored document with its actual classification, not a blanket default. |
| **Authentication secrets** (passwords/hashes, API keys/tokens, the Laravel↔AI Gateway service token) | Highly Sensitive | Never logged, never included in error messages, never returned by any API response (see ADR 0016; `services/ai/app/core/config.py`'s `redact` conventions for the devtools/audit layers that must respect this). |
| **AI prompts/context** | Classification inherited from the data included in the prompt/context | A prompt built from Sensitive or Highly Sensitive ERP data (e.g. a fee-reminder draft referencing a guardian's contact details) is itself Sensitive/Highly Sensitive for logging, audit-storage, and provider-selection purposes — see `docs/ai/AI-SECURITY.md`'s data-exposure controls. This has a direct consequence for provider selection: a provider whose data-handling/retention terms are inadequate for a given classification tier must not be used for prompts at that tier — a **[LEGAL/COMPLIANCE REVIEW REQUIRED]** gate before any real provider integration (ADR 0013) goes live with real school data. |

## Engineering controls this classification drives

- **Access control:** every Sensitive/Highly Sensitive field requires a
  capability check (`docs/security/AUTHORIZATION.md`), not just
  authentication.
- **Logging:** Sensitive and Highly Sensitive values must never appear
  in application logs, error messages, or exception traces — this is a
  code-review checklist item (root `CLAUDE.md`), not just a policy
  statement.
- **AI exposure:** a tool exposed to an AI agent (ADR 0014) must be
  designed to return only what that agent's purpose requires, informed
  by classification — a fee-collection agent's tools should not surface
  Health-tier data even if it's in an adjacent table.
- **Audit:** access to Sensitive/Highly Sensitive data is itself
  auditable (ADR 0017) — not just changes to it.
- **Retention:** retention periods per category are a
  **[LEGAL REVIEW REQUIRED]** decision, not yet made — flagged here so
  it is designed deliberately once the first module handling
  Sensitive/Highly Sensitive data is built, rather than defaulting to
  "keep everything forever" by omission.

## What this document does not do

It does not implement any technical control itself (no encryption
scheme, no field-level access-control code exists yet in Phase 0A) —
it is the classification reference those future controls, and the
capability design in `docs/security/AUTHORIZATION.md`, must be built
against.
