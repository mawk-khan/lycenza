# Phase 5A.8 — Communication Search & Operational Inbox Foundation

## 1. Objective

Make the Communication Hub usable as an operational workspace: a
mixed Inbox/Unread/Sent view across Conversations and Announcements,
a bounded metadata search, and a Failed-delivery operational surface
— all composed from the existing 5A.1–5A.7 domain services, with no
new canonical data model and no external search infrastructure.

## 2. Existing read/search architecture

Audited before writing anything:

- No PostgreSQL full-text search, `pg_trgm`, `tsvector`, or GIN index
  anywhere in this codebase's migrations.
- No Laravel Scout, Meilisearch, Elasticsearch, OpenSearch, Algolia,
  or Typesense dependency in `composer.json`.
- Two existing `ILIKE` search implementations: `CommunicationHubController`'s
  conversation subject/participant search (Phase 5A.7) and
  `CommunicationTemplateController`'s template-name search — both
  plain, parameterized, bounded `ILIKE` queries. This checkpoint
  follows the same pattern.
- Pagination convention: Laravel's standard `paginate()` +
  `->withQueryString()`, rendered via a `{data, current_page,
  last_page, total}` shape on the frontend (Announcements index,
  Conversations index).
- `App\Domain\Communications\Application\ConversationReadModel`
  (Phase 5A.7) already established the "bounded, fixed-query-count
  read model, never one query per item" convention this checkpoint's
  new read models follow.
- `communication_deliveries.read_at`/`status='read'` — a column and
  enum value Phase 5A.1's own schema already reserved but no code
  path had ever written until this checkpoint (see §8).

## 3. Search scope

Searchable: conversation thread subject + participant display names
(reused from 5A.7, unchanged), announcement titles, template names
(gated by `communications.templates.manage`). Matching is a plain,
parameterized `ILIKE '%term%'` — case-insensitive, substring-based,
bounded to 10 results per domain per query, most-recent-first among
matches.

Phase 5A.8 does not introduce an external search engine or
unrestricted historical message-body search.

Conversation MESSAGE bodies and announcement BODY text are not
searched — only titles/subjects. No database extension was enabled.

## 4. Deliberately unsupported full-message search

Explicitly deferred, matching the audit finding that no existing safe
mechanism exists: full conversation-message-body search, stemming,
typo tolerance/fuzzy matching, attachment-content search, OCR,
semantic/vector search, and AI-assisted search. Adding any of these
would require either a new database extension (`pg_trgm`/full-text
config) with real index/maintenance cost, or external search
infrastructure — neither justified by this checkpoint's "first-
generation, bounded" scope.

## 5. Inbox semantics

`/app/communications` (relocated from the conversation list — see
§21). Shows, for the CURRENT membership only:

- **Conversations** where the membership is a genuine active,
  non-archived participant (reused from 5A.7's own query).
- **Published Announcements** where the membership's `user_id`
  appears in `communication_announcement_recipients` — the actual
  immutable resolved-recipient snapshot, never "every published
  announcement in the School" and never inferred from
  `communications.view` alone.

Filterable by `type` (conversation/announcement), `priority`
(announcement-only), and `has_attachments` — all server-validated
against a fixed allowlist (§20 below).

## 6. Mixed-domain read model

`App\Domain\Communications\Application\CommunicationInboxReadModel`
composes the SAME authorized queries `CommunicationHubController`/
`AnnouncementController` already use — no new denormalized inbox
table. Presentation is unified through a new named value object,
`CommunicationInboxItem` (matching this module's existing
`CommunicationDeliveryResult`/`ConversationThreadSummary` convention).

**Mixed-domain pagination tradeoff (documented, not hidden):** a true
page-2/page-3 over a UNION of two structurally different tables
(Conversation, Announcement) is fragile to get correct at page
boundaries — an item could be skipped or duplicated across pages
depending on how ties in activity timestamps land relative to the
page cut. Instead, every mixed method (`inbox()`, `unread()`,
`sent()`, `search()`) fetches up to `$limit` of EACH domain
independently, merges, sorts by latest activity, and caps to `$limit`
total — a bounded "Show more" (the client re-requests with a larger
`$limit`) rather than true cursor/offset pagination across domains.
This is deliberately NOT the entire communication history — it is a
capped recent-activity view. `Unread`/`Sent` over-fetch a wider raw
pool (5× the limit, capped at 200) before filtering+re-capping, so a
realistic user's thread/announcement count doesn't quietly under-fill
just because the first raw page happened to be mostly read/authored
by someone else.

**Single-domain surfaces do not have this problem** and use real,
correctness-safe `paginate()`: Scheduled (reuses
`AnnouncementController`'s existing `?status=scheduled` filter — no
new backend) and Failed
(`App\Domain\Communications\Application\CommunicationDeliveryFailureReadModel`).

## 7. Unread model

**Conversations:** unchanged from Phase 5A.7 — `ConversationReadModel::summarize()`'s
existing `last_read_at`-vs-latest-message derivation, reused as-is.

**Announcements:** see §8.

**Combined total** (Hub nav badge): `ConversationReadModel::totalUnreadCount()`
+ `CommunicationInboxReadModel::unreadAnnouncementCount()` — the
latter a single bounded `COUNT` aggregate query (not built by calling
`unread()` and counting, which would waste a full item-hydration pass
on a value that's ultimately just a number).

Never counted as unread: the sender's/publisher's own communication,
drafts, not-yet-published scheduled announcements, policy-suppressed
channel decisions, or failed EMAIL attempts — all filtered out at the
query level (recipient-snapshot-scoped, `status = 'published'` only,
and derived purely from the actor's OWN in-app delivery read state).

## 8. Announcement read-state decision

In-app read state is distinct from email delivery/open status.

No announcement read marker existed before this checkpoint. Rather
than adding a new column, `AnnouncementService::markRead()` reuses
`communication_deliveries.read_at`/`status='read'` — a column and
enum value Phase 5A.1's own migration already reserved (`status`'s
CHECK constraint has included `'read'` since the very first
Communication Hub migration) but that no code path had ever written.
This is the narrowest possible addition: no migration, no new table,
just the first real writer for schema that was always meant to carry
this meaning.

`markRead()` only ever touches the CALLING actor's own IN_APP
delivery row for that specific announcement's message (found via
their own `CommunicationRecipient` row), only promotes a `'delivered'`
row to `'read'` (never touches a `'failed'`/other terminal status,
never touches the EMAIL channel's row), and is a no-op for a
non-recipient or an unpublished announcement. `AnnouncementController::show()`
calls it only when the viewer is a genuine resolved recipient — never
for the creator or a `communications.manage` viewer inspecting their
own or someone else's announcement.

## 9. Sent semantics

`/app/communications/sent` — communications the CURRENT membership
authored: conversation threads where `created_by_user_id` matches,
and published Announcements where `created_by_user_id` matches.
Never another member's authored content, regardless of
`communications.manage` — Sent is "what I sent," not an
administrative "everything anyone sent" view.

## 10. Scheduled semantics

Not a new surface — the Hub nav's "Scheduled" link goes straight to
the existing `/app/communications/announcements?status=scheduled`
filter Phase 5A.4 already built, visible only to `communications.announce`-
capable members (the same gate the Announcements composer already
uses). A scheduled Announcement is not yet a recipient inbox item —
it does not appear in Inbox/Unread until it actually publishes.

## 11. Failed-delivery semantics

`/app/communications/failed`, gated by `communications.manage`.
`CommunicationDeliveryFailureReadModel::failedAnnouncements()` finds
published Announcements with at least one `communication_deliveries`
row at `status = 'failed'`, real `paginate()`d (single-domain, no
mixed-pagination tradeoff); `failureBreakdown()` batches a per-
(announcement, channel, failure_code) count in one query for the
whole page. Never exposes an individual destination address, a
provider error payload, or per-recipient detail — aggregate counts
only, matching `AnnouncementController::channelDeliverySummary()`'s
existing precedent from Phase 5A.5.

Scoped to Announcements: conversation messages only ever use IN_APP,
which — per Phase 5A.1's own design — "can never transiently fail,"
so a real FAILED delivery in this schema is, in practice, always an
Announcement EMAIL delivery today.

## 12. Suppression vs failure

Unchanged 5A.5 semantics, explicitly NOT conflated: FAILED
(`communication_deliveries.status = 'failed'`, a real attempted send
that was rejected/errored) is structurally distinct from SUPPRESSED
(`communication_delivery_policy_decisions`, a policy/preference
decision that never attempted a send at all). The Failed surface
queries `communication_deliveries` only — a policy-suppressed
recipient never created a delivery row, so it can never appear there,
proven directly by `CommunicationFailedDeliveryTest::a_policy_suppressed_channel_is_not_mislabeled_as_failed`.

## 13. Filters

Implemented on the Inbox surface: `type` (conversation/announcement),
`priority` (announcement-only — a Thread has no single priority of
its own, only its individual messages do, so this filter is a
documented no-op on the conversation side rather than a guess at
which message's priority to compare), `has_attachments` (bool).
Unread/Sent/Search intentionally ship without this same filter set in
this checkpoint (a bounded, documented scope decision, not an
oversight) — the primary discovery surface (Inbox) was prioritized.

## 14. PostgreSQL query/index strategy

No new index and no new database extension. Every search/filter
query is a plain `ILIKE`/equality predicate against already-indexed
or naturally small result sets (`school_id`, `status`, recipient
snapshot joins already covered by existing composite-FK-backed
indexes from Phase 5A.1–5A.6). Query shape for the two hot paths:

- **Inbox/Unread/Sent conversation side:** one thread query (bounded
  `LIMIT`) + `ConversationReadModel::summarize()`'s existing 2
  queries (`DISTINCT ON` latest-message + read-cursor batch).
- **Inbox/Unread/Sent announcement side:** one announcement query
  (bounded `LIMIT`) + one batched read-state query + one batched
  attachment-presence query — 3 total, never one per announcement.
- **Failed:** one `paginate()` query + one batched breakdown query.

Scale boundary: correct and fast for realistic school-size data (a
membership's own thread/announcement counts in the tens to low
hundreds); not designed for, and not claiming to be, a general-purpose
analytics/reporting query engine.

## 15. Pagination

Single-domain surfaces (Announcements, Templates, Conversations,
Failed) use real `paginate()`. Mixed-domain surfaces (Inbox, Unread,
Sent, Search) use the bounded "fetch up to `$limit` per domain, merge,
cap" strategy documented in §6 — never the entire communication
history in one response.

## 16. Privacy

Search never expands communication authorization. An item invisible
through its normal domain access path must also remain invisible
through search.

Every search-inclusion predicate re-derives the EXACT rule the
item's own detail page already enforces: conversations require
genuine active participation (identical query to the Inbox/Conversations
list); announcements require creator OR resolved recipient OR
`communications.manage` (the identical disjunction
`AnnouncementController::show()` uses); templates require
`communications.templates.manage`. No search-specific, looser
authorization path was created.

## 17. Private-conversation search protection

Proven directly (`CommunicationSearchTest::a_non_participant_cannot_discover_a_private_conversation_via_search`,
`::a_non_recipient_cannot_discover_a_private_announcement_via_search`):
a same-School user with `communications.view` but no participation/
recipiency gets zero search results for a unique private subject/
title, and a direct GET to the underlying thread/announcement URL
remains a plain 403.

## 18. Tenant/RLS protection

No new tenant-owned table or column was added this checkpoint — every
query in `CommunicationInboxReadModel`/`CommunicationDeliveryFailureReadModel`
runs through `TenantContext::withSchool()` exactly like every other
Application service in this module, so the existing RLS policies on
`communication_threads`/`communication_announcements`/`communication_deliveries`/etc.
(Phase 5A.1–5A.7) apply unchanged. `CommunicationSearchTest::school_b_cannot_discover_school_as_uniquely_named_content`
proves School B's search for School A's exact unique terms returns
nothing.

## 19. Multi-school behavior

Every read-model method takes the active `School` explicitly (never
inferred globally from the User) — a multi-School User's Inbox/Unread/
Sent/Search under School A's active context shows only School A's
items, proven directly
(`CommunicationInboxReadModelTest::a_multi_school_user_only_sees_the_active_schools_items`,
`CommunicationInboxHttpTest::a_multi_school_users_inbox_only_shows_the_active_schools_items`).

## 20. Authorization

- **Inbox/Unread/Sent/Search:** `communications.view` only — each
  ITEM inside is independently re-authorized by the read model (§16),
  never granted wholesale by this capability.
- **Failed:** `communications.manage`.
- **Filter values:** validated server-side against a fixed allowlist
  (`type` ∈ {conversation, announcement}, `priority` ∈ the existing
  `CommunicationPriority` enum values) before ever reaching a query —
  an unrecognized value is silently dropped, never passed through as
  a raw SQL fragment. Search query length is capped (120 chars) and
  trimmed at the HTTP layer.

## 21. UI/navigation

New shared `Components/App/Communications/HubNav.vue` (this
codebase's first reusable Vue component — justified by 6 real,
immediate consumers, not spec­ulative) renders Inbox/Unread/
Conversations/Announcements/Scheduled/Sent/Failed/Templates/
Preferences/Settings, showing only items the current capabilities
permit (brief §32). `/app/communications` is now the Inbox (brief
§33's own suggested route shape); the conversation list relocated to
`/app/communications/conversations` (component renamed `Index.vue` →
`Conversations.vue`). New pages: `Inbox.vue`, `Unread.vue`, `Sent.vue`,
`Failed.vue`, `Search.vue`, sharing a new `InboxItemList.vue`
component for the common mixed-item list rendering.

**Scope boundary (documented, not an oversight):** the pre-existing
Announcements/Templates/Preferences/Settings pages were NOT
retrofitted with the persistent `HubNav` sidebar in this checkpoint —
they keep their existing layout with a working "← Communication Hub"
link back to the new Inbox. Full sidebar retrofit into those pages is
deferred to a follow-up polish pass; it does not block this
checkpoint's core objective (the new Inbox/Unread/Sent/Failed/Search
surfaces are fully interlinked via the shared nav).

## 22. Performance/query counts

`ConversationIndexScaleTest` (extended) and the new
`CommunicationInboxHttpTest::the_inbox_route_issues_a_bounded_number_of_queries`
prove the Conversations list and the new Inbox route both stay well
under a fixed query-count ceiling for 10–15 items per domain — never
one query per thread/announcement/participant/attachment/unread
check.

A genuine timestamp-precision gap was found and fixed while proving
ordering correctness: `communication_threads.last_activity_at`/
`created_at`/`updated_at` used Laravel's default whole-second
precision (the same class of issue Phase 5A.7 already fixed for
`communication_messages`/`communication_thread_participants.last_read_at`).
Two threads receiving activity within the same wall-clock second
compared as simultaneous, leaving their relative order in a mixed
Inbox/Conversations list unspecified. Widened to `timestamp(6)` +
`CommunicationThread::$dateFormat = 'Y-m-d H:i:s.u'`, mirroring the
5A.7 fix exactly.

## 23. Logging/PII

No new logging was added. Existing structured-log conventions
(school_id, route, actor id — never message bodies, private search
strings, or full email addresses) are unchanged. Search queries are
NOT audited by default (would create surveillance-shaped noise for a
routine read action) — consistent with this module's existing
precedent that inherently personal, non-state-changing view actions
(Phase 5A.5's preference view, Phase 5A.7's conversation `markRead()`)
are not written to the audit trail.

## 24. Tests

- `AnnouncementReadStateTest.php` (7) — §45.
- `CommunicationInboxReadModelTest.php` (12) — §46: composition,
  exclusion, unread filtering, ordering, bounded limit, attachment
  flag, priority/type filters, multi-School isolation.
- `CommunicationSearchTest.php` (10) — §47 + the two MANDATORY
  privacy proofs (§37/§38).
- `CommunicationSentTest.php` (5) — §48.
- `CommunicationFailedDeliveryTest.php` (6) — §50.
- `CommunicationInboxHttpTest.php` (8) — HTTP surface + bounded-query
  + multi-School.
- `ConversationIndexScaleTest.php` — updated for the relocated route,
  threshold adjusted for one added nav-badge query.
- `CommunicationHubTest.php`/`CommunicationConversationHttpTest.php` —
  updated for the `/app/communications/conversations` relocation (no
  behavior change, same assertions against the new URL).

**Exact totals:** new/touched 5A.8 files — **48 new tests**. Full
Communication Hub suite (`--filter=Communication`) — **266 passed
(789 assertions)**. Full application regression (`php artisan test`,
no filter) — **628 passed (1909 assertions), 0 failed.**

Quality gates, all clean, no new PHPStan baseline entries (this
codebase still has none): `vendor/bin/pint --test` (496 files, PASS),
`vendor/bin/phpstan analyse --memory-limit=512M` (0 errors — fixing a
genuine Carbon-subclass type mismatch between `Illuminate\Support\Carbon`
and the base `Carbon\Carbon` library class required typing
`CommunicationInboxItem`'s Carbon properties against the base class,
and a `LengthAwarePaginator` contract-vs-concrete-class mismatch
required typing `CommunicationDeliveryFailureReadModel::failedAnnouncements()`
against the concrete `Illuminate\Pagination\LengthAwarePaginator`),
`npm run type-check` (clean), `npm run lint` (clean), `npm run
format:check` (clean after auto-formatting 8 new/touched Vue files).

## 25. Safety

No real email/SMS/WhatsApp/push transport was exercised (none was
touched this checkpoint beyond reusing `Mail::fake()` in existing test
patterns). No provider credentials, no staging/production change, no
deploy. `main`/`origin/main` untouched; no Phase 1A commit was merged,
rebased onto, or cherry-picked.

## 26. Deferred

Unchanged from Phase 5A.1–5A.7's deferral list, plus this
checkpoint's own: external search infrastructure, unrestricted
historical message-body search, attachment-content indexing/OCR,
AI/semantic search, manual delivery retry UI (no existing safe
targeted-retry mechanism was found to reuse — see brief §42, out of
scope), quiet hours, approval workflows, full sidebar retrofit into
pre-5A.8 pages (§21), granular filters on Unread/Sent/Search (§13),
and every item Phase 5A.1–5A.7 already deferred.

## 27. Next recommendation

Phase 5A.9 candidates, in order of recommendation:

1. **Quiet Hours & Delivery Timing Policy** — extends Phase 5A.5's
   policy engine along a new axis (time-of-day), and now has a
   natural home in the Failed/operational surfaces this checkpoint
   built.
2. **Communication Audit & Delivery Analytics** — a natural next step
   now that Failed/Sent/Inbox exist as real operational surfaces to
   build reporting on top of.
3. **Approval Workflow Foundation** — both major communication
   surfaces now carry enough state to be worth a review gate.
4. **Email Provider Event/Webhook Foundation** — deferred until real
   outbound email is closer to being turned on.

This is a recommendation only — Phase 5A.9 has not been started.
