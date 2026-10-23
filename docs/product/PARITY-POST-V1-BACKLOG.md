# Parity post-v1 backlog (ORG.9A, 2026-10-23)

Status: **post-v1 candidates, not authorized and not part of the production-readiness path.** Generated from `reference-parity/functions.csv` (status P4). Useful reference functionality that a School does not need to operate Lycenza v1. Each would need its own owner authorization, contract and (where noted) legal clearance; several imply new domains whose module boundaries must be designed, not copied from the reference.

## By domain

| Domain | Functions |
|---|---|
| inventory | 31 |
| communications | 27 |
| transport | 27 |
| fees_finance | 26 |
| hostel | 21 |
| hr | 19 |
| sis | 13 |
| admissions | 12 |
| examinations | 12 |
| lms | 12 |
| attendance | 6 |
| curriculum | 6 |
| documents | 6 |
| library | 6 |
| academics | 2 |
| analytics | 2 |
| timetable | 2 |
| canteen | 1 |
| none (not adopted) | 1 |
| organization | 1 |
| visitor | 1 |

## Larger themes (recommend one contract each)

- **Admissions CRM:** enquiries, leads, follow-up, counsellors, entrance tests, applicant self-service (privacy review; ADMISSIONS §3/§9.4).
- **Procurement:** purchase requests and orders, goods inward, vendors, contracts — a domain-owned approval workflow on the shared foundation if built (rule 102); configurable chains are not adopted.
- **Finance back office:** expenses and payees, petty cash, cash deposits, bank reconciliation, budgets, accounting-software export (E28 for integrations).
- **Academic depth (ADR 0061 deferral):** descriptor scales, learning outcomes, unit planning, hall tickets, room allocation and invigilation; anything touching results stays behind E39–E44.
- **Transport and hostel operations:** attendance and scanners, maintenance and fuel, hostel attendance, gate pass, mess, warden assignment (HOSTEL §16; privacy notes).
- **HR depth:** recruitment, appraisal and classroom observation (after HR sensitivity classification, E47), exit clearance, comp-off, hourly permissions, regularization requests.
- **SIS depth:** bulk Campus transfer (whole-School only, per-Student lock order, transfer blockers, per-item outcome), learner profile, discipline, mentors (needs an ownership contract), PTM and appointments, quotas, gate pass.
- **Communications channels:** SMS, WhatsApp, voice, push (E28; ADR 0057/0075).

## Functions

