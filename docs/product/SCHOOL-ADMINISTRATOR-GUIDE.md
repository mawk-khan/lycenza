# Lycenza — School Administrator Guide

This guide is for the person who runs a School in Lycenza through the browser:
setting the School up, bringing staff in, and handling day-to-day
administration and corrections. It describes the application as it is on
`main` after UAT-C6 (2026-10-16). It was checked against the browser on the
local demo.

**Before you start, read [Where Lycenza stands today](#1-where-lycenza-stands-today).**
Lycenza has been proven in **local UAT only**. Some modules exist but are
**not approved for production use** (§20).

## Contents

1. [Where Lycenza stands today](#1-where-lycenza-stands-today)
2. [Getting started](#2-getting-started)
3. [School settings and setup](#3-school-settings-and-setup)
4. [Academic years](#4-academic-years)
5. [Grades and Sections](#5-grades-and-sections)
6. [Staff onboarding](#6-staff-onboarding)
7. [Roles and access](#7-roles-and-access)
8. [Admissions](#8-admissions)
9. [Students and placements](#9-students-and-placements)
10. [Timetable and teaching assignments](#10-timetable-and-teaching-assignments)
11. [Attendance](#11-attendance)
12. [Fees: setup, charges and fee runs](#12-fees-setup-charges-and-fee-runs)
13. [Payments, receipts and what is owed](#13-payments-receipts-and-what-is-owed)
14. [Correcting payments and charges](#14-correcting-payments-and-charges)
15. [Concessions](#15-concessions)
16. [Year rollover](#16-year-rollover)
17. [Data Health](#17-data-health)
18. [Library](#18-library)
19. [Inventory and other operations](#19-inventory-and-other-operations)
20. [Gated and not-yet-enabled areas](#20-gated-and-not-yet-enabled-areas)
21. [Known limitations](#21-known-limitations)
22. [Daily checks](#22-daily-checks)
23. [Troubleshooting](#23-troubleshooting)
24. [Keyboard tips](#24-keyboard-tips)

---

## 1. Where Lycenza stands today

| Stage | Status |
|---|---|
| **Local development / local UAT** | Proven. Every workflow in this guide was exercised on the local demo, with demo data only. |
| **Staging** | **Not yet provisioned or qualified.** The product side is ready to hand over, but there is no staging environment yet. |
| **Pilot** | **Not yet.** It needs the staging environment, release-image qualification and several legal approvals first. |
| **Production** | **Not authorised.** Do not use Lycenza with real School data yet. |

"Ready for staging handoff" means the product is ready to be tested in a
staging environment. It does not mean "production ready".

## 2. Getting started

### The first School Administrator (done for you)

You do not create your own School. A Lycenza platform operator does this
once, outside the School's pages:

1. The operator creates your account and gives you a **one-time activation
   link** over a trusted channel, such as a verified call or in person. The
   link expires, usually after 24 hours.
2. You open the link, choose a password, then sign in normally.
3. The operator creates the School with you as its administrator and
   activates it.

From then on, everything in this guide is yours to do in the browser.

**If your link expired or was lost,** ask the operator to issue a new one;
the old link then stops working. Account recovery by email does not work
for an account that was never activated.

### Sign in and secure your account

- **School choice:** after signing in, choose your School if asked. A
  person with one School may still see "Select a School"; click it (§21).
- **Turn on MFA:** go to **Account security** and select **Enable MFA**,
  then scan the QR code with an authenticator app. Many actions ask for a
  current **authentication code**, for example:
  - inviting staff;
  - granting roles;
  - recording or correcting payments;
  - approving concessions.
- **The Home page** groups everything you can open: School setup,
  Academics, People, Finance, Operations, Communications and Reports. You
  only see what your roles allow.

## 3. School settings and setup

**Settings** (`School Settings`): the School's **Name** and **Timezone**.
Choose the timezone carefully. Lycenza decides "today" by the School's own
date, for example for registers, overdue fees, due dates and year
activation.

**School setup** shows a checklist:
- School profile;
- Campus;
- Academic Year activated;
- Grade Levels;
- Subjects.

It links to Terms, Sections, Academic departments, Rooms, Subject offerings
and Elective groups. Work through it top to bottom.

**Codes** (such as `G6`, `MATH`, `AY2026`) are unique within your School
and are stored in capitals.

**Reference records are deactivated, not deleted**, once anything may refer
to them. This applies to Grades, Sections, Subjects, Rooms and Campuses.

## 4. Academic years

An academic year is **draft**, **active** or **closed**. Only one is active
at a time.

| State | What it means | What you can do |
|---|---|---|
| Draft | Being prepared | Rename; **correct its dates and its Terms' dates**; add Sections, offerings and a rollover plan. **Safe before its start date.** |
| Active | The current operational year | Day-to-day work happens here, including adding a Section or Term mid-year. Its dates are fixed. It cannot be a rollover target |
| Closed | History | Read only: its Sections and Terms stay visible but no new ones are added, and it takes no new placement or rollover. Complete its open placements (§16) |

**Rules that protect you:**
- **Activation waits for the start date.** A draft year can be activated
  only on or after its **start date**, by the School's own date. Before
  then the page says "Can be activated from …" and offers no Activate
  button.
- **Activating closes the previous year.** The confirmation names the year
  that will close.
- **A closed year cannot be reopened** through School administration. Do
  not activate early "to get ahead". Prepare the next year as a draft
  instead.
- **A closed year takes nothing new (SIS 5.2).** The Sections and Terms
  pages show "<year> is closed: it keeps its Sections but takes no new
  ones." instead of the Add form.
- **Activation waits for a running rollover (SIS 5.2).** While a rollover
  into the year is still running, Activate is refused: finish it first
  (Enrollment rollovers → Resume).

**Correcting a mistyped date (draft years only, SIS 5.1):**
- **Academic years → Correct dates** on a draft year. Change the start or end
  date and select **Save dates**. Name, code and status do not change.
- **Terms → Correct dates** on a Term of a draft year. Name, code and order do
  not change.
- **What is checked:**
  - The start must be before the end.
  - The year must not overlap another year.
  - A Term must stay inside its year and must not overlap another Term.
  - Everything already in the year must stay inside the new dates: its
    Terms, placements, teaching assignments, examinations, assignment due
    dates and fee instalments or concessions.
- **If a record would fall outside,** the change is refused with what is in
  the way (for example "Correct the Term dates first: 1 Term would fall
  outside…"). Correct that record first. Nothing is moved or ended for you.
- **Once a year is activated, its dates and its Terms' dates are fixed.**
  The Correct dates action is no longer offered, and a correction started
  before activation is refused. Check the dates before activating.
- **If someone else changed the year or Term** while you were editing, you
  are asked to reload and try again.

**Safe sequence for a new year:**
1. Add the next year (**Add an Academic year**: name, code, start and end
   dates). It is created as draft.
2. Prepare it: Sections, subject offerings, fee structures and the rollover
   plan (§16). Check its dates now: they can be corrected only while it is a
   draft.
3. On or after its start date, **Activate** it.
4. Complete the closed year's open placements (§16, step 9).

## 5. Grades and Sections

- **Grade levels** have an explicit order, so they sort correctly (for
  example Nursery before Grade 1).
- **A Section belongs to one academic year.** "Grade 6 · A" in 2026-27 and
  in 2027-28 are separate Sections.
- **To add a Section:** **Sections** → choose the year → **Show sections**
  → **Add a Section** (Grade, Campus, Name, Code, Capacity). A draft or
  active year only: a closed year keeps its Sections but takes no new ones.
- **To deactivate a Section:** use **Deactivate**. Any Students still
  placed there and any teaching assignments on it then show up in Data
  Health (§17), so you can move them.

Lycenza labels classes "Grade 6 · A" everywhere: registers, timetables
and teaching assignments.

## 6. Staff onboarding

Bringing a member of staff in takes **six separate steps**. Do them in
order; each one does something different.

**Create Employee → Add employment and assignment → Invite → Invitation
accepted → Link the account to the Employee → Assign roles (incl. Staff
Self-Service)**

### Step 1: Create the Employee (HR)
- **Where:** **HR → Employees → Add employee**.
- **What:** Full name and Work email. Phone is optional.
- **Result:** an Employee number such as `EMP-000013`.

### Step 2: Add employment and an assignment (HR)
1. On the Employee's page, select **+ Add employment**: type, start date,
   category.
2. Then **+ Assignment**: position, department, campus, start date.

Employment that starts in the future is "pre-joining". The person's own
self-service pages work only once their employment has **started** (active
or in notice).

### Step 3: Invite them (Settings → Staff accounts)
1. In **Invite a staff member**, enter their email.
2. Tick their **roles** (§7).
3. Enter your **authentication code**.
4. Select **Send invitation**.

What to expect:
- The invitation is emailed. It expires after 7 days, and only one can be
  pending per address.
- You can only give roles whose permissions you hold yourself.

### Step 4: They accept the invitation
- **New to Lycenza:** they open the emailed link, choose a name and
  password, then sign in.
- **Already have a Lycenza account:** they sign in first, then open the
  link again.

### Step 5: Link the account to the Employee (HR)
1. On the Employee's page, find **Sign-in account**.
2. Type the person's name, then pick their account. Results are names only.
   The account whose sign-in email matches the Employee's work email is
   listed first.
3. Select **Link account**. You should see "Account linked."

Rules:
- **One account per Employee:** an account linked to another Employee
  cannot be linked again, and an Employee has at most one account.
- **To change it:** select **Remove link** first, then link the other
  account.
- **Not your own record:** you cannot link or unlink yourself; another HR
  administrator must do it.

### Step 6: Make sure they have the right roles
- **Change roles:** in **Settings → Staff accounts → Active staff**, use
  **Add a role… → Grant** (with a code), or **×** to revoke a role.
- **Self-service needs its own role:** see the box below.

> **Inviting someone as Teacher does not give them self-service.** Their
> own leave (**My leave**), staff attendance (**My staff attendance**) and
> payslips (**My payslips**) need the separate **Staff Self-Service** role.
> Grant it as well, or tick it at invitation. Without it those pages show
> "This action is unauthorized."

**Check it worked:** the person signs in and opens **My leave**. A teacher
also sees their classes once teaching assignments exist (§10).

### Reporting manager (optional)
On the Employee's assignment, select **Set manager**, search by name or
employee number, pick the manager, then **Save**. To clear it, use
**Remove manager**.

Lycenza refuses:
- a reporting line that would loop back ("…would make the reporting line
  loop back to this Employee");
- someone reporting to themselves;
- changes to your own reporting line.

Leave requests from someone without a manager are decided by HR.

### Off-boarding
**Remove access (suspend)** in Staff accounts stops the person's access to
**this** School and revokes their roles here.

It does not touch:
- their account;
- their access to other Schools;
- HR records.

Reactivating requires choosing roles again. Lycenza never leaves the School
without an administrator who can manage staff.

## 7. Roles and access

Lycenza has a fixed catalogue of School roles. **Give each person the
fewest roles their job needs**; a person with two jobs holds two roles.

| Role | In short |
|---|---|
| School Admin | Everything in the School, including staff access, finance approvals and corrections |
| Principal | Academics, Students, admissions, attendance, curriculum, communications approval. **No Finance or Payroll.** |
| Teacher | Their own classes: registers, curriculum delivery, learning content, assignments. **Not self-service** (§6). |
| Staff Self-Service | Own leave, own staff attendance, own payslips |
| HR Officer / HR Sensitive Records (add-on) / Payroll Officer | HR records; sensitive HR data; payroll preparation |
| Accountant | Fee setup and runs, charges, recording payments, outstanding fees, moving a payment to the right charge, concession requests. **Reversals, refunds and approvals stay with School Admin.** |
| Cashier | Recording payments (not corrections) |
| Librarian, Transport Coordinator, Hostel Warden, Front Office, Stores Officer, Canteen Operator, Admissions Officer, Communications Coordinator | Their own desk's module, linked from Home |

**Separation of duties:**
- whoever requests a concession cannot approve it;
- nobody changes their own staff access, HR identity, employment or pay.

Some actions need a **fresh authentication code** even when you hold the
role.

## 8. Admissions

**Goal:** turn an applicant into an active, placed Student.

1. **Admissions → Applicants → Add applicant**: name and date of birth.
   The date of birth cannot be later than today (the School's date).
2. On the applicant, select **New application**. Choose the **Academic
   Year**, Campus and Grade. The current year is preselected; upcoming
   draft years are labelled. Only active Campuses are offered.
3. **Submit application**, then **Accept application…** or **Reject
   application…** (with an optional note), or **Withdraw application**.
   **Accepted is a decision, not yet a Student:** the Student record is
   created only when you convert the application.
4. On an accepted application, **Convert to Student**:
   - Section, roll number and start date (the Student number is assigned
     automatically, SIS 2.3);
   - optionally a **Guardian**: create a new one, link an existing Guardian
     (for siblings), or none.
5. Open **View converted Student**.

**Good to know:**
- **A Guardian is optional.** Their email and phone are optional too.
- **The Student number is assigned automatically** when the Student is
  created, from the School's one sequence (1, 2, 3, ...). Existing numbers
  such as LDS-0001 never change.
- **Possible duplicate:** if an existing Student has the same name and date
  of birth, conversion stops and shows that Student (number, date of birth,
  status, class). Nothing has been saved.
  - If it is the same child, cancel. An inactive Student can be reactivated
    from their profile.
  - If it is a different child, choose **These are different people —
    convert to a new student**.
- **Correcting a mistake** (SIS 3.1):
  - **Applicant name or date of birth:** on the applicant, select **Correct
    details**. This works until one of their applications is converted;
    after that, correct the Student record instead.
  - **Academic Year, Campus or Grade:** on the application, select
    **Correct details** while it is a draft or submitted. The status does
    not change. Once accepted or rejected, the year, Campus and Grade are
    fixed, and **Correct details** edits the decision note only.
  - A converted application cannot be edited: it is the admission record.
  - If someone else changed the record while you were editing, you are asked
    to reload and try again; nothing is overwritten.
- **Discard draft** closes a draft that was never submitted. Use it for a
  draft created by mistake. It then shows as Withdrawn, and the applicant
  stays. After submission, use **Withdraw** instead.
- **Withdraw, Reject and Discard** all close an application for good;
  nothing is deleted. Withdraw is for a submitted or accepted application
  the family no longer pursues; Reject is the School's decision; Discard
  removes a draft created by mistake.
- **A Campus deactivated later** stays on the applications already made
  there ("Campus now inactive"); they remain readable and are never moved.
- **A date of birth in the future** (recorded before this rule existed)
  stops conversion with a message. Correct the applicant's details, then
  convert.
- **Admission fee:** Admissions → Admission fee records a fee *intent*.
  It is not a charge.

## 9. Students and placements

- **Add student** (SIS 2.3) asks for the name and date of birth only:
  - the Student number is assigned automatically from the School's sequence;
  - a date of birth in the future is refused;
  - if an existing Student, active or inactive, has the same name and date of
    birth, a **Possible duplicate** warning lists them, with a link to open
    each record in a new tab, and nothing is saved;
  - choose **Cancel — do not create** if it is the same child, or **These
    are different people — add a new student** to continue;
  - the same check runs on Campus onboarding (only Students you can see) and
    when converting an admission.
- **A Student number can still be corrected** on **Edit**, if it was entered
  wrongly. A number already in use is refused.
- **Students** is the directory. Search works by:
  - any part of the name ("Isha Nair", "Nair Isha");
  - a Student-number fragment ("0103").
- **Enrollments** lists placements by year, Grade, Section and roll number.
- **A Student's page** shows:
  - their current placement and Guardians;
  - **Enroll student** to place them;
  - **Transfer** to move them to another Section;
  - **Mark inactive** / **Mark active**;
  - **History**: admitted, placed, moved, inactive and active, Guardian
    linked.

**Placement start dates** (SIS 4.1):
- **The start date must fall inside the Section's academic year**, first
  and last day included. The Section's year decides, never the active one.
  Outside it, the start date field says which dates are allowed; correct the
  date and save again. This applies to Enroll student, Transfer (the
  effective date) and converting an admission.
- **A closed academic year takes no new placements.** Its Sections are not
  offered, and a closed-year Section is refused with "New placements cannot
  be added to a closed Academic Year."
- **A draft (next) year can be prepared:** placing a Student in a draft
  year's Section works, with a start date inside that year.
- **A start date cannot be edited afterwards.** If a placement was entered by
  mistake, cancel it (reason: entered in error or duplicate placement) and
  enroll again; for a wrong Section, use Transfer.
- **A placement that has not started yet** (for example next year's) has
  **Cancel before it starts** in the Enrollment history (SIS 4.2):
  - it asks for a reason, a fresh code and your confirmation;
  - it stays in the history as "Cancelled before it started" and never
    becomes the Student's placement.

  It is refused while something already depends on it: an elective on that
  placement, a fee charge raised for it, or a mark. Resolve that first, in
  its own module.
- **Correct roll number** fixes a typed roll number on a current or
  not-yet-started placement. A number already used in that Section for the
  year (even by a Student who has left it) is refused.
- **Changing a Student's Campus with Transfer** is refused while they still
  have an active Hostel residency, Transport assignment or elective on the
  current Campus. The message lists them: end or move them first (Hostel →
  Residency, Transport → Student assignments), then transfer again. A Section
  change on the same Campus is not affected.
- **Pickers** list the current year's Sections first. Transfer offers only
  Sections of the same year and Grade.

**Mark inactive is one click, with no confirmation** (§21). Data Health
then lists an inactive Student who still has a placement.

**Guardian contacts** (Guardians → a Guardian → Contact information, SIS 2.1):
- **Correct** fixes a mistyped email address or mobile number, or its label.
  The corrected value is used everywhere this Guardian is reached, for every
  linked Student. Mobile numbers are written with the country code and no
  spaces (+919876543210).
- **Deactivate** stops using a contact without deleting it. It then appears
  under **Inactive contacts** and is never used to reach the Guardian.
- **Reactivate** (under Inactive contacts) brings that same contact back,
  after a confirmation. It returns as a non-primary contact; use **Make
  primary** if needed. You can also correct an inactive contact first; it
  stays inactive until you reactivate it.
- **Adding a value the Guardian already has** (even an inactive one) is
  refused: reactivate the inactive contact instead.
- **Two Guardians of one family may share** an email address or mobile
  number.
- **Not a login.** Correcting a contact's email does not change the
  Guardian's sign-in account.
- **Make primary** works only on an active contact. A deactivated contact
  is reactivated first; it returns non-primary, and you then choose **Make
  primary**. The previous primary of that type stops being primary.
- **There is no search by phone or email** (privacy decision); open the
  Guardian from the Student or the Guardians list.

**Finding a Guardian** (Guardians list, and "link an existing Guardian" on a
Student or during conversion, SIS 2.2): type any part of the name, in any
order. "Aisha Khan", "khan aisha" and "Aisha Noor Khan" all find Aisha Noor
Khan. Every word must appear in the first, middle or last name. The list
shows how many Students each Guardian is linked to, so namesakes can be told
apart. A phone number or email address never finds anyone here.

**Mark inactive / Mark active** on a Guardian asks for confirmation first.
An inactive Guardian stays linked to their Students and keeps their contacts,
but has no Guardian portal access and receives no school emails such as fee
reminders.

## 10. Timetable and teaching assignments

- **Timetable** (`Timetable schedule`): schedule an offering for a Section
  in a period, with a teacher and a room. Every picker searches as you
  type.
  - **Academic year filter.** The list opens on the active year. Choose a
    draft year to prepare next year's timetable while this year's stays
    in use.
  - **Clashes are checked within one academic year.** A teacher, room or
    Section cannot be in two classes in the same period and day of the
    same year. The same teacher or room may hold that slot in another
    year.
  - **Edit** changes a class's teacher, room, period or day. To change
    its subject or Section, schedule a new class and deactivate the old
    one.
  - **Status filter.** Deactivated classes are listed under Inactive or
    All. **Activate** checks every clash again; if one exists, the page
    says why and nothing changes.
- **Timetable periods:** **Edit** corrects a period's code, name, times or
  order. Its times cannot change, and it cannot be deactivated, while any
  active class uses it — including an active class of a closed year.
  Deactivate those classes first. Inactive periods are listed under the
  Status filter.
- **Teaching assignments:** who teaches which offering to which Section,
  from which date. **End** an assignment with its last day and a reason.
  It stays current until that day ends. The last day must fall inside the
  assignment's academic year (its final day is allowed); a later date is
  refused on the date field, never shortened for you.
- **A teacher's own pages** (My class attendance, My curriculum delivery,
  and others) come from their teaching assignments. **My Attendance**
  needs the teacher to have MFA enabled.

## 11. Attendance

### Taking a register (office)
1. **Attendance → Take a register**. The date defaults to the School's
   today.
2. Choose a class: "Grade 6 · A · Subject — Period". Only classes of the
   active academic year are listed; a draft year's timetable never
   appears here.
3. Mark **every** Student present, absent, late or excused, then submit.
   A register is submitted complete; there is no partial save.

**Restrictions:**
- **Future dates are refused** by the School's date. Some screens still
  offer them (§21).
- **There is no "mark all present"**: each Student is marked
  individually (§21).

### Other attendance pages
- **Absent, late and excused** lists every exception for one day.
  Filter by Section and status, then open the register to correct it.
- **Correction:** on a submitted register, select a Student's new status.
  It saves at once (one click) and shows "(Corrected)". Every correction
  is audited.
- **Teachers** take their own classes' registers in **My class
  attendance**.

## 12. Fees: setup, charges and fee runs

### Fee setup (Finance → Fee setup)
1. **Receipt numbering:** prefix and financial-year start month.
2. **Fee heads:** code, name, a **receivable (asset)** account and a
   **revenue (income)** account. Only valid accounts are offered.
3. **Fee structures:**
   - one per academic year and Grade (optionally one campus);
   - add **lines**: a fee head and its yearly amount;
   - generate the **instalment schedule** (one time, term or monthly);
   - then **Activate**.

Nothing in Fee setup bills anyone.

### Ad-hoc charges (Finance → Charges → Assess charge)
For a one-off charge:
- **Choose:** Student, year (the current year is preselected),
  description, amount and due date.
- **Accounts:** receivable (asset) and revenue (income) accounts only.

**Similar-charge warning.** If the Student already has a similar charge
this year, Lycenza lists it. Tick "assess it anyway" only when it really
is a separate charge.

### Fee runs (Finance → Fee assessment runs)
A run bills one billing period of one active fee structure to its
Students.

1. **Create run:** choose the structure and billing period.
2. **Preview.** A preview never creates a charge. Each Student shows as:

   | Preview result | Meaning |
   |---|---|
   | **Ready** | Will be charged |
   | **Already assessed** | Already billed for this fee and period by a run |
   | **Excluded** | Not charged, with the reason (for example optional fee not chosen, or excluded by staff) |
   | **Blocked** | Cannot be charged, for example an inactive fee head |
   | **Decision required** | See below |

3. **"Potential existing charge — decision required".** The Student already
   has a charge that *may* cover this period. Typical example: an ad-hoc
   "Term 1 tuition fee" assessed before the run.
   - Lycenza shows the existing charge (amount, due date, paid or owed)
     beside the charge the run would add.
   - **Lycenza never decides for you.** For each Student choose **Include
     anyway** (they are charged in addition) or **Exclude from this run**
     (not charged by this run).
   - There is deliberately no "include all".
   - The page's **Show them** link lists every such Student.
4. **Preview again.** Any decision or exclusion returns the run to draft.
5. **Execute.** It is offered only when nothing still needs a decision and
   the preview is current. Each Ready Student gets one charge.

**At execution Lycenza checks once more.** If a possibly overlapping charge
was added after the preview, Execute is refused ("…was added after the
preview. Nothing was charged.") Preview again and decide.

**No proration.** Every Student is charged the full instalment amount,
whatever their join date. This is the approved current behaviour. If a
mid-term joiner should pay less, record a concession (§15).

**Undoing a run charge.** Someone who manages charges (School Admin or
Accountant) can **Void** a run charge that has no payment on it.

## 13. Payments, receipts and what is owed

### Recording a payment
1. **Finance → Record an offline payment**, then search for the Student.
2. Enter how much goes to each open charge, the total, and the method
   (Cash, Bank transfer, Cheque).
3. Add the date received, an optional reference and the account it was
   received into.
4. Select **Review payment**, enter your **authentication code**, then
   **Record payment**.

What to expect:
- **What it records:** money already received. Lycenza does not collect
  or move money.
- **A receipt number** is issued (for example `RCPT/2026-27/000031`).
  Open it from the payment page.
- **Overpaying a charge is refused.**

### Three different things
| | Where |
|---|---|
| **Money received** | The payment, its receipt |
| **Where it is applied** | The payment's charges; the charge page's "Payments on this charge" |
| **What the Student still owes** | **Student fee statements**; **Outstanding fees** |

### Payments received: the day's collections (Finance → Payments)
Opens on **today** (by the School's date). For each receipt:
- the receipt number, the Student, when it was recorded and by whom;
- the method and amount;
- where it stands now: **Received**, **Partly refunded**, **Refunded** or
  **Reversed**. "Reallocated" means it was moved to another charge; the
  money received is unchanged.

**Totals** cover every matching receipt, not just the page:
- **Gross received** is what was recorded that day. A later correction
  never removes a receipt from its day.
- **Still retained** is gross less anything since reversed or refunded.

**Find something:** part of a receipt number, a Student's name or number,
a method, or another date (**Today** returns to today). Cashiers can see
this page; corrections are made on a payment's own page by School Admin.

### Outstanding fees (Finance → Outstanding fees)
Shows Students who still owe money, most overdue first.

- **Overdue** means a charge's due date is **before today** by the
  School's date. Not every outstanding amount is overdue.
- **Filters:** outstanding or overdue only, year, Grade, Section and
  Student search.
- **Students:** All, Current, or **Leavers** (Students marked inactive,
  shown as "Left (inactive)"). A leaver has no current class, so a Grade or
  Section filter excludes them.
- **Charge campus:** each charge keeps the Campus it was recorded against
  when it was charged: a fee run's placement, the canteen outlet, the
  library loan, or, for a manual charge, the Student's placement that day.
  It never follows the Student's current Campus, so a transfer, a leaver or
  a closed Campus never moves old debt. A Student with debts at two
  Campuses appears under each with only that Campus's part. "Whole school
  (no campus)" lists charges that had no single placement that day.
- **Statement:** open a Student to see every charge.

## 14. Correcting payments and charges

**Posted payments, allocations and receipts are never edited or deleted.**
A correction is a new, linked record with its own reason, journal entry
and audit trail.

To correct, open the payment and select **Correct this payment…**. Every
correction needs:
- a **reason**;
- your **authentication code**.

| Correction | When to use it | Who |
|---|---|---|
| **Move to another charge** | The money is right, but it was applied to the wrong charge of the **same** Student | Accountant or School Admin |
| **Reverse this payment** | The payment should never have been recorded (for example the wrong Student). It is final. The receipt is kept and marked REVERSED, and a **Record the correct payment** link follows. | School Admin (needs ledger reversal) |
| **Refund money paid back** | Money was actually handed back to the payer: amount, method, account, reference | School Admin |

**Do not use a concession to correct a payment.** Concessions reduce what
is owed (§15); they do not fix where money went.

### A duplicate charge that has already been paid into
1. Open the duplicate charge. While payments stand on it, Lycenza explains
   that it cannot be cancelled yet.
2. Use **View payment** for each payment there. Then either:
   - **Move to another charge** (to the right charge); or
   - reverse or refund it, as appropriate.
3. When nothing stands on it, enter an optional reason and **Cancel
   charge** on the duplicate.
4. Check the Student's statement. The receipt lists "Later corrections".

## 15. Concessions

- **Account:** set the **Concession account** once (an expense account) in
  Finance → Concessions.
- **Request:** from a charge, **Request concession**, a fixed reduction
  with a reason.
- **Approval:** **another person** must approve it, with a fresh
  authentication code. Requesters cannot approve their own request.
- **Lifecycle:** requests can be rejected, withdrawn by the requester, or
  later revoked.
- **The charge amount never changes;** the approved concession reduces what
  the Student owes.

Concessions are discounts, scholarships and waivers. They are **not**
payment corrections or refunds (§14).

## 16. Year rollover

**Goal:** move Students from this year into next year's Sections in one
reviewed operation.

1. **Prepare the target year** as a draft, with its Sections (§4–§5),
   its subject offerings and its teaching assignments.
   - **Prepare its timetable now** (§10): choose the draft year in the
     Timetable schedule's year filter and schedule its classes. The same
     teachers and rooms can be used as this year; clashes are checked
     within the draft year only.
   - **Leave this year's timetable active.** Registers keep using it
     until the draft year is activated.
2. **Enrollment rollovers → Create Plan**: source and target year, then
   **Open** it. The target must be a **draft** year; only draft years are
   offered.
3. **Mappings:** **Add mapping** from each source Grade (optionally
   Section) to a target Grade and Section. Only the target Grade's Sections
   are offered.
4. **Review Students.** Every row names the Grade and Section. Filter as
   needed.
5. **Decide in bulk:** tick Students, then **Promote selected…** or
   **Exclude selected…**. For example, exclude Grade 8 leavers. A single
   row can still be edited. Saving keeps your page and filters.
6. **Resolve conflicts** shown as "Needs review" or "Blocked", such as
   missing mappings or roll-number clashes. Choose a roll-number strategy.
7. **Run Validation** until nothing blocks.
8. **Start Rollover.** It places Students in the target year.
9. **On or after the target year's start date,** **Activate** it. This
   closes the old year, and registers switch to the new year's timetable
   at once.
10. **Complete the old year's open placements:** Data Health → "Placement
    still open in a closed academic year" → **Open placements in closed
    years**, preview, then complete.
11. **Confirm Data Health is clear** (§17).

**Caveats:**
- **Executing and activating are separate.** Rollover runs only while the
  target year is still draft (SIS 5.2): creating the plan, validating it
  and starting it each check this, and the rollover never activates the
  year. If the target year was activated or closed after you planned, the
  rollover is refused (or, mid-run, the plan is marked "Target year is no
  longer draft") and no Student is placed. Activate only after the rollover
  has completed; a running rollover blocks activation.
- **Nothing is closed automatically.** Old placements stay open until you
  complete them (step 10).
- **A closed year cannot be reopened** through School administration.
- **Old timetables stay active** after their year closes. They no longer
  appear in registers, but a period they use cannot change its times.
  Deactivate them when you need to change the bell times.

## 17. Data Health

**Data health** lists records that get in the way of daily work, grouped
**Blocking**, **Action required** and **Review**.

- **Each finding** explains what it means, why it matters, how to fix it,
  and links to the right page.
- **It only reports.** It never changes records itself. You fix the record
  on its own page, and the finding disappears on the next check.
- **You only see** the checks for information your roles allow.

| Check | Usually fixed by |
|---|---|
| No active academic year | Activating the current year (§4) |
| Active academic year has no Sections | Adding Sections (§5) |
| Active Students with no class this year | **Enroll student** from the finding (§9) |
| Inactive Students who still have an active placement | Marking them active again, or ending the placement |
| Students placed in a deactivated Section | **Transfer** to an active Section |
| Sections with no timetable this year | Scheduling the Section (§10) |
| Teaching assignments on a deactivated class | **End** the assignment, or reactivate the Section. An assignment ending today stays listed until tomorrow. |
| Placement still open in a closed academic year | Completing it in **Open placements in closed years** (§16) |
| Possible duplicate Student records | Comparing the records. Different children who share a name and birthday need no change and stay listed. A record added by mistake is corrected, or marked inactive, on its Student page. Records are never merged. |
| Student numbers that differ only in letter case | Giving one Student a clearly different number on its **Edit** page |
| Attendance recorded outside a Student's class dates | Checking the placement's end date. Register marks stay as history; correct a mark only if its status is wrong. |
| Fees billed for a period after the Student's placement ended | Opening the Charge. If it is not owed, **void** its fee assessment on the fee run (this cancels the Charge). If the School bills that period on purpose, no change is needed. |

**Data Health detects; the record's own page repairs.** No check offers a
fix, merge or delete button. A Student placed only for next year is listed
under "Active Students with no class this year" with that placement shown.

## 18. Library

- **Catalogue:** titles and their copies.
- **Check out** (Circulation → Check out an item): pick a copy and a
  Student by typing, then choose a **due date**.
  - The due date is a **calendar date**: "due 24 Oct 2026" means by the
    end of that day, by the School's date.
  - Today is the earliest choice.
  - A copy already on loan is not offered.
- **Check in:** from the active loans list.
- **Returned loans:** a separate tab.
- **Overdue loans** are marked. Fines follow the published **Fine policy**.

## 19. Inventory and other operations

**Inventory** was exercised in local UAT:
- **Items and locations:** add them first.
- **Stock:**
  - **Receive** stock;
  - **Issue** stock (issuing more than is on hand is refused);
  - **Transfer** between locations;
  - **Movement history** lists every receipt and issue, in School time.

**Transport, Hostel, Visitors, Canteen.** These pages open and work for
their roles on the demo (each linked from Home). Local UAT did not test
them in depth, so this guide does not give detailed procedures for them
yet.

**Staff attendance and leave** (for HR):
- **Staff attendance:** a daily register with halves.
- **Leave:** configuration, policies and allocations (assigning a policy
  does not create a balance by itself; allocate it), requests and
  approvals.

## 20. Gated and not-yet-enabled areas

These exist in the application but are **not approved for production use**.
Do not rely on them for a real School.

- **Marks, results and report cards.** Legal gates E35–E44 are open, and
  production refuses marks in code.
- **Payroll.** Not exercised in local UAT, and statutory payroll is legally
  gated (E45; ESI-12).
- **Guardian portal.** Development only (POR). Guardians can see their own
  child's attendance and fees and reply to conversations.
- **Student portal.** There is no student portal (E46); Student accounts
  see nothing yet.
- **Teacher role in production.** Conditionally approved, but production
  grants still wait for deployment checks (E33).
- **Communications and email.** Local email goes to a test mailbox.
  Production email is deferred to pre-production. Communications
  administration beyond what is on the page is decision-gated (V11.4).
- **Executive dashboards / person-counting analytics.** Blocked (V11.1).

## 21. Known limitations

These are known and recorded. Plan around them; they are not errors in
how you are using Lycenza.

- **Small screens.** Daily pages work at phone width:
  - attendance;
  - finance lists and forms;
  - Library;
  - Students;
  - the timetable;
  - fee runs;
  - the Employee page;
  - the Guardian portal.

  Some administrator and configuration pages still scroll sideways on a
  phone (for example Staff accounts, Automation, Communications and
  curriculum pages). Use a tablet or laptop for setup.
- **No "mark all present"** on registers.
- **School selection:** a person with one School may still need to pick it
  after signing in.
- **Formatting:** the Guardian portal shows amounts without thousands
  separators. Canteen charges name what was bought and the day ("Canteen ·
  Lunch meal ×1 · 17 Oct 2026"); a canteen charge recorded before this
  change keeps its original "Canteen order …" description, because posted
  charges are never rewritten.
- **Guardian search** is by name only (any order, any name part). Search by
  phone or email awaits a privacy decision.
- **No fee proration** (approved current behaviour).
- **One-click actions** with no reason captured:
  - attendance corrections;
  - Mark inactive.
- **Display gaps:**
  - multi-day absence: absence is shown one day at a time;
  - the Library loans list shows names only;
  - stock issues do not record who received them.

## 22. Daily checks

1. **Data health:** anything Blocking or Action required?
2. **Absent, late and excused:** today's exceptions.
3. **Outstanding fees → overdue only:** follow-up list.
4. **Staff accounts → Pending invitations:** resend or revoke stale ones.
5. **Fee assessment runs:** any run left in preview, or with Students
   needing a decision?

## 23. Troubleshooting

| Problem | What to do |
|---|---|
| A year cannot be activated ("Can be activated from …") | It has not started yet by the School's date. Keep preparing it as a draft. |
| "Enter a current authentication code" or "That code is not valid" | Enter a fresh 6-digit code. A code already used for another action is refused. Enable MFA first if you have not. |
| A charge cannot be cancelled | Payments still stand on it. Move, reverse or refund them first (§14). |
| A fee run's Execute button is missing | Students need a decision (**Show them**), or the run changed and needs **Preview again**. |
| "…was added after the preview. Nothing was charged." | Preview again and decide for the Students it affects. |
| A new teacher's own pages say "This action is unauthorized" | Check all three: the account is **linked** to their Employee (§6 step 5); their employment has **started**; they hold **Staff Self-Service**. |
| The person you want is not in the account search | They must have accepted the invitation and be an **active** member of this School. Already-linked accounts are not offered. |
| The manager you want is not in the picker | Only current assignments of active staff are offered, and never the same person or yourself. Check their assignment dates. |
| A Data Health finding will not go away | Fix the underlying record on its own page. An assignment ending today stays listed until the day is over. |
| A Library due date looks one day off | It should not. Due dates are calendar dates, shown exactly as chosen. If you see a time next to a due date, report it. |
| A role cannot be granted | You can only grant roles whose permissions you hold yourself. Nobody changes their own access. |

## 24. Keyboard tips

Every "search and pick" field (Students, copies, staff accounts, managers,
items and similar) works from the keyboard:
- type to search;
- **Arrow Down / Arrow Up** to move through the results;
- **Enter** to pick;
- **Escape** to close the list.

A screen reader announces how many results there are and what was picked.
