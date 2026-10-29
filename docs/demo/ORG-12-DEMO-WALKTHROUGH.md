# ORG.12 demo walkthrough (ORG.12B–ORG.12E)

A guided tour of the Organization Control Plane on the canonical demo
dataset (`docs/demo/ORGANIZATION-DEMO-DATA.md`).
- **Before you start:** run `ddev demo-reset`, then open
  <https://lycenza.ddev.site/login>. Every account uses the documented local
  demo password, and the login page lists them under "Organization demo".
- **Automation:** every step below is also exercised automatically against
  DDEV (the ORG.12E-D1 smoke: 25/25 scenarios).

## 1. Setup & Readiness Center (ORG.12B)

1. Sign in as **gachibowli.admin@example.test** → *Setup & readiness*.
   - School profile and Campuses: **Configured**.
   - Academic year: **Partially configured** (a draft year exists, not yet
     active).
   - Grades, sections and subjects, and Fees: **Blocked**. They wait on the
     active year, which is named as the prerequisite and recommended first.
2. Sign in as **greenfield.admin@example.test** → *Setup & readiness*.
   Optional areas (Fees, Transport, Library, …) are listed as **Not
   enabled**. They never count against progress.
3. Sign in as **school.admin@example.test** (Lycenza Demo School): a strongly
   configured School (core areas 8/8).
4. **Needs attention** is not demonstrated: no demo School reaches it
   naturally (see the manifest, §3).

## 2. School profile (ORG.12C)

As **gachibowli.admin** → *School setup → School profile*:
- the display name and the legal name;
- the fictional registration and affiliation references;
- institutional email, phone and website;
- the structured address and timezone.

Identity changes need a reason and a fresh code. Tax and statutory
identifiers are never stored here.

## 3. Campuses (ORG.12C)

1. As **gachibowli.admin** → *Campuses*: two Campuses with different
   services. Main offers day school, meals and transport; Early Years offers
   early years and meals.
2. Open **Gachibowli Main Campus**: structured address, institutional
   contacts and coordinates.
3. **Service is not entitlement:**
   - Jubilee Hills' Campus lists *Transport*, yet its Transport module is not
     enabled;
   - Gachibowli has Transport enabled, but its Early Years Campus does not
     offer it.
4. Horizon Academy (**horizon.admin**) is a standalone multi-Campus School
   with no Group.

## 4. Network Manager (ORG.12D/12E)

As **network.manager@example.test** → *Your School Groups* → **Northstar
Education Network**:
1. **Overview:** 6 member Schools; relationships owned 2, franchise 1,
   managed 1, affiliate 1, unclassified 1. The attention panel names the
   unclassified School.
2. **Regions and Zones:** Telangana (Hyderabad Central, Hyderabad West) and
   Karnataka (Bengaluru North). Create a Zone to see governance work.
3. **Directory:** filter by relationship, Region, Zone or lifecycle, or
   search by name or code.
4. **Classify, Place, Remove** on a School row. Classify and Remove ask for a
   reason and a fresh code (enroll MFA at Account security first). Place
   needs a reason.
5. **Network access:** the Network roles, and the holders of Network roles
   with their scope. Add or revoke a read-only Network Viewer (reason plus
   fresh code).
6. **Refused:** there is no "Enter" link, and `/app/students` and
   `/app/finance` do not open. Adding a School and appointing a manager are
   platform-only.

## 5. Region viewer

As **network.region@example.test**:
- the directory shows Gachibowli, Jubilee Hills, Secunderabad and Warangal
  (the Region-only School);
- Karnataka and the unplaced Mysuru are absent, even when filtering by
  Region;
- there are no management controls, and a write is refused (403).

## 6. Zone viewer

As **network.zone@example.test**:
- only Jubilee Hills and Secunderabad (Hyderabad Central);
- Hyderabad West, Bengaluru North and the Region-only Warangal are absent;
- the structure shows only its own Zone.

## 7. Access Center (ORG.12E)

