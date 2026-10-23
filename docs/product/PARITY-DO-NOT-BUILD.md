# Parity do-not-build register (ORG.9A, 2026-10-23)

Status: **decided by this audit unless the owner reopens one** (each needs a new contract and a review against the rules cited). Generated from `reference-parity/functions.csv` (status P5, decision mostly DO_NOT_BUILD; rows marked OWNER_DECISION_REQUIRED are not-adopted by default pending the owner). Reasons: duplicate, obsolete, reference packaging, conflicts with Lycenza architecture, low value, legal risk, or a better existing Lycenza design.

## Architecture conflicts (never adopt as designed)

- Cross-School writes from a Group or organisation level (copy tests/ECA/catalogues to other branches, organisation planners) — rule 84.
- Menus, user types, user-type preferences and per-user menu toggles as security — rules 98, 100.
- Configurable approval chains and generic pending-request inboxes — rule 102.
- Editing or deleting posted payments, forcing online payments to success, auto-debit mandates, parent bank details — rule 91, ADR 0057.
- Bulk money corrections (bulk refunds, bulk fee unassign) and bulk lifecycle approvals — per-item assurance (ORG.8C, ORG.8E/8G).
- Fee-receivable uploads — bypass fee runs and the ledger.
- Platform billing, invoices, KYC, merchant registration, support tickets, vendor app settings, credit recharge — platform plane / out of scope; entitlements are not billing.
- Username aliases (canonical email, rule 90); sending sign-in details over messaging (one-time email links only).
- Children's social walls, birthday features (date of birth), teacher ratings, marketing campaigns outside the closed email purposes.

## By domain

| Domain | Functions |
|---|---|
| none (not adopted) | 37 |
| fees_finance | 11 |
| organization | 10 |
| communications | 9 |
| lms | 7 |
| sis | 6 |
| admissions | 4 |
| attendance | 4 |
| examinations | 3 |
| hr | 3 |
| portal | 3 |
| curriculum | 2 |
| documents | 2 |
| academics | 1 |
| analytics | 1 |

## Functions

