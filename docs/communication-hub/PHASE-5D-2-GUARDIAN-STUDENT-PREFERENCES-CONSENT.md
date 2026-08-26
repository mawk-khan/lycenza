# Phase 5D.2 — Guardian / Student Communication Preferences & Consent Foundation

## 1. Objective

Build the missing preference/consent layer for logical Student and
Guardian communication recipients on external (non-IN_APP) channels,
without disturbing the existing authenticated SchoolMembership
preference architecture. Distinguishes school channel policy,
recipient preference, recorded consent/withdrawal, communication
requirement, emergency dispatch, technical endpoint availability, and
AccountLink reachability as separate concepts — none collapsed into
one boolean.

## 2. Pre-existing SchoolMembership preference architecture (audit findings)

`CommunicationPreference` (Phase 5A.5) is scoped to
`(school_id, school_membership_id, channel)`, mutated in place via
`updateOrCreate()` — current state only, row absence means "inherit
system/school default." `CommunicationPreferenceService` is the sole
write path, self-service-shaped (always resolves the caller's OWN
membership). Answering the mandatory audit questions:

1. **What does a SchoolMembership preference control?** Whether that
   specific member wants OPTIONAL communications on that specific
   channel — nothing else.
2. **Does REQUIRED bypass preferences?** Yes —
   `CommunicationRequirement`'s own docblock: *"Required: recipient
   preference is not an opt-out on a channel School policy already
   permits for required communication."* Preserved unchanged.
3. **Suppression reasons?** `CommunicationPolicyReason`, a closed
   enum consumed by `CommunicationDeliveryPolicyDecision` (an
   append-only ledger, Phase 5A.5 §20/§21).
4. **Where does Guardian EMAIL decide eligibility?**
   `AnnouncementService::snapshotAndDeliverGuardianRecipients()`, via
   `CommunicationChannelPolicyService::evaluateForDomainParty()` (school
   policy only) then `GuardianEmailAddressResolver` (endpoint).
5. **Was Guardian EMAIL preference-aware before 5D.2?** No —
   `evaluateForDomainParty()`'s own docblock: *"no SchoolMembership
   means no personal CommunicationPreference row could possibly
   exist... there is nothing to consult."* This was the exact gap
   closed.
6. **Any existing consent model?** None found anywhere in the
   repository prior to this checkpoint.
7. **How are preference changes audited?** `AuditRecorder::school()`,
   `communication.preference.updated`.
8. **Evaluation timing?** Entirely at `publish()` time — no
   pre-computation at draft/schedule time.
9. **Scheduled announcements?** `PublishScheduledAnnouncements`
   (console command) calls the exact same `AnnouncementService::publish()`
   at due time — no separate scheduled-delivery code path exists.
10. **Historical immutability?** `CommunicationDeliveryPolicyDecision`
    and `CommunicationDelivery`/`CommunicationRecipient` rows, once
    created, are never revisited by any later action in this codebase.

## 3. Why IN_APP is not duplicated

A linked Guardian/Student's IN_APP eligibility continues to come
**exclusively** from their linked SchoolMembership's own
`CommunicationPreference` row, via the pre-existing
`deliverInAppForLinkedDomainParty()` path — completely untouched by
this checkpoint. No `CommunicationDomainPreference`/
`CommunicationDomainConsentEvent` row is ever read for `in_app`.
Verified directly:
`in_app_eligibility_follows_the_linked_membership_preference_unaffected_by_guardian_email_preference`.

## 4. Domain-recipient preference scope

`communication_domain_preferences`/`communication_domain_consent_events`
exist only for external channels a SchoolMembership has no
reachability model for. Today that is exactly one real channel:
Guardian EMAIL. SMS/WhatsApp/Push are NOT added to the `channel`
column's accepted values in this checkpoint (both new tables' `channel`
is a free string, but the ONLY channel any application code ever reads
or writes is `'email'` — validated at the one HTTP entry point,
`GuardianCommunicationPreferenceController`, via `Rule::in(['email'])`).
No provider, no outbound job, no delivery code exists for any other
channel — brief §8/§40 honored.

## 5. Guardian EMAIL behavior

