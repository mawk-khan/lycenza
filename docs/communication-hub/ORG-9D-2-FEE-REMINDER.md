# ORG.9D-2 — Fee-due reminder email (2026-10-25, ADR 0076 §74)

PV1-11 (COM-045 send fee-due reminders, COM-042 the reminder's template). A
manual, approved, email-only reminder to the current Guardian accounts of
Students who owe fees. It plugs into the existing Communications pipeline:
- one more audience type (`fee_reminder`) and one more resolver;
- a Finance port that Payments implements;
- two narrow tables.

There is no campaign engine, template engine, schedule, new capability or
new channel.

## Flow

**Finance truth → recipients → approval → email.**

1. **Start.** Finance → Outstanding fees → **Send fee reminder**. It is offered
   only to someone who also holds `communications.announce`. It carries the
   page's balance status, academic year, charge Campus and class filters,
   but never search, Students or as-at.
2. **Draft.** The composer shows the selection, which is fixed; to change it,
   start a new reminder. The School writes a note (for example, how to pay),
   and the draft saves the selection (`communication_announcement_fee_reminders`).
3. **Preview.** Only the preparer sees it. It shows:
   - Students who owe;
   - Guardian accounts to email;
   - Students with no Guardian account, who are not emailed;
   - the total outstanding;
   - the "as of" time.
   It is advisory and reserves nothing.
4. **Approval.** Always required, by someone else; never the preparer, even
   if another manager submitted it. The approver sees the message and the
   selection, never balances.
5. **Publish.** Only by the preparer, and only while they still hold the
   Finance authority. The population, balances and Guardians are read again.
   Zero and credit balances drop out. The Guardian's own email preference and
   consent apply. Each recipient's lines are written once.
6. **Email.** One per Guardian account:
   - a fixed subject, "School fees reminder";
   - each of their selected Students with the amount outstanding, the "as of"
     time and the School's note;
   - "If you have already paid, please disregard this reminder."

## Who receives it

**Who it reaches:**
- a current Guardian **User account**: a legal-guardian relationship to an
  active Student;
- an active Guardian with its one active account link;
- an active membership and an enabled User (Identity's
  `CurrentGuardianAccounts`, the set form of `ActingGuardianResolver`).

**Who it never reaches:**
- Student accounts, Staff-only or platform-only Users;
- former, revoked or inactive Guardians;
- Guardians of a leaver;
- Guardians in another School;
- raw Guardian contacts, emergency contacts or Employee contacts.

**A Staff member who is also a Guardian** receives it once, as a Guardian,
by email. It is never shown in a staff inbox.

**Several Guardians, several Students.** Each current Guardian account of a
Student is a recipient. A Guardian of several selected Students gets ONE
email listing only those Students.

## Amounts

- **The Outstanding fees page's own balance** for that Student and population
  at publish: Payments' `FeeReminderBalances` reads
  `FinanceCampusAccess::outstandingFees()`. So uncancelled Charges, live
  concessions and effective payments are counted, and reversals, refunds and
  reallocations are already out. Nothing is calculated in Communications.
- **Campus scope:** a Campus-scoped preparer reminds only Charges whose stored
  Campus is in scope, in counts, totals and every Guardian's lines. A Campus
  outside the scope, or whole-School Charges, is refused.
- **Class filter:** a class filter with no active academic year is refused,
  never widened.

## Snapshot

The lines (`communication_fee_reminder_lines`, append-only) are authoritative
from publish. A later payment, Guardian change or quiet-hours deferral does
not change what is sent. There is no send-time Finance re-read; the
already-paid sentence covers it.

## Not built

- schedules or automation (OD-9.6);
- SMS, WhatsApp, push or in-app reminders;
- reminder opt-out management;
- payment links;
- exports;
- editable fee-reminder templates;
- Required or Emergency reminders.

Production email stays gated by E17–E20. The email goes out under the
existing `school_communication` purpose; whether production needs a dedicated
purpose is a pre-production owner decision (ADR 0076 §74.11).
