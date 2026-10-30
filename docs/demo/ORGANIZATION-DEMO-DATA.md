# Organization demo dataset (ORG.12E-D1)

The canonical, deterministic **local DDEV** reference world for the
Organization Control Plane (ORG.12B–ORG.12E). Later ORG.12 checkpoints extend
it rather than replacing it.

- **Built by** `ddev demo-reset` (`database/seeders/Demo/DemoOrganizationData.php`,
  called from `DemoDataBuilder`).
- **Verified by** `DemoOrganizationVerifier` at the end of every reset. The
  reset fails loudly if the topology is wrong.
- **Where it runs:** local DDEV only (`DemoEnvironmentGuard`: `APP_ENV=local`
  inside DDEV against DDEV's own database, or the automated suite against
  the verified test database). It is refused in production and staging.
- **Data:** everything is fictional and synthetic, and every address is
  `@example.test`. The demo password is the documented local-only one
  (`docs/development/DDEV-DEMO-REVIEW.md`).

The two original demo Schools, **Lycenza Demo School** and **Lycenza Demo
Annexe School** (with the **Lycenza Demo Trust** Group), are unchanged
compatibility fixtures. They carry the deep operational data (Students,
Finance, HR, …) that the other module walkthroughs use.

## 1. Topology

| | Count | Notes |
|---|---|---|
| Networks (School Groups) | 1 canonical (**Northstar Education Network**) + the Lycenza Demo Trust | the Trust: 2 classified members, no structure |
| Regions | 2 | Telangana (`TELANGANA`), Karnataka (`KARNATAKA`) |
| Zones | 3 | Hyderabad Central (`HYD-CENTRAL`) and Hyderabad West (`HYD-WEST`) in Telangana; Bengaluru North (`BLR-NORTH`) in Karnataka |
| Network Schools | 6 | see §2 |
| Standalone Schools | 2 | Greenfield Public School (1 Campus), Horizon Academy (3 Campuses) |
| Campuses (Organization world) | 11 | plus Main Campus and Annexe Campus of the compatibility Schools |

## 2. Schools, relationships and placement

| School (code) | Relationship | Region / Zone | Network ref | Campuses (services) | Modules |
|---|---|---|---|---|---|
| Northstar International School — Gachibowli (`NIS-GCB`) | **Owned** | Telangana / Hyderabad West | NSN-HYD-001 | Gachibowli Main Campus (day school, meals, transport); Gachibowli Early Years Campus (early years, meals) | all |
| Northstar Early Learning — Jubilee Hills (`NEL-JBH`) | **Franchise** | Telangana / Hyderabad Central | NSN-HYD-F01 | Jubilee Hills Campus (early years, meals, **transport**) | **core only** |
| Northstar Academy — Secunderabad (`NAC-SEC`) | **Managed** | Telangana / Hyderabad Central | NSN-HYD-M01 | Secunderabad Campus (day school, boarding, meals) | all |
| Northstar Partner School — Hebbal (`NPS-HBL`) | **Affiliate** | Karnataka / Bengaluru North | NSN-BLR-A01 | Hebbal Campus (day school, transport) | all |
| Northstar School — Warangal (`NSW-WGL`) | Owned | **Telangana, no Zone** (Region-only) | NSN-TS-002 | Warangal Campus (day school) | all |
| Northstar Heritage School — Mysuru (`NHS-MYS`) | **Unclassified (legacy)** | unplaced | — | Mysuru Campus (none) | all |
| Greenfield Public School (`GPS`) | — (no Group) | — | — | Greenfield Campus (day school) | **core only** |
| Horizon Academy (`HZA`) | — (no Group) | — | — | Central (day school, meals, transport), North (day school, boarding), Early Years (early years, meals) | all |

**Attention cases:**
- Mysuru is unclassified and unplaced. It appears under "Needs governance
  attention" and only to whole-Network viewers.
- **Service is not entitlement**, shown both ways:
  - Jubilee Hills' Campus claims Transport while its Transport module is
    *not enabled* (Setup Center: "Not enabled").
  - Gachibowli has Transport enabled, but its Early Years Campus does not
    offer it.

