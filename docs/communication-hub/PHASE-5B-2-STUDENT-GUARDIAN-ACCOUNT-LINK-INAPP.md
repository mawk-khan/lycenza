# Phase 5B.2 — Student & Guardian Account-Link & IN_APP Identity Foundation

## 1. Objective

Establish a safe, explicit, School-scoped way to answer "does this
Student or Guardian correspond to an authenticated School OS
SchoolMembership in this School?" — and use that link, when it exists
and is active, to make IN_APP Announcement delivery reachable, without
ever auto-creating an account.

## 2. Identity architecture audit (as found)

- `User` (central): "authentication identity only... future domain
  records (Employee, Teacher, Guardian, Student) link to a User when
  they need login, they don't extend it" (the model's own docblock).
- `SchoolMembership` (central, no RLS by design — "which Schools does
  this user belong to" must be answerable before entering tenant
  context): `user_id` + `school_id` + `status`
  (`invited`/`active`/`suspended`), `active()` scope already the
  canonical eligibility predicate. Already carries
  `unique(id, school_id)` specifically "to enable composite FKs from
  tenant-owned children" — exactly what this checkpoint needed, no
  migration required.
- Roles: only `platform_super_admin`, `school_admin`, `principal` exist
  today. **No `teacher`/`staff`/`student`/`guardian` role exists.**
  `guardians.manage`'s own seeded label ("Manage Guardians, Guardian
  contact information, and Guardian links") already anticipated a
  "links" concept.
- No prior Employee/Teacher/account-link precedent exists anywhere in
  the codebase — this checkpoint establishes the first one.

## 3. Student/Guardian vs authenticated identity

Unchanged from Phase 5B.1's non-negotiable rule, restated: **the
existence of a Student or Guardian domain record does not create or
imply a User or SchoolMembership.** Phase 5B.2 adds an *optional,
explicit* bridge on top — it never weakens that separation.

## 4. Account-link ownership

**Identity owns the link; Communications only reads it.**
`App\Domain\Identity\Application\AccountLinkService` is the sole write
path (new `App\Domain\Identity` module — DOMAIN-MAP.md's
"Identity & Access" Layer 1 concept, its first real code). It never
uses `communications.manage` for authorization — the HTTP layer reuses
the existing `students.manage`/`guardians.manage` capabilities (no new
capability keys), matching the brief's "Phase 1A/identity
administration owns the link" guidance.
`App\Domain\Communications\Application\AnnouncementService` only calls
`AccountLinkService::activeLinksForGuardians()`/`activeLinksForStudents()`
— read-only, batched, never creates/mutates a link.

## 5. Schema

