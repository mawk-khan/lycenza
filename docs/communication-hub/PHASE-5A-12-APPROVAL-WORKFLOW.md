# Phase 5A.12 — Approval Workflow Foundation

## 1. Objective

Introduce a controlled, opt-in approval workflow for Announcements that
must be reviewed before publication, bound to the **exact reviewed
content** — never a bare `approved = true` flag that would silently
authorize whatever the Announcement happens to contain later.

```
Draft -> Submit for Approval -> Pending Approval -> Approved -> Publish
                                        \-> Rejected -> Draft/Edit -> Submit new request
```

## 2. Architecture audit (discovered)

- `App\Domain\Communications\Infrastructure\CommunicationAnnouncement.status`
  is a plain string closed by a Postgres CHECK constraint
  (`draft, scheduled, published, cancelled` before this checkpoint),
  transitioned exclusively via `App\Domain\Communications\Application\AnnouncementService`'s
  atomic conditional-UPDATE claims (`WHERE id = ? AND status = '<source>'`)
  — the same "claim before any side effect" discipline
  `App\Jobs\ProcessCommunicationDeliveryJob::claim()`/
  `App\Jobs\DeliverWebhookJob::claim()` already established.
- `App\Support\Idempotency\RequestFingerprint` is the established
  canonical-hash pattern in this codebase: recursively key-sorted JSON,
  SHA-256. Reused (not reinvented) for approval fingerprinting.
- `communication_attachments.checksum_sha256` already exists and is
  immutable once created — the exact identity primitive attachment
  fingerprinting needs.
- `App\Support\Idempotency\IdempotencyGuard::claim()` and
  `App\Domain\Communications\Application\CommunicationDeliveryFactory::createDelivery()`
  both establish "a real Postgres UNIQUE constraint is the concurrency
  guarantee, not a check-then-insert, with `UniqueConstraintViolationException`
  handled as the losing side of a race" — reused for the approval
  request claim.
- `academic_years_one_active_per_school`'s partial unique index (root
  CLAUDE.md rule 64) is the precedent for "at most one active X" —
  reused for "at most one pending approval request per Announcement."
- `App\Domain\Communications\Application\Policy\SchoolChannelPolicyService`/
  `CommunicationChannelPolicy` established the "no row means the safe
  system default" School-policy shape — reused for
  `CommunicationApprovalPolicy`.
- No pre-existing approval/workflow precedent existed anywhere else in
  the repository.

## 3. Approval-policy model

`App\Domain\Communications\Infrastructure\CommunicationApprovalPolicy`
— one row per School (never per-channel), three independent boolean
flags. No row, or every flag `false`, means "approval not required for
anything."

## 4. Safe default

A fresh deployment of this checkpoint changes nothing for any existing
School: no policy row exists until a School Admin explicitly visits
Communication Settings → Approval Workflow and enables at least one
rule. Every direct-publish/schedule code path this checkpoint touches
falls through to its exact pre-5A.12 behavior when policy evaluates to
"not required."

## 5. Approval-required rules (first generation)

| Flag | Reason code | Trigger |
| --- | --- | --- |
| `require_school_wide_approval` | `school_wide` | `audience_type = school_wide` |
| `require_required_communication_approval` | `required_communication` | `requirement = required` |
| `require_non_privileged_sender_approval` | `non_privileged_sender` | sender lacks `communications.manage` |

`App\Domain\Communications\Application\Approval\CommunicationApprovalPolicyService::evaluate()`
is the one authoritative decision point (brief §50) — every reason
that applies is reported, never just the first match.

## 6. Emergency exemption

`CommunicationApprovalPolicyService::evaluate()` returns
`required: false` unconditionally whenever `$announcement->isEmergency()`
is true, evaluated *before* any policy row is even read.
`CommunicationApprovalService::submit()` independently throws
`EmergencyCannotUseApprovalWorkflowException` if somehow called for an
Emergency draft. This is a deliberate governance decision (brief §10):
Emergency communications already require the dedicated
`communications.emergency` capability and an explicit publish-time
acknowledgement (Phase 5A.10) — a normal approval queue must never
become a way to delay a time-critical communication. Forged Emergency
mode cannot evade approval either way: declaring Emergency without the
capability is already rejected by `AnnouncementController`'s composer
validation (Phase 5A.10), independent of this checkpoint.

