# Phase 5A.2 — Announcement & Audience Resolution Foundation

Branch: `feature/phase-5a-communication-hub` (Phase 5A.1 committed at
`8edb8b9b19ceb6be23edb4b2c69cccd5c4c71c46`). See
`docs/communication-hub/PHASE-5A-1-FOUNDATION.md` for the Communication
Hub architecture this checkpoint extends — it is not re-derived here.

---

## 1. Announcement architecture

Per the brief's flow diagram (§5), an Announcement is deliberately
**not** a Thread. A Thread is a conversational context between known
participants (Phase 5A.1); an Announcement is a one-to-many broadcast
to a *computed* audience. Reusing Thread for this would mean creating
a `thread_participants` row per resolved recipient purely so a message
could exist — wasted writes for a concept (participation, muting,
per-participant `left_at`) that doesn't apply to a broadcast recipient.

Instead, `communication_messages.thread_id` was made **nullable** and a
new nullable `announcement_id` column added (migration
`2026_08_23_100700_add_announcement_support_to_communication_messages_table.php`),
with a `CHECK (num_nonnulls(thread_id, announcement_id) = 1)` constraint
— a message belongs to **exactly one** container, structurally enforced,
never both, never neither. This is the only schema change to Phase
5A.1's tables; every other 5A.1 table, model, and service is untouched
except for two small extractions covered in §2.

```text
CommunicationAnnouncement (draft: title/body/priority/audience_type)
    ↓ publish()
Audience Resolver (registry, dispatches on audience_type)
    ↓
ResolvedAudience (deduplicated user ids + category breakdown)
    ↓
communication_announcement_recipients  (immutable snapshot, append-only)
    ↓
CommunicationMessage (announcement_id set, thread_id NULL — the canonical, immutable content)
    ↓
CommunicationRecipient × N              (Phase 5A.1's message-recipient table, reused)
    ↓
CommunicationDelivery (in_app) × N      (Phase 5A.1's delivery ledger, reused)
    ↓
ProcessCommunicationDeliveryJob         (Phase 5A.1's job, reused, dispatched after commit)
```

