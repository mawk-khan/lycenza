# Phase 5B.1 — Student & Guardian Communication Audience / Reachability Foundation

## 1. Objective

Extend the unified Communication Hub (Phase 5A, integrated into `main`)
so Student and Guardian domain audiences can participate as
Announcement recipients — without inventing an implicit Student/User
or Guardian/User identity link, and without pretending a domain party
is reachable on a channel it genuinely has no destination for.

## 2. Unified Phase 1A / 5A architecture (as found)

- `Student`, `Guardian` (Phase 1A): permanent, School-scoped domain
  identities. Neither carries a `user_id` column. Neither has any
  relationship to `SchoolMembership`.
- `StudentGuardianRelationship`: the join, carrying `is_primary`,
  `is_legal_guardian`, `is_emergency_contact`, `is_authorized_pickup`
  per (Student, Guardian) pair.
- `GuardianContact`: a Guardian's contact points (`email`/`mobile`),
  encrypted at rest (`encrypted_value`, Laravel `encrypted` cast) plus
  a `lookup_hash` for exact-match search. `is_primary`/`is_active`/
  `verified_at` already exist.
- `CommunicationRecipient` (Phase 5A.1): the message-level logical
  recipient, previously hard-keyed to `recipient_user_id` (NOT NULL,
  FK to `users`).
- `CommunicationAnnouncementRecipient` (Phase 5A.2): the immutable,
  append-only resolved-audience snapshot, previously hard-keyed to
  `user_id`/`school_membership_id` (both NOT NULL).
- `CommunicationDeliveryPolicyDecision` (Phase 5A.5): the append-only
  suppression ledger, previously hard-keyed to `recipient_user_id`
  (NOT NULL).
- `CommunicationChannelPolicyService::evaluate()`: the one
  authoritative "should a delivery be planned" decision point —
  already branches on a `?string $schoolMembershipId`, but its very
  first check (`$schoolMembershipId === null` → suppress) applied
  universally to every channel, not only IN_APP (see §"A production
  bug this checkpoint caught").

## 3. Student identity — confirmed

`Student` has `status` (`active`/`inactive`, no DB CHECK, service-layer
validated) and no other lifecycle/contact fields. There is no
canonical Student email/phone endpoint anywhere in the codebase.

## 4. Guardian identity — confirmed

`Guardian` has `status` and `contacts()` (`HasMany<GuardianContact>`).
`GuardianContactService` is the sole write path — normalization,
encryption, and `lookup_hash` computation happen exactly once there.

## 5. No implicit User/SchoolMembership mapping

**Neither Student nor Guardian implicitly becomes a User or
SchoolMembership identity merely because it is now a communication
audience target.** No `Student.user_id`/`Guardian.user_id` column was
added. No `SchoolMembership` row is ever fabricated for a Student or
Guardian. This is the load-bearing constraint the entire design below
respects.

## 6. Recipient / reachability abstraction

Two separate concepts, deliberately kept separate:

- **Logical audience membership** — "this Announcement targets this
  Student/Guardian" — represented by the widened
  `communication_announcement_recipients` snapshot. Always recorded,
  regardless of reachability.
- **Channel reachability** — "through which channel is this party
  currently reachable" — decided at publish time by
  `CommunicationChannelPolicyService::evaluateForDomainParty()` (new)
  plus, for EMAIL, `GuardianEmailAddressResolver` (new). A valid
  logical recipient may have zero reachable channels.

No new named abstraction (`CommunicationReachableParty`, etc.) was
introduced as a class — the distinction is expressed directly through
`ResolvedAudience`'s three id arrays (`userIds`/`guardianIds`/
`studentIds`) and the widened snapshot/recipient tables' nullable typed
columns. A dedicated reachability-summary class was judged unnecessary
for this foundation; `AnnouncementController::domainAudiencePreview()`
computes the summary inline, mirroring `emailPreview()`'s existing
shape.

## 7. Schema changes

All additive, all backward-compatible (root CLAUDE.md rule 6):

