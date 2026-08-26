# Phase 5D.1 — Student / Guardian Conversation Participation & Safeguarding Foundation

## 1. Objective

Extend the existing private Conversation system (Phase 5A.1/5A.7) so
authenticated Student and Guardian domain identities may participate
in private Threads when they have a legitimate, explicit, active
account link (Phase 5B.2) — under strictly narrower authorization than
ordinary staff-to-staff conversations. Account linking establishes
authenticated reachability; it does not by itself authorize private
conversation participation.

## 2. Existing Conversation architecture (as audited)

`CommunicationThread` → `CommunicationThreadParticipant` (keyed by
`(thread_id, user_id)`, `user_id` referencing the central `users`
table) → `CommunicationMessage`. `CommunicationThreadService` is the
sole write path (`createThread()`/`addParticipant()`/
`removeParticipant()`/`markRead()`/`archiveForParticipant()`); every
write validates an **active `SchoolMembership`** exists for the target
`user_id` in the thread's School before creating a participant row —
an id alone is never trusted. `CommunicationMessageService::send()`
re-checks the sender is an active participant of an `open` thread
inside the same transaction as message creation.

`communication_thread_participants`' own creating migration already
called this out explicitly: *"no Guardian/Student identity exists yet
to be a participant instead"* — this checkpoint is exactly that
follow-up.

Private-thread security (Phase 5A.7 §22 pattern, preserved unchanged):
`communications.view` alone does **not** expose an arbitrary thread —
`CommunicationHubController::show()` requires either genuine
participation (`user_id` matches an active participant row) or
`communications.manage`. This invariant is unchanged and untouched by
this checkpoint's extension.

## 3. Account-link identity prerequisite

`StudentGuardianAccountLink` (Phase 5B.2, `app/Domain/Identity/`) is
the one explicit, never-auto-created link between a Student/Guardian
domain identity and an existing `SchoolMembership`. Exactly one of
`student_id`/`guardian_id` per row (database-enforced), at most one
active link per persona and per membership (partial unique indexes).
`AccountLinkService::activeLinkForGuardian()`/`activeLinkForStudent()`
are the sole read paths this checkpoint uses — never a new lookup.

## 4. Student account readiness

**Unchanged honest asymmetry, re-confirmed on current `main`:** no
`student`/`guardian` system role exists in `CapabilityAndRoleSeeder`
(only `platform_super_admin`/`school_admin`/`principal`). A
`SchoolMembership` today is only ever held by an administrative/staff
User. The Student conversation participation path is fully built and
symmetric with Guardian's, but a realistic Student account — a genuine
Student holding their own authenticated login — has no real candidate
until a future Student-portal/account-role checkpoint exists. This
checkpoint does not fabricate one; it is exercised in tests via a
staff `SchoolMembership` explicitly linked as a Student (the same
pattern Phase 5B.2 established for Guardian), and is additionally
policy-disabled by default (§7) so it cannot be reached in production
today even structurally.

## 5. Guardian account readiness

Unchanged from Phase 5B.2: a Guardian may be linked to any existing
`SchoolMembership`, including a staff member's own (the
teacher-who-is-also-a-parent scenario) — today the **only realistic**
way Guardian conversation participation actually activates in
practice, since no dedicated Guardian portal/account-role exists
either. Unlinked Guardian: not eligible.

## 6. Safeguarding policy (the core rule)

A valid `StudentGuardianAccountLink` only proves **authenticated
reachability**. It never, by itself, authorizes a staff member to
start a private conversation with that Student/Guardian. Every
Guardian/Student conversation-participant resolution passes through
one centralized decision path
(`App\Domain\Communications\Application\ConversationParticipantAuthorizationService`)
that enforces, in fixed order:

1. the acting staff member holds the relevant capability
   (`communications.conversations.guardians`/`.students`) — a real
   `AuthorizationException` (403), never a soft UI hint;
2. the School's own conversation policy allows this participant type
   (§7) — independent of the capability grant;