| ID | Domain | Function | Decision | Why |
|---|---|---|---|---|
| LMS-020 | academics | Classroom recordings | DO_NOT_BUILD | Audio/video of staff and children; not adopted. |
| ADM-010 | admissions | Marketing campaigns, field agents and lead scoring | DO_NOT_BUILD | Sales/marketing CRM is outside the School ERP scope. |
| ADM-011 | admissions | External CRM and telephony integrations | DO_NOT_BUILD | Vendor-specific CRM/telephony; integrations stay disabled (ADR 0057, E28). |
| ADM-019 | admissions | Configurable admission form fields | DO_NOT_BUILD | Minimal fixed schema; any new field must be classified individually (s44.27), not via dynamic forms. |
| FIN-047 | admissions | Collect application/registration fees before admission | OWNER_DECISION_REQUIRED | No applicant charge subject (ADR 0067 §29); fees start at conversion. Pre-admission fees need an owner decision. |
| LMS-059 | analytics | Learner time-on-activity | DO_NOT_BUILD | Learner tracking needs Student accounts; not adopted. |
| ATT-009 | attendance | Upload absentee lists | DO_NOT_BUILD | Registers must match the session roster; no import path; mark-all is a separate owner decision (S-05). |
| HR-020 | attendance | Campus-level attendance modification settings | DO_NOT_BUILD | Staff attendance is whole-School; no modification windows. Campus adoption is a separate deferred decision. |
| HR-021 | attendance | Delete monthly staff attendance | DO_NOT_BUILD | Evidence is corrected, never deleted; retention is E21-governed. |
| HR-059 | attendance | Staff temperature records | DO_NOT_BUILD | Health data; structurally excluded (HRX-L1). |
| APT-004 | communications | Click-to-call telephony log | DO_NOT_BUILD | Telephony integration not adopted (E28). |
| COM-040 | communications | Marketing campaign templates for enquiries | DO_NOT_BUILD | Prospect marketing sits outside the closed transactional EmailPurpose catalogue (ADR 0055) |
| COM-043 | communications | Greeting images for automated emails | DO_NOT_BUILD | Birthday/greeting automation relies on DOB; not adopted |
| COM-049 | communications | Log of pre-admission campaigns | DO_NOT_BUILD | Follows the non-adopted enquiry campaigns |
| COM-068 | communications | Report of class wall activity | DO_NOT_BUILD | Follows the not-adopted class wall |
| COM-069 | communications | Class social wall for students | DO_NOT_BUILD | Peer-to-peer child social feed is not adopted; staff-mediated conversations cover messaging |
| COM-087 | communications | Birthday wishes by WhatsApp | DO_NOT_BUILD | Relies on DOB and a deferred provider |
| LRN-003 | communications | Institution achievements showcase | DO_NOT_BUILD | Marketing content; announcements cover publishing. |
| SIS-033 | communications | Principal daily diary | DO_NOT_BUILD | Diary/calendar products not adopted (ADR 0076 s31.8). |
| LMS-103 | curriculum | Group-level content planner | OWNER_DECISION_REQUIRED | Architecture conflict: Group authority cannot author School data; would need a template-copy design. |
| LMS-116 | curriculum | Configurable teaching plan fields | DO_NOT_BUILD | Custom form fields not adopted. |
| DOC-016 | documents | Unowned School-wide document repository | DO_NOT_BUILD | Conflicts with owner-scoped Documents (access decided by owning record); policies go via announcements |
| FIN-089 | documents | Finance supporting documents | DO_NOT_BUILD | Documents UI deferred (OD-SWC-2); label gives no workflow. |
| LIB-016 | examinations | Assess student reading ability | OWNER_DECISION_REQUIRED | Student performance record; would sit with assessment domains and marks isolation, never Library. |
| LMS-081 | examinations | Delete online exam results | DO_NOT_BUILD | Result deletion contradicts correction-not-deletion; do not build. |
| LMS-083 | examinations | Online exam invigilation | DO_NOT_BUILD | Remote proctoring; not adopted. |
| FIN-017 | fees_finance | Configurable approval rules for cash/petty cash | DO_NOT_BUILD | Approval topology is code-owned (rule 102); no configurable approval settings. No deposit object exists. |
| FIN-018 | fees_finance | Voucher print layout design | DO_NOT_BUILD | No vouchers; print layout customisation not adopted. |
| FIN-025 | fees_finance | Delete a recorded payment | DO_NOT_BUILD | Rule 91: posted payments are never deleted; reversal by approved correction only. |
| FIN-029 | fees_finance | Edit details of a posted payment in place | DO_NOT_BUILD | Posted payments, allocations and receipts are immutable (rule 91); corrections are new linked records. |
| FIN-052 | fees_finance | Staff meal fees | DO_NOT_BUILD | Charge subject is a Student only; Canteen orders are Student orders. Staff meals not adopted. |
| FIN-070 | fees_finance | Auto-debit (ECS) mandates and parent bank details | DO_NOT_BUILD | Never accept bank credentials (rule 91); auto-debit needs a gateway (ADR 0057). |
| FIN-072 | fees_finance | Student fee loans/financing | DO_NOT_BUILD | Third-party fee financing out of scope. |
| HST-005 | fees_finance | Student hostel deposit holding | OWNER_DECISION_REQUIRED | Held deposits/wallets are a Finance liability design, never a Hostel ledger (OPF seam carries no money in Hostel). |
| HST-019 | fees_finance | Hostel deposit categories | OWNER_DECISION_REQUIRED | Depends on a deposit/wallet Finance design not adopted. |
| HST-040 | fees_finance | Deposit transactions by category | OWNER_DECISION_REQUIRED | Depends on deposit/wallet Finance design not adopted. |
| TRN-045 | fees_finance | Bulk upload receivable balances | DO_NOT_BUILD | Receivables come only from fee runs/ledger; uploading balances bypasses FEE assessment and the ledger. |
| COM-022 | hr | List of staff birthdays | DO_NOT_BUILD | Date of birth is HR-sensitive; a broad birthday surface has no operational need |
| LIB-036 | hr | Staff professional development log | DO_NOT_BUILD | Staff CPD is not a Library function; no HR CPD adopted. |
| ORG-036 | hr | Ratings and reviews of teachers | DO_NOT_BUILD | Staff ratings are HR-sensitive; not adopted without an owner decision |
| LMS-002 | lms | Assignment grade visibility settings | DO_NOT_BUILD | Submission and grading cancelled 2026-09-05. |
| LMS-003 | lms | Assignment grading scale | DO_NOT_BUILD | No assignment grading (Submission cancelled); exam grade scales are separate. |
| LMS-005 | lms | Third-party online assignments | DO_NOT_BUILD | Vendor content integration; not adopted. |
| LMS-006 | lms | Student online assignment submission | DO_NOT_BUILD | Submission cancelled as product scope; legal concerns never cleared. |
| LMS-008 | lms | Online assignment marks/status reports | DO_NOT_BUILD | Requires cancelled Submission. |
| LMS-032 | lms | Vendor question resources | DO_NOT_BUILD | Third-party content packs; not adopted. |
| SIS-034 | lms | Third-party learning platform link | DO_NOT_BUILD | Vendor-specific integration; integrations deferred (ADR 0057, E28). |
| COM-017 | none (not adopted) | Weekly transaction digest (label only) | DO_NOT_BUILD | Label is ambiguous under Announcements; no workflow inferred from the reference |
| COM-073 | none (not adopted) | Telephony agent setup | DO_NOT_BUILD | Provider call-centre administration |
| COM-103 | none (not adopted) | Prospect opt-in report via vendor | DO_NOT_BUILD | Vendor-specific prospect marketing |
| EXM-005 | none (not adopted) | Copy or assign exam/activity setup to other branches | DO_NOT_BUILD | Cross-School copy writes tenant data from outside the School; Groups never touch tenant data (rule 84). Each School configures its own. |
| EXM-037 | none (not adopted) | OMR sheet generation | DO_NOT_BUILD | Scanner-specific print artefact; reference only. |
| EXM-039 | none (not adopted) | Answer-sheet envelope and rack logistics | DO_NOT_BUILD | Physical exam-cell logistics; reference only. |
| EXM-045 | none (not adopted) | Aptitude (KYA) tests, marks and reports | OWNER_DECISION_REQUIRED | Aptitude profiling of children is reference-only; if adopted it falls under marks/report-card gates. |
| EXM-053 | none (not adopted) | IB transdisciplinary themes | DO_NOT_BUILD | Board-specific framework vocabulary; a generic curriculum tag could cover it later. |
| EXM-060 | none (not adopted) | Higher-ed course and programme model | OWNER_DECISION_REQUIRED | Higher-ed is outside the documented School OS scope; Subjects/Offerings cover school courses. |
| FIN-078 | none (not adopted) | Admissions campaign budget | DO_NOT_BUILD | Label suggests an admissions marketing budget; no Lycenza owner function; would be part of FIN-077 if ever. |
| FIN-086 | none (not adopted) | Reference-only or unclear finance settings | DO_NOT_BUILD | Label only; no matching Lycenza concept identified. |
| FIN-088 | none (not adopted) | Enrichment programme attendance | *R1: removed — now P8 of FIN-057* | *R1: the screens show an event-fee definition, not programme attendance; it is a duplicate of FIN-057 (optional/ad-hoc fee), not a do-not-build item.* |
| HST-030 | none (not adopted) | Hostel committee setup | DO_NOT_BUILD | Committee administration not adopted in any Lycenza domain. |
| HST-031 | none (not adopted) | Hostel committee members | DO_NOT_BUILD | Committee administration not adopted. |
| HST-036 | none (not adopted) | Committee head listing | DO_NOT_BUILD | Committees not adopted. |
| INV-017 | none (not adopted) | Store sales by item group | OWNER_DECISION_REQUIRED | School-store selling not adopted; Canteen sells via Fees but is not a store. A store would need Fees event-charge seam. |
| INV-019 | none (not adopted) | Store sales by item | OWNER_DECISION_REQUIRED | School-store selling not adopted (see group-wise sales). |
| INV-034 | none (not adopted) | Configurable approval chains | DO_NOT_BUILD | Configurable approval topology conflicts with rule 102 (approval topology is code-owned; no generic engine). |
| INV-036 | none (not adopted) | Outsourced staff register | OWNER_DECISION_REQUIRED | Non-employee personal data not in any domain; HR Employee is the only staff record. |
| INV-038 | none (not adopted) | Manpower supply contracts | OWNER_DECISION_REQUIRED | Labour-supply contracting not adopted in any domain. |
| LIB-013 | none (not adopted) | Reading-level bands per grade | DO_NOT_BUILD | Proprietary reading-level scale; reader assessment not adopted in Library or Examinations. |
| LIB-014 | none (not adopted) | Reading-level scale setup | DO_NOT_BUILD | Proprietary scale; not adopted. |
| LIB-015 | none (not adopted) | Reader assessment parameters | OWNER_DECISION_REQUIRED | Student assessment belongs to Examinations, not Library; not adopted. |
| LMS-070 | none (not adopted) | Online virtual classes (third-party) | DO_NOT_BUILD | Video-platform integration and licensing; not adopted. |
| LMS-071 | none (not adopted) | Online class attendance analytics | DO_NOT_BUILD | Depends on online class integration. |
| LMS-072 | none (not adopted) | Platform email IDs for staff/students | DO_NOT_BUILD | Third-party account provisioning; not adopted. |
| LMS-073 | none (not adopted) | Online class access toggle | DO_NOT_BUILD | Depends on online classes. |
| LMS-074 | none (not adopted) | Online meeting credits and licences | DO_NOT_BUILD | Vendor billing; out of scope. |
| ORG-003 | none (not adopted) | Configure vendor staff app menus | DO_NOT_BUILD | Vendor-branded app configuration; not a Lycenza function |
| ORG-016 | none (not adopted) | Third-party web analytics tracking | DO_NOT_BUILD | Third-party tracking on a children's-data product is not adopted |
| ORG-017 | none (not adopted) | Device or hardware scan (label only) | DO_NOT_BUILD | Label is ambiguous; no workflow inferred |
| ORG-025 | none (not adopted) | Mobile app configuration | DO_NOT_BUILD | Vendor app settings; Lycenza mobile client is an unverified skeleton |
| ORG-026 | none (not adopted) | Help and FAQ content | DO_NOT_BUILD | Help content, not product data |
| ORG-028 | none (not adopted) | Default search values preference | DO_NOT_BUILD | UI preference with no business effect |
| ORG-031 | none (not adopted) | Vendor support tickets | DO_NOT_BUILD | Vendor support desk is outside the product |
| SIS-030 | none (not adopted) | Higher-education programme and attendance | DO_NOT_BUILD | K-12 scope; professional-college programmes are not a Lycenza product line. |
| STR-006 | none (not adopted) | Online order fulfilment, delivery and coupons | DO_NOT_BUILD | E-commerce order logistics; not a school operation Lycenza adopts. Hand-over could reuse Inventory issue if a store is built. |
| COM-081 | organization | Buy provider message credit | DO_NOT_BUILD | Provider credit purchase is billing; out of scope |
| COM-092 | organization | Send login details by WhatsApp | DO_NOT_BUILD | Access is granted by one-time email links; credentials never travel over messaging |
| COM-108 | organization | Provider business account setup | DO_NOT_BUILD | Provider account administration is platform operations |
| IDN-006 | organization | Set an alternate login username | DO_NOT_BUILD | Canonical email is the only identifier (rule 90) |
| ORG-001 | organization | Configure staff menu visibility | DO_NOT_BUILD | Navigation follows capabilities and entitlements; menu visibility is never authorization |
| ORG-018 | organization | Vendor subscription invoices | DO_NOT_BUILD | Billing is out of scope; entitlements are availability, never billing (rule 100) |
| ORG-019 | organization | Merchant KYC details | DO_NOT_BUILD | Payment-provider onboarding; gateway deferred (ADR 0057) |
| ORG-023 | organization | Assign modules per user type | DO_NOT_BUILD | Modules are School entitlements; per-person access is capabilities from a fixed role catalogue |
| ORG-024 | organization | Payment merchant registration | DO_NOT_BUILD | Provider-specific merchant onboarding; gateway deferred |
| ORG-033 | organization | Configure user type behaviour | DO_NOT_BUILD | Role catalogue is read-only at runtime (rule 93) |
| ORG-004 | portal | Configure portal menus | DO_NOT_BUILD | Portal surfaces come from portal capabilities, source modules and E46, not menu toggles |
| ORG-008 | portal | Choose profile fields shown to families | DO_NOT_BUILD | Portal data minimization is fixed per surface, not School-configurable |
| SIS-028 | portal | Per-student portal menu disablement | DO_NOT_BUILD | Access is module entitlement + capability; no Student portal (E46); no per-student menu toggles. |
| COM-023 | sis | List of student birthdays | DO_NOT_BUILD | Student DOB stays off broad surfaces (rule 101 SIS minimization) |
| CRT-012 | sis | ID card handover tracking | DO_NOT_BUILD | Low value without CRT-008. |
| SIS-005 | sis | Bulk approval of status change requests | DO_NOT_BUILD | No request objects exist; each status change needs its own reason, fresh MFA and locked current authority (ORG.8E). |
| SIS-006 | sis | Cross-module pending request inbox | DO_NOT_BUILD | No generic approval engine (rule 102); SIS has no request/approve workflows; domain queues stay domain-owned. |
| SIS-012 | sis | Mandatory profile fields before promotion | DO_NOT_BUILD | No custom/required field model; dry run already blocks unsafe items; Data Health covers record gaps. |
| SIS-026 | sis | Store parent bank account details | DO_NOT_BUILD | Bank credentials are never accepted (rule 91); refunds are recorded corrections, not payouts. |

## ORG.9A-R1 (screenshot evidence, 2026-10-23)

- **Count unchanged.** There are still 103 not-adopted (P5) functions: FIN-088 left (it is now a P8 duplicate of FIN-057), and HR-089 joined it.
  - **HR-089** — choosing the employing legal entity per staff member — is DO_NOT_BUILD. The School is the only tenant and the employer of record (rule 84).
- **DOC-016** is the renumbered "Unowned School-wide document repository" row. ORG.9A had published it with a duplicate id.
- **Reference behaviours recorded as design differences (never adopted).** The screenshots show them; the full list is in `reference-parity/screen-contradictions.csv`:
  - concession auto-approval within a per-accountant limit;
  - approvers editing amounts;
  - in-place edits of assessed amounts, payments and receipt dates;
  - deleting, moving and force-succeeding payments;
  - replace-mode concession uploads;
  - approval chains choosing approvers by role name;
  - health reasons in Student leave;
  - network addresses shown to School users;
  - a role chosen on the HR record;
  - per-Campus email senders;
  - edition upsell;
  - default vendor credentials derived from a code and a phone number;
  - bulk export of identity, tax, bank, caste and religion columns.
