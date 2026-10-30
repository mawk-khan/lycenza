# SIS.EXP-1 to 5 — Student 360, directory, movement register, Student documents, certificates and bulk import: demo data and owner walkthrough

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
| Main Campus | Grade 5 · A (5), Grade 5 · B (3), Grade 4 · A (1), Grade 3 · A (4), Grade 3 · B (0) |
| Early Years Campus | UKG · A (3), Grade 3 · E (0) |
| Students | 18 (13 from SIS.EXP-1, 4 from SIS.EXP-2, Meera Iyer from SIS.EXP-4), numbers from the School counter; each with one Guardian and contacts |
| SIS.EXP-2 history | 2025-26 (now closed) Grade 4 · A/B rolled into 2026-27: **Rohan Pillai** promoted to Grade 5 · A, **Anika Joshi** repeats Grade 4 (4 · A), **Arjun Mehta** excluded and his 2025-26 placement completed (an exit; still marked active, so Setup shows *Student records: Needs attention*). **Kiara Sen**: Grade 3 · E on the Early Years Campus (1 Jun 2026), Campus transfer to Grade 3 · B on the Main Campus (6 Jul 2026), then Section change to Grade 3 · A (3 Aug 2026) |
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
| 1 | `gachibowli.admin` | *Learners* (`/app/students`) | — | 18 Students (16 with a current class); **Campus**, **Grade** and **Section** filters (current class). Early Years → Diya, Neel, Tara. Grade 5 → 8. Grade 5 · B → Ishaan, Sara, Vivaan. Sections narrow to the chosen Campus and Grade | a filter that lists Students of a Campus or Section you cannot read |
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

## 4. SIS.EXP-2 — Student movements (owner path)

No withdrawal or "marked inactive" row is seeded: both need a fresh code,
which the demo never fabricates. Doing step 6 creates them live.

| # | Persona | Route | Expected | Must not happen |
|---|---|---|---|---|
| 1 | `gachibowli.admin` | *People* → **Student movements** (`/app/students/movements`) | 24 rows, newest first: 18 entries; Meera Iyer's **Withdrawn** (30 Sep 2026, Transferred to another School); Kiara's Section change (3 Aug 2026, Grade 3 · B → Grade 3 · A, Main) and Campus transfer (6 Jul 2026, Grade 3 · E Early Years → Grade 3 · B Main) as one row each; Rohan's **Promotion** and Anika's **Repeated the Grade** (1 Jun 2026, 2025-26 → 2026-27); Arjun's **Placement completed** (31 Mar 2026) | any edit, approve, export or print control; an actor name; a free-text note |
| 2 | (same) | **Campus** = Early Years | Kiara's Campus transfer (from Early Years), Kiara's first enrollment, Diya's, Neel's and Tara's entries | rows that never touched Early Years |
| 3 | (same) | **Movement type** = Campus transfer; then **Category** = Movement | 1 row; then 4 rows | — |
| 4 | (same) | **Exits only** | Meera Iyer, Withdrawn (Transferred to another School), Grade 5 · B; Arjun Mehta, Placement completed, final placement Grade 4 · A (2025-26) | — |
| 5 | (same) | click **Arjun Mehta** | his Student profile (the profile re-checks authority); History shows his placements | — |
| 6 | (same, MFA enrolled) | Arjun → *Mark inactive…* (reason "Left the School", fresh code) → back to Student movements | a new **Marked inactive** exit row with the reason "Left the School"; Setup's *Student records* returns to Configured | — |
| 7 | `gachibowli.ey.principal` | `/app/students/movements` (no menu entry) | refused (403): movement history is whole-School | Kiara's past on the Early Years Campus |
| 8 | `network.manager`, `platform.admin`, `gachibowli.cashier` | `/app/students/movements` | refused / back to the dashboard | any Student movement |

Run `ddev demo-reset` after step 6.

## 5. SIS.EXP-3 — Student documents (owner path)

**What the reset seeds:**
- Five fictional record documents: synthetic PDFs that each say
  **DEMO / FICTIONAL** and carry no signature or identifier.
  - **Aarav Menon:** Admission form (Enrollment paperwork); Transfer
    certificate from previous school (Previous school record); Field trip
    consent 2026-27 (Consent or permission form); and one archived *Admission
    form (first copy)*, reason "Replaced by a newer document".
  - **Diya Menon:** Letter about Early Years settling-in (Family
    correspondence).
- The cross-School negative: one Lycenza Demo School Student (LDS-0001)
  document.
- `ddev demo-reset` empties the local document keyspaces first, so repeated
  resets never accumulate orphan files.