**How the data is written:**
- **Through the real services** (audited on the platform ledger): the
  Network, its Regions and Zones, and each placement, as the demo platform
  root.
- **Direct, database-validated writes** (the existing demo builder pattern):
  membership classification and Network grants. The seeder never
  fabricates a fresh-MFA proof. CHECKs, composite keys and
  `trg_group_role_assignments_network` still apply.
- **The legacy unclassified membership** is written exactly as ORG.12D
  memberships were before classification existed.

## 3. ORG.12B — Setup & Readiness Center states

| State | Where | Why (canonical data, no flag) |
|---|---|---|
| Strongly configured | **Lycenza Demo School** (school.admin) | full operational dataset: core 8/8 configured |
| Partially configured | **Horizon Academy**, Academic year | a *draft* academic year exists, not yet active (SIS.EXP-1 moved this from Gachibowli) |
| Blocked | **Horizon Academy**, Grades/sections (and Fees) | waits on an active academic year |
| Not enabled | **Greenfield** and **Jubilee Hills** (core modules only) | optional modules are not entitled |
| Needs attention | **Northstar Gachibowli**, Student records (SIS.EXP-2) | Arjun Mehta completed 2025-26 and has no 2026-27 placement while still marked active: a real leaver the School has not yet marked inactive (that needs a fresh code, which the seeder never fabricates). Resolve it by marking him inactive with a reason |

## 4. ORG.12C — profiles

- **Rich School profile:** Gachibowli and Horizon. Display name, legal name,
  fictional registration and affiliation references (`DEMO-REG-…`,
  `DEMO-AFF-…`), institutional email, phone, website, structured address,
  timezone. There are no tax or statutory identifiers.
- **Rich Campus profile:** structured address, institutional phone and email,
  fictional coordinates (five decimals), and services. Every code (day school,
  early years, boarding, meals, transport) appears at least once.

## 5. Personas (password: the documented local demo password)

| Persona | Email | Context | Role / scope | Can | Cannot |
|---|---|---|---|---|---|
| Platform Super Admin | platform.admin@example.test | platform | root (existing) | appoint Network Managers and Viewers, add Schools to Groups | open School pages |
| Network Manager | network.manager@example.test | Northstar | `network_manager`, whole Group | Regions, Zones, classify (MFA), place, remove (MFA), grant/revoke read-only viewers (MFA) | add a School, appoint a manager, enter a School, read Students/Finance (`schoolCapabilities() = []`) |
| Network Viewer (whole) | network.viewer@example.test | Northstar | `network_viewer`, whole Group | all 6 Schools incl. Mysuru (unclassified) | any governance write, School entry |
| Network Viewer (Region) | network.region@example.test | Northstar | `network_viewer`, Region Telangana | Gachibowli, Jubilee Hills, Secunderabad, Warangal | Karnataka, unplaced Mysuru, writes |
| Network Viewer (Zone) | network.zone@example.test | Northstar | `network_viewer`, Zone Hyderabad Central | Jubilee Hills, Secunderabad | Hyderabad West, Bengaluru North, Region-only Warangal, writes |
| Shared Network + School | network.shared@example.test | Northstar + Gachibowli | `network_viewer` Karnataka + `principal` at Gachibowli | Hebbal in the Network view; Gachibowli as Principal | any other School (no membership); Network writes |
| School Admin (Gachibowli) | gachibowli.admin@example.test | Northstar Gachibowli | `school_admin`, whole School | Setup Center, profiles, Campuses, Access Center, effective access | other Schools |
| Accountant | gachibowli.accounts@example.test | Northstar Gachibowli | `accountant`, whole School | Finance School-wide ("Accountant — Whole School") | other Schools |
| Cashier (Campus) | gachibowli.cashier@example.test | Northstar Gachibowli | `cashier`, Campus: Gachibowli Main Campus | Campus finance pages for Main Campus | the Early Years Campus; whole-School Finance pages (e.g. fee runs) |
| Principal (Campus) — SIS.EXP-1 | gachibowli.ey.principal@example.test | Northstar Gachibowli | `principal`, Campus: Gachibowli Early Years Campus | the Student directory and Campus overview of Early Years Students | the whole-School Student profile; Main Campus Students |
| Former librarian | gachibowli.former@example.test | Northstar Gachibowli | `librarian`, account **disabled** | — (cannot sign in) | everything; shown as "Account disabled" in the Access Center |
| School Admin (Greenfield) | greenfield.admin@example.test | Greenfield (standalone) | `school_admin` | Setup Center with "Not enabled" areas; effective access showing "Module not enabled" | any Group page |
| School Admin (Horizon) | horizon.admin@example.test | Horizon (standalone, 3 Campuses) | `school_admin` | multi-Campus profiles without a Group | any Group page |
| Principal | principal@example.test | Lycenza Demo School | `principal` (existing) | Access Center catalogue (read-only) | assignments/effective access (needs `school.roles.view`) |
| Multi-school user | multi.school@example.test | both compatibility Schools | Principal + School Admin (existing) | effective access computed per School | — |
| Platform Manager | platform.manager@example.test | platform | existing | Registry, onboarding | any School page |