3. the Guardian/Student id actually resolves within the acting School
   (never trusts a client-supplied id — a cross-School id is
   indistinguishable from "does not exist");
4. an active `StudentGuardianAccountLink` exists whose linked
   `SchoolMembership` is itself currently active.

Any failure aborts thread creation entirely — no partial thread is
ever created (all four checks, plus every participant write, run
inside `CommunicationThreadService::createThread()`'s single
transaction).

## 7. School conversation policy

New table `communication_conversation_policies` — one optional
override row per School, mirroring `communication_channel_policies`'
exact "no row = system default" shape
(`CommunicationConversationPolicyService::defaultPolicy()`):

| | System default (no row) |
|---|---|
| Guardian conversations | **allowed** (still capability-gated) |
| Student conversations | **disallowed** |

`SchoolConversationPolicyService::setPolicy()` is the sole write path
(validate → write → audit, `communication.conversation_policy.updated`).
A minimal administrative surface exists
(`CommunicationConversationPolicyController::show()`/`update()`,
`GET`/`PUT /app/communications/settings/conversations`,
`communications.manage`-gated) — **no dedicated settings page UI is
wired yet** (§26, deferred).

## 8. Capabilities

Two new narrow capabilities (never a role-name check):

- `communications.conversations.guardians` — granted by default to
  `school_admin` and `principal` (same operational rationale already
  justifying their `guardians.manage` grant).