**MFA:** the files are Highly Sensitive.
- Enroll an authenticator under *Account security*, then sign out and back
  in with the code; the list and downloads need that current MFA.
- Each upload, edit and archive asks for a fresh code (or an unused
  recovery code).

| # | Persona | Route | Expected | Must not happen |
|---|---|---|---|---|
| 1 | `gachibowli.admin` | Aarav's profile → *At a glance* → **Documents** | "3 documents on record, 1 archived", one count per category, **Open documents** | a title or file name on the profile |
| 2 | (same, before MFA) | **Open documents** (`/app/students/{id}/documents`) | the MFA step-up page | the list |
| 3 | (same, MFA enrolled and signed in with a code) | **Open documents** | the 3 active documents with category, document date, upload time, file (PDF · size), *Active* | any delete, share, export or public-link control |
| 4 | (same) | **Download** *Transfer certificate…* | a PDF download (attachment) reading DEMO / FICTIONAL | the file opening inline from a storage URL |
| 5 | (same) | **Status** = Archived | the superseded *Admission form (first copy)*, still downloadable | an Edit or Archive action on it |
| 6 | (same) | **Upload a document**: Family correspondence, a title, a PDF, a fresh code | "Document uploaded."; 4 active | — |
| 7 | (same) | upload an HTML file renamed `.pdf` | "Upload a PDF, JPEG or PNG file…" under the file field | a stored file |
| 8 | (same) | **Archive** the new document, reason *Uploaded in error*, fresh code | it moves to Archived (final) | an un-archive |
| 9 | `gachibowli.ey.principal` (Campus-scoped) | Aarav's documents URL | 403: whole-School authority only | any file |
| 10 | `gachibowli.cashier` | (same) | 403 | — |
| 11 | `network.manager`, `platform.admin` | (same) | back to the dashboard / sign-in | any file |
| 12 | `gachibowli.admin` | the LDS-0001 Student's documents URL | not found (another School) | that School's file |

**Verified on 2026-10-30** (DDEV, real browser, Playwright 1.62.1): 25/25
checks passed. Covered:
- enrollment and step-up;
- the counts card and the step-up refusal;
- list, download (attachment, `nosniff`, the fictional PDF) and the archived
  filter;
- the spoofed-PDF refusal tied to the file input, upload, and archive;
- no page overflow at 1280, 768 and 390;
- keyboard reach to Download;
- the cross-School 404;
- Campus Principal, Cashier, Network Manager and Platform Admin refused for
  both the list and the download.

Run `ddev demo-reset` after steps 6–8.

## 6. SIS.EXP-4 — Leaving and Transfer certificates (owner path)

**What the reset seeds:**
- **Meera Iyer** (Grade 5 · B, Main): joined 1 June 2026 and withdrawn on
  30 September 2026, transferred to another School. That is a recorded
  transfer exit, so both types are eligible.
  - Her first Leaving Certificate, **LC-000001**, carried a misspelt surname
    ("Iyr"). The School corrected the record and reissued it.
  - **LC-000002** replaces LC-000001, which stays on record, voided.
  - **TC-000001** is her Transfer Certificate.
- Every PDF is the generic template with the banner **DEMO / GENERIC
  CERTIFICATE TEMPLATE — NOT A STATUTORY DOCUMENT**.
- **Arjun Mehta** is deliberately ineligible: his placement completed but no
  exit was recorded.
- Issuing in production is refused until the wording is legally approved;
  the demo is local.

**MFA:** as in §5. Every issue, void and reissue asks for a fresh code.

| # | Persona | Route | Expected | Must not happen |
|---|---|---|---|---|
| 1 | `gachibowli.admin` | Meera's profile → *At a glance* → **Certificates** | LC-000002 · Issued; TC-000001 · Issued; LC-000001 · Void (replaced by LC-000002) | a name, date of birth or fee on the card |
| 2 | (same) | **Certificates** (`/app/students/{id}/certificates`) | preview: the frozen facts; both types "already issued"; reason "Transferred to another School"; "No outstanding fees on record" (Finance holder only) | a fee balance on the certificate |
| 3 | (same, MFA) | **Download LC-000002** | the PDF: Leaving Certificate LC-000002, Meera Iyer, Grade 5 · Section B, joined 1 June 2026, left 30 September 2026, DEMO banner and generic footer | a statutory or board claim; a QR or verification link |
| 4 | (same) | **Void and reissue TC-000001** (confirm, fresh code) | TC-000002 issued, replaces TC-000001 (now Void, replaced) | the old number reused; the old file deleted |
| 5 | (same) | **Void LC-000002**, reason *Issued in error*; then **Issue certificate** → Leaving (confirm, code) | LC-000002 Void; **LC-000003** issued | LC-000002 reused |
| 6 | (same) | Arjun Mehta → **Certificates** | "Not eligible: … no exit … has been recorded. Record the exit first." No issue form | a certificate for a Student whose exit was never recorded |
| 7 | (same) | a Student with an unpaid Charge who has left | the issue still succeeds; the fee note is information only | fees blocking issuance |
| 8 | `gachibowli.ey.principal` (Campus-scoped) | Meera's certificates URL | 403: whole-School authority only | — |
| 9 | `gachibowli.cashier` | (same) | 403 | — |
| 10 | `network.manager`, `platform.admin` | (same) | back to the dashboard | any certificate |