| ID | Domain | Function | Gate | Size | Note |
|---|---|---|---|---|---|
| EXM-043 | academics | Co-curricular activity enrolment and schedules | none | M | Not built; prefer reusing SubjectOffering/elective enrolment and Timetable over a separate ECA module. |
| TTB-004 | academics | Class teacher designation and list | none | M | ADR 0063 D-02 approved-deferred; a class-teacher link would become ownership authority, so it needs its own contract. |
| ADM-006 | admissions | Enquiry/lead capture and enquiry forms | none | L | No enquiry/lead aggregate in v1 (ADMISSIONS s3); pre-application contact capture deferred for privacy (s9.4). |
| ADM-007 | admissions | Assign enquiries to counsellors | none | M | Depends on ADM-006 lead model; no counsellor ownership concept. |
| ADM-008 | admissions | Enquiry follow-up log and reports | none | M | Depends on ADM-006; follow-up history and overdue lists not modelled. |
| ADM-009 | admissions | Enquiry and referral source lists | none | S | Reference data for ADM-006; not modelled. |
| ADM-012 | admissions | Online self-service application links | none | XL | Public applicant portal/login/OTP deferred (ADMISSIONS s16); needs its own ADR, privacy and host design. |
| ADM-013 | admissions | Bulk upload of enquiries/applications | none | L | Student/Guardian import is an open owner decision (OD-SWC-4/5). |
| ADM-018 | admissions | Application number series | none | S | application_number explicitly deferred (ADMISSIONS s5). |
| ADM-020 | admissions | Grade-wise admission age rules | none | S | No age eligibility check; statutory age rules vary by state/board. |
| ADM-021 | admissions | Per-Campus admission settings | none | S | Applications carry a Campus; no per-Campus intake open/close settings. |
| ADM-022 | admissions | Printable admission forms with branding | none | M | No print templates for applications. |
| ADM-025 | admissions | Pre-admission entrance test | none | L | Not modelled; applicant test scores are a new children's-data category, distinct from StudentMark. |
| ADM-029 | admissions | Duplicate applicant/student detection | none | M | Student has no matching key; Guardian contact sweep needs an ADR 0041 amendment (D-06d). |
| FIN-074 | analytics | Projected fee revenue/strength | none | M | Projection counts people (V11.1 gate) and revenue; post-v1 analytics. |
| FIN-076 | analytics | Revenue impact of concessions, cancellations, inactivation | none | M | Concession account totals are in the journal; no impact analytics. |
| ATT-007 | attendance | Student leave requests and leave types | none | M | D-16/SB-06 deferred; excused status carries no reason by design (Health tier). |
| COM-083 | attendance | WhatsApp absence alert | E28 deferred integrations (SMS/WhatsApp/push/voice); E46 portal | M | In-app/email absence alert is the v1 candidate; WhatsApp after a provider decision |
| HR-004 | attendance | Work-from-home assignment and marking | none | M | No WFH status; would reopen ADR 0065 attendance evidence model. |
| HR-005 | attendance | Staff shifts and sessions | HRX-L2 | L | *R1: now P6 LEGAL_DECISION_REQUIRED — every shift on screen is built on clock-time grace, late and early thresholds (HRX-L2); kept here for traceability.* Shifts/overtime excluded by ADR 0065 v1; needs reopening contract. |
| HR-011 | attendance | Staff attendance regularization requests and approval | none | M | Employee-initiated request + approval absent; would be a rule 102 domain workflow. Owner decides need (§64.25). |
| HR-058 | attendance | Team attendance for managers | none | M | No reporting-line read path; new authority path needs owner decision. |
| CAN-001 | canteen | Meal menu planning | none | S | Canteen items/outlets exist; no dated menu. |
| ADM-024 | communications | Admission email/WhatsApp templates and messages | none | M | Admission notifications deferred (ADMISSIONS s16); no applicant contact stored; WhatsApp/SMS E28. |
| APT-001 | communications | Parent-staff appointments and availability | none | M | Not modelled; parent self-booking would be a Guardian surface (E46). |
| APT-002 | communications | Parent-teacher meeting scheduling and feedback | none | M | Not modelled; announcements/conversations can carry invitations only. |
| APT-003 | communications | Parent call log | none | S | Contact log of families not modelled; in-app conversations exist. |
| COM-001 | communications | Publish and browse School activity photo galleries | E21 retention; E46 portal; image consent | L | No media gallery; child images need consent, retention and portal gates before any build |
| COM-002 | communications | Group photos into per-activity albums | E21 retention; E46 portal | M | Sub-feature of a future gallery; nothing exists today |
| COM-003 | communications | Review gallery items before they are published | E21 retention; E46 portal | S | Would reuse the communications approval pattern; no gallery to approve yet |
| COM-004 | communications | Class or group photographs | E21 retention; image consent | M | No photo storage surface; Documents is owner-scoped and has no gallery owner |
| COM-056 | communications | Send mobile push notifications | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | Push is an enum placeholder without driver; in-app inbox covers web |
| COM-063 | communications | SMS delivery status report | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Follows an SMS driver |
| COM-065 | communications | SMS message templates | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Generic templates could extend once an SMS channel exists |
| COM-066 | communications | Send SMS messages | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | Channel enum only, no driver; V11 channels are in-app and email (ADR 0075) |
| COM-074 | communications | Call a guardian from the screen | E28 deferred integrations (SMS/WhatsApp/push/voice) | L | Voice stays E28 / V2 (ADR 0075) |
| COM-075 | communications | Log of calls made | E28 deferred integrations (SMS/WhatsApp/push/voice); E21 retention | M | Call logs are a listed ORG.9 candidate; depend on a voice provider |
| COM-078 | communications | Schedule voice broadcasts | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Announcement scheduling exists; voice channel does not |
| COM-079 | communications | Send voice broadcast messages | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | No voice channel |
| COM-086 | communications | Ad-hoc WhatsApp broadcast | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | WhatsApp is an enum placeholder without driver |
| COM-088 | communications | Announcements over WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | Would be a new driver behind the existing channel registry |
| COM-093 | communications | Event notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Follows a WhatsApp driver and an events calendar |
| COM-097 | communications | Parent-teacher meeting notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | PTM scheduling is a listed ORG.9 candidate |
| COM-099 | communications | Two-way WhatsApp inbox with parents | E28 deferred integrations (SMS/WhatsApp/push/voice); E46 portal | L | In-app guardian replies exist; inbound WhatsApp needs provider and identity matching |
| COM-107 | communications | Provider-approved WhatsApp templates | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Provider template registration follows a driver |
| COM-111 | communications | WhatsApp message log | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Follows a WhatsApp driver |
| HR-063 | communications | Staff groups | none | M | No staff-group audience; departments/positions exist in HR. |
| HR-080 | communications | Staff wall feed | none | L | Social feed for staff not built; announcements exist. |
| HR-081 | communications | Staff meeting scheduling and minutes | none | M | No meeting record. |
| HR-082 | communications | Staff meetings report | none | S | Depends on staff meetings. |
| COM-005 | curriculum | Per-teacher log of classroom activities | none | M | Curriculum delivery records lessons taught; photo posts per teacher are not built |
| EXM-018 | curriculum | Learning outcome catalogue per subject | none | M | Outcome statements not modelled; syllabus units exist. Outcomes are School-owned, so branch-specific is the default. |
| EXM-052 | curriculum | Unit / lesson planning | none | L | Lesson plans/planners not built (ADR 0076 §31.8); one planner for every board. |
| LMS-110 | curriculum | Lesson plans | none | L | Lesson planning not built. |
| LMS-111 | curriculum | Lesson plan approval | none | M | Would be a registered domain workflow (rule 102). |
| LMS-117 | curriculum | Subject macro (term/year) plan | none | M | Syllabus units are ordered but undated; no macro plan by term. |
| ADM-023 | documents | Applicant document collection | E21 | M | Documents not activated for applicants/students (OD-SWC-2); no required-document concept. |
| DOC-001 | documents | Student document repository | E21 | M | OD-SWC-2: activate Student owner type with a category; never a second repository. |
| DOC-003 | documents | Student contracts, undertakings and concession evidence | E21 | M | Depends on DOC-001; no form/e-signature model. |
| DOC-015 | documents | Student contract document templates | E21 retention | M | Student contracts are a listed ORG.9 candidate; no document generation exists |
| HR-064 | documents | Staff handbook and policies | none | M | No staff document library surface. |
| ORG-014 | documents | Signature images for issued documents | none | S | Only useful once certificates or report cards are issued (ORG.9 candidates) |
| COM-094 | examinations | Exam notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Exam schedules exist; channel deferred |
| EXM-006 | examinations | Clone an exam setup within the School | none | S | Convenience copy; papers are year/Offering-bound so a clone must re-map Offerings. |
| EXM-017 | examinations | Catalogue of skills, activities and attributes | none | S | Pure School configuration; only useful with gated descriptor grading (EXM-016). |
| EXM-034 | examinations | Hall tickets / admit cards | none | M | Not built; could render from papers + Section roster via Documents. Eligibility conditions (attendance, fees) would need E44/policy. |
| EXM-035 | examinations | Exam room allocation | none | M | room_id is deliberately absent from papers; Rooms already exist in Academic Structure. |
| EXM-036 | examinations | Invigilator duty assignment | none | M | Invigilator deliberately absent from papers; would reference Employees (Sensitive). |
| EXM-038 | examinations | Question paper formats / blueprints | none | L | Question banks and online exams are not built (ADR 0076 §31.8). |
| LMS-010 | examinations | Baseline assessment setup | none | M | No baseline assessment family. |
| LMS-033 | examinations | Question bank | none | L | No question bank (ORG.9 candidate list). |
| LMS-040 | examinations | Bloom taxonomy tests | none | L | No taxonomy-tagged tests. |
| LMS-050 | examinations | Holistic progress configuration | none | L | No NEP HPC stages, domains, competencies or holistic scales. |
| LMS-060 | examinations | Objective exam setup and answer keys | none | L | No objective/OMR exam type or keys. |
| ADM-014 | fees_finance | Pre-admission registration fee and collection | E30 | L | Applicant is not a financial subject; a pre-conversion fee needs its own ADR (ADMISSIONS s16); receipts E30. |
| ADM-027 | fees_finance | Admission cancellation refund policy | none | M | Refunds are recorded corrections approved by another person (ORG.8C); no policy table/rules. |
| COM-062 | fees_finance | SMS fee due reminder | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | Email-first fee reminder is the v1 candidate; SMS follows a provider decision |
| COM-077 | fees_finance | Voice fee due reminder | E28 deferred integrations (SMS/WhatsApp/push/voice) | M | Follows a voice provider decision |
| COM-091 | fees_finance | Fee due reminder by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Email fee reminder first |
| CRT-004 | fees_finance | No-dues certificate | none | S | Outstanding fees read exists; certificate depends on CRT-001. |
| FEE-002 | fees_finance | Dues collection follow-up log | none | M | Outstanding list exists; no follow-up/contact log. |
| FEE-003 | fees_finance | Refunds to withdrawn students | none | S | Recorded refunds exist per payment; no list joined to withdrawals. |
| FIN-005 | fees_finance | Expense totals by Campus | none | L | Journal lines have no Campus; Campus expense split would need a ledger dimension (ADR). Campus is never a tenant. |
| FIN-006 | fees_finance | Petty cash float, withdrawals and balances | none | M | No petty-cash object; can be an asset ledger account moved by manual journals today. Approval not adopted. |
| FIN-007 | fees_finance | Cash and cheque deposit to bank with deposit register | none | M | No deposit object; a cash-to-bank move is a manual journal (fresh MFA). Approval step not adopted; topology is code-owned. |
| FIN-008 | fees_finance | Reconcile bank statements against the ledger | none | L | No bank reconciliation (ADR 0076 §31.8). Needs bank statement import design. |
| FIN-010 | fees_finance | Cost-centre dimension on postings | none | M | No cost-centre dimension; journal lines carry no Campus. Would need an ADR before adding a dimension. |
| FIN-011 | fees_finance | Balance sheet and income statement | none | M | No balance sheet; derivable from LedgerBalanceReader by account type. After trial balance. |
| FIN-028 | fees_finance | Student credit/wallet/deposit and prepaid balances | none | M | Overpayment/unapplied credit deliberately deferred (0G.5 full allocation; OPF: no credits or deposits). |
| FIN-040 | fees_finance | Collect fees in advance / advances and liabilities | none | M | No advance credit; payments must fully allocate to assessed charges. |
| FIN-043 | fees_finance | Bulk receipt download | E30 | M | Receipts print one at a time; no PDF/bulk export (export architecture deferred, D-11). |
| FIN-046 | fees_finance | Online payments, payment links and unidentified online receipts | none | L | No gateway; online payments deferred (ADR 0057, E28 conditional disabled). |
| FIN-050 | fees_finance | Fee collection follow-up log for defaulters | none | M | No follow-up log; Outstanding list with overdue filter is the work list. |
| FIN-053 | fees_finance | Bulk upload of fee payments | none | M | No bulk payment import; per-payment MFA and idempotency would need a reviewed batch contract. |
| FIN-056 | fees_finance | Fee reminder notice to families | none | M | No reminder notice; Guardian notifications are decision-gated (D-07/D-08) and portal is E46. |
| FIN-064 | fees_finance | Fee invoices/challans (demand documents) | E30 | M | No invoice/challan document; statement is the demand view. Tax-invoice form depends on E30. |
| FIN-068 | fees_finance | Card payments and card surcharge | none | S | Method catalogue is closed (cash, bank transfer, cheque; rule 91); card needs a contract amendment. |
| FIN-075 | fees_finance | Export accounts to external accounting (Tally) | none | M | No accounting export (E28 deferred integrations; export architecture D-11). |
| FIN-077 | fees_finance | Expense budgets and budget-vs-actual | none | M | No budget concept; ADR 0076 §31.8 lists budgets as not built. Not needed to run existing modules. |
| HR-055 | fees_finance | Fee details of staff wards | E47 staff personas HS data | M | Joins staff identity with children fee data; no relation exists; privacy decision first. |
| HST-003 | hostel | Hostel resident roll call | none | L | Hostel attendance/curfew is explicit non-scope (HOSTEL.md §16). Would need its own design; not student class Attendance. |
| HST-007 | hostel | Warden observations on residents | none | M | Behaviour/disciplinary records are explicit non-scope; sensitive student data needing classification review. |
| HST-008 | hostel | Warden remarks on residents | none | M | Same as observations; disciplinary/remark records out of Hostel scope. |
| HST-011 | hostel | Resident leave request | none | L | Resident leave/out-pass is non-scope; distinct from staff Leave module. |
| HST-016 | hostel | Remarks on hostel roll call | none | S | Depends on hostel attendance, which is non-scope. |
| HST-017 | hostel | Hostel roll-call timings | none | S | Depends on hostel attendance/curfew, non-scope. |
| HST-020 | hostel | Resident leave types | none | S | Resident leave non-scope. |
| HST-021 | hostel | Resident exit gate pass | none | L | Curfew/exit control non-scope; Visitor module models visitors, not resident exits. |
| HST-024 | hostel | Resident packing checklist | none | XS | Static checklist; could be a Communications announcement/attachment. |
| HST-026 | hostel | Warden per hostel | none | S | hostel_warden system role exists; no hostel-to-staff assignment (non-scope). |
| HST-027 | hostel | Daily mess menu | none | M | Meal plans non-scope; Canteen is outlets/orders, not a mess. |
| HST-028 | hostel | Mess meal settings | none | M | Meal plans non-scope. |
| HST-029 | hostel | Mess meal schedule | none | M | Meal plans non-scope. |
| HST-037 | hostel | Mess food item list | none | S | Mess non-scope; stock of food items fits Inventory items. |
| HST-038 | hostel | Gate pass register | none | S | Depends on resident gate pass (non-scope). |
| HST-039 | hostel | Hostel roll-call report | none | S | Depends on hostel attendance (non-scope). |
| HST-042 | hostel | Resident leave report | none | S | Depends on resident leave (non-scope). |
| HST-044 | hostel | Mess menu report | none | S | Mess non-scope. |
| HST-045 | hostel | Mess operations report | none | S | Mess non-scope. |
| HST-046 | hostel | Monthly residency counts | none | S | Residency history has start/end dates; monthly aggregation is a new read model (occupancy analytics non-scope). |
| HST-047 | hostel | Mess schedule report | none | S | Mess non-scope. |
| CRT-007 | hr | Staff service/experience certificates | none | M | HR letter from employment record; not built. |
| DOC-006 | hr | Bulk upload staff photos | E21 retention | M | No profile photo attribute for staff |
| DOC-007 | hr | Manage individual staff photos | E21 retention | S | No profile photo attribute for staff |
| DOC-008 | hr | Coverage of staff photos | none | S | Only meaningful after staff photos exist |
| HR-008 | hr | Training sessions attended report | none | S | Depends on a training-session record that does not exist. |
| HR-022 | hr | Exit clearance and department NOC | none | L | Separation exists; no clearance/NOC checklist. ORG.9 candidate per ADR 0076 §31.8. |
| HR-031 | hr | Compensatory-off credit requests | none | M | Admin ledger adjustments exist; no staff comp-off request/approval flow. |
| HR-032 | hr | Short-duration permission requests | none | M | Hourly permissions not modelled; leave is half-day granular. |
| HR-036 | hr | Leave application windows | none | S | Label only; no blackout or special window concept in Leave. |
| HR-050 | hr | Staff children enrolled report | none | M | No Employee-to-Student relation; linking via Guardian accounts crosses SIS/HR privacy lines. |
| HR-052 | hr | Staff praise, feedback and complaints | none | L | *R1: decision OWNER_DECISION_REQUIRED — complaint records with severity up to a formal memo are conduct/discipline data, a new Highly Sensitive HR category.* Performance feedback not built; needs HR data classification first. |
| HR-053 | hr | Staff profile cards | none | S | Printable cards/ID cards not built. |
| HR-070 | hr | Staff task management | none | L | No staff task feature (ORG.9 candidate list). |
| HR-071 | hr | Staff task reports | none | S | Depends on staff tasks. |
| HRX-001 | hr | Staff professional development log | none | S | HR certifications exist; no CPD log. |
| HST-009 | hr | Hostel staff duty roster | none | M | Warden/staff assignment non-scope; hostel_warden role exists but no duty roster or hostel-staff link. |
| LMS-021 | hr | Teacher classroom observations | E47 staff personas HS data | L | Staff performance evaluation data; classification and sensitive-HR boundary decision first. |
| LMS-023 | hr | Observations report | E47 staff personas HS data | S | Depends on observations. |
| LRN-002 | hr | Staff achievements record | none | S | HR qualifications/certifications exist; achievements not modelled. |
| HR-049 | inventory | Items issued to staff | none | M | Inventory has no issue-to-employee movement. |
| HST-049 | inventory | Hostel purchases by vendor | none | M | Suppliers/procurement/costing deferred (INVENTORY.md §25). |
| INV-001 | inventory | Asset purchase records | none | L | Individually tracked assets and costing deferred (INVENTORY.md §3, §12). |
| INV-002 | inventory | Asset type master | none | S | Asset tracking deferred; would extend Item/Location catalogue. |
| INV-003 | inventory | Fixed asset register and custody | none | XL | Asset + custody deferred (§14: two explicit FK arms, never polymorphic). Depreciation would be a Finance seam. |
| INV-004 | inventory | Map items to categories/assets | none | M | No InventoryCategory or asset entity (deliberate v1 omission). |
| INV-006 | inventory | PO/goods receipt print layouts | none | S | Depends on procurement (deferred). |
| INV-008 | inventory | Items issued to staff | none | M | Issue records no recipient; staff custody deferred (§14). |
| INV-012 | inventory | Item barcode labels | none | S | Barcode/RFID/scanning explicit non-scope. |
| INV-018 | inventory | Vendor master listing | none | S | Suppliers deferred. |
| INV-021 | inventory | Purchase order report | none | M | Procurement deferred. |
| INV-022 | inventory | Staff issue report | none | S | Needs issue recipient/custody (deferred). |
| INV-024 | inventory | Items issued to students | none | M | Student custody deferred; sold items to students would be a store/Fees decision. |
| INV-026 | inventory | Pending PO receipts | none | M | Procurement/goods-receipt workflow deferred. |
| INV-027 | inventory | Raise purchase order | none | L | Procurement deferred; any approval must be domain-owned + ApprovalWorkflowRegistry (rule 102). |
| INV-029 | inventory | Request vendor quotations | none | M | Procurement deferred. |
| INV-033 | inventory | Contract approvals report | none | S | Depends on contracts + approval workflow (not adopted). |
| INV-035 | inventory | Vendor master | none | M | Suppliers deferred (§25). |
| INV-037 | inventory | Vendor maintenance contract proposals | none | M | Contracts not adopted; depends on vendors. |
| INV-039 | inventory | Approve purchases/contracts | none | L | Would be a domain-owned request table on the shared approval foundation (rule 102), after procurement exists. |
| INV-040 | inventory | Purchase requisition | none | M | Procurement request deferred; maker/checker via shared foundation if built. |
| INV-041 | inventory | Vendor rate contracts | none | M | Costing/procurement deferred. |
| INV-042 | inventory | Vendor service contracts | none | M | Contracts not adopted. |
| STR-001 | inventory | Store setup and item catalogue | none | L | No school store (Canteen is not a store). |
| STR-002 | inventory | Item kits (uniform/book sets) | none | M | No school store in Lycenza (ADR 0076 §31.8; Canteen is not a store). Houses are not modelled (D-17). |
| STR-003 | inventory | Uniform/shoe size measurements | none | S | No school store in Lycenza (ADR 0076 §31.8; Canteen is not a store). Measurements would belong to a future store. |
| STR-004 | inventory | Store vendors and purchase orders | none | M | No school store in Lycenza (ADR 0076 §31.8; Canteen is not a store). Bulk ordering from vendors. |
| STR-005 | inventory | Sell store items to Students and track dues | none | M | No item sale to Students; would combine Inventory issue and a Fees source charge seam. |
| STR-007 | inventory | Store sales, profit and projection reports | none | S | No school store in Lycenza (ADR 0076 §31.8; Canteen is not a store).  |
| STR-009 | inventory | Store tax settings | E30 | M | No school store in Lycenza (ADR 0076 §31.8; Canteen is not a store). Tax on sales needs legal treatment (E30 family). |
| STR-010 | inventory | Barcode labels for store items | none | S | No school store in Lycenza (ADR 0076 §31.8; Canteen is not a store).  |
| LIB-001 | library | E-resource library | none | L | No digital resources in Library; LMS content (lms.content.*) is nearest. Licensing/ownership unreviewed. |
| LIB-002 | library | Reserve/hold a book | none | M | Reservations/holds explicit non-scope (LIBRARY.md §14). |
| LIB-003 | library | Copy barcode labels | none | S | Copies have unique codes; label printing/scanning not built. |
| LIB-026 | library | Library supplier master | none | S | Supplier/procurement not adopted (same deferral as Inventory). |
| LIB-033 | library | Library overview dashboard | none | M | No Library dashboard; Analytics today covers curriculum coverage only. |
| LIB-034 | library | Library usage log | none | M | Visit/usage logging not modelled. |
| COM-028 | lms | Approve class diary entries before release | E46 portal | S | No diary entity; approval would reuse an existing pattern only if a diary is built |
| COM-031 | lms | Track diary acknowledgements or submissions | E46 portal | M | No submission/acknowledgement records exist for assignments or diary |
| COM-089 | lms | Assignment notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Follows a WhatsApp driver |
| COM-090 | lms | Diary notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Follows a diary and a WhatsApp driver |
| COM-096 | lms | Online class notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Online classes are not built |
| EXM-054 | lms | Student subject reflections | none | M | Reflections not built (ADR 0076 §31.8); children's free text; any student-facing entry needs E46. |
| EXM-056 | lms | Review submitted projects | none | M | Submissions not built; an LMS review must never implicitly become a grade (EXAMINATIONS.md §2). |
| EXM-057 | lms | Student groups for projects | none | S | Not built; audiences are Section-level today. |
| LMS-001 | lms | Assignment approval before publish | none | M | Teachers publish owned assignments directly; an approval would be a registered workflow (rule 102). |
| LMS-031 | lms | Flash cards | none | M | Interactive content type not built. |
| LMS-056 | lms | Projects and learner reflections | none | M | No project records. |
| LMS-090 | lms | Subject reflection setup | none | M | No reflection templates. |
| ORG-013 | none (not adopted) | Collect feedback from users | none | M | Label only; no survey or feedback capture exists |
| IDN-012 | organization | Analysis of login activity | none | M | Aggregate counts only, never per-person PII; identity-level data |
| CRT-003 | sis | Bulk transfer certificate generation | E21 | M | After CRT-002: per-student eligibility, unique numbers, no silent skips, whole-School only. |
| CRT-006 | sis | Achievement and competition certificates | none | M | Depends on LRN-001 achievements and CRT-001 templates. |
| CRT-010 | sis | Barcodes for students and staff | none | S | No consumer: Library and other desks use searchable pickers. |
| DOC-012 | sis | Bulk upload student photos | E21 retention; children's image consent | M | No student photo attribute; prerequisite for future ID cards |
| DOC-013 | sis | Manage individual student photos | E21 retention; children's image consent | S | No student photo attribute |
| DOC-014 | sis | Coverage of student photos | none | S | Only meaningful after student photos exist |
| EXM-059 | sis | Child-care daily routine and activity log | Health-tier review | M | Not built; meals/sleep/toileting entries can be health data, so the Health-tier review applies first. |
| EXM-061 | sis | Learner profile and interests | none | M | Learner profile listed as not built (ADR 0076 §31.8); E40 applies if it feeds report cards. |
| LRN-001 | sis | Student achievements record | none | M | Not modelled; publication of children's achievements needs a purpose decision. |
| LRN-008 | sis | Mentor/observer assignment to students | none | M | Would create an ownership authority like teaching ownership (ADR 0063); needs a contract. |
| LRN-009 | sis | Student hobbies and talents | none | S | Extra personal data; field must be classified first (D-15 discipline). |
| SIS-008 | sis | Bulk Campus transfer of many students | none | L | v1 no (ADR 0076 s44.25). Needs per-student lock order, transfer blockers, per-item outcomes, whole-School only, no partial silent skips. |
| SIS-021 | sis | Student groups, segments, houses and activities | none | L | Not built; D-17/SB-07 defer until a pilot names one; no generic grouping framework. |
| HR-054 | timetable | Staff substitution/replacement | none | M | No substitute-teacher record. |
| SIS-022 | timetable | Sub-section practical/lab batches | none | L | SB-07: would change Timetable and Attendance addressing (whole Sections today); needs its own design. |
| COM-060 | transport | Transport alerts to riders' guardians | E28 deferred integrations (SMS/WhatsApp/push/voice); E46 portal | M | No route-rider audience resolver; push deferred |
| COM-098 | transport | Transport notices by WhatsApp | E28 deferred integrations (SMS/WhatsApp/push/voice) | S | Follows a transport audience and WhatsApp driver |
| TRN-001 | transport | Vehicle expense report | none | M | Fuel/maintenance/expenses non-scope; costs would be Finance, not Transport. |
| TRN-003 | transport | Consolidated transport overview | none | M | No consolidated view across routes/vehicles/riders. |
| TRN-004 | transport | Daily transport MIS | none | M | Depends on daily trip/boarding data (non-scope). |
| TRN-005 | transport | Day-wise transport report | none | M | Needs daily operations data (trips/boarding) not modelled. |
| TRN-006 | transport | Monthly transport details | none | S | Assignments have dates; monthly aggregation is a new read model. |
| TRN-008 | transport | Special route riders report | none | S | Depends on special routes (not modelled). |
| TRN-009 | transport | Student bus attendance report | privacy review | M | Bus boarding attendance explicit non-scope; student location is Sensitive. |
| TRN-010 | transport | Daily bus attendance report | privacy review | M | As bus attendance; non-scope. |
| TRN-016 | transport | Siblings using transport | none | S | Cross-domain Guardian relationship join; Guardian data needs guardians.view. |
| TRN-018 | transport | Vehicle data collection | none | S | Vehicle stores code/registration/capacity only; compliance documents not modelled. |
| TRN-020 | transport | Vehicle fuel log report | none | M | Fuel logs non-scope. |
| TRN-021 | transport | Vehicle mileage report | none | M | Fuel/mileage non-scope. |
| TRN-023 | transport | Group stops into zones | none | M | No zone entity; stops are ordered per route. |
| TRN-026 | transport | Riders by zone | none | S | Depends on zones. |
| TRN-029 | transport | Temporary/alternate route | none | M | One active assignment per student; different in/out routes deliberately deferred (§9). |
| TRN-032 | transport | Daily in-charge MIS entry | none | M | Daily operations logging not modelled. |
| TRN-033 | transport | Trip/shift scheduling | none | L | Only route-vehicle-driver assignment; trip/session engine deliberately not modelled (§5). |
| TRN-035 | transport | Own transport / parent pickup | none | M | Parent pickup confirmation non-scope; absence of assignment = no school transport. |
| TRN-037 | transport | Boarding scanner | privacy review | L | RFID/QR boarding explicit non-scope. |
| TRN-038 | transport | Mark bus attendance | privacy review | L | Bus boarding attendance explicit non-scope; student location Sensitive. |
| TRN-039 | transport | Monthly bus attendance detail | privacy review | M | Depends on bus attendance. |
| TRN-040 | transport | Monthly bus attendance summary | privacy review | M | Depends on bus attendance. |
| TRN-051 | transport | Vehicle maintenance log | none | M | Maintenance non-scope; any cost goes to Finance, not Transport. |
| TRN-052 | transport | Vehicle maintenance report | none | S | Depends on maintenance log. |
| TRN-053 | transport | Bulk fuel log upload | none | S | Fuel logs non-scope. |
| SIS-029 | visitor | Early exit / gate pass for students | none | M | Authorised-pickup flag exists; no early-dismissal log or exit points. |