No second delivery system was built (brief §5's explicit instruction).
`CommunicationRecipient`/`CommunicationDelivery`/`ProcessCommunicationDeliveryJob`
are exactly the same classes 5A.1's thread messages use.

### 1.1 Why draft `title`/`body` live on the Announcement, not the Message

`CommunicationMessage` has no draft state in 5A.1 — it is created,
immutable, at send time. Rather than adding a `draft` status to the
canonical message table (which would ripple into every existing
Communication Hub read path), `communication_announcements.title`/
`.body` are the draft content store. At publish time their values are
copied once into a newly-created `CommunicationMessage` row
(`announcement_id` set), which becomes the canonical, permanent record;
`communication_announcements.message_id` links to it. After publish, no
edit endpoint exists (§3), so the two copies never diverge.

---

## 2. Reused vs. new: what changed in Phase 5A.1 code

Two small, behavior-preserving extractions, not rewrites (root
CLAUDE.md rule 2 spirit — extend, don't duplicate):

- **`App\Domain\Communications\Application\CommunicationDeliveryFactory`**
  (new) — `createRecipient()`/`createInAppDelivery()`, extracted
  verbatim out of `CommunicationMessageService`'s former private
  `createDelivery()` method (same idempotent-savepoint-and-catch
  pattern, unchanged). `CommunicationMessageService::send()` and the new
  `AnnouncementService::publish()` both inject and call it — brief §16's
  "do not duplicate delivery creation logic" satisfied structurally,
  not by convention alone.
- **`App\Models\SchoolMembership::scopeActive()`** (new) — centralizes
  the `where('status', 'active')` predicate that `CapabilityResolver`
  and `CommunicationThreadService::addParticipant()` each independently
  hand-wrote before this checkpoint (brief §13: "Centralize eligibility
  logic. Do not scatter..."). Both call sites were retrofitted to use
  it; behavior is identical (same SQL), confirmed by the full regression
  suite still passing.

---

## 3. Announcement status: a deliberately smaller set than the brief's "may include" list

The brief §7 lists `DRAFT|READY|PUBLISHING|PUBLISHED|PARTIALLY_DELIVERED|FAILED|CANCELLED`
as concepts that *may* be included, then immediately warns: "avoid
adding statuses that cannot yet be reached meaningfully." This
checkpoint implements only **`draft` → `published`** and **`draft` →
`cancelled`** (a `CHECK (status IN ('draft', 'published', 'cancelled'))`
constraint) because:

- `READY` implies an approval/review step 5A.2 does not implement.
- `PUBLISHING` would only be observable as a distinct, queryable state
  under an *asynchronous* publish flow. Publishing here is fully
  synchronous — audience resolution, snapshot, message, recipients, and
  deliveries all happen inside one `DB::transaction()` in the same
  request — so a persisted `publishing` row would never actually be
  visible to a reader; it would transition to `published` (or roll back
  entirely) before any other connection could see it.
- `PARTIALLY_DELIVERED`/`FAILED` (as *announcement*-level states,
  distinct from a `CommunicationDelivery`'s own `failed` status) would
  only be meaningful once a channel exists whose deliveries can
  genuinely fail. `in_app` cannot fail (`InAppChannelDriver` always
  succeeds) — the only registered driver today.

**Idempotent publish is the real mechanism, not a `publishing` status.**
`AnnouncementService::publish()` executes one atomic conditional
`UPDATE communication_announcements SET status = 'published', ... WHERE
id = ? AND status = 'draft'` as the very first statement inside the
transaction — the same "atomic claim before any side effect" discipline
`App\Jobs\DeliverWebhookJob::claim()` and
`App\Jobs\ProcessCommunicationDeliveryJob::claim()` already established.
Zero rows affected means either "already published" (return the
existing state as a no-op — brief §18's exact scenario) or "not a
draft" (`cancelled` → `InvalidAnnouncementTransitionException`). Any
exception thrown *after* a successful claim — including
`EmptyAudienceException` — rolls back the **entire** transaction,
including the claim itself, so the Announcement genuinely reverts to
`draft` rather than getting stuck half-published (brief §17, proven in
`AnnouncementServiceTest::publishing_with_an_empty_resolved_audience_throws_and_leaves_the_announcement_a_draft`).

Widening the `status` CHECK constraint later (e.g. adding `partially_delivered`
once a real external channel exists, or `ready` once an approval
workflow lands) is additive, never destructive.

---

## 4. Audience definition model

### 4.1 `communication_announcements.audience_type`

Rather than a separate `communication_announcement_audiences` table for
a 1:1 relationship 5A.2 doesn't yet need (no composite/multi-rule
audiences are supported — brief §11 explicitly says so), `audience_type`
(`individual|school_wide`, `CHECK`-constrained) lives directly on the
Announcement row. The conceptual "Audience Definition" from the brief's
flow diagram exists as a PHP value concept (the audience_type +, for
`individual`, the linked member rows below), not as its own table — a
deliberate simplification the brief's §6 explicitly permits ("do not
blindly implement these exact columns").

### 4.2 `communication_announcement_audience_members`

The **authored** explicit member list for an `individual` Announcement
(empty/unused for `school_wide`). Composite foreign key against
`school_memberships(id, school_id)` — the same structural pattern
`membership_role_assignments` already established (root CLAUDE.md rule
70) — so a cross-School `school_membership_id` is rejected by the
database at INSERT time, proven in
`CommunicationAnnouncementsRlsIsolationTest::a_cross_school_audience_member_reference_is_rejected_at_insert_time`.
`unique(announcement_id, school_membership_id)` deduplicates at
authoring time.

### 4.3 Deferred: `campus_wide`

`CommunicationAudienceType` deliberately has **no** `CampusWide` case.
Investigated per brief §9's instruction ("if campus targeting is not
safely representable yet: design for it; document it; defer
execution") — `SchoolMembership` has no `campus_id` column, and no
membership↔campus relationship exists anywhere in the codebase today
(confirmed via `grep`). `communication_announcements.campus_id` exists
as a nullable, informational tag only (mirroring
`communication_threads.campus_id` from 5A.1) — it does not drive
resolution. Adding `CampusWide` later is an additive enum case + a new
`CampusAudienceResolver` registered in `PlatformServiceProvider`, no
change to `AnnouncementService` or the schema's shape.

### 4.4 Other future audience types

`STUDENT`/`GUARDIAN`/`CLASS`/`SECTION`/`GRADE`/`SUBJECT`/`HOUSE`/`CLUB`/
`TRANSPORT_ROUTE`/`CUSTOM_GROUP` (brief §9/§25/§26) are not implemented
and no placeholder classes exist for them (brief §26: "do not create
empty speculative classes merely for appearances"). Each becomes a new
`CommunicationAudienceType` enum case (additive to the CHECK
constraint) plus a new class implementing
`App\Domain\Communications\Application\Audience\CommunicationAudienceResolver`,
registered in `PlatformServiceProvider`'s existing registry-building
closure — see §7 below for exactly what each future resolver needs.

---

## 5. Audience resolution

`App\Domain\Communications\Application\Audience\CommunicationAudienceResolver`
(interface) + `CommunicationAudienceResolverRegistry` (audience_type =>
resolver map) mirror `CommunicationChannelDriver`/`CommunicationChannelRegistry`'s
shape from Phase 5A.1 exactly, deliberately not the same interface
(brief §8: "Audience definition and channel destination resolution are
separate concerns"). A resolver returns
`App\Domain\Communications\Application\Audience\ResolvedAudience` — a
deduplicated `array<string> $userIds` plus a `categoryBreakdown` for
the preview UI (§8 below) — never a provider address.

Two resolvers registered in `PlatformServiceProvider`:

- **`IndividualMembersAudienceResolver`** — reads the authored
  `communication_announcement_audience_members` rows, re-validates
  `sm.status = 'active'` **at resolve time** (not just at authoring
  time — a member's status can change between drafting and publishing,
  brief §13), excludes the Announcement's creator.
- **`SchoolWideAudienceResolver`** — every active `SchoolMembership` in
  the School, excluding the creator. One `LEFT JOIN` query (not an
  unbounded `whereIn()` list, not N+1) against
  `school_memberships`/`membership_role_assignments`/`roles` builds
  both the deduplicated user id list and the role-name breakdown in a
  single round trip.

Both resolvers wrap their query in `TenantContext::withSchool()`
defensively (the same reasoning `CommunicationMessageService::send()`'s
participant check has — a resolver may be called from a context that
doesn't already match the Announcement's School).

### 5.1 Why the creator is excluded from their own audience

Mirrors Phase 5A.1's `CommunicationMessageService::send()`, which
excludes the sender from a thread message's recipients — a person
publishing a broadcast does not need an unread in-app notification of
their own announcement. Proven in
`AnnouncementServiceTest::the_creator_is_excluded_from_their_own_school_wide_announcement_audience`.

---

## 6. Deduplication and immutability

**Deduplication** happens at the resolver layer (`ResolvedAudience`'s
`userIds` are unique by construction — a JOIN keyed by `sm.user_id`
with a "first row wins" guard in `SchoolWideAudienceResolver`, and a
`->distinct()` query in `IndividualMembersAudienceResolver`), proven by
`AnnouncementServiceTest::a_school_wide_audience_deduplicates_and_reports_a_deterministic_count`.
Brief §11's invariant ("a person matching an audience multiple ways
should remain one logical recipient") is already satisfied by this
single-source-per-audience-type design; a future multi-rule composite
audience (e.g. Class A + Football Club) would need its resolver to
union and deduplicate across rules before returning — the
`ResolvedAudience` contract already requires deduplicated output, so no
downstream code changes when that day comes.

**Immutability**: `communication_announcement_recipients` is the
durable, append-only (`TenantRls::makeAppendOnly`) resolved-audience
snapshot — written once, at publish time, never re-resolved on view.
If a member's `SchoolMembership` status changes after publish, this
table still shows the original resolved set (brief §10's exact "842
targets, three later leave" scenario) — `communication_announcements.recipient_count`
is the count taken at that moment, never recomputed.

---

## 7. Idempotent publish

Guaranteed by **one** mechanism, not several overlapping ones (root
CLAUDE.md rule 30: never a check-then-insert):

1. The atomic `UPDATE ... WHERE status = 'draft'` claim (§3) is the
   single-winner gate — a second `publish()` call for an already-
   published Announcement affects 0 rows and returns immediately,
   never reaching the resolve/snapshot/message/recipient/delivery code
   at all.
2. Defense in depth, exercised only if the claim mechanism were ever
   bypassed (it structurally can't be, since `AnnouncementService::publish()`
   is the sole write path): `communication_announcement_recipients`'
   `unique(announcement_id, user_id)` and `communication_deliveries`'
   `unique(recipient_id, channel)` (Phase 5A.1) would both reject a
   duplicate row.

Proven end-to-end in
`AnnouncementServiceTest::publishing_the_same_announcement_twice_does_not_duplicate_recipients_or_deliveries`
— message id and `published_at` are identical across both calls, and
exactly one snapshot row exists per resolved member.

---

## 8. Audience preview

No separate JSON/preview endpoint was added — `AnnouncementController::show()`
computes a live preview via `AnnouncementService::previewAudience()`
(the *same* resolver `publish()` uses) whenever the Announcement is
still a `draft`, and passes it as an ordinary Inertia prop. This keeps
the whole composer flow inside plain Inertia GET/POST/PUT actions
matching every other controller in this codebase (no ad hoc JSON route
introduced). Once published, `preview` is `null` and the page instead
shows the durable `recipient_count` from the snapshot — never a
re-resolved live count (brief §14: "the definitive recipient snapshot
must still be produced during publish... so stale preview data does not
become authoritative").

The category breakdown only shows labels that reflect real,
currently-assignable School roles (`school_admin`/`principal` today) —
"Administrators/Teachers/Staff/Other Members" from the brief's example
UI is **not** reproduced verbatim, because no Teacher/Staff/Student
identity exists yet (§9 of this doc). A member with no role assignment
falls into an honest `"Other Members"` bucket instead.

---

## 9. Membership eligibility

Centralized in `App\Models\SchoolMembership::scopeActive()` (§2) — both
new audience resolvers and the audience-member-authoring/re-validation
logic in `AnnouncementService::syncAudienceMembers()`/`snapshotRecipients()`
use it. A `suspended` or `invited` membership is never included, proven
in `AnnouncementServiceTest::an_inactive_member_is_excluded_from_a_school_wide_audience`.

---

## 10. Large-audience scaling boundary (brief §19)

`AnnouncementService::publish()` chunks resolved user ids into batches
of 500 (`AnnouncementService::CHUNK_SIZE`) for both the recipient
snapshot insert and the `CommunicationRecipient`/`CommunicationDelivery`
creation loop — bounding memory and avoiding one gigantic `INSERT`
statement, while staying inside the single publish transaction (so the
idempotency/atomicity guarantees in §7 still hold for the whole
Announcement, not per-chunk). The snapshot insert itself is a genuine
batch `insert()` (bypassing Eloquent events, ids generated manually via
`Symfony\Component\Uid\UuidV7` — the same generation Phase 0B's
`GeneratesUuidV7` trait uses under the hood); `CommunicationRecipient`/
`CommunicationDelivery` creation stays per-row through
`CommunicationDeliveryFactory` because its idempotent-savepoint pattern
is a per-row concern.

**Documented boundary**: this is safe for a single School's realistic
membership count today (hundreds to low thousands) inside one HTTP
request's transaction and timeout budget. It is explicitly **not** a
distributed bulk/campaign-dispatch system — brief §19's "do not
prematurely implement distributed campaign infrastructure" is honored.
A School with tens of thousands of members publishing school-wide would
need a genuinely async publish flow (queue the resolution+snapshot+
delivery-creation work itself, not just delivery *processing* as today)
— deferred to a future checkpoint if/when a real School's scale
requires it.

---

## 11. Tenant safety

Every new table (`communication_announcements`,
`communication_announcement_audience_members`,
`communication_announcement_recipients`) uses `BelongsToSchool` +
`TenantRls::enable()` (Postgres RLS, forced) exactly like every other
Communication Hub table. Every cross-table reference to a School-scoped
parent is a composite FK against `(id, school_id)`. Proven at the raw-
SQL layer, independent of Eloquent, in
`tests/Feature/Postgres/CommunicationAnnouncementsRlsIsolationTest.php`
(6 tests: RLS enabled+forced, no-context isolation, cross-School read
isolation, cross-School write isolation, append-only enforcement for
the recipient snapshot, and the cross-School composite-FK rejection
test in §4.2).

`AnnouncementController` re-derives the active School from
`TenantContext::requireSchool()` on every action — `school_id` never
comes from request input (root CLAUDE.md rule 19/68). Cross-School
route access returns 404 (`findOrFail()` inside the active School's RLS
context finds nothing for another School's id), proven in
`AnnouncementHubTest::school_a_cannot_view_school_bs_announcement` and
`::school_a_cannot_publish_school_bs_announcement`.

---

## 12. Authorization

`communications.announce` (new capability) — evaluated and deliberately
**not** folded into the existing `communications.send`. `.send` starts
a Thread with an explicit, bounded participant list the sender chose
themselves; publishing an Announcement targets a *computed*, often
School-wide audience — a materially larger blast radius that warrants
its own capability. Granted to the same roles as `.send` today
(`school_admin`, `principal`) — no School role grants it to an ordinary
member (brief §22: "do not assume ordinary students/guardians can
publish school-wide announcements" — trivially satisfied, since no
Student/Guardian role exists yet either).

Every `AnnouncementController` action uses `AuthorizesCapability`
exactly like `CommunicationHubController`. `update()`/`publish()`/
`cancel()` additionally require the actor be either the Announcement's
creator or hold `communications.manage` — mirroring
`CommunicationHubController::show()`'s participant-or-manage pattern
from 5A.1.

---

## 13. Audit events

`announcement.created`, `announcement.updated`, `announcement.published`,
`announcement.cancelled` — written via `AuditRecorder::school()`
exactly like every other Communication Hub action, no new audit table.
Brief §23's `announcement.audience_resolved` is folded into
`announcement.published`'s metadata (`audienceType`, `resolvedCount`)
rather than a separate audit row — resolution and publication happen
atomically in the same transaction, so a separate event with its own
timestamp would be redundant. No message body or recipient list is
ever written to audit metadata (root CLAUDE.md rule 44/55) — the
canonical `CommunicationMessage`/`communication_announcement_recipients`
rows remain the source of that content.

Three outboxed domain events (not webhook-registered, same as 5A.1):
`CommunicationAnnouncementCreated`, `CommunicationAnnouncementPublished`,
`CommunicationAnnouncementCancelled`.

---

## 14. Routes and UI

```
GET  /app/communications/announcements                     index    communications.view
GET  /app/communications/announcements/create               create   communications.announce
POST /app/communications/announcements                      store    communications.announce
GET  /app/communications/announcements/{announcement}        show     communications.view (+ creator/recipient/manage)
PUT  /app/communications/announcements/{announcement}        update   communications.announce (+ creator/manage), draft only
POST /app/communications/announcements/{announcement}/publish   publish  communications.announce (+ creator/manage)
POST /app/communications/announcements/{announcement}/cancel    cancel   communications.announce (+ creator/manage)
```

Registered under the existing `/app/communications` prefix, **before**
the 5A.1 `/{thread}` wildcard route so `announcements` never resolves
as a thread id. `resources/js/Pages/App/Communications/Announcements/{Index,Create,Show}.vue`
— same self-contained `<script setup>` Tailwind style as 5A.1's pages
(no shared app shell exists yet to plug into). The "Announcements" nav
item in `Communications/Index.vue`, previously a greyed-out disabled
placeholder, is now a real link; `Scheduled`/`Drafts`/`Failed` remain
disabled placeholders (brief §20: "do not show future navigation items
that remain nonfunctional" — Announcements is now functional, the rest
still are not).

`Index.vue` is this codebase's **first** use of Laravel pagination
(`CommunicationAnnouncement::query()->paginate(20)`, brief §20's
explicit pagination requirement) — no prior precedent existed to match,
so the standard Laravel/Inertia paginator shape (`{data, current_page,
last_page, total}`) was used directly.

---

## 15. Tests

35 new tests, all passing against real PostgreSQL:

- `tests/Feature/Communications/AnnouncementServiceTest.php` — 15 tests
  (draft creation, invalid audience member rejection, draft update,
  invalid edit/cancel transitions on a published Announcement,
  idempotent double-cancel, empty-audience rejection + rollback,
  end-to-end publish creating message/snapshot/recipients/deliveries,
  creator exclusion, inactive-member exclusion, deduplication +
  deterministic count, individual-audience resolution, idempotent
  double-publish).
- `tests/Feature/Postgres/CommunicationAnnouncementsRlsIsolationTest.php`
  — 6 tests (RLS enabled+forced, no-context isolation, cross-School read
  isolation, cross-School write isolation, append-only enforcement,
  cross-School composite-FK rejection).
- `tests/Feature/App/AnnouncementHubTest.php` — 9 tests (guest redirect,
  no-role denial, composer denial, empty state + `canAnnounce` prop,
  full draft→preview→publish→index flow, empty-audience validation
  error, non-creator/non-manager publish denial, cross-School 404 on
  both `show` and `publish`).

Full regression: **387/388 passing** — identical shape to the Phase
5A.1 checkpoint's own result. The one failure
(`Tests\Feature\RateLimiting\LoginThrottleTest::the_seventh_login_attempt_within_a_minute_is_throttled`)
was reproduced and diagnosed rather than assumed: running it in
isolation immediately after `FLUSHALL` on the shared Redis instance
passes cleanly (2/2), but re-running the *full* suite reintroduces the
failure even right after a fresh flush — consistent with a concurrent
session on this shared machine also exercising the same Redis-backed
login rate limiter mid-run (`ListAgents` showed other active sessions
at the time), not a code defect in this checkpoint. No Communications/
Announcements test touches login throttling in any way.

`vendor/bin/pint --test` (398 files), `phpstan analyse --memory-limit=512M`
(226 files, 0 errors), `npm run lint` (ESLint, exit 0), `npm run
format:check` (Prettier, exit 0), `npm run type-check` (`vue-tsc
--noEmit`, exit 0) — all clean.

---

## 16. Student/Guardian forward compatibility

Unchanged from 5A.1's finding (`PHASE-5A-1-FOUNDATION.md` §1.1): no
Student/Guardian/Staff/Teacher identity exists anywhere in this
repository (`phase/1a-student-guardian-identity` remains an unstarted
placeholder branch, HEAD-identical to `main`). This checkpoint's
audience resolvers only ever produce `User` ids reached through
`SchoolMembership` — the same constraint 5A.1's thread participants
have. When Phase 1A lands, `StudentAudienceResolver`/
`GuardianAudienceResolver`/`ClassAudienceResolver`/`SectionAudienceResolver`
each:

1. Implement `CommunicationAudienceResolver`, returning `ResolvedAudience`
   with `userIds` reached however that identity ultimately maps to a
   `User` (e.g. a Guardian's own login `User`, or a Student's
   Guardians' `User`s) — the interface does not assume the audience
   member IS a `SchoolMembership` holder directly, only that resolution
   ultimately yields `User` ids.
2. Add their `CommunicationAudienceType` enum case (additive to the
   `audience_type` CHECK constraint — a genuinely non-breaking
   migration, root CLAUDE.md rule 10's spirit).
3. Register in `PlatformServiceProvider`'s existing
   `CommunicationAudienceResolverRegistry` closure — no change to
   `AnnouncementService`, `AnnouncementController`, or any existing
   migration.

No destructive restructuring is required of anything shipped in this
checkpoint (brief §26).

---

## 17. Deferred work (unchanged from brief §24/§25 unless noted)

Student/Guardian-specific audiences, class/section/grade audiences,
WhatsApp/SMS/email/push provider delivery, templates, scheduling,
approval workflow, AI, emergency escalation — all deferred, none
started. Additionally, specific to this checkpoint: `CampusAudienceResolver`
(§4.3 — no reliable data to resolve it from yet), multi-rule/composite
audiences (§11 — the invariant is proven but only one audience source
exists to compose), true async/bulk campaign-scale publish (§10 — a
documented boundary, not implemented), and message editing/attachments
(unchanged from 5A.1, no canonical file/document model exists yet).

## 18. Known limitations

- Publishing to a School with tens of thousands of eligible members
  happens synchronously inside one HTTP request/transaction — see §10's
  documented scaling boundary.
- The audience-preview category breakdown reflects the two seeded
  school-scoped roles only (`school_admin`, `principal`); a School with
  finer-grained future roles will see more specific breakdown labels
  automatically (the query already groups by whatever `roles.name`
  actually exists), no code change needed.
- No JSON API surface for Announcements (`/api/v1/schools/{school}/announcements`)
  — same limitation 5A.1 documented for the whole Communication Hub;
  deferred to whichever future checkpoint needs external/mobile access.

## Next Checkpoint

Recommended: continue the Communication Hub's channel breadth or
conversation richness now that both the conversational (5A.1) and
broadcast (5A.2) message paths exist end-to-end on the same delivery
substrate — e.g. a first real external channel adapter (Email, since it
has the lowest integration cost of the four adapter-ready channels), OR
class/section academic-audience resolution once it's product-prioritized
relative to Phase 1A's Student/Guardian timeline. Not started in this
execution.
