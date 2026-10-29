# SIS.EXP-1 — Student 360 & directory: demo data and owner walkthrough

Status: **canonical SIS demonstration** (SIS.EXP-1, 2026-10-29), inside the
ORG.12E-D1 Organization world (`ORGANIZATION-DEMO-DATA.md`). Built by
`database/seeders/Demo/DemoSisData.php` through the real services and
proven after every `ddev demo-reset` by `DemoSisVerifier`. Every name,
date, email (`@example.test`) and phone number is fictional; no
identifier, photo or health data exists.

Password for every account: printed by `ddev demo-reset` (local demo only).

## 1. What the reset builds (Northstar International School — Gachibowli)

| Item | Value |
|---|---|
| Academic year | 2026-27, **active** (1 Jun 2026 – 31 Mar 2027) |
| Main Campus | Grade 5 · A (4), Grade 5 · B (3), Grade 3 · A (3) |
| Early Years Campus | UKG · A (3) |
| Students | 13, numbers from the School counter; each with one Guardian and contacts |
| Subjects | English, Mathematics, Environmental Studies (required) per Grade |
| The Menon family | **Aarav Menon** (Grade 5 · A, Main) with two Guardians, Lakshmi (mother, primary) and Vikram (father); his sister **Diya Menon** (UKG · A, Early Years) shares Lakshmi |
| Aarav's records | a Term 1 Charge of ₹30,000 with ₹12,000 paid in cash; the Kondapur Route (pick-up Kondapur Junction); one library loan; five first-period English registers on the last five school days before the reset (one absent, one late) |
| Other cross-domain data | Diya: an unpaid ₹18,000 Charge on the Early Years Campus; Meera: a ₹30,000 Charge; Kabir: a rider; Ananya: a loan |
| Hostel | the module is enabled; **no residency** is seeded (the card shows an authorized, empty state) |
| New persona | **Principal — Campus: Gachibowli Early Years** (`gachibowli.ey.principal@example.test`), an existing role on an existing Campus-grant pattern |

The ORG.12B "Partially configured / Blocked" Setup states moved to Horizon
Academy (Gachibowli now has an active year); see `ORG-12-DEMO-WALKTHROUGH.md` §1.

Gachibowli's ORG.12F navigation profile labels *Students* as **Learners**:
the menu label changes, never the access.

## 2. Owner walkthrough

| # | Persona | Route | Student | Expected | Must not happen |
|---|---|---|---|---|---|
| 1 | `gachibowli.admin` | *Learners* (`/app/students`) | — | 13 Students; **Campus**, **Grade** and **Section** filters (current class). Early Years → Diya, Neel, Tara. Grade 5 → 7. Grade 5 · B → Ishaan, Sara, Vivaan. Sections narrow to the chosen Campus and Grade | a filter that lists Students of a Campus or Section you cannot read |
| 2 | (same) | open **Aarav Menon** | Aarav | *On this page* navigation; **Family**: Diya Menon (UKG · A · Early Years Campus, shares Lakshmi Menon); **At a glance**: Subjects (3 required), Attendance (latest records, link), Fees (billed ₹30,000, paid ₹12,000, outstanding ₹18,000, fee statement link), Transport (Kondapur Route), Hostel (no current residency), Library (1 book on loan) | an edit control on any summary card; a rate or tally of attendance; family fee totals |
| 3 | (same) | Aarav → **Edit** in two tabs; save tab 2, then tab 1 | Aarav | tab 2 saves; tab 1 says the details were *changed by someone else after you opened this form* and saves nothing; reload and save works | tab 1 silently overwriting tab 2 |
| 4 | (same) | Aarav → **History** | Aarav | one "updated" entry per save naming only the field that changed (e.g. last name) | an entry listing unchanged fields; a value of the change |
| 5 | `network.shared` (Principal of Gachibowli) | Aarav's profile | Aarav | Family, Subjects, Attendance, Transport, Hostel, Library | the **Fees** card (no Finance capability) |
| 6 | `gachibowli.ey.principal` | *Learners* | — | Diya, Neel and Tara only; rows open the Campus overview | Aarav or any Main Campus Student; Aarav's overview by URL (404); Diya's whole-School profile (403) |
| 7 | `gachibowli.cashier` | `/app/students` | — | refused (403): a Finance role holds no Student capability | — |
| 8 | `network.manager`, `platform.admin` | `/app/students` | — | back on the dashboard: no School context, no Student data | any Student page |
| 9 | `teacher` (Lycenza Demo School) | any Student profile | — | refused: the teacher role sees owned classes in *My attendance* only | — |

Afterwards run `ddev demo-reset` (steps 3–4 change Aarav's name).

## 3. Verified on 2026-10-29 (DDEV, real browser)

- **Browser smoke:** 33/33 checks passed (Playwright, Chromium; script in
  the session scratchpad, not committed):
  - filters;
  - every heading and card;
  - one H1;
  - the labelled section navigation;
  - no horizontal overflow on the profile and directory at 1280, 768 and
    390 px;
  - stale-edit refusal, then a successful reload;
  - the Principal without Fees;
  - the Early Years Principal (list, 403);
  - Network and Platform personas redirected to the dashboard.
- **Audit:** the two browser edits recorded `changedFields`
  `["last_name"]`, then `["first_name"]`.
- **Determinism:** three consecutive resets verified. A semantic snapshot
  of every Gachibowli Student (number, names, date of birth, placement,
  Guardians, Charges, rides, loans, attendance count) was identical before
  and after the walkthrough plus a reset.