## 7. Approval capability

`communications.approve` (new) — granted to `school_admin` and
`principal` in `CapabilityAndRoleSeeder`. Distinct from
`communications.announce` (send) and `communications.manage` (thread/
policy administration): the ability to send a communication never
implies the ability to review someone else's.

## 8. Separation of duties

`CommunicationApprovalService::decide()` (the shared implementation
behind `approve()`/`reject()`) throws `SelfApprovalNotAllowedException`
whenever `$request->requested_by_user_id === $approver->id`, checked
*before* the atomic decision claim. No self-approval path exists in
this checkpoint.

## 9. Request/decision domain

`communication_approval_requests` — one row per submission cycle.
`status` (`pending|approved|rejected|cancelled|invalidated`) transitions
in place via the same atomic-claim discipline as
`communication_deliveries` — NOT append-only at the database privilege
level (unlike `communication_delivery_attempts`), because a real
single-row transition is exactly what's needed. A rejected/withdrawn/
invalidated request is never reused or overwritten — resubmission
always creates a brand-new row with a brand-new fingerprint, so full
history survives automatically.

`fingerprint` (SHA-256 hex) + `snapshot` (compact structured JSON of
the same approval-sensitive fields) are both stored — see §11/§17.

## 10. State machine

```
draft --(submit, policy requires it)--> pending_approval
pending_approval --(approve)--> approved
pending_approval --(reject)--> rejected
pending_approval --(withdraw)--> draft
approved --(edit, fingerprint changes)--> draft   [invalidation]
approved --(publish/schedule)--> published/scheduled
rejected --(edit)--> draft
```

`communication_announcements.status` is the single source of truth for
"what state is this Announcement in right now" (routing/UI). The
request table is the source of truth for "who requested/decided, when,
against what content." `CommunicationApprovalService` keeps both
consistent by changing them together, in the same transaction, on
every write.

## 11. Fingerprint / version model

`App\Domain\Communications\Application\Approval\CommunicationApprovalFingerprint`:

```
snapshot(announcement) = {
  title, body, priority, requirement, dispatchMode, audienceType,
  individualMemberIds: sorted(school_membership_id[]),
  channels: sorted(channel[]),
  attachmentChecksums: sorted(checksum_sha256[]),
}
hash(snapshot) = SHA-256(recursively-key-sorted JSON)
```

Server-computed only — never client-supplied. `scheduled_at` is
deliberately excluded (§16). Deliberately excludes anything resolved
only at publish time (the actual recipient list).

## 12. Approval-sensitive fields

Title, body, priority, requirement, dispatch mode, audience type,
individual audience member selection, requested channels, attachment
set (add/remove/replace, by checksum identity). Any change to any of
these, once approved, invalidates the approval — proven individually
for every field in `CommunicationApprovalServiceTest`'s mandatory
matrix (brief §80), never assumed from testing just one.

## 13. Audience-definition semantics

> Approval binds to the approved audience *definition*; recipient
> eligibility is still resolved according to the established
> publication-time rules.

No recipient snapshot, `CommunicationRecipient`, or
`CommunicationDelivery` row is ever created during submit/approve/
reject/withdraw — proven by
`CommunicationApprovalServiceTest::submitting_creates_no_recipients_or_deliveries`.
The real snapshot is still produced only inside `publish()`, exactly as
Phase 5A.2 established.

## 14. Attachment semantics

`App\Domain\Communications\Application\CommunicationAttachmentService::upload()`/
`remove()` both call
`CommunicationApprovalService::invalidateIfFingerprintChanged()`
immediately after a successful mutation, for Announcement-owned
attachments only. Published attachment immutability (Phase 5A.6) is
untouched — this only affects a still-editable (draft/approved/
rejected) Announcement's attachment set.

## 15. Templates

