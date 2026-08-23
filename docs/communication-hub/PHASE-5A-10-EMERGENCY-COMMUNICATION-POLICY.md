# Phase 5A.10 — Emergency Communication Policy Foundation

## 1. Objective

Introduce an explicit, server-side-only STANDARD/EMERGENCY dispatch
mode for announcements, gated by a new `communications.emergency`
capability, that can — only where the owning School has explicitly
opted in per channel — bypass Phase 5A.9's quiet-hours timing policy.
No second communication or delivery subsystem was created; Emergency
is a new precedence step inserted into the existing pipeline:

```
Communication -> Requirement (OPTIONAL/REQUIRED)
   -> Dispatch Mode (STANDARD/EMERGENCY)
   -> Channel Policy (5A.5) -> Recipient Preference (5A.5)
   -> Timing Policy (5A.9) -> normal timing OR explicitly-authorized
      emergency bypass (5A.10)
   -> existing CommunicationDelivery / queue / redispatch pipeline
```

## 2. Existing policy/timing architecture (discovered)

- `CommunicationChannelPolicyService::evaluate()` — the one ALLOW/
  SUPPRESS decision path for (recipient, channel), precedence:
  ineligible → IN_APP always ALLOW → unsupported channel closed →
  School `required_allowed`/`optional_allowed` → recipient preference
  (Optional only). Untouched by this checkpoint.
- `CommunicationDeliveryTimingPolicyService::evaluate()` (Phase 5A.9)
  — the one SEND_NOW/DEFER decision for an already-ALLOWed channel.
  Its own docblock had already reserved the exact seam this checkpoint
  fills: *"A future explicit, separately-authorized emergency-bypass
  concept would slot in as an additional, clearly-named precedence
  step."*
- `AnnouncementService::publish()` computes one `CommunicationTimingDecision`
  per requested channel, once per publish() call (never once per
  recipient) — the natural, already-batched integration point.
- Capabilities are role-seeded in `CapabilityAndRoleSeeder`; only
  `school_admin` and `principal` exist as school-scoped roles.
  `principal` already lacks `communications.manage`/`.audit.view`.
- `AuditRecorder::school()` is the sole audit-write path, already used
  throughout the Communications domain (`announcement.created`,
  `announcement.published`, `communication.delivery_timing_policy.*`).

## 3. Emergency dispatch mode

`App\Domain\Communications\Domain\CommunicationDispatchMode`:
`Standard = 'standard'` (default) | `Emergency = 'emergency'`.

> Emergency is an explicit dispatch mode. CRITICAL priority does not
> imply Emergency.

Never inferred from priority, requirement, wording, template, audience
size, sender role, or automated classification — always an explicit
human choice, validated server-side and gated by
`communications.emergency`.

## 4. Priority vs. Emergency

`CommunicationPriority` (`NORMAL/IMPORTANT/URGENT/CRITICAL`) is
untouched and fully independent. Proven directly:
`CommunicationEmergencyTimingTest::critical_standard_during_quiet_hours_with_bypass_enabled_still_defers` —
a CRITICAL, STANDARD-dispatch-mode announcement defers exactly like
NORMAL does, even when the School has enabled emergency bypass for the
channel.

## 5. Requirement vs. Emergency

`CommunicationRequirement` (`OPTIONAL/REQUIRED`) is untouched and
independent in the other direction: REQUIRED does not imply Emergency
(`AnnouncementEmergencyDomainTest::standard_required_matches_pre_5a10_behavior`).

## 6. The REQUIRED invariant

> Emergency communication must be REQUIRED, but REQUIRED communication
> does not imply Emergency.

`AnnouncementService::assertValidDispatchMode()` enforces
`dispatch_mode = EMERGENCY AND requirement != REQUIRED → reject`
(`EmergencyMustBeRequiredException`), called from both `createDraft()`
and `updateDraft()` against the EFFECTIVE combination for that call —
so an edit that doesn't touch `dispatch_mode` still re-validates
against the announcement's current stored value, never silently
drifting invalid. This deliberately reuses Phase 5A.5's existing
REQUIRED preference-bypass semantics rather than a second
implementation — Emergency has no bypass logic of its own for
preferences.

## 7. Authorization capability

New capability `communications.emergency`, granted ONLY to
`school_admin` (the narrowest existing role — `principal` already
lacks the comparably-sensitive `communications.manage`). A sender
needs BOTH `communications.announce` AND `communications.emergency` to
declare/publish Emergency:

- `AnnouncementController::validateComposer()` restricts the
  `dispatch_mode` request value to `['standard']` (always) plus
  `['emergency']` only when the actor holds the capability —
  `Rule::in()`, the SAME dynamic-allowed-values pattern already
  established for `requirement`/`canMarkRequired`. A forged
  `dispatch_mode=emergency` from an unauthorized actor fails
  validation (422), never silently downgrades.
- `AnnouncementController::publish()` additionally calls
  `authorizeCapability('communications.emergency', $school)` when
  `$model->isEmergency()` — publishing is the moment bypass-capable
  semantics actually take effect, so it is gated independently of
  whoever originally declared it.

This is the first genuinely double-capability-gated action in this
codebase (`communications.announce` AND, conditionally,
`communications.emergency`) — no existing precedent for stacking two
`authorizeCapability()` calls existed before this checkpoint.

## 8. School emergency bypass configuration

New column `communication_delivery_timing_policies.emergency_bypass_allowed`
(boolean, default `false`), settable only via
`SchoolDeliveryTimingPolicyController`/`SchoolDeliveryTimingPolicyService`
(`communications.manage`-gated, same as the rest of that settings
page). Included in the existing `communication.delivery_timing_policy.*`
audit metadata rather than a new event type.

## 9. Safe default

> Emergency mode does not automatically bypass quiet hours. Bypass
> occurs only when the owning School has explicitly enabled emergency
> bypass for that channel.

`emergency_bypass_allowed` defaults to `false` at the column level; no
migration ever sets it `true` for an existing row. Proven:
`CommunicationDeliveryTimingPolicySettingsHubTest::omitting_emergency_bypass_allowed_defaults_to_false`
and `CommunicationEmergencyTimingTest::emergency_during_quiet_hours_with_bypass_disabled_still_defers`.

## 10. Timing precedence

`CommunicationDeliveryTimingPolicyService::evaluate()` gained one new
optional parameter, `bool $emergencyBypassRequested = false`:

```
Channel !== Email?              -> SEND_NOW (unchanged)
No enabled policy row?          -> SEND_NOW (unchanged)
Outside quiet window?           -> SEND_NOW (unchanged)
Inside quiet window:
    emergencyBypassRequested AND policy->emergency_bypass_allowed?
        -> SEND_NOW, reason = emergency_quiet_hours_bypass
    else
        -> DEFER (unchanged)
```

`$emergencyBypassRequested` is read from the announcement's own,
already-validated `dispatch_mode` (`AnnouncementService::publish()`
passes `$fresh->isEmergency()`) — never re-derived from priority,
requirement, or anything else. `ProcessCommunicationDeliveryJob::scheduleRetry()`
also passes it (looked up via `recipient -> message -> announcement`,
one extra query per single-delivery retry) so a transiently-failed
Emergency retry is not silently downgraded to ordinary deferral.

Reason codes (`CommunicationTimingReason`, closed enum): `allowed_now`
| `quiet_hours` | `emergency_quiet_hours_bypass` — the one new case
this checkpoint adds.

## 11. Channel-policy precedence

Emergency timing is evaluated strictly AFTER channel policy already
returned ALLOW — unchanged integration order. Proven:
`CommunicationEmergencyTimingTest::school_channel_policy_denial_of_required_email_still_suppresses_an_emergency` —
a School denying required EMAIL suppresses an Emergency announcement's
email exactly like a Standard Required one; no delivery row is ever
created, so there is nothing for timing to bypass.

## 12. Preference behavior

Unchanged from Phase 5A.5: REQUIRED (which Emergency must be) already
bypasses recipient preference at the channel-policy layer, before
timing is ever considered. Emergency itself has no preference-bypass
logic of its own. Proven:
`CommunicationEmergencyTimingTest::a_disabled_optional_email_preference_never_suppresses_a_required_emergency`.

## 13. Global technical gate

> Emergency mode never overrides tenant security, recipient
> eligibility, school channel-policy denial, or the global technical
> channel enablement gate.