| Table | Change |
| --- | --- |
| `communication_announcements` | `audience_type` CHECK widened: `+student, +guardian, +guardians_of_students` |
| `communication_announcement_domain_audience_members` (new) | The authored Student/Guardian selection. Nullable `student_id`/`guardian_id`, composite FKs to `students(id, school_id)`/`guardians(id, school_id)`, `CHECK (num_nonnulls(student_id, guardian_id) = 1)`, RLS-enabled |
| `communication_announcement_recipients` | `user_id`/`school_membership_id` now nullable; new nullable `student_id`/`guardian_id` + composite FKs; `CHECK (num_nonnulls(user_id, student_id, guardian_id) = 1)`; two new unique indexes |
| `communication_recipients` | `recipient_user_id` now nullable; new nullable `recipient_guardian_id` + composite FK; `CHECK (num_nonnulls(...) = 1)`; new unique index. **No `recipient_student_id`** — a Student never gets a delivery row (§9) |
| `communication_delivery_policy_decisions` | Same shape as above for `recipient_guardian_id`; `reason` CHECK widened with `recipient_destination_unavailable` |

**Chosen tradeoff (brief §5):** explicit nullable typed FK columns per
party type, never a single generic polymorphic `(target_type,
target_id)` column. A polymorphic column cannot carry a composite
`(id, school_id)` foreign key against more than one concrete parent
table, which would have weakened the cross-tenant integrity guarantee
every other child table in this domain relies on (root CLAUDE.md rule
70). Two/three extra nullable columns plus a `num_nonnulls` CHECK cost
almost nothing and preserve full FK-level tenant safety.

## 8. Logical recipient semantics

Every resolver produces a `ResolvedAudience` with (at most) one of
`userIds`/`guardianIds`/`studentIds` populated. `publish()` snapshots
**all** resolved parties into `communication_announcement_recipients`
unconditionally — a Student/Guardian with zero reachable channels is
still recorded (brief §34, "never silently dropped").

## 9. Student audience (`audience_type = student`)

`StudentAudienceResolver` resolves the authored `student_id` rows,
re-validated same-school + `status = 'active'` at resolve time
(mirroring `IndividualMembersAudienceResolver`'s re-validation
discipline). Snapshot-only — **zero delivery rows are ever created for
a Student**, on any channel, because no canonical Student contact
endpoint exists (§3). This is intentional and permanent for this
checkpoint, not a bug: "Student audience resolution may still become a
valid logical audience while external delivery remains unavailable"
(brief §7).

## 10. Guardian audience (`audience_type = guardian`)

`GuardianAudienceResolver` resolves authored `guardian_id` rows, same
re-validation. IN_APP is always unavailable (§14). EMAIL is planned
when a canonical destination resolves (§12).

## 11. Student → Guardian audience decision

**Implemented** (`audience_type = guardians_of_students`), not
deferred — Phase 1A's `StudentGuardianRelationship` flags were judged
sufficiently unambiguous. Relationship-eligibility rule (documented,
deliberately narrow, brief §13's "if ambiguous, defer" did not apply
here because a clear line exists):

> A relationship is eligible for general school communication only
> when `is_primary = true` OR `is_legal_guardian = true`.
> `is_emergency_contact`-only and `is_authorized_pickup`-only
> relationships are excluded — those flags authorize contact in an
> emergency/pickup context specifically, not routine school
> communication.

`GuardiansOfStudentsAudienceResolver` takes authored `student_id` rows
as input, joins through eligible relationships, and `distinct()`s on
`guardian_id` — a Guardian shared by two selected Students collapses
to one logical recipient (proven by
`a_guardian_shared_by_two_selected_students_collapses_to_one_logical_recipient`).

## 12. GuardianContact resolution (destination selection)

`GuardianEmailAddressResolver` (new, the Guardian-side counterpart to
`EmailAddressResolver`) selects deterministically:

1. the active, primary email contact, if one exists;
2. otherwise, the **oldest** active email contact (`created_at ASC, id
   ASC` tie-break).