No template-rendering code exists in the approval path at all — an
Announcement's own canonical `title`/`body` (copied once, at
composer-fill time, from the template) is the only content the
fingerprint ever reads. Editing a *source* template afterward cannot
touch an already-created Announcement's own row, so it can never
invalidate an unrelated approval — proven by
`editing_the_source_template_never_invalidates_an_unrelated_approval`.

## 16. Submit flow

`CommunicationApprovalService::submit()` — valid only from `draft`,
only when `CommunicationApprovalPolicyService::evaluate()` currently
returns `required: true`, never for Emergency. Atomic claim:
`communication_announcements` `draft -> pending_approval` (conditional
UPDATE) *then* an idempotency-defensive INSERT into
`communication_approval_requests` guarded by the partial unique index.
Audits `announcement.approval_requested` with `reasons`/`fingerprint`.

## 17. Approve flow

`CommunicationApprovalService::approve()` (via the shared `decide()`)
— self-approval blocked, then an atomic conditional UPDATE
(`WHERE status = 'pending'`) on the request row, then the matching
Announcement `pending_approval -> approved` UPDATE, same transaction.
Audits `announcement.approved`.

## 18. Reject flow

Same atomic shape, `status = 'rejected'` on both rows, a non-empty
bounded (max 1000 chars) plain-text reason is mandatory. Audits
`announcement.rejected` with `decisionNote`.

## 19. Withdrawal

`CommunicationApprovalService::withdraw()` — the pending request
transitions to `cancelled` (never deleted, brief §37), the Announcement
returns to `draft`. Audits `announcement.approval_withdrawn`.

## 20. Invalidation after edits

`CommunicationApprovalService::invalidateIfFingerprintChanged()` —
called from `AnnouncementService::updateDraft()` (after content/
channel/audience sync) and both `CommunicationAttachmentService`
mutation paths. No semantic-diff heuristics (brief §35): it always
recomputes the full fingerprint and compares byte-for-byte against the
active `approved` request's stored one. A no-op, single indexed
lookup, for the overwhelming majority of Announcements that never used
approval (safe default preserved at the per-edit level too).

## 21. Publish-time fingerprint verification

`AnnouncementService::publish()`/`schedule()` recompute, inside the
SAME transaction as their atomic claim:

- `$approvedSourceAllowed` — true only if an `approved` request exists
  AND its stored fingerprint still equals the Announcement's current
  content, recomputed fresh, right now.
- `$draftSourceAllowed` — true only if current policy does not require
  approval for this Announcement.

The conditional UPDATE's `WHERE` clause only includes the `approved`/
`draft` branches actually permitted; a defensive `1 = 0` base clause
guarantees an empty permitted set can never fall through to an
unqualified (match-anything) group. This is genuine defense in depth
(brief §32 — "Do not trust an `approved` flag alone"): even a
hypothetical future mutation path that forgot to call
`invalidateIfFingerprintChanged()` would still be caught here, proven
by `publish_is_denied_if_content_diverged_from_the_approved_fingerprint_even_with_a_stale_approved_status`.

## 22. Scheduling semantics

> Schedule time is operational timing, not message meaning, and may be
> changed without content re-approval.

`schedule()` uses the identical approval gate as `publish()`.
`reschedule()` is untouched — it only ever changes `scheduled_at`,
never approval-sensitive content, so it never invalidates. An
already-`scheduled` due Announcement's automatic publication (via
`App\Console\Commands\PublishScheduledAnnouncements`) is unaffected by
a *later* policy change — it was already correctly gated at the moment
`schedule()` ran.

## 23. Concurrency / atomicity

Every transition is a single atomic conditional UPDATE (request row,
then Announcement row, same DB transaction) — Postgres row-level
locking serializes concurrent attempts; the loser observes `$claimed
=== 0` and receives `ApprovalAlreadyDecidedException`. Proven for two
concurrent decisions
(`only_one_of_two_decision_attempts_wins`) and for a decision racing a
withdrawal (`a_withdrawn_request_cannot_then_be_approved`).

## 24. Idempotency

A second `submit()` for an already-`pending_approval` Announcement
fails its own claim (`$claimed === 0`) before ever reaching the
request INSERT — no duplicate active request, no duplicate audit
event. `publish()`'s pre-existing idempotent-replay behavior (Phase
5A.2) is unchanged.

