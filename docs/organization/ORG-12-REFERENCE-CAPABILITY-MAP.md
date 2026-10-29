# ORG.12 reference capability map — Organisation pack (515 screens)

Status: **authoritative reference map for the ORG.12 Organization expansion**
(ORG.12A, 2026-10-28, ADR 0080 §13; RF-01 reclassified by ORG.12B, ADR 0080
§7; RF-02/RF-03 by ORG.12C, ADR 0080 §8). Re-used unchanged by ORG.12H and by
the two future Organization re-audits (§6).

This map records what the reference ERP's Organisation area **does**, who
owns that function in Lycenza, and whether Lycenza builds it. Nothing here
is itself a capability, role, module, Campus adoption or implementation
decision. The reference product's security design (user types, menu
checkboxes, hierarchy numbers, per-user masking) is **evidence of business
need, never Lycenza's security contract** (ADR 0076 §15, §19; rules 93, 98,
100).

## 1. Provenance and handling

| Item | Value |
|---|---|
| Source | Owner-supplied captures of the owner's own organisation's tenant on the reference ERP, under one system-administrator persona |
| Location | Owner-held, outside the repository (`Screenshots Downloaded/Organisation` in the owner's Lycenza folder) |
| Files | 515 PNG files; 506 byte-distinct (9 byte-identical repeats) |
| Pack fingerprint | SHA-256 of the sorted per-file SHA-256 list: `d11eee52b5f6465a127d9720043af3e598311f7ff6cffba68be9345e41e060d4` |
| Relation to earlier evidence | Supersedes, for the Organisation area only, the six `SF-ORG-*` families (68 screens) of `docs/product/REFERENCE-SCREENSHOT-REGISTER.md` §4. That register and `reference-parity/*.csv` are otherwise unchanged. |
| Method | Every screen inspected visually (contact sheets of the top of each screen; originals where needed); each screen assigned to exactly one family |
| Screen ids | `O001`–`O515`, in sorted file-name order. The id → file index stays with the pack, outside Git. |
| Committed | Normalized, Lycenza-worded findings only |
| Never committed | Screenshots, reference layouts, distinctive reference titles, the id → file index, any school, branch, person, contact, identifier, URL or amount |

The screens show a live organisation. Indexing recorded **structure only**:
purpose, filters, columns, actions and states. Three observations are
security-relevant and are recorded without values (§5).

## 2. Classification vocabulary

| Classification | Meaning |
|---|---|
| `EXISTING_EQUIVALENT` | Lycenza already provides the function in an equivalent form |
| `EXISTING_DIFFERENT_ACCEPTED` | Lycenza meets the need differently, deliberately; the difference is accepted |
| `PARTIAL` | Part of the function exists; the rest is named with its owner |
| `ORG12_BUILD` | Built in a named ORG.12 checkpoint (B–H) |
| `DOMAIN_FUTURE` | Belongs to an owning business domain's future programme, not ORG.12 |
| `PLATFORM_FUTURE` | Belongs to the platform plane (Lycenza as vendor), never School tenant data |
| `POST_V1` | Valid need, scheduled after v1 |
| `DO_NOT_BUILD` | Contradicts a frozen rule or ADR |
| `OWNER_DECISION_REQUIRED` | Product owner must decide before any design |
| `LEGAL_DECISION_REQUIRED` | Blocked on a legal/regulatory decision |

## 3. Family map

Columns: reference family · observed function · Lycenza owning domain ·
current Lycenza status · ORG.12 checkpoint · future programme ·
intentional architectural difference · build decision. `RF-nn` ids are
stable; ORG.12A setup definitions cite them
(`OrganizationSetupRegistry::DEFINITIONS['reference']`,
`OrganizationSetupArchitectureTest`).

| RF | Reference family | Screens | Observed function | Lycenza owner | Current Lycenza status | ORG.12 | Future programme | Intentional difference | Decision |
|---|---|---|---|---|---|---|---|---|---|
| RF-01 | Getting Started | 7 | Per-branch setup summary (settings, key staff, fee-plan coverage) and a step checklist with paths to each setting | Organization Control Plane | **Setup & Readiness Center** (ORG.12B, `/app/school-setup/readiness`): 21 domain-owned areas, core vs enabled-module progress, prerequisites, next actions, capability-resolved links (§4.1) | 12A (spine), 12B (built) | — | Readiness is derived from owning domains, never stored; no staff identities, usernames or passwords on a setup page; no step a School ticks by hand | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-02 | Organization Profile | 0 dedicated (inside RF-03) | Legal identity, contact, affiliation, tax ids, social links, maps | Schools (Organization) | **Rich School profile** (ORG.12C): display + optional legal name, code, institutional contacts, structured international address, board, registration and affiliation references, timezone, Finance currency shown | 12C (built) | — | Identity changes need fresh MFA + reason; no tax ids, social links or map URLs; one School profile | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-03 | Branch / Campus | 8 | Branch name/code/type/status, facility flags (day, daycare, boarding, lunch, transport), currency/time zone, contact, social links, maps, behaviour flags | Campuses (Organization) | **Rich Campus profile** (ORG.12C): code, name, status, contacts, structured address, coordinates, code-owned service profile (day school, early years, boarding, meals, transport) | 12C (built) | — | Campus is the only Branch; services are descriptive, never entitlement or authority; currency and time zone stay School/Finance-wide; behaviour flags (e.g. fee-due gating of report cards) stay in their owning domain; no franchise/revenue flags (ORG.12D) | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-04 | Academic Year | 10 | Common and per-branch academic months, per-branch/class start dates, age-cut-off date, change of current year | Academic Structure | School AcademicYear + terms, one active per School (rule 64), draft-date correction (ADR 0077) | 12B (readiness only) | Admissions (age criteria) | One School calendar, not per-branch months; age criteria belong to Admissions eligibility | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-05 | Class / Section | 7 | Grade → section structure per year, sections per branch, master-class mapping | Academic Structure | GradeLevel (explicit `sequence`), Section per AcademicYear × Campus (rules 66, 67), education stage | 12B (readiness only) | — | A Section belongs to exactly one year; history never mutates | `EXISTING_EQUIVALENT` |
| RF-06 | Attendance configuration | 15 | Daily/period structure with sessions and entry deadline, copy previous year, absent/present reason catalogue, fee-type mapping, absent-SMS template | Attendance (+ Communications for SMS) | Period registers against the timetable; closed absence-neutral correction reasons (ORG.8E) | 12B (readiness only) | Attendance (daily-session model); Communications (SMS, E28) | Attendance is never billing — attendance→fee mapping is not built; no free-text or health reasons | `DOMAIN_FUTURE` |
| RF-07 | Fee-plan configuration | 2 | Fee plans per branch/year (normal/transport), copy, print | Fees | Fee heads, fee structures per year/grade/Campus with activation and retirement (ADR 0062) | 12B (readiness only) | — | Structures are versioned and immutable once used; no in-place amount edit | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-08 | Admissions configuration | 6 | Per-branch enquiry/application/admission form templates, direct-admission and pay-at-admission flags, web-enquiry switch | Admissions | Applicants, applications, conversion, optional Admission fee (OPF.3) | 12B (readiness only) | Admissions (public enquiry, print templates — ADM-022 post-v1) | No tenant-built dynamic forms | `PARTIAL` |
| RF-09 | Recruitment | 4 | Applicant grid, public careers link per branch, manual add | HR | Not built | — | HR depth (E47 sensitivity first) | Public intake needs a privacy/retention decision | `POST_V1` |
| RF-10 | User Types | 14 | Tenant-defined user types from a role-name catalogue with module checkboxes, dashboard/PIN/settings flags, per level (org/zone/branch/staff) | Identity & Access | 17 platform-curated School role templates (ADR 0071), assigned per School or Campus; **Access Center role explorer and role × module matrix** (ORG.12E, `/app/settings/access`) | 12E (built: read-only role explorer) | — | No tenant role creation (rule 93); roles carry capabilities, not menus; scope sits on the assignment, never in a cloned role | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-11 | User hierarchy | 4 | Numeric hierarchy level (1–10) and reporting user type per user type | — | HR reporting line exists as ownership (ADR 0076 §62); Network roles (ORG.12E) are scoped grants, not levels | — | — | A role hierarchy number is not authority; reporting lines are HR facts | `DO_NOT_BUILD` |
| RF-12 | Zonal access | 2 | Zone tier above branch, restricting which zonal user types switch access level | Platform (School Groups) | **Group Regions and Zones** (ORG.12D) plus **Region/Zone-scoped read-only Network Viewers** and a whole-Group **Network Manager** (ORG.12E, ADR 0080 §10): governance metadata visibility per Region or exact Zone | 12D (structure), 12E (built: scoped governance visibility) | — | Zonal *governance visibility* exists on the Group layer; a zonal *School business authority* (zone-scoped Student/Staff/Finance access) is still never built — a Region or Zone is never a School scope or tenant boundary | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-13 | Branch restriction | 3 | Per-user assigned/restricted branches | Identity & Access | Campus-scoped role assignments (ORG.7A, rule 101), shown per assignment in the Access Center effective-access view (ORG.12E) | 12E (built: scope visualization) | — | Scope sits on the grant, resolved by `CampusScopeResolver` | `EXISTING_EQUIVALENT` |
| RF-14 | Access levels (menu-permission matrix) | 259 | Per user type: module grants and submenu checkboxes per product area, at org/branch/staff level; read-only "menus per user type" report; profile-tab visibility per user type | Identity & Access | Capability catalogue, route authorization contracts (rule 98), entitlement intersection (rule 100) | 12E (built: read-only capability explorer, filters by module, read/manage, sensitive, Campus scope) | — | A ticked menu is a permission there; here a menu is a projection of capabilities and never authority | `DO_NOT_BUILD` |
| RF-15 | Menu management | 0 dedicated (inside RF-14) | Menu titles, descriptions, order sequence, "refresh menus" publish step | Navigation (presentation) | **School presentation profile** (ORG.12F, `/app/school-setup/presentation`): order within code-owned groups, optional hiding, plain-text labels over the one `StaffNavigation` catalogue | 12F (built) | — | Presentation only; applied after security; never stored as authorization (ADR 0076 §15, ADR 0080 §11); no icons or tenant groups | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-16 | Parent menus | 6 | Parent web/app menus: rename, order, per-audience toggles, per-branch availability, translations | Guardian Portal (presentation) | **Guardian portal presentation** (ORG.12F): order, hide (not required notices), labels for the portal navigation; ownership unchanged; production-refused until POR-L1 | 12F (built) | — | Presentation only; per-branch or per-family menu toggles are never access (PARITY-DO-NOT-BUILD ORG-004); "Guardian", never a parallel Parent identity | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-17 | Student menus | 1 | Student app menus, same structure as parent menus | Portal | No Student accounts | — | POR (Student portal) | Student accounts are gated by POR-L1 (E46) | `LEGAL_DECISION_REQUIRED` |
| RF-18 | Mobile menus | 16 | Staff/parent/student mobile menu matrices per user type (incl. approval menus), app name/logo/store links per branch | Mobile client | No mobile client in production (`apps/mobile` skeleton) | — | Mobile programme | No mobile menu projection before a real client (ADR 0076 §21); approvals are capabilities + maker/checker, never menus | `POST_V1` |
| RF-19 | Banners | 2 | Mobile-app banners per branch with image limits | Portal / Navigation presentation | Not built: needs a governed media pipeline (ORG.12F deferral, ADR 0080 §11.8) | — | Media pipeline | Presentation only; no tenant HTML or script; never a Communication | `POST_V1` |
| RF-20 | Branding | 1 | Branch logo upload | Schools (Organization) | **School and Network brand** (ORG.12F): label + accent with derived readable text, explicit Network inheritance; logo upload deferred (no media pipeline) | 12F (built, logo deferred) | Media pipeline | School-level branding; Network brand only by explicit choice; never on outbound email From domain; never changes School identity | `PARTIAL` |
| RF-21 | Sensitive-field visibility | 11 | Per-user and per-user-type masking matrices for Student/staff fields (mobile, email, address, fee amount, national id, tax id, passport, visa, registry ids); field-group edit rights; parent-app profile field exposure | Privacy / Sensitive Field Projection | Highly Sensitive reads behind capabilities + current MFA (rule 95); fixed portal exposure (ADR 0070); **code-owned sensitive-data catalogue and explorer** (ORG.12E: 8 real families, each with its gating capability and why it is not yet School-configurable) | 12E (built: catalogue/explorer; deny-only policy deferred, ADR 0080 §10.8) | Privacy | A School may only ever NARROW access (deny-only, OD-12.15), and only once every exposure path enforces it on the server; UI-only hiding is never the mechanism; new national identifiers need a legal basis | `PARTIAL` |
| RF-22 | Tax / legal configuration | 2 | Branch tax ids; HR statutory codes and basic-pay % | Schools (identity); Payroll (statutory) | Not built; statutory payroll legally gated (ADR 0034/0036) | 12C (reference fields after decision) | Payroll statutory | Statutory values never configured by a School without a legal decision | `LEGAL_DECISION_REQUIRED` |
| RF-23 | Email | 1 | Per-purpose notification recipient addresses per branch | Platform email layer; Communications | Durable email layer, fixed sender domain, no School SMTP (ADR 0055/0060); **Hub shows platform delivery state + this School's own delivery health** (ORG.12G) | 12G (built: readiness) | Communications | No School mail credentials; no per-branch sender | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-24 | SMS | 2 | Branch SMS gateway credentials, sender id, regulatory sender registration | Communications | Channel placeholder only (E28; ADR 0057 §5 deferred); **Hub: Not available** with that decision (ORG.12G) | 12G (built: Not available) | Communications channels (E28) | Credentials never in Organization; provider registration is platform/legal | `DOMAIN_FUTURE` |
| RF-25 | Payment gateway | 2 | Per-branch bank, gateway and fee-account activation | Payments | Manual/offline recording only; gateway deferred (ADR 0057 §2); **Hub: Not available** (ORG.12G) | 12G (built: Not available) | Payments gateway | No bank account numbers or gateway secrets on an Organization page | `DOMAIN_FUTURE` |
| RF-26 | Biometric | 1 | Device inventory, renewal dates, last-log staleness | Integrations & Devices (new) | Not built (biometrics behind HRX-L1/L2); **Hub: Not available** (ORG.12G `attendance.biometric`) | 12G (built: Not available) | Integrations & Devices | Readiness overview only; device ingestion is a domain programme with its own privacy review | `DOMAIN_FUTURE` |
| RF-27 | RFID | 2 | RFID and transport RFID logs (not subscribed in the pack) | Integrations & Devices | Explicit non-scope (INV-012, TRN-037); **Hub: Not available** (ORG.12G `attendance.rfid`, `transport.rfid`) | 12G (built: Not available) | Integrations & Devices | Privacy review before any scanning | `POST_V1` |
| RF-28 | VTS | 2 | Vehicle tracking and tracking+RFID logs (not subscribed in the pack) | Integrations & Devices; Transport | Not built; GPS filters are privacy-blocked in the filter register; **Hub: Not available** (ORG.12G `transport.vts`) | 12G (built: Not available) | Integrations & Devices | No live location of people | `POST_V1` |
| RF-29 | Hardware logs | 1 | Raw biometric punch log with notification flag, export | Integrations & Devices; Staff Attendance | Not built; no device or log table exists; **Hub: Not available** under `attendance.biometric` (ORG.12G) | 12G (built: Not available) | Integrations & Devices | Raw device events never become attendance without the owning domain's rules | `DOMAIN_FUTURE` |
| RF-30 | Subscription invoices | 7 | Vendor tax invoices to the school (per-student subscription, device maintenance), due/paid/receipts, billing accounts | Platform Billing / Subscription Management | Not built; entitlements are availability, never billing (rule 100) | — | Platform Billing | Never confused with Student Fees; never School tenant data | `PLATFORM_FUTURE` |
| RF-31 | Support tickets | 6 | Ticket list by month and state, conversation and resolution tabs | Platform Support | Not built | — | Platform Support | Vendor support desk, outside the School tenant | `PLATFORM_FUTURE` |
| RF-32 | Feedback | 10 | Logout satisfaction, public-link feedback, configurable daily feedback types/questions/scale, rating reports | Surveys / Feedback | Not built (ORG-013 post-v1) | — | Surveys / Feedback | Respondent data needs a privacy design; no public link without it | `DOMAIN_FUTURE` |
| RF-33 | Complaints / reviews | 1 | Teacher compliments/reviews log by branch | HR (conduct) | Not built (HR-052 owner decision) | — | HR | Conduct data is sensitive; follows HR-052 | `OWNER_DECISION_REQUIRED` |
| RF-34 | FAQs | 3 | Organisation FAQ list with web/Android/iOS types and rich-text answers | Help / Knowledge Base | Not built | — | Help / Knowledge Base | Product help, not tenant data | `PLATFORM_FUTURE` |
| RF-35 | Organization MIS | 1 | Organisation MIS with month tabs and refresh | Analytics / Organization Reporting | **Organization MIS built (ORG.12H):** School Control Plane report (setup, gaps, integrations, presentation, access) and the scoped Network report (lifecycle, relationships, Region/Zone distribution, attention, directory). Business MIS (student/staff/fee figures by month) not built | 12H (built: structural/setup MIS) | Analytics (business MIS, Network Campus counts and setup readiness need a future cross-School read-model decision, ADR 0080 §13.4) | Network reporting never bypasses School tenancy; person-counting gated (ADR 0040) | `PARTIAL` |
| RF-36 | Module catalogue / per-user-type module grants | 78 | Subscribed vs available modules (licence tier, upsell catalogue), module × user-type tick matrices per level | Platform (module entitlements) | Code-owned `ModuleCatalogue`, platform-governed School entitlements (ORG.5) | 12B (entitlement awareness) | — | Modules are School entitlements, never per-person grants (PARITY-DO-NOT-BUILD ORG-023); no licence tier | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-37 | Admin user accounts | 13 | Org/zone/branch admin user lists, create user with branches, reset password, app PIN, login history with IP, settings change log | Identity & Access | Staff accounts by invitation only, MFA, off-boarding by suspension (ADR 0059, rule 92); audit ledgers | 12E (built: effective-access view per staff member, this School only) | — | No admin-set passwords or PINs, no plaintext credential columns, no vendor user inside a School | `EXISTING_DIFFERENT_ACCEPTED` |
| RF-38 | Per-user approval delegation | 5 | Per-user flags "can approve teaching / non-teaching / transport staff leave" | Leave / approval foundation | Leave approval by capability + current reporting line, maker/checker (ORG.8D, rule 102) | 12E (built: approval and fresh-MFA markers in the role explorer) | — | Approval authority is capability + relationship, never a per-user flag | `EXISTING_DIFFERENT_ACCEPTED` |

**Coverage:** 38 families, **515 screens** (sum of the Screens column), 0
unclassified screens. RF-02 and RF-15 have no dedicated screen in the pack;
their functions are observed inside RF-03 and RF-14 respectively.

## 4. Summary

| Classification | Families | Ids |
|---|---|---|
| `EXISTING_EQUIVALENT` | 2 | RF-05, RF-13 |
| `EXISTING_DIFFERENT_ACCEPTED` | 13 | RF-01, RF-02, RF-03, RF-04, RF-07, RF-10, RF-12, RF-15, RF-16, RF-23, RF-36, RF-37, RF-38 |
| `PARTIAL` | 4 | RF-08, RF-20, RF-21, RF-35 |
| `ORG12_BUILD` | 0 | — |
| `DOMAIN_FUTURE` | 6 | RF-06, RF-24, RF-25, RF-26, RF-29, RF-32 |
| `PLATFORM_FUTURE` | 3 | RF-30, RF-31, RF-34 |
| `POST_V1` | 5 | RF-09, RF-18, RF-19, RF-27, RF-28 |
| `DO_NOT_BUILD` | 2 | RF-11, RF-14 |
| `OWNER_DECISION_REQUIRED` | 1 | RF-33 |
| `LEGAL_DECISION_REQUIRED` | 2 | RF-17, RF-22 |
| **Total** | **38** | |

By screens, 374 of 515 (73 %) are the reference's user-type / menu /
module permission machinery (RF-10, RF-14, RF-16–RF-18, RF-36). Lycenza
meets that need with the capability catalogue, fixed role templates and
entitlements, and does not copy it.

### 4.00 ORG.12H final closure audit (2026-10-29)

All 38 families were re-checked against the pack inventory (515 screens,
fingerprint `d11eee52…`) and the repository as of ORG.12H. One
classification changed: **RF-35 Organization MIS, `DOMAIN_FUTURE` →
`PARTIAL`** (structural and setup MIS built; business MIS stays Analytics).
`ORG12_BUILD` is **0**: no Organization-owned gap remains.

**Every remaining non-complete family has a named owner:**

| Class | Family | Owner / programme | Blocker | Blocks Organization closure? |
|---|---|---|---|---|
| PARTIAL | RF-08 Admissions configuration | Admissions | Public enquiry and print templates (ADM-022, post-v1) | No |
| PARTIAL | RF-20 Branding | Media pipeline (platform) | No governed media pipeline for logo uploads (ADR 0080 §11.8) | No (recorded prerequisite) |
| PARTIAL | RF-21 Sensitive-field visibility | Identity & Access (security architecture) | A central server projection and per-role evaluation are needed before any deny-only policy (ADR 0080 §10.8) | No (recorded prerequisite) |
| PARTIAL | RF-35 Organization MIS | Analytics | Business MIS is person-counting (ADR 0040); Network Campus counts and setup readiness need a cross-School read-model decision (ADR 0080 §13.4) | No |
| DOMAIN_FUTURE | RF-06 Attendance configuration | Attendance (daily-session model); Communications (absence SMS, E28) | Domain programme | No |
| DOMAIN_FUTURE | RF-24 SMS | Communications channels (E28) | ADR 0057 §5, legal and provider review (incl. DLT) | No |
| DOMAIN_FUTURE | RF-25 Payment gateway | Payments gateway | ADR 0057 §2: provider, merchant scope, jurisdiction, PCI | No |
| DOMAIN_FUTURE | RF-26 Biometric | Attendance / HR devices (Integrations & Devices) | HRX-L1/L2 legal gate; privacy review | No |
| DOMAIN_FUTURE | RF-29 Hardware logs | Attendance / HR devices | As RF-26 | No |
| DOMAIN_FUTURE | RF-32 Feedback | Surveys / Feedback | Respondent privacy design (ORG-013) | No |
| PLATFORM_FUTURE | RF-30 Subscription invoices | Platform Billing | Entitlements are never billing (rule 100) | No |
| PLATFORM_FUTURE | RF-31 Support tickets | Platform Support | — | No |
| PLATFORM_FUTURE | RF-34 FAQs | Help / Knowledge Base | — | No |
| POST_V1 | RF-09 Recruitment | HR (recruitment) | Not in v1 scope; staff join by invitation | No |
| POST_V1 | RF-18 Mobile menus | Mobile app (`apps/mobile`) | No mobile surface exists yet | No |
| POST_V1 | RF-19 Banners | Portal presentation + media pipeline | Media prerequisite; never a Communication | No |
| POST_V1 | RF-27 RFID | Attendance / Transport devices | Documented non-scope; privacy review | No |
| POST_V1 | RF-28 VTS | Transport (tracking) | No live location of people without a privacy design | No |
| DO_NOT_BUILD | RF-11 User hierarchy | — | Replaced by Network governance roles plus HR reporting lines; a hierarchy is never authority | — |
| DO_NOT_BUILD | RF-14 Access levels (menu matrix) | — | Replaced by capability + scope + entitlement, with presentation kept separate (rules 98, 100, 115) | — |
| OWNER_DECISION | RF-33 Complaints / reviews | HR (conduct) | HR-052 owner decision | No |
| LEGAL_DECISION | RF-17 Student menus | Student Portal | No Student portal; Student-data legal gate | No |
| LEGAL_DECISION | RF-22 Tax / legal configuration | Schools (identity) after a legal decision | GST/PAN/VAT identifiers stay gated (rule 112) | No |

**ORG.REAUDIT-1** is satisfied for Organization-owned surfaces by ORG.12. A
cross-domain re-audit stays planned for when SIS, HR, Finance, Admissions,
Portal and Integrations mature. ORG.12H acts as **ORG.REAUDIT-FINAL** for
the current product state. A pre-production global parity audit may
follow.

### 4.0 Campus service profile vs the reference facility flags (ORG.12C)

| Reference branch flag | Lycenza `CampusServiceType` | Difference |
|---|---|---|
| Day scholar | `day_school` | — |
| Daycare | `early_years` | covers early years and daycare |
| Boarding | `boarding` | never creates Hostel records |
| Lunch | `meals` | never creates Canteen records or charges |
| Transport | `transport` | never enables the Transport module or routes |
| Revenue / franchise branch | — | governance on the Group membership (ORG.12D relationship: owned, franchise, managed, affiliate); no revenue terms |

Tax/statutory screens (branch tax ids, HR statutory codes) stay **RF-22
`LEGAL_DECISION_REQUIRED`**: ORG.12C's neutral institutional references are
not tax identifiers.

### 4.0a Network and franchise governance vs the reference zonal model (ORG.12D)

| Reference | Lycenza (ORG.12D) |
|---|---|
| Org → Zone → Branch, where a zone is also an access tier for zonal user types | Group (Network) → optional Region → optional Zone → member School. These are descriptive governance placements. Access never follows them. |
| Franchise/revenue flags on a branch | The relationship on the Group membership (owned, franchise, managed, affiliate) is classification only. There are no revenue, royalty or billing terms. |
| Zonal admins see the zone's branches and their data | A Group Admin sees member School identity and the Group's own classification only. School data stays behind School membership; entering a School is a 30-minute, MFA-gated elevation with no School permissions. |
| Structure changes are unaudited settings | Add, remove and reclassify need fresh authority, a closed reason and fresh MFA. Moves need a reason. All are audited on the platform ledger without names. |
| One school, one zone | Many-to-many Groups (ADR 0045 §7). Each membership is classified and placed independently. |

### 4.0b Access administration: why Lycenza's model is different, and stronger (ORG.12E)

| Reference (User Types / Access Levels / field matrices) | Lycenza (ORG.12E) |
|---|---|
| Tenant-created user types with module and menu checkboxes | **Capability-based security** from code-owned role templates; no tenant role or capability creation (rule 93) |
| A role per branch or zone ("North Branch Cashier") | **Scope separated from role**: one Cashier template, assigned for the whole School or one Campus |
| A ticked menu is a permission | **No menu-as-permission**: navigation is a projection; every page checks its capability |
| Branch restriction lists per user | **Explicit Campus scope** on the assignment, explained in the effective-access view |
| Zonal users see zone branches and their data | **Safe Network Region/Zone visibility**: read-only governance metadata for one Region or exact Zone; never School records |
| Organisation admins act inside branches | **No Group-to-School escalation**: Network roles grant no School capability, membership or entry |
| Per-user field masking checkboxes | **Deny-only sensitive-data customization** by design (a School may only narrow), offered only once enforced on every server path — today a code-owned catalogue explains each family and its gate |
| "Why can this user see X?" is unanswerable | **Effective-access explanations**: each capability shows its role and scope, or every layer that stops it |
| Access changes are unaudited settings | **Fresh-MFA access administration**: Network role grants/revocations need a reason and a fresh code; School role grants keep RoleGrantAuthority, MFA and audit |

### 4.0d Integrations and devices: readiness, never configuration (ORG.12G)

The pack shows per-branch provider credentials (SMS gateway user/password/API
key, sender ids, bank and gateway activation), device inventories, raw
punch logs and live tracking. Lycenza's Integration & Device Readiness Hub
(`/app/school-setup/integrations`, ADR 0080 §12) deliberately shows only
**status**, from each owning module, and never a credential:

| Reference | Lycenza (ORG.12G) |
|---|---|
| Branch SMTP / email settings (RF-23) | Platform-operated email; the Hub shows whether delivery is enabled and this School's own recent delivery health |
| SMS gateway credentials, sender id, regulatory (DLT) registration (RF-24) | **Not available**: deferred (ADR 0057 §5, E28). Any provider, including Indian DLT sender registration, needs its own contract and a legal and provider review; no DLT semantics are modelled |
| Payment gateway / bank activation (RF-25) | **Not available**: deferred (ADR 0057 §2); manual/offline recording is the v1 path |
| Biometric devices, renewal, punch logs (RF-26, RF-29) | **Not available**: biometrics behind HRX-L1/L2; no device table, ingestion or log |
| RFID (attendance and transport) (RF-27) | **Not available**: documented non-scope; privacy review first |
| VTS / GPS tracking (RF-28) | **Not available**: documented non-scope; no live location of people |
| (not in the pack) Webhooks, partner API clients | Real, School-managed: status from their own records; never the URL, name, scope or credential |

The classifications are unchanged. The families stay `DOMAIN_FUTURE` /
`POST_V1`: a readiness row is not the integration.

### 4.0c Navigation and branding: why Lycenza's model is different, and stronger (ORG.12F)

| Reference | Lycenza (ORG.12F) |
|---|---|
| Menu checkboxes are the permission | **Permission, scope, module entitlement, navigation presentation and branding are separate layers.** A menu is computed from security; a School preference can only hide, reorder or relabel what security already allows |
| Hiding a menu "removes access" | Hidden is not denied: an authorized person can still open the page |
| Showing a menu "grants access" | Visible is not granted: an unauthorized person never sees it and is refused |
| Franchise branches carry the franchisor's branding automatically | **Network brand inheritance is explicit.** Franchise, owned, managed or affiliate status never forces a brand; a School chooses one Group it belongs to |
| Renaming menus renames the concept | A label alias is presentation only: routes, capabilities, data, reports and APIs keep their canonical names |
| Free-form colours and uploads | Bounded tokens: one accent with a derived readable foreground; no CSS, HTML or script; uploads wait for a governed media pipeline |

### 4.1 Getting Started: why Lycenza's Setup Center is different, and stronger (ORG.12B)

| Reference Getting Started | Lycenza Setup & Readiness Center |
|---|---|
| A fixed step list and a per-branch summary page | 21 setup areas from the code-owned registry, each answered by the module that owns the configuration |
| Steps marked done by the summary's own logic | **Derived readiness:** recomputed from canonical domain state on every visit; nothing stored |
| Same checklist whatever is subscribed | **Entitlement-aware:** a module the School has not enabled is "Not enabled" and never counts against progress |
| No dependency view | **Dependency-aware:** an area waiting on another is "Blocked", names the prerequisite only when the viewer may see it, and the prerequisite is recommended first |
| Links open for anyone who can see the menu | **Capability-aware actions:** Configure / View / no link resolved on the server from the capabilities the destination page checks |
| One progress picture | **Separate core and enabled-module progress** |
| Menu ticks decide who sees what | **No menu-as-security:** the navigation entry is discoverability; every destination re-checks |
| The summary lists staff usernames and default credentials | **Facts only:** no people, contacts, credentials or amounts |
| Branch-wide data on one page | **School isolation:** one School per request, refused for platform and Group operators and for a School that is not active |

**Reclassification note.** PARITY-DO-NOT-BUILD rows ORG-018 (subscription
invoices), ORG-026 (FAQ) and ORG-031 (support tickets) said "outside the
product" for the **School** product. ORG.12A records the same functions as
`PLATFORM_FUTURE` (owner instruction): they are never built as School
tenant features, and any future build belongs to the platform plane.
ORG-001 and ORG-004 (menu visibility as security) stay `DO_NOT_BUILD`;
RF-15/RF-16 `ORG12_BUILD` is presentation only and does not reverse them.

## 5. Security observations (no values recorded)

1. **Menu == permission.** On RF-14 a ticked submenu is the permission,
   including high-impact actions (fee due-date edits, bulk refund, grace
   marks, Campus transfer of a Student, parent bank details) with no
   step-up or maker/checker. Lycenza keeps capabilities, route contracts,
   fresh MFA and maker/checker (rules 95, 98, 102).
2. **Credential-shaped values on screen.** An app-PIN column in user lists,
   SMS gateway username/password/API-key fields, unmasked bank account
   numbers, and a default student/parent password convention on the
   Getting Started summary. Lycenza never shows a credential after its
   one-time issue (rules 46, 90) and ORG.12G readiness shows no credential.
3. **Vendor and franchise users inside the tenant.** Vendor-support and
   franchise roles appear in the school's own user and menu lists. Lycenza
   keeps platform authority separate from School authority (ADR 0076 §5).

Additional functions observed without a separate family: menu translations
(RF-16–RF-18), per-branch menu availability (RF-16), licence edition labels
and a manual "refresh menus" publish step (RF-14/RF-36), field-group edit
rights (RF-21), and per-purpose notification recipients (RF-23).

## 6. Future re-audits

- **ORG.REAUDIT-1:** after SIS, HR, Attendance, Admissions, Finance, Portal
  and Integrations mature — re-classify every family here against the code.
- **ORG.REAUDIT-FINAL:** before final product / staging acceptance — same
  pack, same fingerprint, same ids.

Both reuse this map; a family's classification changes only with the
evidence and decision that changed it.
