# ORG.9D-3 — School Events Calendar (2026-10-25, ADR 0076 §75)

PV1-12 (COM-024, COM-027). One Communications-owned place for School events.
An Event is information only:
- it never closes the School, cancels a class, excuses a Student or changes
  Attendance (OD-9.3);
- it never changes the timetable, leave, payroll or exams.

## What an Event is

- **Content:** a title, a description and an optional display-only location
  (it books nothing).
- **When:** all day (one or several School-local dates) or at a time (start
  and end in the School's timezone).
- **Who can see it:** the whole School, Staff (current HR staff), or the
  Guardians of one Grade or one Section of the current year.
- **Not supported:** recurrence, reminders, notifications, RSVP, capacity,
  attachments, links, categories, colours or Campus targeting.

## Lifecycle

Communications → Events (`communications.announce`):

1. **Draft.** Created and edited by its preparer (or a `communications.manage`
   holder).
2. **Submit.** The request enters the existing approvals queue.
3. **Approve or reject.** Someone with `communications.approve` decides —
   never the requester and never the preparer. Approval covers exactly the
   submitted terms. Editing an approved Event returns it to draft for a new
   approval.
4. **Publish.** Only with a matching approval, before the Event has ended. It
   shows the Event on the calendar and sends nothing.
5. **Cancel.** At any point. A cancelled published Event stays on the
   calendar, marked cancelled. A published Event is never edited or deleted;
   to change it, cancel it and publish a corrected copy.

## Who sees it

Visibility is checked for the viewer each time they open the calendar,
never stored per person:

| Surface | Sees |
|---|---|
| **Staff:** Communications → School calendar (`communications.view`) | Whole-School Events; Staff Events only while the viewer is current staff |
| **Guardians:** the portal calendar (development only until E46) | Whole-School Events, and Grade/Section Events for the classes their children are in now; never Staff Events |

Both have upcoming and past lists and a month filter.

## Separation

- `CommunicationEventSeparationArchitectureTest` keeps Attendance, Staff
  Attendance, Timetable, Leave, Payroll, Examinations, Academic Structure and
  Curriculum Delivery from reading Events.
- A published "Holiday" Event on a teaching day leaves every register status
  and timetable entry unchanged.

## Retention

Events follow the Communications content period (D3):
- a published Event (cancelled later or not) expires 3 years after the end of
  the Academic Year containing its last day;
- a never-published cancelled Event expires 1 year after cancellation;
- drafts are working state.

Expiry runs through the retention identity's database functions, never by
hand; the runtime role cannot delete an Event.