As **gachibowli.admin** → *Access Center*:
1. **Role templates:** the 17 code-owned roles. Each card shows its modules,
   capability count, assignment scope ("Whole School or one Campus" vs
   "Whole School only"), access-administration marker and sensitivity.
   *Show capabilities* groups them by module, with Read/Manage, Sensitive,
   Approval, Fresh MFA and Campus-scopable markers.
2. **Role matrix:** role × module, each cell Read, Manage or —. Columns are
   modules, never menu entries.
3. **Capabilities:** filter by module, kind (read/manage), *Sensitive only*
   and *Campus-scopable only*.
4. **Users & effective access:**
   - **Imran Qureshi**: "Accountant — Whole School";
   - **Kavya Reddy**: "Cashier — Campus: Gachibowli Main Campus". Her Finance
     capabilities are *Effective — Gachibowli Main Campus* only;
   - **Suresh Babu**: "Account disabled". Every capability shows "Why not:
     Account disabled".
5. As **greenfield.admin**, open your own effective access: optional-module
   capabilities show "Why not: Module not enabled for this School".

## 8. Sensitive data catalogue (ORG.12E)

*Access Center → Sensitive data*: eight families, each with its domain,
classification, fields, gating capability and **"School restriction: Not
available yet"** with the reason.

There is deliberately no switch: this is a security decision (ADR 0080
§10.8), not missing data. The reasons:
1. no family has one central server projection;
2. effective access cannot yet tell which role granted a capability;
3. statutory identifiers are legally gated.

## 8a. Branding and navigation (ORG.12F)

1. **Network brand:** as **network.manager** → Northstar → *Network brand*.
   Change the label or accent with a reason and a fresh code (enroll MFA
   first). Then, as **gachibowli.admin**, the shell's stripe and chip show
   the change. Only Schools that chose Northstar are affected.
2. **Inherited brand:** as **gachibowli.admin** → *School setup →
   Branding & navigation → Branding*.
   - Source: "School Group brand (Northstar Education Network)".
   - The preview updates as you type and saves nothing until you save.
   - Choose "None" to return to the Lycenza default, or set your own
     colour.
3. **School override:** as **jubilee.admin**, the shell shows "Jubilee
   Little Stars" in purple, although Jubilee Hills is a franchise of
   Northstar. Franchise status never forces a brand.
4. **System default:** as **greenfield.admin**, there is no stripe and no
   chip: exactly the default shell.
5. **Staff navigation:** as **gachibowli.admin** → *Staff navigation*.
   - Data health is hidden, Students reads "Learners", and Setup &
     readiness is first in School setup.
   - Use ↑/↓, *Hidden* and the label field, then save or *Reset to
     defaults*.
   - The note says it plainly: navigation preferences do not change
     permissions.
6. **Hidden is not denied:** Data health is not in the menu, but
   `/app/data-health` opens for the School Admin.
7. **Visible is not granted:** as **gachibowli.cashier**, there is no
   "Learners" item, and `/app/students` returns 403.
8. **Disabled module:** as **jubilee.admin**, there is no Library item,
   although a "Story books" preference exists. Library is not enabled for
   this School.
9. **No bleed:** as **multi.school**, Students reads "Pupils" in the Annexe
   and "Students" in the Demo School.
10. **Guardian portal:** as **guardian01** (Demo School), the portal menu
    starts with School calendar, and Conversations reads "Talk to the
    School". Ownership is unchanged: she still sees only her own child.

## 9. Negative checks

| # | Try | Expected |
|---|---|---|
| 1 | network.manager → `/app/students` | refused (no School context or capability) |
| 2 | network.manager → `/app/finance` | refused |
| 3 | network.viewer → create a Region | 403 |
| 4 | network.region → Karnataka | absent from structure and directory |
| 5 | network.zone → Hyderabad West | absent |
| 6 | network.shared → any School other than Gachibowli | no membership; no access |
| 7 | gachibowli.cashier → `/app/finance/fee-runs` (a whole-School page) | 403, while `/app/finance/charges` (Campus) opens |
| 8 | platform.manager → `/app/students`, Access Center, Setup Center | refused |

`ddev demo-reset` restores the canonical world after any experiment.
