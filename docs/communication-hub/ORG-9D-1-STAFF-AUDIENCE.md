# ORG.9D-1 — Staff-only audience (2026-10-23, ADR 0076 §73)

PV1-10 (COM-016 staff announcements, COM-034 staff email). The fix plugs into the existing Communications pipeline:
- one more audience type (`staff`) and one more resolver;
- no new table, send service, approval workflow, channel or capability.

## Who is "staff"

A **business identity, never a role, capability, persona or email domain**. HR answers it with `App\Domain\HR\Application\CurrentStaffMembers::userIds()`, the set-based form of `ActingEmployeeResolver`'s chain:

1. an active School membership for the User in this School;
2. an enabled User;
3. the Employee linked through `employees(school_id, user_id)` (unique per School), with `record_status = 'active'`;
4. exactly one employment with `starts_on <= today` and (no end, or `ends_on >= today`), status `active` or `notice_period`. "Today" is the School-local date.

Consequences:
- **Excluded:** a leaver, a future hire, an archived Employee, a disabled User, a suspended membership, an Employee without a User account, and a Guardian-only, Student-only or platform-only User.
- **Two eligible employments** (impossible under HR's overlap invariant) exclude the person; there is never a tie-break.
- **Membership is evaluated per School:** staff in School B is not staff in School A.
- **Projection:** HR returns User ids only. Communications learns no position, pay, contact, leave or other HR field.

## Audience behaviour

- **Whole-School.** Communications has no Campus targeting, so none was invented.
- **The creator is excluded,** as for the School-wide audience.
- **Deduplicated by User:** a staff member who is also a Guardian, or who holds several roles, is one recipient.
- **When it is resolved:** at preview (advisory, reserves nothing) and again inside `publish()`. That is when the recipient snapshot is written, so a leaver after the preview is not delivered to, and a hire before publish is.
- **After publish:** the snapshot and its deliveries are fixed (the existing contract for every audience). A queued or quiet-hours-deferred email still goes to someone who left after publish.
- **Empty audience:** refused as for every audience (`EmptyAudienceException`). Nothing is published.

## Approval and channels

- **Approval:** the School-wide approval rule (`require_school_wide_approval`) applies to Staff, so switching a School-wide announcement to Staff never avoids approval. The approval fingerprint includes the audience type, so an approval given for one audience does not cover another. The other reasons (required communication, non-privileged sender) and the domain workflow are unchanged.
- **Channels:** existing channels only. In-app goes to the User. Email goes to the User account's address through the existing email channel (production email still gated, E17–E20). There is no SMS and no HR contact field.
- **Audit:** existing audit events only; no recipient list.

## Performance

Four bounded reads (16 ms at 1,050 staff), never one join of memberships against employments. The first draft of that join was a nested-loop cross product, about 2.2 s at 1,050 staff.

## Reading what was delivered (ORG.9D-1R1, ADR 0076 §77)

ORG.9D-1 delivered in-app copies to current staff, but the Hub checked
`communications.view` (the School announcement list) before recognising a
recipient, so most staff could not open them. OD-9.6 closed it:

- **Capability:** `communications.inbox.view` -- read what you actually
  received or take part in. On every staff persona role except the
  `hr_sensitive_records` add-on; never the Guardian, Group or platform roles.
- **Surfaces:** Hub inbox, unread and search; a received announcement and
  its attachments; the conversations you are an active participant of (and
  your own archive flag). Sent, the School announcement list, Failed,
  analytics, approvals, templates, settings and the audit trail keep their
  own capabilities.
- **Per item:** the published recipient record (`AnnouncementRecipientAccess`:
  the audience snapshot, or a linked login's in-app delivery row; never a
  draft, scheduled, pending, cancelled or fee-reminder announcement) or
  active thread participation. The capability never makes anyone a recipient.
- **Recipient view:** the message, sender, audience type, attachments and
  own read state -- never the recipient count, delivery figures, approval
  history, template or audit/analytics links.
- **History:** the snapshot is the fact. A recipient whose employment later
  ends keeps what was delivered while they keep School access (an active
  membership and a role carrying the capability); off-boarding ends it. A
  Staff announcement published after they left never reaches them. This is
  deliberately different from Events, which are evaluated per viewer at
  read time.
