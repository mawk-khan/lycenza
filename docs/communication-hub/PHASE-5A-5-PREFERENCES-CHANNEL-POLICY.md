# Phase 5A.5 — Communication Preferences & Channel Policy Foundation

## 1. Objective

Introduce the ONE authoritative server-side policy layer that answers,
for every (communication, recipient, School, requested channel) tuple:
*should a delivery actually be planned?* Sits between "requested
channels" (Phase 5A.2/5A.3) and `CommunicationDelivery` creation
(`CommunicationDeliveryFactory`), reusing the existing pipeline end to
end — no second delivery system, no policy logic duplicated in a
controller, job, channel driver, or Vue.

## 2. Architecture audit (before writing anything)

- **`CommunicationDeliveryFactory`/requested-channel handling**
  (Phase 5A.2/5A.3): `AnnouncementService::publish()` already iterates
  `requestedChannels() × resolved audience` inside one transaction,
  batching a `User` lookup per chunk purely for email-address
  resolution. This is the EXACT integration point — the policy engine
  slots into that same loop, batching preferences the identical way.
- **Phase 5A.3 email eligibility**: `EmailAddressResolver` answers "is
  this a valid, sendable address" — a completely separate concern from
  policy ("should we even try"). Both now compose: policy runs first;
  only an ALLOWed recipient ever reaches address resolution.
- **Phase 5A.4 scheduled publication**: `publish()` is reused
  UNCHANGED for scheduled announcements (the atomic claim doesn't care
  whether the source was `draft` or a due `scheduled` row) — so policy
  evaluation automatically happens at due-publication time for
  scheduled announcements too, with zero extra code (see §18).