`COMMUNICATION_EMAIL_ENABLED=false` remains authoritative — the
channel-policy layer is unaware of it, so a delivery row IS still
created and planned (possibly with a bypass timing decision), but
`EmailChannelDriver::send()` itself refuses deterministically
(`email_channel_disabled`, non-retryable), exactly like a Standard
delivery. Proven:
`CommunicationEmergencyTimingTest::a_globally_disabled_email_driver_still_produces_no_real_send_for_an_emergency_bypass`.

## 14. Recipient eligibility

Unchanged: `CommunicationChannelPolicyService::evaluate()`'s
`RecipientIneligible` branch, RLS, and every existing membership check
apply identically regardless of dispatch mode. Emergency cannot target
an inactive membership, a wrong School, or an arbitrary email address
— it only ever reaches the SAME resolved-audience/recipient-snapshot
pipeline every other announcement does.

## 15. Emergency announcement flow

Authorized actor → create announcement (Requirement=REQUIRED,
DispatchMode=EMERGENCY, justification, composer-time acknowledgement)
→ select audience/channels → publish (capability re-checked,
publish-time acknowledgement) → `AnnouncementService::publish()`
(unchanged audience resolution/recipient snapshot) → channel policy →
timing policy (bypass only where explicitly allowed) → existing
delivery pipeline. `AnnouncementService` was extended, not replaced.

## 16. Acknowledgement UX

Two independent, explicit confirmations, neither treated as
authorization on its own:

- **Composer-time** (`emergency_acknowledged`, `required_if:dispatch_mode,emergency`
  + `accepted`): captured when Emergency is first declared, alongside
  the justification.
- **Publish-time** (`acknowledged`, checked only when
  `$model->isEmergency()`): a deliberate, separate re-confirmation
  immediately before the bypass-capable publish actually executes.
  Implemented as a plain imperative check rather than a validation
  rule — Laravel's bare `accepted` rule is *implicit* and runs even
  when the field is absent, which would have broken every ordinary
  STANDARD publish (still posting an empty body, unchanged since Phase
  5A.4); this was caught and fixed during this checkpoint's own test
  run.

## 17. Justification/audit metadata

`emergency_justification` (text, `max:500` at the HTTP layer, non-empty
enforced at the Application layer too) is tenant-owned data on
`communication_announcements` — not a generic application log. Never
required to be shown to recipients (the composer/detail-page copy says
so explicitly). Restricted server-side:
`AnnouncementController::show()` only includes it in the Inertia
payload when the viewer holds `communications.manage` or
`communications.emergency` — an unauthorized viewer's response never
contains the field at all, rather than receiving-and-hiding it.

Audit events (`AuditRecorder::school()`, existing convention, no new
mechanism):

- `announcement.emergency_declared` — actor, requirement, justification.
  Emitted from both `createDraft()` and `updateDraft()`.
- `announcement.emergency_published` — emitted alongside the existing
  `announcement.published` event, only for an Emergency announcement.
- `communication.emergency_quiet_hours_bypass_used` — one event per
  BYPASSED channel per publish() call (never per recipient — the
  timing decision is already per-channel, so this is the natural,
  non-duplicated granularity).

Never logs recipient addresses or message body.

## 18. Historical immutability

> Once published, an Emergency communication cannot be silently
> converted to STANDARD, and a published STANDARD communication cannot
> be retroactively converted to EMERGENCY.

This falls out of the ALREADY-existing `isEditable()` guard
(`isDraft() || isScheduled()`) with no new code required for the
published case: `updateDraft()` already rejects any edit to a
published announcement (`InvalidAnnouncementTransitionException`), and
since Emergency can never reach `scheduled` (§20), a published
Emergency announcement was always a `draft` immediately before
publish. Proven directly for both directions in
`AnnouncementEmergencyDomainTest`.

## 19. Draft behavior

For a DRAFT (only), `updateDraft()` accepts a new, optional
`?CommunicationDispatchMode $dispatchMode` and `?string $emergencyJustification`.
Setting Emergency stamps `emergency_declared_by_user_id`/
`emergency_declared_at` to the acting user/now. Removing Emergency
(dispatch_mode → Standard) clears all three emergency-only columns.
Editing an unrelated field (e.g. title) without touching `dispatch_mode`
leaves the existing value — Emergency or Standard — untouched. An
unauthorized editor's request can never even contain
`dispatch_mode=emergency` (§7), so they cannot activate emergency
authority by editing someone else's draft; they can still edit other
fields of an already-Emergency draft they're otherwise permitted to
edit (owner or `communications.manage`), which simply preserves the
existing dispatch mode.