**Network Manager actions that need a code:** classification, removal and
viewer grants require a fresh MFA code. Enroll a factor at Account security
first. Recovery codes work too. MFA is never disabled for the demo.

## 6. Sensitive data (ORG.12E)

The Access Center → **Sensitive data** tab shows the eight real families:
- Guardian contact details;
- employee personal contact details;
- employee home address;
- employee emergency contacts;
- date of birth;
- statutory identifiers;
- highly sensitive HR documents;
- visitor phone number.

Each shows its classification and gating capability, with "School
restriction: Not available yet" and the reason. **There is no policy switch,
no policy table and no seeded denial.** The deny-only policy is deferred
(ADR 0080 §10.8) for three reasons:
1. no family has one central server projection;
2. `CapabilityResolver` keeps no per-role provenance;
3. statutory identifiers are legally gated.

## 6a. Presentation profiles (ORG.12F)

| Example | Where | What it proves |
|---|---|---|
| Network brand | **Northstar Education Network**: label "Northstar Education", accent `#1D4E89` | Network Managers own Network brand values (reason and fresh MFA to change) |
| Inherited brand | **Gachibowli** explicitly chose Northstar | inheritance is a choice; the accent stripe and "Northstar Education" chip show, while the School name stays its own |
| School override | **Jubilee Hills** (a *franchise*) uses its own brand: "Jubilee Little Stars", `#7A3E9D` | franchise status never forces the Network brand |
| System default | **Greenfield** | no presentation rows; the shell looks exactly as before |
| Custom staff navigation | **Gachibowli**: Data health hidden; Students relabelled "Learners"; Setup & readiness moved to the top of School setup | order, hide and label |
| Hidden is not denied | gachibowli.admin | Data health is absent from the menu, but `/app/data-health` opens |
| Visible is not granted | gachibowli.cashier | no "Learners" item, and `/app/students` returns 403 |
| Disabled module | **Jubilee Hills** has a "Story books" preference for Library but no Library module (jubilee.admin) | the item stays absent; the route stays denied |
| School-specific | **Annexe** relabels Students "Pupils"; multi.school@example.test sees "Pupils" only in the Annexe and "Students" in the Demo School | no bleed between Schools |
| Guardian portal | **Demo School**: School calendar first, Messages second, Conversations relabelled "Talk to the School" (guardian01) | Guardian presentation; ownership unchanged |

**New persona:** `jubilee.admin@example.test` (School Admin, Northstar
Jubilee Hills).

**Deferred:** logo, banner and icon assets; there is no governed media
pipeline yet.

## 6b. Integration & device readiness (ORG.12G)

All rows are fictional. Nothing is ever contacted: no delivery job runs, the
endpoint host is a reserved `.example.test` name, and the API credentials
are hashes of random bytes discarded at once (no usable credential exists).