`student_guardian_account_links` (new, RLS-enabled): one unified table
for both persona types (see §7's tradeoff), `status`
(`active`/`revoked`, current operational state — mirrors
`school_memberships.status`, never a hard delete), nullable
`student_id`/`guardian_id` (`num_nonnulls = 1`), NOT NULL
`school_membership_id`, all three composite-FK'd against
`(id, school_id)` on their respective parent tables. Three partial
unique indexes:

- `sgal_one_active_per_student` / `sgal_one_active_per_guardian` — one
  domain identity, at most one active link (brief §9).
- `sgal_one_active_per_membership` — one membership, at most one
  active persona link, covering **both** "already linked to a
  different Guardian" and "already linked as a Student" with a single
  index, since both persona types share this one table (brief §10).

`communication_delivery_policy_decisions` gained a third nullable
`recipient_student_id` column (parallel to Phase 5B.1's
`recipient_guardian_id`) — needed once a linked Student's IN_APP
suppression has a party to record itself against.

## 6. SchoolMembership linkage

A link always points at a real `SchoolMembership`, re-verified
same-School at link time (`CrossSchoolMembershipLinkException` if not
— root CLAUDE.md rule 19, "a membership id alone is not
authorization"). The membership's `active` status is **not** required
at link time (an `invited` membership may be linked in advance); it is
re-checked at every publish (§13/§21).

## 7. Staff/Guardian dual-role behavior

Verified directly
(`a_staff_membership_can_be_linked_as_a_guardian_without_losing_its_staff_role`):
linking a `principal`-role membership as a Guardian leaves its own
role/capability grants completely untouched — the link table is a
layer on top of a membership's identity, never a replacement for its
role assignments. This is also, today, the **only realistic** way
IN_APP reachability actually activates in practice (§8).

## 8. Student account-role readiness

**Honest asymmetry, as instructed:** no `student`/`guardian` role
exists, so a `SchoolMembership` today is only ever held by
administrative/staff users. The schema and
`AccountLinkService`/`StudentAccountLinkController` are fully built and
symmetric for Student, but a *realistic* Student link — a genuine
Student holding their own authenticated SchoolMembership — has no real
candidate accounts until a future Student-portal/account-role
checkpoint exists. This checkpoint does not fabricate one.

## 9. Guardian account linking

`GuardianAccountLinkController` (search/store/destroy,
`App\Http\Controllers\App` — matching Phase 1A's own admin-UI
convention, not `App\Domain\Communications`) + a Guardian/Student
detail-page UI section (`Link account` / `Unlink`, search-then-pick,
mirroring the Communication composer's own search-picker pattern from
Phase 5B.1). Search never returns cross-School data and excludes
already-linked memberships (brief §31).

## 10. Explicit-link requirement

No auto-linking by email/name/contact match anywhere. The search
endpoint helps an admin *find* a candidate; only an explicit
`POST .../account-link` call by an authorized actor creates a row.

## 11. No automatic provisioning

Confirmed: `AccountLinkService` never calls `User::create()` or
`SchoolMembership::create()` — both must already exist. No password,
no invitation, no login credential is ever generated by this
checkpoint.

## 12. Link/unlink lifecycle

`link()`/`unlink()` are both `DB::transaction()`-wrapped, audited
(`AuditRecorder::school()`), and idempotent-by-construction (root
CLAUDE.md rule 30): the partial unique indexes are the authoritative
concurrency guard, a `UniqueConstraintViolationException` race is
translated to the matching domain exception, never a check-then-insert
alone. Unlink sets `status = 'revoked'` — the Student/Guardian/User/
SchoolMembership rows and all historical communication are always
preserved; only *future* reachability changes.

## 13. IN_APP reachability

`AnnouncementService::deliverInAppForLinkedDomainParty()` (new, shared
by the Student and Guardian delivery paths): no link, or a link to a
currently-inactive membership, both resolve to `recipient_ineligible`
— identical to a departed member's own suppression. Once resolved to a
real active membership, this reuses the **exact same primitives** the
membership loop already uses —
`CommunicationChannelPolicyService::evaluate()` (never
`evaluateForDomainParty()`) and
`CommunicationDeliveryFactory::createRecipient()` keyed to the linked
membership's `user_id` — so the resulting delivery is, from the
pipeline's perspective, indistinguishable from an ordinary member's.
No second IN_APP delivery table was created (brief §20).

## 14. Logical recipient vs delivery endpoint

Preserved exactly as Phase 5B.1 established: the immutable
`communication_announcement_recipients` snapshot row still records
`guardian_id`/`student_id` — never the linked User. Only the
*delivery* (`communication_recipients.recipient_user_id`) additionally
identifies the account endpoint actually used.

## 15. Deduplication

Structurally, one `SchoolMembership` can have at most one active
persona link (§5's `sgal_one_active_per_membership`), and one
Announcement has exactly one `audience_type` — so the brief's
"direct-membership-selection + linked-Guardian-to-the-same-membership"
double-delivery scenario cannot occur within a single Announcement
today. Defense-in-depth was still added: `createRecipient()` is now
idempotent (`(message_id, recipient_user_id)` unique-constraint catch,
matching `createDelivery()`'s existing pattern exactly) — proven
directly by
`creating_the_same_user_recipient_twice_for_one_message_never_duplicates`.

## 16. Preferences

Unchanged split, extended consistently:

- Direct SchoolMembership recipient (individual/school-wide) →
  existing `CommunicationPreference` semantics, untouched.
- Guardian EMAIL via GuardianContact → Phase 5B.1's
  `evaluateForDomainParty()`, still never consults any membership
  preference.
- Guardian/Student IN_APP via a link → the real `evaluate()` call with
  the linked membership id — canonical, immediate, cannot be opted
  out, exactly like any other member's IN_APP (brief §27's explicit
  choice).

## 17. Guardian EMAIL independence

Confirmed unchanged: EMAIL destination resolution is still
`GuardianEmailAddressResolver` over `GuardianContact` only. A linked
User's own `email` column is never consulted for Guardian EMAIL
delivery — account linking and external contact policy remain fully
separate concerns.

## 18. Approval semantics

Link state is **not** part of `CommunicationApprovalFingerprint`
(unchanged from Phase 5B.1's Student/Guardian audience-id fields).
Linking or unlinking a Guardian/Student never invalidates an existing
approval — proven directly
(`linking_or_unlinking_a_guardian_never_invalidates_an_existing_approval`).
Reachability is evaluated fresh at publish time regardless of when the
link changed relative to approval (§19/§41 both proven).

## 19. Audit

`student.account_linked`/`student.account_unlinked`/
`guardian.account_linked`/`guardian.account_unlinked` via
`AuditRecorder::school()`, metadata limited to domain entity id,
SchoolMembership id, actor, timestamp — proven to never contain
password/token-shaped values.

## 20. RLS/security

`student_guardian_account_links` is RLS-enabled and forced. 8 RLS
tests: enabled/forced, zero-rows-with-no-context, cross-School SELECT/
INSERT/UPDATE/DELETE rejection, cross-School membership composite-FK
rejection, and the `sgal_one_active_per_membership` concurrency
guarantee. HTTP-level cross-School forgery is separately rejected with
a clean validation error (never a raw exception, never revealing
foreign-membership existence).

## 21. Multi-school behavior

Proven directly
(`a_multi_school_users_membership_in_school_b_does_not_satisfy_a_school_a_link`):
the same central `User` holding memberships in two Schools cannot have
School B's membership satisfy a School A link request — School
scoping is enforced on the *membership*, not the User.

## 22. Historical immutability

Proven for both directions: unlinking after publication never removes
the historical delivery/recipient row (§18's test), and linking after
drafting-but-before-publication correctly changes reachability *only*
at the next publish (§41's two tests, both directions).

## 23. Inbox/read state

A real gap was found and fixed during this checkpoint (see next
section) — `markRead()` itself needed no change (it already keys on
`recipient_user_id`, which a linked delivery populates correctly), but
`AnnouncementController::show()`'s `isRecipient` check and
`CommunicationInboxReadModel`'s three internal visibility queries all
filtered candidate announcements through
`communication_announcement_recipients.user_id` alone — which is
always `NULL` for a Guardian/Student-audience snapshot row. Both were
widened with an additive `OR EXISTS via communication_recipients.
recipient_user_id` check (`AnnouncementController`'s inline fix;
`CommunicationInboxReadModel::applyRecipientVisibility()`, a new
shared private helper used by `announcementItems()`,
`searchAnnouncements()`, and `unreadAnnouncementCount()`). No new
Guardian-specific Inbox table was created.

## 24. Performance

Both `AccountLinkService::activeLinksForGuardians()`/
`activeLinksForStudents()` and the delivery loop are single batched
queries per audience chunk — proven by
`batch_link_lookup_for_many_guardians_uses_a_bounded_query_count` (25
Guardians, under 5 queries) and
`publishing_in_app_to_many_linked_guardians_uses_a_bounded_query_count`
(20 linked Guardians, bounded well below what a per-Guardian N+1 on
link resolution would cost).

## 25. Tests

**42 new tests, all passing** (full suite: 1085 tests, 3242 assertions,
0 failures):

- `AccountLinkServiceTest` (13) — link/unlink lifecycle, persona/
  membership conflict, cross-School rejection, staff dual-role,
  multi-School User, audit, performance.
- `StudentGuardianAccountLinksRlsIsolationTest` (8) — RLS + composite-
  FK + concurrency-constraint proofs.
- `StudentGuardianAccountLinkInAppTest` (13) — publish-time IN_APP
  reachability (linked/unlinked/inactive-membership, Student and
  Guardian), link-change-before/after-publication, read/unread, Inbox
  visibility, approval-fingerprint independence, dedup, performance.
- `AccountLinkHubTest` (8) — HTTP admin-UI flow, search authorization/
  isolation/exclusion, cross-School forgery, capability denial.

### A gap this checkpoint caught (§23 above)

Both `AnnouncementController::show()`'s recipient-detection check and
three queries inside `CommunicationInboxReadModel` assumed
`communication_announcement_recipients.user_id` was the only way to
recognize "this actor is a genuine recipient" — true before Phase
5B.1, silently wrong once a Guardian/Student-audience snapshot row
(which never sets `user_id`) became possible. Caught immediately by
this checkpoint's own read/unread/Inbox tests (403 and "item not
found" failures) before any manual QA would have.

## 26. Safety

- `COMMUNICATION_EMAIL_ENABLED=false` unchanged.
- All email-adjacent tests use `Mail::fake()` (Guardian EMAIL path
  untouched by this checkpoint).
- No SMS/WhatsApp/Push code.
- No staging/production system touched.

## 27. Deferred (explicitly out of scope)

- Automatic User/SchoolMembership creation, password provisioning,
  Guardian/Student invitation systems.
- Guardian portal, Student portal, personal preference portal — no
  login flow, no self-service UI beyond the admin link/unlink actions
  built here.
- Account recovery, SSO.
- Private Guardian/Student conversation participation — thread
  participants remain `SchoolMembership`-based only; account linking
  unlocks Announcement IN_APP reachability, nothing else.
- SMS/WhatsApp/Push, provider webhooks, Class/Section/Grade
  audiences, AI.
- Automatic email-based account matching/linking — search assists a
  human; nothing auto-links.

## 28. Next recommendation

See the final report's §V.