## 20. Scheduled Emergency: deferred, not implemented

> dispatch_mode = EMERGENCY AND attempting schedule() → reject.

Enforced in two places with the same exception
(`EmergencyCannotBeScheduledException`): `AnnouncementService::schedule()`
rejects scheduling an Emergency draft outright; `updateDraft()` rejects
setting `dispatch_mode=Emergency` on an announcement that is currently
`scheduled` (closing the other direction of the same invariant — an
Emergency announcement can never exist in the `scheduled` state,
either by entering it directly or by being edited into Emergency while
already there). Existing Phase 5A.4 scheduled STANDARD announcements
are completely unaffected; CRITICAL/REQUIRED scheduled announcements
remain non-emergency and continue to obey quiet hours normally.

## 21. IN_APP semantics

IN_APP remains canonical and immediate for every dispatch mode —
`CommunicationDeliveryTimingPolicyService::evaluate()` still returns
`SEND_NOW` unconditionally for any channel other than Email, with no
new code path for Emergency. Proven:
`CommunicationEmergencyTimingTest::in_app_is_never_delayed_for_an_emergency_announcement_either`.
No parallel Emergency inbox was created.

## 22. Operational Inbox presentation

`CommunicationInboxItem` gained one new `bool $isEmergency = false`
field (default `false` for conversations/templates, which can never be
Emergency). Both announcement-producing sites in
`CommunicationInboxReadModel` (`announcementItems()`,
`searchAnnouncements()`) populate it from
`$a->dispatch_mode === 'emergency'`. `InboxItemList.vue` renders a
`⚠ Emergency` badge (distinct red-filled style, positioned before the
`Required` badge) for any authorized viewer who can already see the
item — the badge itself carries no restricted information, unlike the
justification (§17). No separate Emergency Inbox was built; no search
scope was broadened (Phase 5A.8's existing authorization predicate is
completely untouched — this checkpoint only added a presentation
field to an already-authorized result set).

## 23. Timing auditability

An operator can distinguish all three states purely from existing
delivery/audit evidence, no new tables:

- STANDARD deferred by quiet hours: `CommunicationDelivery.status = 'queued'`,
  no `communication.emergency_quiet_hours_bypass_used` event.
- EMERGENCY bypassed quiet hours: delivery sent immediately, plus a
  `communication.emergency_quiet_hours_bypass_used` audit event naming
  the channel.
- EMERGENCY still deferred (School bypass disabled): delivery
  `status = 'queued'` exactly like STANDARD, with no bypass event —
  the ABSENCE of the event combined with `announcement.dispatch_mode = 'emergency'`
  on the parent announcement is how an operator confirms bypass was
  considered but not applied.

## 24. Tenant/RLS security

No new tenant-owned table. `dispatch_mode`/`emergency_*` columns were
added to `communication_announcements`; `emergency_bypass_allowed` to
`communication_delivery_timing_policies` — BOTH already RLS-protected,
already covered by `CommunicationAnnouncementsRlsIsolationTest` and
`CommunicationDeliveryTimingPoliciesRlsIsolationTest` (Postgres RLS
policies apply per-row, not per-column, so those existing suites
already prove School A cannot read/write School B's new columns
either — re-run and confirmed passing unmodified in this checkpoint,
no new raw-SQL test file was needed). HTTP-layer proof added directly:
`AnnouncementEmergencyAuthorizationTest::school_a_admin_cannot_view_school_bs_emergency_announcement`
(404, not 403 — the row is genuinely invisible) and
`CommunicationDeliveryTimingPolicySettingsHubTest::school_a_admin_cannot_enable_school_bs_emergency_bypass`.

## 25. Multi-school behavior

Emergency authority is evaluated from the ACTIVE `SchoolMembership`'s
role in that specific School — the same capability-resolution
mechanism every other capability in this codebase already uses, no
new caching/session logic. Proven:
`AnnouncementEmergencyAuthorizationTest::a_users_emergency_authority_in_one_school_does_not_grant_it_in_another` —
one User, `school_admin` in School A / `principal` in School B, can
declare Emergency only while School A is active.

## 26. Performance