| Example | Where | What it shows |
|---|---|---|
| Healthy | **Gachibowli** webhooks: an active endpoint with a delivery 2 hours ago | recent success, read from the School's own records |
| Configured | **Gachibowli** partner API client with a current credential (not used yet) | set up, no recent use |
| Needs attention | **Horizon** partner API client whose credential expires in 12 days | rotate before expiry |
| Not configured | **Horizon** webhooks | the module is enabled but nothing is registered |
| Not applicable | **Greenfield**, **Jubilee Hills** (core modules only) | Integrations not enabled: never evaluated |
| Not available | everywhere: SMS, payment gateway, biometric, attendance RFID, transport RFID, VTS (GPS), AI assistance | the recorded decision (ADR 0057, HRX-L1/L2, module non-scope) |
| Email | every School | platform-operated; DDEV delivers to Mailpit. The Demo School is Healthy (its invitations), the others Configured |
| No access for Network | network.manager / network.viewer | a Network role never opens a School's Hub |
| No secret | any page | no URL, endpoint or client name, scope, key, hash or SMTP detail |

## 6c. Organization MIS (ORG.12H)

No new records: every report derives from the canonical world above.

| Report | Persona | Expected (verified on every reset) |
|---|---|---|
| Network distribution, whole | network.viewer | Telangana 4 (Hyderabad Central 2, Hyderabad West 1, Region only 1), Karnataka 1 (Bengaluru North 1), Not placed 1 |
| Network distribution, Region | network.region | Telangana only; no Not placed row |
| Network distribution, Zone | network.zone | Hyderabad Central 2 only |
| School Control Plane | gachibowli.admin / greenfield.admin / school.admin | gaps in optional areas / optional modules not enabled / core complete |

The owner path is `docs/demo/ORGANIZATION-OWNER-VERIFICATION.md`.

## 7. Feature mapping

| Checkpoint | Demo records |
|---|---|
| ORG.12B | Lycenza Demo School (configured), Horizon (partial/blocked; Gachibowli until SIS.EXP-1), Greenfield and Jubilee Hills (Not enabled) |
| SIS.EXP-1 | Gachibowli: active 2026-27, 13 Students on both Campuses, the Menon siblings, Aarav's Student 360, the Early Years Principal (`SIS-EXP-1-WALKTHROUGH.md`) |
| SIS.EXP-2 | Gachibowli: 2025-26 promotion, repeat and completed exit; a Campus transfer then Section change (22 movements) |
| SIS.EXP-3 | Gachibowli: Aarav three active + one archived record document, Diya one; one Lycenza Demo School Student document (cross-School negative); fictional PDFs only |
| SIS.EXP-4 | Gachibowli: Meera Iyer (transfer withdrawal) with LC-000001 (void, replaced), LC-000002 and TC-000001 (generic demo template); Arjun Mehta ineligible (exit not recorded) |
| SIS.EXP-5 | none seeded: fictional import fixtures in `docs/demo/fixtures/` (4 valid Students on both Campuses with a shared Guardian; an invalid file with 4 errors); reset removes walkthrough imports |
| ORG.12C | Gachibowli and Horizon profiles; 11 Campuses with addresses, coordinates and all five services |
| ORG.12D | Northstar: 2 Regions, 3 Zones, owned/franchise/managed/affiliate, Region-only Warangal, unclassified Mysuru |
| ORG.12E | Network Manager and Viewers (whole, Region, Zone), shared identity; Access Center with whole-School Accountant, Campus Cashier, disabled former member, Greenfield "module not enabled" |
| ORG.12F | Northstar brand; Gachibowli inherits; Jubilee Hills own brand; Greenfield default; Gachibowli navigation profile; Jubilee Hills disabled-module preference; Annexe label; Demo School Guardian portal order |
| ORG.12H | Network report for whole / Region / Zone viewers; Organization report for Gachibowli (gaps), Greenfield (not enabled), Demo School (configured) |
| ORG.12G | Gachibowli webhooks Healthy + API client Configured; Horizon webhooks Not configured + expiring credential (Needs attention); Greenfield/Jubilee Not applicable; 7 Not available families |

## 8. Reset guarantees (proven at ORG.12E-D1)

- **Deterministic:** two consecutive resets produce a byte-identical logical
  topology snapshot.
- **Idempotent:** no duplicates; counts are identical.
- **Restorative:** after changing a placement, a Campus service and a role
  assignment, one reset restores the canonical values (same snapshot hash).