- `communications.conversations.students` — granted to **no** system
  role by default (brief's stricter safeguarding instruction). A
  School must explicitly create/extend a role with it, and even then
  the school-level policy toggle (§7) independently defaults Student
  conversations to disabled.

## 9. Participant provenance

`communication_thread_participants` gained three additive columns:
`participant_kind` (`membership`/`guardian`/`student`, CHECK-
constrained), nullable `guardian_id`/`student_id` (composite FKs
against `guardians(id, school_id)`/`students(id, school_id)`, `RESTRICT`
on delete — deliberately **not** `CASCADE` like
`student_guardian_account_links` uses, because this table is historical
Thread participation, not current link state; see the migration's
docblock). A CHECK constraint keeps `participant_kind` from ever
drifting out of sync with which id is set. The authenticated
participant endpoint is unchanged — still `(thread_id, user_id)`;
provenance is optional metadata layered on top, never a second
identity.

## 10. Guardian participation

Composer flow: an authorized actor supplies `guardian_ids[]` on
`POST /app/communications/conversations`.
`CommunicationThreadService::createThread()` resolves each via
`ConversationParticipantAuthorizationService::resolveGuardianParticipant()`,
obtains the linked `SchoolMembership`, and creates a
`CommunicationThreadParticipant` with `participant_kind = 'guardian'`
and `guardian_id` set. A dedicated audit event
(`communication.conversation.guardian_participant_added`) is recorded
in addition to the generic thread-creation audit.

## 11. Student participation

Structurally identical to Guardian (`student_ids[]`,
`resolveStudentParticipant()`, `communication.conversation.student_participant_added`),
gated by the stricter default-disabled school policy (§7) **and** the
narrower capability (§8). Honest per §4: exercisable today only via a
staff membership explicitly linked as a Student — there is no path to
a genuine, independent Student login yet.

## 12. Staff/Guardian dual-role behavior

Verified directly
(`a_staff_membership_linked_as_guardian_can_participate_in_both_a_staff_context_and_a_guardian_context_thread`):
the same underlying `SchoolMembership`/User participates with
`participant_kind = 'membership'` in a thread it was added to directly,
and `participant_kind = 'guardian'` in a thread it was added to via its
Guardian link — two separate `CommunicationThreadParticipant` rows on
two separate threads, correctly distinguished. Staff capabilities
never expose a Guardian thread the membership isn't actually a
participant of.

## 13. Guardian/Student relationship constraints

When one thread composition names both Guardian(s) and Student(s),
`ConversationParticipantAuthorizationService::assertGuardianStudentRelationshipsEligible()`
requires every named Guardian/Student pair to share an eligible
`StudentGuardianRelationship` — the identical `is_primary OR
is_legal_guardian` predicate `GuardianProjectionResolver` (Phase 5B.3)
already established for general-communication audiences.
Emergency-contact-only/pickup-only-only relationships are not
sufficient. An unrelated pair aborts thread creation entirely (same
transaction, no partial thread).

## 14. Thread visibility / privacy

Unchanged from Phase 5A.7/5A.8: a linked account's own login sees only
threads where its own `SchoolMembership` is an actual, active
participant row (`CommunicationHubController::conversations()`'s
`whereHas('participants', ...)` scope). No new broad "every thread
targeting my linked child" lookup was introduced — verified directly
in `CommunicationConversationParticipantHubTest`.

## 15. Search privacy

Two new server-side search endpoints
(`GET /app/communications/participants/search/guardians`/`/students`)
mirror `CommunicationHubController::searchParticipants()`'s existing
shape exactly: same-School, bounded (`limit(20)`), minimal safe fields
(id + display name) only. Each is gated by its own
`communications.conversations.guardians`/`.students` capability — not
`communications.send` alone. Search deliberately does **not**
pre-filter by account-link/eligibility (a School staff member can
search broadly); eligibility is authoritatively re-verified at
thread-creation time regardless of what search returns.

## 16. Attachments

Untouched. Attachment authorization (`CommunicationAttachmentService`,
Phase 5A.6/5A.7) is keyed off actual thread participation exactly as
before — a Guardian/Student-provenance participant is just another
active `CommunicationThreadParticipant`, so no attachment-layer change
was needed or made.

## 17. Read/unread

Untouched. `CommunicationThreadParticipant.last_read_at` (Phase 5A.7)
is reused as-is — no `guardian_message_reads`/`student_message_reads`
table was created. A linked Guardian/Student participant's own login
moves the same read cursor any other participant would.

## 18. Archive

Untouched. Per-participant archive (`archived` boolean on
`CommunicationThreadParticipant`) needs no Guardian/Student-specific
state — domain provenance does not change archive semantics.

## 19. Audit

Two new security-sensitive audit event types, written by
`CommunicationThreadService::addParticipant()` when `$kind` is not
`Membership`: `communication.conversation.guardian_participant_added`
/ `.student_participant_added`. Metadata: `threadId`, `userId`
(the authenticated `SchoolMembership`'s User), `domainParticipantType`,
`guardianId`/`studentId` — never message body, never any other
Sensitive field. `communication.conversation_policy.updated` audits
School policy changes (§7). All go through the existing
`AuditRecorder::school()` — no parallel audit mechanism.

## 20. RLS / security

Both new/altered structures use `App\Support\Tenancy\TenantRls`
exactly like every other tenant-owned table:
`communication_conversation_policies` (new table, `TenantRls::enable()`)
and `communication_thread_participants`' new columns (existing table,
RLS already enabled — only the composite FKs/CHECK constraints are
new). Raw-SQL proof:

- `CommunicationConversationPoliciesRlsIsolationTest` — RLS
  enabled/forced, no-context zero rows, cross-School read/write
  denial.
- `CommunicationThreadParticipantProvenanceIsolationTest` — composite
  FK rejects a cross-School Guardian/Student reference at raw INSERT;
  CHECK constraints reject `participant_kind`/id inconsistency and an
  unrecognized kind value.

Cross-School HTTP forgery (a foreign Guardian/Student/AccountLink id)
is verified to fail safely (a clean 422 validation error, no foreign
identity leakage) in
`CommunicationConversationParticipantHubTest::a_cross_school_guardian_id_is_denied`.

## 21. Multi-School behavior

`ConversationParticipantAuthorizationService` resolves the Guardian/
Student strictly under the acting School (`TenantContext::withSchool()`,
self-contained — never assumes a caller's ambient context already
matches). A `StudentGuardianAccountLink` from School B can never
satisfy a School A conversation — School-scoped `SchoolScope` filtering
means a cross-School id simply resolves to nothing
(`ConversationTargetNotFoundException`).

## 22. Membership/account-link lifecycle

- **Account link removed after participation:** the
  `CommunicationThreadParticipant` row and full thread history remain
  untouched — unlinking never retroactively deletes anything (matches
  Phase 5B.2's own §42 semantics exactly; this checkpoint adds no new
  behavior here, only confirms it still holds).
- **Linked membership later deactivated:** future access/send follows
  the existing inactive-membership rules already enforced by
  `CommunicationThreadService::addParticipant()`/
  `CommunicationMessageService::send()` — no reactivation, thread
  remains auditable.

## 23. Performance

Guardian/Student resolution during thread creation is per-named-target
(never per-audience-scale — a Thread's explicit, small participant
list is a fundamentally different shape from an Announcement's
computed audience, so the existing audience-resolver batching
discipline doesn't apply here). The relationship-eligibility check
(§13) is one batched query regardless of how many Guardian/Student ids
are named together, never one query per pair.

## 24. Tests

New (Phase 5D.1), all passing:

- `ConversationParticipantAuthorizationServiceTest` (13) — capability,
  school policy, account-link, cross-School, relationship-eligibility
  matrix.
- `CommunicationThreadServiceGuardianStudentParticipationTest` (8) —
  provenance persistence, transactional all-or-nothing rejection,
  deduplication, dual-role, Guardian+Student composition, dedicated
  audit event.
- `CommunicationConversationParticipantHubTest` (13) — full HTTP
  surface: success/denial paths, search endpoints, privacy.
- `SchoolConversationPolicyServiceTest` (3) — default/override/update.
- `CommunicationConversationPoliciesRlsIsolationTest` (4) and
  `CommunicationThreadParticipantProvenanceIsolationTest` (5) — raw-SQL
  RLS/constraint proof.

**46 new tests, 0 regressions.** Full suite (Communications + Phase 5A/
5B/Identity/RLS/security/attachments/whole application): **2489 tests,
8570 assertions, 3 errors + 2 failures — all 5 in
`Documents\*MinioIntegrationTest`, a pre-existing real-MinIO-connectivity
environment issue in this sandbox, verified reproducible in isolation
and entirely unrelated to (and untouched by) this checkpoint.** Prior
verified baseline (this checkpoint's own `main`, before any 5D.1
changes): 2443 tests / 8525 assertions / 0 failures — the same 46-test
delta accounts for the full difference.

## 25. Safety

`COMMUNICATION_EMAIL_ENABLED=false` unchanged. No live external sends,
no SMTP/provider activation, no SMS/WhatsApp/Push, no deploys, no
staging/production changes. All work stayed on the isolated
`school_os_test` database (`platform:test-db-reset --force`) — the
shared `school_os` development database was never migrated with any
5D.1 schema change.

## 26. Deferred / out of scope (per brief §57, confirmed not implemented)

Automatic account provisioning, invitation emails, Guardian/Student
portals, guardian-to-guardian or student-to-student chat, unrestricted
private Student messaging, real-time WebSockets/typing indicators,
message edit/delete, conversation email forwarding, SMS/WhatsApp/Push
chat, provider integrations, AI moderation, automated safeguarding
analysis, user blocking/reporting. Additionally, and specific to this
checkpoint's own scope cut: **no Vue composer UI was wired up** for the
new Guardian/Student participant pickers or the conversation-policy
settings page — the backend endpoints, authorization, and safeguarding
foundation are complete and tested, but selecting a Guardian/Student
target when composing a Thread, and administering the School policy
toggle, both currently require a direct API call rather than a form.

## 27. Next recommendation

Wire the Guardian/Student picker into the existing Communication Hub
compose UI and the conversation-policy toggle into the existing
Channels settings page — this is pure frontend work against an already
complete, tested backend, and is the natural, low-risk next increment
before broadening scope further (Phase 5D.2 preferences/consent, or an
account-invitation/activation checkpoint). Both of those larger
checkpoints depend on this foundation being real and exercised through
an actual UI first.