**Documented limitation:** a Guardian with several active, non-primary
email contacts always resolves to the same (oldest) one — this
codebase never sends one logical delivery to multiple destinations. A
School/Guardian wanting a different address delivered marks it
primary via the existing `GuardianContactService::setPrimary()`.

## 13. Encryption / privacy boundary

`GuardianContact.encrypted_value` is decrypted **only** inside
`GuardianEmailAddressResolver::resolve()`, for the duration of one
call, and the plain string is placed directly into a delivery's
`destination_snapshot` (the same operational-destination shape Phase
5A already established for Users) — never returned from a read model,
never logged, never placed in an audit-event `metadata` array, never
hashed into an approval fingerprint. Proven by
`no_plaintext_contact_value_ever_leaks_into_the_delivery_policy_decision_or_recipient_snapshot`
and `the_guardian_search_response_never_includes_a_contact_value`.

## 14. IN_APP eligibility

A Guardian/Student has no application account, therefore no in-app
inbox — **never fabricated**. `CommunicationChannelPolicyService::
evaluateForDomainParty()` (new) unconditionally suppresses IN_APP for
any domain party with reason `recipient_ineligible` (the same reason
code an inactive/departed SchoolMembership recipient already gets —
semantically consistent: "not a currently reachable account-based
recipient").

## 15. EMAIL eligibility

Guardian EMAIL is planned when: the requested channel passes School
channel policy (`evaluateForDomainParty()`) **and** a canonical
GuardianContact email resolves. Student EMAIL was never implemented —
no canonical endpoint exists (§9). `COMMUNICATION_EMAIL_ENABLED=false`
remains the default; nothing in this checkpoint changes that.

## 16. Preferences behavior

A Guardian/Student has no `SchoolMembership`, therefore no
`CommunicationPreference` row can exist for it. `evaluateForDomainParty()`
never consults the preference cache at all (unlike `evaluate()`, which
does for a real membership) — there is nothing to consult, not
"nothing configured yet." School channel policy and the
communication's own `requirement` are the only two inputs that decide
EMAIL eligibility for a domain party.

## 17. Channel policy integration

Both `evaluate()` (membership path) and `evaluateForDomainParty()`
(domain-party path) call the SAME private `policyFor()` — one School
channel-policy row per (School, channel), loaded once. No second
policy engine was added (brief §20's explicit prohibition honored).

## 18. Quiet hours / emergency integration

Guardian EMAIL deliveries reuse
`CommunicationDeliveryTimingPolicyService`'s `$timingDecisions`
computed ONCE per `publish()` call (same array the membership loop
already built) — no second timing evaluation. Emergency bypass applies
identically. Proven by
`guardian_email_respects_quiet_hours_deferral_exactly_like_a_membership_recipient`
and `guardian_email_emergency_bypasses_quiet_hours`.

## 19. Approval fingerprint integration

`CommunicationApprovalFingerprint::snapshot()` gained
`domainAudienceIds` (canonicalized: sorted, deduplicated Student ids
for `student`/`guardians_of_students`, Guardian ids for `guardian`).
Changing the selection after approval invalidates it — exactly the
`individualMemberIds` precedent.

**Documented semantics (brief §44):** approval binds to **who** is
targeted, not to where a message happens to be deliverable right now.
Editing a Guardian's contact email after approval does **not**
invalidate it — contact-destination resolution is a publish-time
concern, never part of the approval content. Proven by
`changing_a_guardians_contact_email_after_approval_does_not_invalidate_it`.

## 20. Recipient snapshots

`communication_announcement_recipients` now interprets a row via
exactly one of `user_id`/`student_id`/`guardian_id` — historical rows
are untouched (all three pre-5B.1 columns stay `user_id`/
`school_membership_id` populated, `student_id`/`guardian_id` null).

## 21. Historical immutability

Proven directly: `editing_a_guardian_contact_after_publish_does_not_change_the_historical_destination_snapshot`
and `deactivating_a_guardian_after_publish_does_not_remove_it_from_the_historical_snapshot`
— a delivery's `destination_snapshot` and a recipient's snapshot row
are both write-once at publish time, never re-derived from current
Guardian/GuardianContact state.

## 22. RLS / tenant security

`communication_announcement_domain_audience_members` is RLS-enabled
(`TenantRls::enable()`). Every new composite FK
(`student_id`/`guardian_id` against `students`/`guardians`) rejects a
cross-School reference at INSERT time, independent of RLS. Proven in
`CommunicationAnnouncementDomainAudienceMembersRlsIsolationTest` (9
tests): RLS-enabled/forced, zero-rows-with-no-context, cross-School
SELECT/INSERT rejection, `num_nonnulls` CHECK rejection (both "names
both" and "names neither"), and cross-School FK rejection on the two
widened tables.

## 23. Performance

`GuardiansOfStudentsAudienceResolver`/`StudentAudienceResolver`/
`GuardianAudienceResolver` each resolve with a single joined query —
no per-Student/per-Guardian query loop.
`snapshotAndDeliverGuardianRecipients()` batch-loads all Guardians in
one query before its per-recipient decision loop (matching the
existing membership loop's own batching discipline). Proven by
`resolving_guardians_of_students_for_a_large_audience_uses_a_bounded_query_count`
(30 Students/Guardians/relationships, asserted under 10 queries).

## 24. Tests

**49 new tests, all passing** (full suite: 1043 tests, 3127
assertions, 0 failures):

- `StudentGuardianAudienceServiceTest` (30) — resolution, deduplication,
  cross-School rejection, IN_APP/EMAIL semantics, quiet hours,
  emergency bypass, partial reachability, snapshot immutability,
  approval fingerprint, scale.
- `CommunicationAnnouncementDomainAudienceMembersRlsIsolationTest` (9)
  — RLS + composite-FK + CHECK-constraint proofs.
- `CommunicationDomainAudienceHubTest` (10) — HTTP composer flow,
  reachability preview, search-endpoint authorization/isolation/privacy.

### A production bug this checkpoint caught

`CommunicationChannelPolicyService::evaluate()`'s existing null-
membership short-circuit (`if ($schoolMembershipId === null) return
suppress(RecipientIneligible);`) was written for Phase 5A's own
"member became inactive between resolve and publish" case, and applies
to **every** channel unconditionally — not only IN_APP. Naively
reusing `evaluate($school, null, $channel, $requirement)` for a
Guardian's EMAIL evaluation therefore suppressed EMAIL entirely,
regardless of school policy or contact availability. Caught by the
very first EMAIL-delivery test run (`recipient_ineligible` instead of
a real delivery/`recipient_destination_unavailable`). Fixed by adding
`evaluateForDomainParty()` — the same policy engine, a second entry
point for a party with no `SchoolMembership` at all, documented in
§17 above.

## 25. Safety

- `COMMUNICATION_EMAIL_ENABLED=false` unchanged.
- All email tests use `Mail::fake()`; no provider credentials
  anywhere in this checkpoint.
- No SMS/WhatsApp/Push code added.
- No staging/production system touched.

## 26. Deferred (explicitly out of scope)

- **Student/Guardian account linking** (`StudentUserLink`/
  `GuardianUserLink`) — no portal login, no `SchoolMembership` was
  created or implied. IN_APP for a Student/Guardian remains
  unavailable until a real account-link foundation exists.
- **Guardian/Student portal** — no login, no preferences UI, no
  parent-facing surface of any kind.
- **Private conversation participation** — `communication_recipients`
  now structurally supports a Guardian party (for Announcement EMAIL
  delivery only), but no thread/conversation code path creates a
  Guardian participant; `CommunicationThreadParticipant` stays
  `SchoolMembership`-based, untouched.
- **SMS/WhatsApp/Push for Guardian** — only EMAIL was implemented;
  the same `evaluateForDomainParty()`/snapshot pattern generalizes
  when a real provider channel exists.
- **Class/Section/Grade audiences** — not part of this checkpoint.

## 27. Next recommendation

See the final report's §V for the evaluated options and the
recommendation. Not started in this checkpoint.