- **`SchoolMembership` lifecycle**: deliberately CENTRAL/platform data
  (`App\Models\SchoolMembership`'s own docblock: "Do NOT add
  school_id-based RLS to this model"), not RLS-protected itself, but
  every OTHER Communications table already references it via a
  composite FK against `school_memberships(id, school_id)`
  (`communication_announcement_audience_members`, `..._recipients`).
  `communication_preferences` follows this exact established pattern
  — see §4.
- **Existing settings architecture**: `App\Support\Settings\SettingRegistry`/
  `SchoolSettingsService`/`App\Models\SchoolSetting` is a generic,
  typed, School-scoped key-value store (one unused example key,
  `communications.digest_frequency`, registered in Phase 0C and never
  extended since). Considered and REJECTED for channel policy — see
  §5's reasoning.
- **Existing preference precedents**: none. This is the first
  recipient-facing preference concept in the codebase.
- **Audit conventions**: `App\Support\Audit\AuditRecorder::school()`
  (append-only `SchoolAuditEvent`) for every write; a domain event only
  for significant STATE-transition-shaped actions (Phase 5A.2's
  `AnnouncementService::updateDraft()` is audit-only, no event, for a
  content edit — the same precedent both new services below follow).
- **RLS conventions**: `App\Support\Tenancy\TenantRls::enable()`/
  `makeAppendOnly()`, composite `unique(id, school_id)` only on tables
  that are themselves FK targets (none of the three new tables are, so
  none declare one), composite FKs everywhere a child row references a
  School-scoped parent.

## 3. Preference ownership

**Recipient preferences are scoped to `SchoolMembership`, not
globally to `User`.** The same `User` can hold independent, unrelated
preferences in every School they belong to — proven directly
(`CommunicationPreferenceServiceTest::the_same_user_has_independent_preferences_in_two_different_schools`,
`CommunicationPreferenceHubTest`'s forged-membership-id test). A plain
`user_id` column on `communication_preferences` was deliberately
rejected: it would make a School-A-scoped row structurally guessable/
targetable from a School-B request even under RLS (RLS scopes THIS
row's `school_id`, but a `user_id`-keyed uniqueness constraint doesn't
prevent enumerating a real user id from elsewhere). Scoping the
natural key to `school_membership_id` removes that whole class of
mistake.

## 4. `SchoolMembership` scoping (schema)

`communication_preferences`: `id`, `school_id`, `school_membership_id`
(composite FK against `school_memberships(id, school_id)`,
`cascadeOnDelete()`), `channel` (CHECK-restricted to `'email'` ONLY —
see §7), `preference` (CHECK `'enabled'`/`'disabled'` only — no stored
`INHERIT`, see §8), `unique(school_membership_id, channel)`. RLS
enabled. No `TenantRls::makeAppendOnly()` — a preference is genuinely
mutable (a person can change their mind), unlike an audience snapshot
or a delivery attempt.

## 5. Channel-policy model

`communication_channel_policies`: `id`, `school_id`, `channel`
(CHECK `'in_app'`/`'email'` only), `optional_allowed`,
`required_allowed`, `recipient_can_opt_out` (all plain booleans),
`unique(school_id, channel)`. RLS enabled.

**A new dedicated table, not the existing generic `SchoolSetting`
key-value store.** Considered directly: `SchoolSetting`/`SettingRegistry`
fits a single typed SCALAR per key well (its one real example,
`communications.digest_frequency`, is exactly that shape). A channel
policy is a small multi-field STRUCT per channel — modeling it as
3 separate scalar setting keys per channel would work, but every OTHER
real domain concept this module has introduced
(`communication_announcement_channels`, `communication_templates`, the
`communication_announcements.requirement` column) already gets its
own typed, CHECK-constrained, RLS-protected table rather than being
folded into a generic JSONB blob. A dedicated table is what this
specific codebase has consistently done for a genuine domain concept;
following that precedent was chosen over introducing a second
modeling style for a data shape the KV store was never actually
proven out on.

## 6. System defaults

`CommunicationChannelPolicyService::defaultPolicy()` (a pure static
function, no I/O) supplies the default when NO School override row
exists:

| Channel | optional_allowed | required_allowed | recipient_can_opt_out |
|---|---|---|---|
| IN_APP | true | true | **false** (never consulted — see §15) |
| EMAIL | true | true | true |
| anything else | false | false | false |

Chosen to exactly match what every School already had before this
checkpoint existed (brief §14): IN_APP and EMAIL both fully permitted,
matching "IN_APP always delivers, EMAIL delivers whenever explicitly
requested and globally enabled" — the ENTIRE pre-5A.5 behavior. No
migration seeds an override row for any existing School; deploying
this checkpoint changes zero delivery behavior until a School
explicitly visits Settings → Channels (proven: `SchoolChannelPolicyServiceTest::a_school_with_no_override_gets_the_fully_permissive_system_default`).

## 7. Communication requirement

`App\Domain\Communications\Domain\CommunicationRequirement`:
`Optional` (default) / `Required`. A new
`communication_announcements.requirement` column (CHECK, default
`'optional'`). Gated by `communications.manage` — the SAME capability
that already governs thread/participant administration and channel-
policy settings (brief §8: reused, not a new capability). A forged
`requirement: 'required'` HTTP payload from a `communications.announce`-
only sender (e.g. `principal`, who has `.announce` but not `.manage`)
fails validation (422) — proven in
`AnnouncementHubTest::a_sender_without_communications_manage_cannot_mark_an_announcement_required`.
Templates carry no requirement field at all (brief §37) — applying a
template can never grant authority the applying user doesn't already
have.

## 8. Priority vs requirement

Deliberately independent, unrelated columns.
`CommunicationPriority::Critical` does not imply `Required`;
`CommunicationPriority::Normal` does not imply `Optional`. Priority
answers "how important is this to the reader"; Requirement answers
"may a recipient's optional-channel preference suppress this."
`App\Domain\Communications\Domain\CommunicationRequirement`'s own
docblock states this explicitly, and `CommunicationAnnouncement::requirementEnum()`
is a separate accessor from `priorityEnum()`.

## 9. Policy precedence (implemented exactly)

`App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService::evaluate()`,
in order:

1. `$schoolMembershipId === null` (recipient failed the final
   eligibility re-check, e.g. membership lapsed between audience
   resolution and this call) → **SUPPRESS** `recipient_ineligible`,
   for every channel, unconditionally.
2. Channel is `IN_APP` → **ALLOW** `canonical_in_app`, unconditionally
   — never consults School policy or preference (see §15).
3. Channel is anything other than `EMAIL` → **SUPPRESS**
   `unsupported_channel` (defensive; today only `in_app`/`email` are
   ever requestable at all — `AnnouncementService::SUPPORTED_CHANNELS`).
4. Requirement is `Required` → School's `required_allowed` for this
   channel decides ALLOW/SUPPRESS (`school_required_channel_disabled`)
   — recipient preference is NEVER consulted on this branch.
5. Requirement is `Optional` → School's `optional_allowed` decides
   first (`school_optional_channel_disabled` if false); only if true
   AND `recipient_can_opt_out` is true is the recipient's own stored
   preference consulted (`recipient_preference_disabled` if
   `'disabled'`); otherwise **ALLOW**.

Every branch proven directly in `CommunicationChannelPolicyServiceTest`
(10 tests, one per precedence rule/edge case) and end-to-end in
`AnnouncementPolicyIntegrationTest`.

## 10. Policy decision model

`App\Domain\Communications\Application\Policy\CommunicationPolicyDecision`:
`allowed: bool`, `reason: CommunicationPolicyReason` (a closed enum —
`allowed`, `canonical_in_app`, `recipient_preference_disabled`,
`school_optional_channel_disabled`, `school_required_channel_disabled`,
`recipient_ineligible`, `unsupported_channel`). Deliberately excludes
any provider/transport-failure reason
(`email_transport_unavailable` etc.) — those belong to a real delivery
ATTEMPT (`CommunicationDeliveryResult`, Phase 5A.3), never to a policy
decision made before a delivery even exists.

## 11. Suppression vs failure

```text
ALLOW    -> CommunicationDeliveryFactory::createDelivery() (existing, unchanged)
SUPPRESS -> CommunicationDeliveryPolicyDecision row -- no CommunicationDelivery ever created
```

A SUPPRESS never creates a `CommunicationDelivery` row at all — there
is no `failed`-status delivery invented to represent "we didn't try."
This is structurally different from Phase 5A.3's `recipient_email_missing`/
`recipient_email_invalid` (a delivery WAS created because policy
ALLOWed it, and it then failed at the driver layer) — proven side by
side in
`AnnouncementPolicyIntegrationTest::three_recipients_with_different_states_are_each_handled_distinctly`
(recipient A: allowed + sent; B: policy-suppressed, no delivery row at
all; C: allowed + failed with `recipient_email_missing`, a real
attempted-and-failed delivery).

## 12. Suppression auditability

`communication_delivery_policy_decisions` (append-only,
`TenantRls::makeAppendOnly()`) — but stores ONLY suppression events,
never ALLOW ones. An ALLOW already has its own durable record: the
resulting `CommunicationDelivery` row IS that evidence (brief §20:
"do not add this table if an existing ledger already models this
cleanly" — for the allowed case, it already does). This keeps row
growth bounded to the genuinely interesting case rather than doubling
row volume for every eligible recipient on every publish. Keyed by
`(school_id, message_id, recipient_user_id, channel)` — a plain
`recipient_user_id` (`App\Models\User`), NOT a `recipient_id` FK
against `communication_recipients`, since a fully ineligible recipient
never gets a `CommunicationRecipient` row created for them at all (see
§13) and would have nothing to FK against.

## 13. School policy administration

`/app/communications/settings/channels` (`CommunicationChannelPolicyController`),
gated by `communications.manage`. Exposes IN_APP as a read-only
informational row (its policy is never actually consulted by
`evaluate()` — writing an override for it would be dead data the
engine never reads, so the update endpoint's validation rejects
`channel: 'in_app'` outright) and EMAIL as the one real, editable
target (three checkboxes: optional-allowed, required-allowed,
recipient-can-opt-out). No provider credentials, no SMTP config, no
SMS/WhatsApp provider setup exposed here (brief §26).

## 14. Recipient preference UI

`/app/communications/preferences` (`CommunicationPreferenceController`),
**deliberately NOT gated by any `communications.*` capability** — this
was a genuine finding during test-writing: an ordinary member with no
Communications role (someone who can still receive announcements)
must be able to manage their OWN email preference, since it is
inherently self-scoped and cannot affect anyone else (root CLAUDE.md
rule 24 argues the other way here: a role-based capability check would
be the wrong tool for a setting every active member has regardless of
role). The only gate is `currentMembership()`'s own 403 — reachable
only by a genuine active member of the current School. Shows IN_APP as
always-on/non-configurable text, and one real toggle for optional
email. Never called a marketing "unsubscribe center" (brief §27) —
School OS has no marketing-campaign concept.

## 15. Canonical IN_APP semantics

**The Communication Hub is the canonical school communication
record.** A recipient preference can never remove IN_APP visibility —
`evaluate()` returns `ALLOW`/`canonical_in_app` for IN_APP
unconditionally (once the recipient is eligible), never even reading
`policyFor()`/a stored preference for that channel. There is no
IN_APP row in `communication_preferences` at all — the table's own
CHECK constraint (`channel IN ('email')`) makes writing one
structurally impossible, not just discouraged by convention. This is
distinct from a future PUSH notification preference (brief §10): a
push "should I alert you" toggle is a genuinely different concept
(whether your device buzzes) from "does this message exist in your
Communication Hub" (it always does) — that distinction is documented
here explicitly as the seam a future PUSH checkpoint should respect,
not conflate.

## 16. Optional communication behavior

`Requirement::Optional` + School `optional_allowed=true` +
`recipient_can_opt_out=true` (all three the system default): the
recipient's own stored `enabled`/`disabled` preference decides EMAIL;
no stored row means the same "allowed" behavior every School already
had pre-5A.5 (§6). `recipient_can_opt_out=false` means the School has
decided optional EMAIL is never individually suppressible on this
channel — the preference is never even read.

## 17. Required communication behavior

`Requirement::Required`: a recipient's `disabled` EMAIL preference is
bypassed IF AND ONLY IF the School's own `required_allowed=true` for
that channel — School policy remains fully authoritative regardless of
requirement (brief §24, proven:
`required_email_is_still_suppressed_when_school_required_policy_denies_it`).
"Required" never means "every requested channel is guaranteed to
technically succeed" — Phase 5A.3's global `COMMUNICATION_EMAIL_ENABLED`
gate and real address validity remain fully independent, downstream
concerns (§20 below).

## 18. Scheduled communication behavior

**Policies and preferences are evaluated at actual PUBLICATION time,
never frozen at schedule-creation time.** This required zero new code
— `AnnouncementService::publish()` (reused unchanged by
`App\Console\Commands\PublishScheduledAnnouncements` for a due
`scheduled` row, Phase 5A.4) is the ONLY place policy evaluation
happens, and it always reads the CURRENT `communication_preferences`/
`communication_channel_policies` state at the moment it actually runs.
Proven both directions in `ScheduledPolicyTest`: a preference disabled
after scheduling but before due time suppresses email at publication;
a preference enabled after scheduling but before due time permits it
— in both cases the schedule-time preference is provably irrelevant.

## 19. Policy snapshot semantics

**A preference change never rewrites a historical decision.** Once
`publish()` commits, the `CommunicationDelivery` row (ALLOW) or
`CommunicationDeliveryPolicyDecision` row (SUPPRESS) it produced is
final — nothing re-evaluates it later. Proven directly, both
directions, in `AnnouncementPolicyIntegrationTest`:

- **Scenario A** (brief §44): email enabled → publish → delivery
  created and sent → preference disabled five seconds later → the
  historical delivery's `status` is provably unchanged (`sent`).
- **Scenario B**: email disabled → publish → suppressed, zero email
  deliveries → preference enabled later → the already-published
  message is provably NOT retroactively emailed (delivery count for
  that channel stays 0, `Mail::assertNothingSent()`).

## 20. Multi-school user behavior

One `User`, two `SchoolMembership` rows, independently-set EMAIL
preferences — proven at the service layer
(`CommunicationPreferenceServiceTest::the_same_user_has_independent_preferences_in_two_different_schools`)
and, implicitly, by the general RLS isolation proofs (a preference row
is only ever visible/writable under its OWN School's RLS context, so
cross-School leakage is structurally impossible regardless of shared
`user_id`).

## 21. Tenant/RLS isolation

All three new tables: `TenantRls::enable()`,
`communication_delivery_policy_decisions` additionally
`makeAppendOnly()`. Proven at the raw-SQL level for every table (14
tests across `CommunicationChannelPoliciesRlsIsolationTest`,
`CommunicationPreferencesRlsIsolationTest`,
`CommunicationDeliveryPolicyDecisionsRlsIsolationTest`): RLS enabled +
forced, no-context sees zero rows, School A cannot SELECT/UPDATE/
(where applicable) INSERT for School B, a cross-School composite-FK
reference is rejected at insert time, and the runtime role cannot
UPDATE/DELETE an append-only decision row.

## 22. Authorization

- **Recipient preference** (own): no capability — see §14.
- **School channel policy** (admin): `communications.manage`.
- **Marking an Announcement Required**: `communications.manage`
  (dynamic-allowed-values validation, same pattern as the existing
  EMAIL-channel-availability gate).
- Every write re-derives School/actor from `TenantContext`/session,
  never from client-supplied ids — a forged cross-School membership id
  in a preference-update payload is structurally ignored (the
  controller never reads one from the request at all), and a forged
  cross-School announcement/template/policy id 404s or is silently
  ignored per each existing established pattern.

## 23. Performance / query strategy

`CommunicationChannelPolicyService` is resolved fresh per HTTP
request/command run (never a singleton, same as `AnnouncementService`
itself) and keeps a private in-memory cache for that instance's
lifetime only — safe because it never outlives one
request/command invocation. School policy: ONE query per School,
reused for every subsequent `evaluate()` call regardless of recipient/
channel count (proven:
`school_policy_is_loaded_once_per_school_regardless_of_evaluation_count`,
0 additional queries across 20 more lookups). Preferences: ONE
batched query per (School, channel, audience CHUNK) via
`preloadPreferences()`, mirroring `AnnouncementService::publish()`'s
existing email-address-resolution batching exactly — proven at the
query-count level for a 30-membership chunk (3 total queries: the one
real `SELECT`, plus `TenantContext::withSchool()`'s own unrelated
set/clear overhead — never 30). `evaluate()` itself performs zero I/O
— it is a pure function over already-loaded caches.

## 24. Tests

61 new tests: `CommunicationChannelPolicyServiceTest` (10, every
precedence rule), `CommunicationPreferenceServiceTest` (3),
`SchoolChannelPolicyServiceTest` (3),
`CommunicationChannelPoliciesRlsIsolationTest` (5),
`CommunicationPreferencesRlsIsolationTest` (5),
`CommunicationDeliveryPolicyDecisionsRlsIsolationTest` (4),
`AnnouncementPolicyIntegrationTest` (6: optional-suppresses,
required-bypasses, required-still-respects-school-policy, the
three-distinct-recipient-states proof, and both mandatory snapshot
scenarios), `ScheduledPolicyTest` (2, both directions of the mandatory
due-time-not-schedule-time proof), `CommunicationPreferenceHubTest`
(4), `CommunicationChannelPolicySettingsHubTest` (7),
`AnnouncementHubTest` (+3: required-communication authorization),
`AnnouncementPolicyScaleTest` (2, direct query-count proofs).

## 25. Safety

Every email-touching test uses `Mail::fake()` (Phase 5A.3's
established discipline, unchanged); no live provider credential
touched; `COMMUNICATION_EMAIL_ENABLED` remains `false` by default and
untouched by this checkpoint; School channel policy can never turn
email on when it is globally disabled (§20 of the brief — the two
layers are fully independent, `EmailChannelDriver`'s own send-time
`config('communications.channels.email.enabled')` check is completely
unaffected by anything in this checkpoint); no SMS/WhatsApp/Push
external call anywhere (their driver/enum seams remain unregistered,
untouched); no staging/production change of any kind.

## 26. Deferred functionality

Unchanged from the brief's explicit out-of-scope list (§61): provider
webhooks, bounce/open tracking, inbound email/Reply-To, SMS/WhatsApp/
Push integration, custom sender domains, quiet hours, approval
workflow, emergency escalation, all AI features, communication-
category-specific preferences (`fees.email`, `attendance.email`, ...),
Student/Guardian/Class/Section/Grade audiences or preferences,
attendance/fee/transport/campaign automation, a marketing unsubscribe
system. `App\Models\SchoolMembership` remains the sole preference/
policy-evaluation identity — the seam for Phase 1A Student/Guardian
identity to map into this same architecture is exactly what §42 of the
brief anticipated: a future Guardian identity that resolves to (or is
represented by) a `SchoolMembership`-shaped row can reuse this
unchanged; nothing here assumes only staff/admin memberships exist.

## Recommended next checkpoint

**Phase 5A.6 — Attachments & Rich Communication Foundation.**
Reasoning: this checkpoint (5A.5) completed the full policy/preference
layer sitting between "what was requested" and "what actually gets
delivered" — the Communication Hub's DECISION layer is now complete
for every currently-implemented channel. Of the four candidates
evaluated:

- **Quiet Hours & Delivery Timing Policy** is a natural NEXT layer
  (brief §40 itself defers it, correctly — it needs its own timezone/
  emergency-bypass/scheduler-interaction design, and now has a real
  policy-engine seam to plug into: a quiet-hours check would slot in
  as an additional `evaluate()` precedence step). A strong second
  choice, but deliberately still one increment further out.
  - **Approval Workflow Foundation** has no concrete driver yet — no
  module in this codebase currently NEEDS a communication to be
  approved before publishing, and inventing the workflow now would be
  exactly the "speculative infrastructure" root CLAUDE.md rule 2
  rejects.
- **Email Provider Event/Webhook Foundation** still has no real
  external provider configured or authorized (`COMMUNICATION_EMAIL_ENABLED=false`
  throughout every checkpoint so far) — Phase 5A.3's own closing
  recommendation already identified this gap and it remains true here:
  building bounce/webhook handling now would mean guessing a payload
  shape rather than integrating a real one.
- **Attachments & Rich Communication** is pure content-shape work
  (object storage is already established elsewhere in this codebase,
  ADR 0011) with no unresolved cross-cutting policy question left to
  answer first, and directly extends the one piece of the
  Announcement/Template content model (`body`/`subject`, still plain-
  text-only since Phase 5A.3) that hasn't been revisited since it was
  first built.

Not starting Phase 5A.6 from this branch.