## ORG.9A-R1 additions (screenshot evidence, 2026-10-23)

All of these are P4 and not authorized. The evidence is recorded in
`REFERENCE-SCREENSHOT-REGISTER.md` §6.

| Function | Domain | Title | Gate | Size | Note |
|---|---|---|---|---|---|
| ORG-035 | organization | Ratings report over configured family feedback | none | M | R1: was P7; Lycenza has no feedback capture (follows ORG-013) |
| HR-084 | hr | Planned retirement date tracking | legal check on statutory ages | S | owner decision |
| HR-086 | hr | Staff dependants and statutory nominees | PAY-L1, E21 | M | owner decision |
| TRN-056 | transport | Staff as transport riders | none | M | owner decision; its own Employee rider arm, never polymorphic |
| SIS-036 | sis | Bulk import of existing Student records | none | L | owner decision OD-SWC-4 / OD-PPR-20 |
| ACA-003 | academics | Standing home room per Section | none | S | same-Campus composite key |
| INV-043 | inventory | Item low-stock thresholds | none | S | configuration, never a movement |
| FIN-090 | fees_finance | Several collecting legal entities within one School | E30 + ADR | XL | owner decision; never a second tenant layer |
| FIN-091 | fees_finance | Multi-year fee commitment from a starting year | E31 | L | owner decision; one screen only |