**Verified on 2026-10-30** (DDEV, real browser, Playwright 1.62.1): 18/18
checks passed. Covered:
- the seeded history and replacement links, the preview and the attachment
  download;
- void and reissue (TC-000002), void then issue (LC-000003);
- Arjun's explained refusal and the Student 360 card;
- no page overflow at 1280, 768 and 390, and keyboard reach;
- Campus Principal, Cashier, Network Manager and Platform Admin refused.

Steps 7 and the Teacher refusal are covered by `StudentCertificateTest`;
there is no Gachibowli Teacher persona.

Run `ddev demo-reset` after steps 4–5.

## 7. SIS.EXP-5 — Bulk Student & Guardian import (owner path)

**Nothing is seeded.** The import IS the demonstration; `ddev demo-reset`
returns the canonical world (18 Gachibowli Students) afterwards.

**Fictional fixtures** in `docs/demo/fixtures/`:

| File | Contents | Expected preview |
|---|---|---|
| `sis-exp-5-import-valid.csv` | Riya Kapoor (Grade 5 · A, Main) and her brother Dev Kapoor (UKG · A, Early Years) share ONE new Guardian, Anil Kapoor (key `KAPOOR`); Riya also has Pooja Kapoor. Ishita Nair (Grade 3 · A) has Suresh Nair; Kabir Rao (Grade 4 · A) has Lata Rao. Rolls 41, start 1 October 2026 | 4 Students, 4 Guardians, 5 relationships, both Campuses, all Ready |
| `sis-exp-5-import-invalid.csv` | an unknown Section (`Z`); the same Student twice; an unknown relationship (`uncle`); a malformed date (`31/12/2016`) | 4 errors and 1 warning; no Import form |

**MFA:** the commit asks for a fresh code (enroll under *Account security*
first).

| # | Persona | Route | Expected | Must not happen |
|---|---|---|---|---|
| 1 | `gachibowli.admin` | *People* → **Import Students** (`/app/students/import`) | the page, the template download (`student_guardian_import_v1`), "How to fill it in" and this School's codes | — |
| 2 | (same) | upload `sis-exp-5-import-invalid.csv` | each error row names its field and reason; nothing can be imported | any Student created |
| 3 | (same) | upload `sis-exp-5-import-valid.csv` | 4 Ready rows, "Student numbers will be assigned on import"; still no Riya in the directory | a number reserved; any record before the commit |
| 4 | (same) | confirm, enter a code, **Import all rows** | "Imported 4 Student(s), 4 Guardian(s) and 4 placement(s)."; one row in Past imports | an email, SMS, invitation or portal account |
| 5 | (same) | *Learners* → Riya Kapoor (Student 360) | Anil Kapoor (father, primary) and Pooja Kapoor (mother); the Family card shows Dev Kapoor | a link to an existing Guardian |
| 6 | (same) | *Student movements*, search Riya / Dev | each a **First enrollment** (Main / Early Years) | a fake or historical movement |
| 7 | (same) | upload the valid file again | "This exact file was already imported…"; no Import form | a second set of Students |
| 8 | `gachibowli.ey.principal` (Campus-scoped) | `/app/students/import` | 403 | — |
| 9 | `principal@example.test` (Lycenza Demo School Principal) | (same) | 403: import is School Admin only | — |
| 10 | `gachibowli.cashier` | (same) | 403 | — |
| 11 | `network.manager`, `platform.admin` | (same) | back to the dashboard | — |

**Verified on 2026-10-30** (DDEV, real browser, Playwright 1.62.1): 23/23
checks passed on a fresh `ddev demo-reset`, including 1280/768/390 widths,
keyboard order and no email sent. Run `ddev demo-reset` afterwards:
the imported Students disappear, and the verifier confirms the canonical
world.