## 25. Audit integration

Phase 5A.11's `CommunicationAuditReadModel` is the evidence surface —
no separate approval-specific audit view. Five new event types:
`announcement.approval_requested`, `.approved`, `.rejected`,
`.approval_withdrawn`, `.approval_invalidated`, all normalized with
presentation labels and a safe-metadata allowlist
(`reasons`, `fingerprint`, `previousFingerprint`, `decisionNote`,
`requestId` — never a recipient list or message body).

## 26. UI

- Announcement composer/detail: approval status badge, requirement
  reasons, Submit/Withdraw actions, "Review approval" link for capable
  approvers (`resources/js/Pages/App/Communications/Announcements/Show.vue`).
- `/app/communications/approvals` — pending queue
  (`Approvals/Index.vue`).
- `/app/communications/approvals/{id}` — review detail, renders from
  the immutable `snapshot` (never live, possibly-diverged content),
  Approve/Reject forms (`Approvals/Show.vue`).
- Communication Settings → Approval Workflow section
  (`Settings/Channels.vue`), three checkboxes.
- HubNav gained an "Approvals" tab, `canApprove`-gated.

## 27. Authorization

| Action | Capability |
| --- | --- |
| Submit / withdraw | `communications.announce` (+ creator/manage ownership check) |
| Approve / reject / queue / detail | `communications.approve` |
| Policy settings | `communications.manage` |

## 28. RLS / tenant isolation

Both new tables: `TenantRls::enable()`, composite FK
(`communication_approval_requests.announcement_id` against
`communication_announcements(id, school_id)`), full raw-SQL isolation
suites (`CommunicationApprovalPoliciesRlsIsolationTest`,
`CommunicationApprovalRequestsRlsIsolationTest`) — SELECT/INSERT/UPDATE
protection, composite-FK cross-School rejection, and the partial
unique index's own concurrency guarantee.

## 29. Multi-School users

`communications.approve` is evaluated against the ACTIVE School
membership only (`CapabilityResolver::canInSchool`), proven by
`a_multi_school_user_may_approve_only_in_the_school_granting_the_capability`
— identical capability in School A, absent in School B, on the same
User.

## 30. Performance

Every workflow method issues a small, fixed number of queries. The
approval queue eager-loads `announcement`/`requestedBy`
(`with([...])`) and paginates (20/page); a 25-row scale test asserts
the query count stays well under what an N+1 requester/announcement
lookup would produce.

## 31. Tests

- `tests/Feature/Communications/CommunicationApprovalServiceTest.php`
  (36) — policy, submit, approve/reject, self-approval, concurrency,
  the full 8-field invalidation matrix, template-edit non-invalidation,
  publish-time fingerprint tamper detection, forged direct publish,
  approved full-pipeline publish, scheduling before/after approval,
  reschedule non-invalidation.
- `tests/Feature/App/CommunicationApprovalHubTest.php` (9) — HTTP
  authorization, cross-School/multi-School isolation, forged id,
  queue pagination/query-count.
- `tests/Feature/Postgres/CommunicationApprovalPoliciesRlsIsolationTest.php`
  (5) + `CommunicationApprovalRequestsRlsIsolationTest.php` (7) — RLS +
  composite FK + partial unique index.
- `CommunicationAuditReadModelTest` gained 1 new test proving the
  approval events appear in the Phase 5A.11 audit timeline.

## 32. Safety

`COMMUNICATION_EMAIL_ENABLED` untouched; every test used `Mail::fake()`;
no provider credentials; no SMS/WhatsApp/Push/voice; no staging/
production changes; no Phase 1A integration.

## 33. Deferred work

Multi-step/sequential approval chains, two-person Emergency
authorization, external/email-based approval, delegation/substitutes,
automatic escalation, SLA reminders, approval analytics/rankings,
provider webhooks, AI approval recommendations/risk classification,
Student/Guardian/Class-specific rules — all explicitly out of scope
per the brief and untouched here.

## 34. Phase 5A final-scope recommendation

See the Phase 5A.12 final report's §S.
