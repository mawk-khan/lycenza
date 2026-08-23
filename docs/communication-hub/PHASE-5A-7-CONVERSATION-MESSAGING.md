# Phase 5A.7 — Conversation Messaging Completion

## 1. Objective

Complete the conversation-messaging surface Phase 5A.1 founded but left
schema-ready rather than genuinely usable: a real participant picker,
pagination, read/unread state, and secure attachments — reusing every
existing service, capability, and storage primitive rather than
building a parallel system.

## 2. Pre-existing conversation architecture

Audited before writing anything. Already complete and unchanged in
this checkpoint:

- `communication_threads` (`thread_type` direct/group, `status`
  open/archived/closed, `last_activity_at`), `communication_thread_participants`
  (`joined_at`/`left_at`/**`last_read_at`**/`muted`/**`archived`** —
  both columns this checkpoint needed already existed, unused, from
  Phase 5A.1).
- `CommunicationThreadService::createThread()`/`addParticipant()`/`removeParticipant()`
  — validated membership, reactivate-on-rejoin, fully audited.
- `CommunicationMessageService::send()` — atomic message + recipient +
  IN_APP delivery creation, participant-authorization enforced.
- `CommunicationHubController` — thin, capability-gated
  index/store/show/storeMessage; `Communications/Index.vue` and
  `Communications/Show.vue` existed but were genuinely minimal: a raw
  comma-separated "participant user IDs" text field, no pagination, no
  read state, no attachments.

**Genuinely new in this checkpoint:** participant search, pagination
(both threads and messages), read/unread derivation and mark-read,
per-participant archive, and the entire attachment integration.

## 3. Identity limitations

Conversation participants resolve exclusively through `User` +
`SchoolMembership`, exactly as Phase 5A.1 established. No Student/
Guardian/Parent/Class/Section/Grade model or relationship was created.
Phase 1A's concurrent work on local `main` was not merged, rebased, or
cherry-picked into this branch at any point in this checkpoint.

## 4. Thread model

Unchanged. `direct`/`group` remain the only thread types (matching the
identity model above) — no Guardian/Teacher-specific type was added.
`status` (`open`/`archived`/`closed`) is now actually **enforced**, not
just stored: `CommunicationMessageService::send()` and
`CommunicationAttachmentService::uploadForThread()` both reject a
non-open thread (`ThreadNotOpenException`), where previously nothing
checked it.

## 5. Participant eligibility

`CommunicationHubController::searchParticipants()`
(`GET /app/communications/participants/search`) — same-School, active
`SchoolMembership` only, excludes the current actor, gated by the same
`communications.send` capability thread creation already requires,
bounded to 20 results, search-driven (never dumps every membership to
the browser). `SchoolMembership` carries no RLS of its own (by design
— central/platform data), so this query explicitly filters `school_id`
itself rather than relying on one, and never returns anything about a
membership outside the active School.

## 6. Conversation creation

Deliberately **not** collapsed into one atomic "thread + initial
message" step. `CommunicationHubController::store()` still only
creates the thread + participants (unchanged); the first message is
sent through the exact same reply composer/endpoint as every
subsequent one. This was a considered design choice, not an oversight:
since the thread genuinely exists (even with zero messages) the
instant `store()` returns, attachment upload — which needs a stable
pre-message owner — works identically for a thread's first message and
its fiftieth, with no separate "compose a brand-new conversation with
an attachment in one request" code path to build, test, and secure.

## 7. Message send flow

`CommunicationMessageService::send()` (extended, not replaced): now
also rejects a non-open thread, and accepts an explicit
`attachmentIds` array (default `[]`) to link pending uploads in the
same transaction the message is created in. Everything else — sender-
participant check, per-recipient `CommunicationRecipient`/IN_APP
`CommunicationDelivery` creation, audit, domain event, after-commit
delivery job dispatch — is untouched from Phase 5A.1.

## 8. Attachment integration

Conversation attachments reuse the canonical Communication Hub
attachment architecture; no provider-specific or conversation-specific
file silo is introduced.

`CommunicationAttachmentService::uploadForThread()` is the new sibling
to Phase 5A.6's `upload()` (Announcement-scoped), sharing one private
`store()` core (validation, checksum, storage write, compensating
delete-on-DB-failure — identical for both). Authorization: active
thread participation, not ownership. Removal: only the uploader may
remove their own not-yet-linked pending upload
(`NotThreadParticipantException` otherwise) — matching the drafts-only
removal window Phase 5A.6 established for Announcements.

## 9. Attachment schema normalization

`communication_attachments.communication_announcement_id` (NOT NULL in
Phase 5A.6) is now **nullable**; a new nullable
`communication_thread_id` (composite FK → `communication_threads(id,
school_id)`, cascade delete) was added; a CHECK constraint
(`communication_attachments_one_parent_check`,
`num_nonnulls(communication_announcement_id, communication_thread_id) = 1`)
enforces exactly one pre-message owner per row, always.

**Backward compatibility:** every Phase 5A.6 row already has
`communication_announcement_id` set and `communication_thread_id`
NULL, satisfying the new CHECK with zero data migration. `upload()`'s
public signature, behavior, and every Phase 5A.6 test are unchanged
and re-verified passing (§25). `communication_message_id` — the
eventual channel-rendering FK — is untouched: `AnnouncementService::publish()`'s
backfill and `CommunicationMessageService::send()`'s new backfill are
the same one-column UPDATE pattern, just triggered from two different
places. No historical attachment data was altered or lost; the
migration changes schema only.

## 10. Read/unread model

**Storage:** the existing `communication_thread_participants.last_read_at`
column (Phase 5A.1, previously unused).

**Derivation** (`ConversationReadModel::summarize()`): a thread is
unread for a participant when its latest message was created after
that participant's `last_read_at` (or `last_read_at` is null) **and**
that latest message was not authored by the participant themselves —
exactly the brief's own prescribed rule. No per-message read-receipt
row exists or was added.

**Mark-read** (`CommunicationThreadService::markRead()`): opening a
thread (`CommunicationHubController::show()`) updates ONLY the current
participant's own `last_read_at`, and only when they are genuinely an
active participant — a `communications.manage` viewer who isn't a
participant never mutates anyone's read state. Forward-only (a stale/
duplicate call can never rewind a cursor past a newer read).

**A precision subtlety worth recording:** the original Phase 5A.1
`created_at`/`last_read_at` columns used Laravel's default whole-second
timestamp precision. Two events genuinely less than a second apart (a
read immediately followed by a reply — trivial to trigger in an
automated test, and a real possibility for two humans acting quickly)
compared as simultaneous, silently breaking the unread invariant. This
checkpoint widened `communication_messages.created_at`/`updated_at`
and `communication_thread_participants.last_read_at` to `timestamp(6)`
and set `$dateFormat = 'Y-m-d H:i:s.u'` on both Eloquent models so the
stored values actually carry that precision (Laravel's own default
serialization format silently truncates it otherwise). `markRead()`
deliberately updates via Eloquent (not a raw query-builder statement)
specifically so its write picks up that same precision-preserving
format.

## 11. Authorization

- **View a thread:** `communications.view` **plus** genuine active
  participation (or `communications.manage`) — the capability alone
  never grants access to a specific private thread.
- **Send/reply:** `communications.reply` plus active participation
  plus the thread being `open`.
- **Create:** `communications.send`.
- **Download a conversation attachment:** active participation in the
  parent thread (or `communications.manage`) — re-derived every
  request from `CommunicationAttachmentService::authorizeRead()`,
  never from knowing the attachment id.
- **Archive/unarchive/participant search:** self-scoped, gated by
  genuine active participation (archive) or the `communications.send`
  capability (search) — not `communications.manage`, matching Phase
  5A.5's precedent that an inherently personal, harmless-to-others
  action doesn't need a broader capability gate.

## 12. Privacy

General `communications.view` permission does not by itself grant
access to private conversation content.

No "platform admin reads every conversation" feature was built. Every
thread/message/attachment read re-derives participation or
`communications.manage` on every request — there is no cached or
assumed access. `presentThreadSummary()`'s index payload exposes a
truncated message preview only, never a full body, and never any
participant's `last_read_at` (no read-receipt UI — see §10/§24 below).

## 13. Tenant/RLS protection

`communication_attachments` keeps its existing `TenantRls` policy
(unaffected by the new nullable column). The new composite FK
(`communication_thread_id`, `school_id`) follows the exact same
pattern as the existing announcement one. Raw-SQL proof, extending
`CommunicationAttachmentsRlsIsolationTest.php`: School A cannot
`SELECT` a School B thread-owned attachment; a forged cross-School
`communication_thread_id` is rejected by the composite FK; a row
claiming both or neither pre-message owner is rejected by the new
CHECK constraint. Combined with the pre-existing `communication_threads`/
`communication_thread_participants`/`communication_messages` RLS
(unchanged), School A cannot view/send-into/read a School B thread, add
a School B participant, or alter School B read state.

## 14. Multi-school users

Read state is keyed by `communication_thread_participants.id`, itself
scoped to exactly one School's thread — a User with memberships in two
Schools has two entirely independent participant rows, two independent
`last_read_at` cursors, and reading a thread in School A never touches
School B's state. Proven directly (`ConversationReadStateTest::a_multi_school_user_has_independent_read_state_per_school`).

## 15. Inactive membership behavior

Unchanged from Phase 5A.1: `CommunicationMessageService::send()`
already required an active (`left_at IS NULL`) participant row; this
checkpoint added the parallel `isOpen()` thread-status check alongside
it. A participant who leaves (or whose membership becomes inactive)
cannot send new messages; their historical messages/attachments are
never erased — `removeParticipant()` still only sets `left_at`, never
deletes.

## 16. Channel behavior

New conversation messages default to `IN_APP` only, unchanged.
Conversation EMAIL delivery was **not** activated in this checkpoint —
no UI, no request field, no code path requests it. `COMMUNICATION_EMAIL_ENABLED`
remains `false` by default.

## 17. Policy integration

Not touched. `CommunicationMessageService::send()` still creates
IN_APP deliveries directly via `CommunicationDeliveryFactory`, exactly
as Phase 5A.1 left it — it never consulted Phase 5A.5's optional-
channel policy engine (that machinery is Announcement-EMAIL-specific)
and still doesn't.

## 18. IN_APP canonical semantics

Unchanged: IN_APP delivery is created unconditionally for every active,
non-muted participant on send — thread visibility was never routed
through any optional-channel preference, and this checkpoint didn't
change that.

## 19. UI / read model

`Communications/Index.vue`: participant search-and-select composer
(replacing the raw comma-separated id input), unread bold/dot/badge
per thread, a total-unread nav badge, latest-message preview,
attachment indicator, archived-view toggle, and a search box.
`Communications/Show.vue`: paginated message history ("Load older
messages"), per-message attachment list with authorized download
links, a composer with attach/remove-before-send, and a per-participant
archive/unarchive button.

## 20. Pagination

Both threads (`CommunicationHubController::index()`) and messages
(`::show()`) use Laravel's standard `paginate()` — no custom scroll
architecture. Messages paginate newest-page-first (`orderByDesc('created_at')`,
30 per page) and are reversed for chronological on-page display; "Load
older messages" pages backward from there.

## 21. Performance

`ConversationReadModel` issues a fixed, small number of queries
regardless of thread/participant count: one `DISTINCT ON` query for
latest-message-per-thread (Postgres-specific, consistent with ADR
0024's Postgres-only stance) plus one for read cursors — never one
query per thread. `presentThreadSummary()`'s other-participant names
come from an eager load already on the index query (`participants` +
nested `user`), not a second query per row. `ConversationIndexScaleTest`
proves the index route's TOTAL query count for 15 threads stays well
under a fixed ceiling.

## 22. Real-time decision

No Laravel Broadcasting/Reverb/Pusher/WebSocket/SSE infrastructure
exists anywhere in this codebase, and none was added. Real-time
delivery (live-updating unread badges/new messages without a manual
reload) is explicitly deferred — the standard Inertia request/response/
redirect cycle (a full page reload after every send/archive/upload) is
this checkpoint's whole update mechanism.

## 23. Tests

New/extended files:

- `ConversationReadStateTest.php` (7) — unread-for-never-opened,
  not-unread-for-sender, mark-read-on-open scoped to one participant,
  newer-reply-reopens-unread, bystander-visit-doesn't-mark-read,
  multi-School isolation, bounded total-unread count.
- `ConversationAttachmentTest.php` (11) — upload-then-send links it,
  another participant's pending upload is never silently claimed,
  sent attachments are immutable, uploader-only pending removal, non-
  participant upload/download denial, type/size validation reused
  unchanged, cross-School download denial (404), non-open-thread
  upload rejection.
- `ConversationIndexScaleTest.php` (1) — bounded query count for 15
  threads.
- `CommunicationConversationHttpTest.php` (8) — participant search
  (scoped + capability-gated), archive/unarchive (participant-specific,
  never thread-global), archived-filter behavior, thread-scoped
  attachment HTTP routes, attachment-in-reply round-trip, message
  pagination shape.
- `CommunicationAttachmentsRlsIsolationTest.php` — extended with 3
  thread-ownership-path tests (cross-School SELECT denial, forged
  thread-reference FK rejection, one-parent CHECK rejection).
- `CommunicationHubTest.php` — 2 existing assertions updated for the
  now-paginated `threads` prop shape (`threads.data`, not a bare
  array); no behavior change, same test count.

**Exact totals:** the 53 tests spanning every file touched or added by
this checkpoint (`ConversationReadStateTest`, `ConversationAttachmentTest`,
`ConversationIndexScaleTest`, `CommunicationConversationHttpTest`,
`CommunicationThreadServiceTest`, `CommunicationMessageServiceTest`,
`CommunicationHubTest`, `CommunicationAttachmentsRlsIsolationTest`) —
**53 passed (159 assertions)**. Full Communication Hub suite
(`--filter=Communication`) — **218 passed (653 assertions)**. Full
application regression (`php artisan test`, no filter) — **580 passed
(1773 assertions), 0 failed.**

Quality gates, all clean, no new PHPStan baseline entries (this
codebase has none, and still has none): `vendor/bin/pint --test` (485
files, PASS), `vendor/bin/phpstan analyse --memory-limit=512M` (0
errors — resolving a genuine PHPStan Collection-template-covariance
false positive required introducing `ConversationThreadSummary`, a
named value object, in place of a complex inline array shape, matching
this module's existing `CommunicationDeliveryResult`/`ResolvedAudience`
convention rather than suppressing the warning), `npm run type-check`
(`vue-tsc --noEmit`, clean), `npm run lint` (ESLint, clean), `npm run
format:check` (Prettier, clean after auto-formatting the two touched
Vue files).

## 24. Safety

No real email/SMS/WhatsApp/push transport was exercised (none was
touched at all this checkpoint). No provider credentials, no staging/
production change, no deploy. `main`/`origin/main` untouched; no
Phase 1A commit was merged, rebased onto, or cherry-picked.

## 25. Deferred

Unchanged from Phase 5A.1–5A.6's deferral list, plus this checkpoint's
own: real-time/WebSocket delivery, detailed read receipts ("seen by X
at..."), full message-content search (thread/participant-name search
only), message editing, message deletion/moderation, thread-global
close/archive UI (the underlying `status` enforcement exists; no
endpoint to change it was built), template attachments, rich-text/HTML
content, conversation EMAIL delivery, and every item Phase 5A.6 already
deferred (full Document Management, public sharing, real external
providers, AI features, Student/Guardian/Class-scoped messaging).

## 26. Next recommendation

Phase 5A.8 candidates, in order of recommendation:

1. **Communication Search & Operational Inbox** — the most natural
   next step now that both Announcements and Conversations are fully
   usable surfaces; an operator-facing cross-cutting inbox/search view
   is the remaining visible gap.
2. **Quiet Hours & Delivery Timing Policy** — extends Phase 5A.5's
   policy engine along a new axis.
3. **Approval Workflow Foundation** — now that both major communication
   surfaces carry enough state to be worth a review gate.
4. **Email Provider Event/Webhook Foundation** — deferred until real
   outbound email is closer to being turned on.

This is a recommendation only — Phase 5A.8 has not been started.