Timing evaluation (including the emergency-bypass check) remains
exactly one call per requested channel per `publish()` call — the
Phase 5A.9 query-count guarantee is unchanged since
`emergency_bypass_allowed` piggybacks on the SAME
`communication_delivery_timing_policies` row lookup `policyFor()`
already performs, adding zero new queries. The one deliberate
exception is `ProcessCommunicationDeliveryJob::scheduleRetry()`'s new
`recipient -> message -> announcement` lookup — a single extra query
per individual retry (never per recipient, since a retry is already a
single-delivery operation).

## 27. Idempotency

Unchanged: Emergency mode changes POLICY DECISIONS (whether/when a
channel sends), never delivery IDENTITY. Every existing idempotency
guarantee (atomic publish claim, `unique(recipient_id, channel)`,
atomic delivery claim, atomic due-redispatch claim) applies
identically regardless of dispatch mode — proven implicitly by every
timing test in this checkpoint reusing the unmodified publish/redispatch
pipeline, and explicitly by the existing Phase 5A.9 redispatch
idempotency test still passing unmodified.

## 28. Tests

- `AnnouncementEmergencyDomainTest` — 15 tests (28 assertions):
  REQUIRED invariant both directions, justification requirement,
  scheduling rejection both directions, published immutability both
  directions, draft set/remove/preserve semantics, declaration and
  publish audit events.
- `CommunicationEmergencyTimingTest` — 10 tests (24 assertions): the
  full §50 combination matrix, IN_APP immediacy, preference
  precedence, channel-policy precedence, global technical gate.
- `AnnouncementEmergencyAuthorizationTest` — 8 tests (54 assertions):
  full declare-and-publish flow, announce-only rejection, composer
  visibility gating, unauthorized-actor-with-forged-acknowledgement
  rejection, cross-School invisibility, multi-School capability
  isolation, standard-publish-no-acknowledgement-required regression,
  missing-acknowledgement rejection.
- `CommunicationDeliveryTimingPolicySettingsHubTest` — 3 new tests
  (in addition to the 9 already existing from Phase 5A.9): safe
  default, explicit enable, cross-School isolation for the new field.

**36 new tests, ~116 new assertions.** Full application regression
(`composer test`, clean shell): **701 passed, 2136 assertions, 0
failed** — no flakes this run. Quality gates: Pint 518 files PASS,
PHPStan 0 errors (no baseline entries), Prettier clean, ESLint clean,
vue-tsc clean.

## 29. Safety

No real external email sent (`Mail::fake()` throughout);
`COMMUNICATION_EMAIL_ENABLED` untouched (still `false` default); no
SMS/WhatsApp/Push/voice provider code, SDK, or credentials added; no
staging/production configuration touched; no Phase 1A code read,
referenced, or merged; nothing pushed, merged, or rebased.

## 30. Deferred: escalation

No escalation chain (Push fails → WhatsApp → SMS → Voice), no channel
fallback orchestration, no provider activation. This checkpoint
establishes only emergency classification and controlled timing-policy
bypass for the one channel (EMAIL) that already has a real driver.

## 31. Deferred: approval workflow

No pending-emergency-approval state, no principal approval chain, no
two-person authorization. Emergency authority is controlled solely by
the `communications.emergency` capability for now; approval governance
remains a separate, future checkpoint.

Also deferred, none implemented: automated emergency detection, SMS/
WhatsApp/Push/voice providers, geo-targeted emergency messaging,
provider webhooks, inbound email, AI (detection/classification/
rewriting/audience-selection/auto-send/escalation),
Student/Guardian/Class-specific emergency rules, Phase 1A integration,
a public emergency portal, anonymous recipients.

## 32. Next recommendation

Evaluated but **not started**, per instruction:

- **Phase 5A.11 — Approval Workflow Foundation**
- **Phase 5A.11 — Communication Audit & Delivery Analytics**
- **Phase 5A.11 — Emergency Escalation Foundation**
- **Phase 5A.11 — Email Provider Event/Webhook Foundation**

Communication Audit & Delivery Analytics is now the most natural next
layer — Phase 5A.9 and 5A.10 have both been deliberately generating
rich, structured audit/timing evidence (quiet-hours deferrals,
emergency declarations, bypass usage) with no dedicated reporting
surface yet to make it easy for an operator to review. Emergency
Escalation Foundation is the reasonable alternative if multi-channel
urgency is the more pressing product need, but it depends on a real
SMS/WhatsApp/Push provider integration this repository does not have
yet.