`AnnouncementService::snapshotAndDeliverGuardianRecipients()` now, for
`channel === Email` and `requirement === Optional` only, checks (in
order) the Guardian's `CommunicationDomainPreference` then
`CommunicationDomainConsentEvent` current status, BEFORE resolving a
destination via the unchanged `GuardianEmailAddressResolver`. Both
lookups are batched once per audience chunk (never per-Guardian).
`GuardianContact` remains the sole endpoint source — no plaintext
contact value is ever read by the preference/consent layer itself.

## 6. Student channel reality

Re-confirmed on the unified codebase: no `StudentEmailAddressResolver`,
no `StudentContact` model, no Student email endpoint exists anywhere.
`CommunicationDomainPreferenceService`/`CommunicationConsentService`
accept a Student generically (schema/service symmetry, brief §13/§26),
and `DomainCommunicationPreferenceReadModel::forStudentEmail()` exists
for structural/test completeness, but `endpointAvailable` is hard-coded
`false` for it — never a fabricated endpoint. `AnnouncementService`'s
Student delivery path is entirely unmodified (Students have no EMAIL
recipient path to extend). The Student detail page shows a purely
static, honest note; no interactive controls are offered for a channel
that does not exist.

## 7. Preference vs. consent

`CommunicationDomainPreference`: "I'd rather not" — current state
only, mutated in place, absence = default. `CommunicationDomainConsentEvent`:
an explicit consent/withdrawal decision — append-only, history-preserving,
absence = "unknown" (a third, distinct state, never coerced to a
boolean). Different tables, different suppression reasons
(`recipient_preference_disabled` vs. `consent_withdrawn`), never
conflated in code, audit, or analytics.

## 8. Consent history

`communication_domain_consent_events` is APPEND-ONLY at the database
privilege level (`TenantRls::makeAppendOnly`, mirroring
`communication_delivery_attempts`' exact precedent) — the runtime role
has no UPDATE/DELETE grant on this table at all, proven by
`the_runtime_role_cannot_update_or_delete_a_consent_event`. "Current"
status is derived, never stored, as the latest `recorded_at` (ties
broken by `id`, a UUIDv7). A `granted → withdrawn → granted` sequence
retains all three rows — verified by
`full_consent_history_is_preserved_across_multiple_transitions`.

## 9. Default / backward-compatible behavior

No explicit preference row + no consent event = existing pre-5D.2
delivery behavior, unchanged — verified by
`default_backward_compatibility_no_preference_or_consent_row_still_delivers`
against the real `AnnouncementService::publish()` path. Introducing
this checkpoint changes nothing for a School that never touches the new
surface.

## 10. OPTIONAL semantics

An explicit preference opt-out suppresses OPTIONAL Guardian EMAIL with
reason `recipient_preference_disabled`; opt-in behaves exactly like no
row at all (normal eligibility, subject to every other check). The
logical recipient snapshot (`CommunicationAnnouncementRecipient`)
always still contains the Guardian — only the channel-level delivery is
suppressed.

## 11. REQUIRED semantics

REQUIRED bypasses an ordinary preference opt-out — this was already
the established behavior for SchoolMembership preferences, and this
checkpoint applies the identical rule to the Guardian domain
preference, unchanged. **REQUIRED also bypasses a withdrawn consent
record in this foundation.** This is a deliberate engineering choice —
ONE unified bypass rule applied identically to both recipient-level
suppression signals, matching the single existing precedent rather than
inventing a second, different bypass rule for consent. **This is not a
legal or regulatory claim** that a REQUIRED school notice may
lawfully override a withdrawn consent in every jurisdiction or every
type of communication — it is this foundation's specific, documented,
revisitable application-level default (brief §16/§30's explicit
non-claim requirement).

## 12. Emergency semantics

Emergency communications are always REQUIRED (existing invariant,
unchanged) and therefore bypass preference/consent via the same rule
as §11 — no separate "Emergency bypasses consent" rule was added.
Verified unaffected: quiet hours, AccountLink resolution, and endpoint
availability logic are untouched by this checkpoint
(`emergency_communication_is_unaffected_by_guardian_preference`).
Emergency creates no endpoint, no AccountLink, and does not turn
Student email into a supported channel.

## 13. Channel-policy interaction

School channel policy remains fully authoritative and is checked
FIRST, before any preference/consent lookup — a School disabling
OPTIONAL email produces `school_optional_channel_disabled` regardless
of the Guardian's own preference, never misreported as a preference/
consent suppression. Verified by
`school_channel_policy_disabled_is_reported_over_preference`.

## 14. Endpoint-availability interaction

An opted-in, consent-granted Guardian with no eligible `GuardianContact`
email still produces `recipient_destination_unavailable` — the
pre-existing Phase 5B.1 reason, unchanged, never reclassified as a
preference/consent suppression. Verified by
`opted_in_guardian_with_no_email_endpoint_is_reported_as_destination_unavailable`.

## 15. Decision order (as implemented)

For a Guardian EMAIL channel during `snapshotAndDeliverGuardianRecipients()`:

1. School channel policy + requirement (`evaluateForDomainParty()`, unchanged).
2. *(OPTIONAL only)* Guardian domain preference.
3. *(OPTIONAL only)* Guardian domain consent status.
4. Destination endpoint resolution (`GuardianEmailAddressResolver`, unchanged).
5. Delivery-timing/quiet-hours (already resolved once per channel before this loop, unchanged).
6. Delivery creation.

## 16. Scheduling/publication semantics

Because `PublishScheduledAnnouncements` calls the same `publish()`
method at due time with no pre-computed state, a preference/consent
change made after scheduling but before the due time is naturally
applied — verified by
`a_preference_change_after_scheduling_but_before_publication_is_applied`.
No freezing at draft time occurs anywhere in this pipeline.

## 17. Historical immutability

`communication_delivery_policy_decisions` (suppression) and
`communication_deliveries` (successful sends) are created once at
publish time and never revisited — a later preference/consent change
never rewrites an already-created record. Verified by
`a_later_preference_change_never_rewrites_a_historical_delivery`: a
prior delivery stays `sent`; only a subsequent, separate Announcement
reflects the new state.

## 18. Approval

`CommunicationApprovalFingerprint` is untouched — preference/consent
state was never part of it (it was never part of reachability at all,
per its own pre-existing docblock). Verified by
`an_approved_announcement_remains_valid_after_a_preference_change`: an
approved Required announcement's status stays `approved` after both a
preference opt-out and a consent withdrawal are recorded for its
target Guardian.

## 19. GuardianContact lifecycle

`CommunicationDomainPreference`/`CommunicationDomainConsentEvent` are
scoped to `(school_id, guardian_id, channel)` — never to an individual
`GuardianContact` row. An opt-out survives a primary-contact change,
verified end-to-end through the real `GuardianContactService::create()`/
`setPrimary()` write path by
`preference_survives_a_guardian_contact_change`, and at the delivery
layer by `consent_events`/preference rows never referencing a contact
id at all (schema-level guarantee, not just a test).

## 20. AccountLink independence

Neither new table has any foreign key or code path touching
`StudentGuardianAccountLink`/`SchoolMembership`. A Guardian can have an
EMAIL preference and consent state entirely independent of whether
they have ever linked a School OS account — AccountLink remains
relevant only for IN_APP (§3).

## 21. Administration UX

First-generation administrative-only surface (no self-service portal,
brief §33) on the existing Guardian detail page
(`Pages/App/Guardians/Show.vue`): a "Communication preferences" section
showing Email endpoint availability, current preference
(Default/Enabled/Opted out), current consent (Unknown/Granted/Withdrawn),
and two write actions each, visible only when
`canManageCommunicationPreferences` is true. Never shows a decrypted
email address in this section (status only — the address itself is
shown elsewhere on the same page via the pre-existing, unrelated
Contacts section, unchanged). The Student detail page shows a purely
static, accurate note (no controls, no fabricated availability).

## 22. Authorization

`GuardianCommunicationPreferenceController`'s two write actions require
**BOTH** `communications.manage` AND `guardians.manage` — recording a
consent decision is more sensitive than either capability alone,
mirroring the layered-capability precedent `hr.employees.sensitive.manage`
already established (never satisfied by the base capability alone). No
role-name check anywhere. Read access (viewing the state on the
Guardian detail page) reuses the page's existing `guardians.view` gate
— no new capability was needed for viewing.

## 23. Audit

`communication.domain_preference.updated`,
`communication.consent.granted`, `communication.consent.withdrawn` —
all via the existing `AuditRecorder::school()`. Metadata: guardian/
student id, channel, and (for preference) the new preference value —
never a contact address, never message content.

## 24. Analytics / suppression reasons

`CommunicationDeliveryAnalyticsReadModel`'s existing `reason`-grouped
breakdown (unchanged code) automatically distinguishes the two new
reason values (`recipient_preference_disabled` reused,
`consent_withdrawn` new) from `school_optional_channel_disabled`/
`recipient_destination_unavailable` — no analytics code needed to
change for this to work correctly.

## 25. RLS / multi-school security

Both new tables (`communication_domain_preferences`,
`communication_domain_consent_events`) have RLS enabled and forced,
proven by raw-SQL tests: no-context zero rows, cross-School read
denial, cross-School insert rejection (both RLS `WITH CHECK` and the
composite Guardian/Student FK), and — for the append-only ledger —
unconditional UPDATE/DELETE denial at the privilege level regardless of
School context. `only_one_current_preference_row_may_exist_per_guardian_and_channel`
proves the partial unique index. All controller actions resolve the
Guardian strictly under the active School (`Guardian::query()->findOrFail()`
under `TenantContext`'s `SchoolScope`) — a cross-School id returns 404,
verified by `a_cross_school_guardian_id_is_not_found`.

## 26. Performance

Both services expose batched read methods
(`currentPreferencesForGuardians()`, `currentStatusesForGuardians()`)
used by `AnnouncementService` — one query each per audience chunk,
never per-Guardian. The consent batch query uses a single PostgreSQL
`DISTINCT ON` statement. Bounded-query-count tests exist both at the
service layer (20 Guardians, <5 queries) and on the real end-to-end
publish path (20 Guardians, <350 total queries — the same generous
bound `StudentGuardianAccountLinkInAppTest` already established for
this exact pipeline shape, guarding specifically against a regression
to one preference/consent query per Guardian).

## 27. Tests

- `CommunicationDomainPreferenceServiceTest` (7)
- `CommunicationConsentServiceTest` (6)
- `CommunicationDomainPreferencesRlsIsolationTest` (6)
- `CommunicationDomainConsentEventsRlsIsolationTest` (6)
- `GuardianEmailPreferenceConsentDeliveryTest` (14) — real
  `AnnouncementService::publish()` end-to-end for every rule in §9-§18
  above
- `GuardianCommunicationPreferenceHubTest` (11)

**50 new tests, 105 assertions, all passing.** Full Communications
suite: 663 tests, 1897 assertions (up from 613/1792 by exactly the 50
new tests). Full application suite: **2561 tests, 8835 assertions, 0
failures, 0 errors** (up from the published Phase 5D.1 baseline of
2511/8730 by exactly the 50 new tests) — confirmed with a full
`platform:test-db-reset --force` immediately beforehand.

## 28. Safety

`COMMUNICATION_EMAIL_ENABLED`-equivalent config
(`communications.channels.email.enabled`) is untouched by default;
tests that exercise a real send explicitly use `Mail::fake()` (Laravel's
fake mail transport — no real SMTP, no live send, matching this
module's existing test convention exactly). No production provider, no
SMS/WhatsApp/Push code, no staging/production change.

## 29. Legal/compliance non-claims

Recording a `granted`/`withdrawn` consent event is evidence that an
explicit decision was made and by whom, when, and through what
administrative action — it is **not** a claim that School OS thereby
satisfies GDPR, COPPA, FERPA, or any other legal/regulatory regime.
§11's REQUIRED-bypasses-consent default is an engineering choice
mirroring existing preference-bypass behavior, explicitly not a legal
determination about which School communications may lawfully override
a guardian's withdrawn consent in any given jurisdiction.

## 30. Deferred / out of scope (confirmed not implemented)

Guardian/Student self-service portals, public unsubscribe pages,
account invitations, a Student email endpoint, SMS/WhatsApp/Push
delivery, provider webhook handling, marketing automation, a
legal-policy engine, consent-document storage, AI consent inference,
and any Phase 5E provider integration work.

## 31. Next recommendation

The Guardian/Student preference-and-consent foundation is complete,
tested, and backward-compatible. Reasonable next steps, in no
particular mandated order: **Phase 5D.2 Final Integration/Publication**
(mirroring the 5D.1 gate sequence already established), an **Account
Invitation / Communication Activation Foundation** (the natural
prerequisite for this preference UI ever reaching a real Guardian
self-service surface), a **Production Email Provider Integration**
(Phase 5E), or a **Phase 5 Communications Closure Audit**. This is a
sequencing decision for the requester, not made here.
