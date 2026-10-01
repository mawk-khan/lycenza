# Phase 5A.6 — Communication Attachments & Rich Content Foundation

## 1. Objective

Add secure file attachments to the Communication Hub's Announcement
flow, on a canonical, provider-independent model that every channel
renderer (IN_APP, EMAIL) reads from unchanged. This checkpoint does
**not** build a Document Management module, does **not** add raw
arbitrary rich-text/HTML, and does **not** touch any external send
gate — `COMMUNICATION_EMAIL_ENABLED` stays `false` by default and no
real email/SMS/WhatsApp/push transport is exercised anywhere in this
work.

Communication attachments are canonical School OS assets, not
provider-specific copies.

## 2. Architecture audit (before writing anything)

Read/grepped before any code was written:

- `config/filesystems.php` — stock Laravel disks: `local` (private,
  `storage_path('app/private')`), `public` (symlinked, public), `s3`
  (env-configured, MinIO-compatible per `docker-compose.yml`'s `minio`
  service and `.env.example`'s `AWS_*` keys). No attachment-specific
  disk existed.
- `App\Support\Tenancy\TenantStoragePath` — a rigorous, already-written,
  previously-unused tenant-safe path builder
  (`schools/{school_id}/{path}`, full traversal/segment validation).
  This checkpoint is its first real consumer.
- `docs/architecture/adr/0012-file-document-storage-architecture.md` —
  an **accepted but unimplemented** future "Documents module" shape
  (polymorphic owner, classification tags, per-request authorization,
  tenant-namespaced paths). No such module exists yet.
- No `Document`/`File`/`Media`/`Upload` model anywhere in the codebase.
- No `Storage::` usage anywhere touching file content (the one hit,
  in `OperationalStatusService`, is an unrelated health check).
- No checksum convention for files (existing hash usage is webhook
  signing / idempotency fingerprints / AI service tokens — unrelated).
- No signed-URL usage, no download-controller pattern
  (`response()->download`/`Storage::download`/`response()->stream`)
  anywhere in the codebase.
- `composer.json`: no image-processing package, no HTML-sanitizer
  package, no Markdown renderer, actually required (despite
  `intervention/image` appearing only as a Laravel-framework-level
  *suggest* in `composer.lock`, never actually required). `ext-fileinfo`
  and `ext-gd` are present in the running `platform` container's PHP.
  `league/flysystem-aws-s3-v3` **is** a real dependency (S3/MinIO
  support already present per ADR 0011).
- `package.json`: no rich-text editor, no HTML sanitizer (no DOMPurify,
  no TipTap/Quill/CKEditor/ProseMirror), no Markdown renderer.
- `AuditRecorder` (`App\Support\Audit\AuditRecorder`) — the sole audit
  write path (ADR 0017), event-type strings dotted/snake per-module
  (`communication.thread.created`, `communication_template.created`,
  `announcement.published`, ...). No existing precedent for auditing
  file/download access specifically, but root CLAUDE.md rule 11
  ("document access ... always audited") settles the design question:
  attachment downloads are audited.
- `config/communications.php` — existing top-level sections
  (`channels`, `delivery`, `scheduling`), all env-var-backed with
  inline documentation; a new `attachments` section follows the exact
  same shape.
- `communication_messages`/`communication_announcements`/
  `communication_delivery_policy_decisions` migrations — the
  established composite-FK pattern (`unique(['id','school_id'])` on
  the parent, `foreign(['x_id','school_id'])->references(['id','school_id'])`
  on the child) is what any new child table must reuse.

**Conclusion:** there is no document-management subsystem, no
malware-scanning hook, no rich-text/sanitizer package, and no existing
download-authorization pattern to reuse. Everything in this checkpoint
had to be built narrowly and explicitly, consistent with ADR 0012's
principles but without building the full future module.

## 3. Why not the full Documents module

ADR 0012 fixes the *shape* a future Documents module must follow; it
explicitly has no implementation. Building that full module now would
be exactly the kind of speculative infrastructure root CLAUDE.md rule
2 forbids — this checkpoint's explicit brief also forbids it directly.
Instead, `CommunicationAttachment` owns its own metadata directly
(`app/Domain/Communications/Infrastructure/CommunicationAttachment.php`),
satisfying ADR 0012's principles (private-by-default storage,
per-request re-verified authorization, tenant-namespaced paths) at a
scope narrow enough for one module, with an obvious future migration
seam: if/when a real Documents module exists, `CommunicationAttachment`
gains a `document_id`/version reference instead of owning storage
metadata directly — no data model surprises, no schema redesign, just
an additional column and a cutover of *where* metadata lives.

## 4. Canonical attachment architecture

```
CommunicationAnnouncement (draft/scheduled — the only stable pre-publish parent)
        │  upload
        ▼
CommunicationAttachment  (communication_announcement_id, always set)
        │  AnnouncementService::publish() backfills, same transaction
        ▼
        (communication_message_id, set once, never re-copied)
        │
        ▼
CommunicationMessage
        │
   ┌────┴─────┐
   ▼          ▼
IN_APP      EMAIL
```

`CommunicationMessage` (the true channel-rendering unit for both
Announcements and conversation threads, per Phase 5A.1/5A.2) does not
exist until `AnnouncementService::publish()` creates it — but the
composer needs to accept uploads **before** publish, during the
draft/scheduled editing window. `CommunicationAttachment` therefore
carries `communication_announcement_id` (not null, the stable parent
from upload time through the whole lifecycle) and
`communication_message_id` (nullable, backfilled by `publish()` in the
same transaction the message is created in). The row itself —
`storage_path`, `checksum_sha256`, every byte-identity field — is never
rewritten; publishing only adds a second foreign key to the exact same
row. No attachment is ever re-uploaded, copied, or re-created at
publish time.

Direct conversation-thread-message attachments
(`CommunicationMessageService::send()`, which has no draft/edit phase
at all — a message is sent and complete in one call) are **out of
scope** for this checkpoint; see §26.

## 5. Schema

`communication_attachments` (migration
`2026_08_23_101700_create_communication_attachments_table.php`):

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `school_id` | FK `schools`, cascade delete |
| `communication_announcement_id` | not null, composite FK `(id, school_id)` → `communication_announcements`, cascade delete |
| `communication_message_id` | nullable, composite FK `(id, school_id)` → `communication_messages`, null-on-delete |
| `storage_disk` | server-recorded, never client-supplied |
| `storage_path` | server-generated key (UUIDv7 + normalized extension) — never the raw filename |
| `original_filename` / `safe_display_name` | display metadata only, sanitized (basename, control-char-stripped, length-capped) |
| `mime_type` | the real, server-sniffed type |
| `size_bytes` | unsigned bigint |
| `checksum_sha256` | integrity/observability only, never used for cross-attachment dedup |
| `created_by_user_id` | FK `users` |

`TenantRls::enable('communication_attachments')` — RLS, not
append-only (a draft-only attachment can be legitimately hard-deleted;
see §15). `unique(['id','school_id'])` for downstream composite-FK
reuse if a future module ever needs one.

## 6. Storage disk & path strategy

`config('communications.attachments.disk')`
(`COMMUNICATION_ATTACHMENTS_DISK`, default `local`) — Communication
Hub's own explicit storage choice, independent of
`config('filesystems.default')`, matching `channels.email.mailer`'s
existing precedent. `local` maps to `storage_path('app/private')` —
already outside the public web root, never symlinked, never served
directly.

Every path is built through `TenantStoragePath::for($school, "communications/announcements/{$announcement->id}/{$storageKey}.{$extension}")`
— `$storageKey` is a fresh UUIDv7, never the uploaded filename. This is
`TenantStoragePath`'s first real consumer in the codebase.

## 7. File type allowlist & validation

`config('communications.attachments.allowed_mime_types')` maps a real
sniffed MIME type to its permitted extension(s): PDF, JPEG, PNG, WEBP,
DOC/DOCX, XLS/XLSX. SVG and every macro-enabled Office format
(`.docm`/`.xlsm`/...) are deliberately excluded.

`CommunicationAttachmentService::assertAllowedType()` reads
`UploadedFile::getMimeType()` — Symfony/Laravel's real, `fileinfo`-backed
content-sniffed type, never `getClientMimeType()` (the browser-supplied,
spoofable header) and never the extension alone. A sniffed type not on
the allowlist, **or** a sniffed type whose declared extension doesn't
match one of its permitted extensions, is rejected with the same
`attachment_type_not_allowed` failure code either way.

## 8. Size / count limits

All under `config('communications.attachments.*')`, env-var-backed,
conservative defaults:

- `max_file_size_mb` (default 10) — per-file.
- `max_per_message` (default 5) — attachment count per Announcement.
- `max_total_size_mb` (default 25) — cumulative bytes per Announcement,
  the canonical storage limit.
- `email_max_total_size_mb` (default 8) — a **separate**, smaller
  threshold the EMAIL channel alone respects (§13).

Each is checked before any storage write, with a distinct exception/
failure code (`attachment_too_large`, `attachment_count_exceeded`,
`attachment_total_size_exceeded`).

## 9. Filename handling

`original_filename`/`safe_display_name` are the sanitized (basename
only, control characters stripped, 180-char cap) client-supplied name
— display metadata only, never used to build a storage path. The real
storage key is always server-generated (§6). A crafted name like
`../../etc/passwd\x01evil.pdf` is reduced to a safe display string with
no path separators, no `..`, and no control bytes.

## 10. Checksum

SHA-256, computed from the uploaded file's real bytes
(`hash_file('sha256', ...)`) before storage. Recorded for integrity/
observability only. Deliberately **not** used for any cross-attachment
or cross-School deduplication — doing so would let one School
probabilistically infer whether another School has uploaded an
identical file, a side channel this checkpoint refuses to open.

## 11. Malware-scanning boundary

No scanning engine exists anywhere in this stack, and this checkpoint
does not pretend otherwise. The mitigations actually in place: a
conservative type allowlist (§7), private-by-default storage (§6), no
server execution of uploaded content, and authorized-only, streamed
download (never a public URL, §17). A future scanning integration
could set a `scan_status` column (`pending`/`clean`/`rejected`)
without any other schema change — deliberately not built here.

## 12. Upload flow & compensating actions

`CommunicationAttachmentService::upload()`
(`app/Domain/Communications/Application/CommunicationAttachmentService.php`)
is the single authoritative validation/association boundary (brief
§35):

1. No-I/O checks first: lifecycle (`isEditable()`), type, size, count/
   total-bytes — reject before ever touching storage.
2. Compute the SHA-256 checksum from the still-local upload.
3. Write bytes to the configured disk under the server-generated key.
   If this fails, throw `AttachmentStorageException` — **no DB row is
   ever attempted**.
4. Create the `communication_attachments` row inside `DB::transaction()`.
   If this throws, the just-written file is deleted (compensating
   action) before the exception propagates — a communication never
   claims an attachment exists when its DB record failed to persist.

## 13. No pending/orphan state

There is no "pending upload" architecture anywhere in this codebase
(confirmed in the audit), and this checkpoint does not build one. A
`CommunicationAttachment` row is only ever created already associated
with a real, already-existing, already-editable Announcement the
caller has verified — there is no intermediate unassociated state, and
therefore no destructive cleanup scheduler to build or test.

## 14. Version-pinning / immutability semantics

Published communications cannot silently change attachment content
later.

There is no versioned document model in this checkpoint (§3), so
"version pinning" reduces to a structural guarantee instead of a
version-number reference: `CommunicationAttachmentService` has **no**
method that rewrites `storage_path`/`checksum_sha256`/any byte-identity
field after creation. The mandatory scheduled-attachment invariant
(brief §25 — create draft with an attachment, schedule it, publish
later) holds for free: `AnnouncementAttachmentPublishTest` and
`ScheduledAnnouncementAttachmentTest` both assert the storage path and
checksum are bit-identical from immediately after upload through
scheduling through due publication.

## 15. Lifecycle

Reuses `CommunicationAnnouncement::isEditable()` (`isDraft() ||
isScheduled()`) unchanged — no separate state machine.

- **DRAFT/SCHEDULED** — attachments may be added
  (`CommunicationAttachmentService::upload()`) or removed
  (`::remove()`, a real hard delete — the only case where physically
  deleting a row is safe, since nothing has referenced it yet).
- **PUBLISHED** — both throw `InvalidAnnouncementTransitionException`.
  `remove()` additionally checks `communication_message_id !== null`
  as a second, independent guard against ever deleting a
  message-linked (i.e. potentially delivered) attachment.

## 16. Scheduled attachment snapshot

Covered in depth by `ScheduledAnnouncementAttachmentTest`: schedule an
Announcement with an attachment, let `communications:publish-scheduled`
run past the due time, and confirm (a) the exact same attachment row
(same `storage_path`/`checksum_sha256`) is now linked to the produced
message, (b) a second scheduler run creates no duplicate, (c)
cancelling a schedule leaves the attachment untouched and still
editable, with zero delivery side effects, and (d) rescheduling never
disturbs the attachment.

## 17. Download authorization

Knowing an attachment/storage identifier never grants access by
itself.

`GET /app/communications/attachments/{attachment}/download`
(`CommunicationAttachmentController::download()`) verifies, every time,
never cached from a prior request:

1. An active School context exists (`TenantContext::requireSchool()`).
2. The attachment's `school_id` matches the active School — RLS/
   `BelongsToSchool` already make a foreign School's row invisible to
   `findOrFail()`, so a forged id 404s rather than 403s.
3. The current actor is entitled to read the **parent Announcement** —
   `CommunicationAttachmentService::authorizeRead()` reuses the exact
   same rule `AnnouncementController::show()` already enforces
   (creator, a resolved recipient, or `communications.manage`) rather
   than inventing a second, divergent definition of "may read this."

The response streams via `Storage::disk(...)->download(...)`, serving
`safe_display_name` — the real `storage_disk`/`storage_path` never
appear in any response body, Inertia prop, or client-visible URL.

## 18. Cross-tenant security

`communication_attachments` uses `TenantRls` like every tenant-owned
table (root CLAUDE.md rule 18) and a composite FK against
`communication_announcements(id, school_id)` (rule 70's established
pattern), so a cross-School announcement reference is rejected at
INSERT time regardless of RLS.
`tests/Feature/Postgres/CommunicationAttachmentsRlsIsolationTest.php`
proves, at the raw-SQL layer: RLS is enabled and forced; no context
sees any row; School A cannot `SELECT` School B's attachment; an
`INSERT` claiming School B's `school_id` under School A's context is
rejected by the RLS policy; `UPDATE`/`DELETE` against School B's row
under School A's context affect zero rows; and a forged cross-School
`communication_announcement_id` is rejected by the composite FK.
`CommunicationAttachmentAuthorizationTest` proves the same boundary at
the HTTP layer, including a forged attachment id, a forged Announcement
id, and a non-creator/non-manager upload attempt.

## 19. `CommunicationAttachmentService` — the policy boundary

The one authoritative service (brief §35): validates School/message
ownership, lifecycle, file metadata, creates the association, and
authorizes the canonical read relationship. No attachment validation
logic is scattered into a controller.

## 20. Delivery policy interaction (unchanged)

Attachment presence never overrides a recipient's channel preference
or School policy. `AnnouncementService::publish()`'s existing
`CommunicationChannelPolicyService::evaluate()` call runs exactly as it
did in Phase 5A.5 — an attachment is only ever included in a delivery
that channel policy already allowed; a policy-suppressed EMAIL delivery
still never gets attempted, attachment or not.

## 21. Partial channel failure (preserved)

An oversized attachment fails only the EMAIL channel
(`attachment_email_size_exceeded`, §22) — IN_APP still succeeds, the
Announcement still ends up fully `published`, and a retry never
duplicates the send. `AnnouncementAttachmentEmailDeliveryTest` proves
this exact partial-failure shape.

## 22. EMAIL integration

`EmailChannelDriver::send()` (extended, Phase 5A.3's file) now checks
the linked message's attachment bytes against
`config('communications.attachments.email_max_total_size_mb')` (default
8MB — deliberately smaller than the canonical `max_total_size_mb`)
**before** attempting any transport call:

- Within the threshold: `CommunicationEmailPayload` carries lightweight
  `{disk, path, displayName, mimeType}` descriptors (never raw bytes);
  `CommunicationMail::attachments()` streams each one lazily via
  `Attachment::fromStorageDisk(...)->as($displayName)->withMime($mimeType)`
  at send time.
- Over the threshold: the delivery is marked `failed` with
  `attachment_email_size_exceeded`, deterministically, with no
  transport call attempted and no attachment silently dropped from an
  otherwise-sent email.

`COMMUNICATION_EMAIL_ENABLED` stays `false` by default, unchanged; the
driver's existing disabled/missing-address/invalid-address checks are
untouched. Every email test in this checkpoint uses `Mail::fake()` —
no real transport is exercised.

## 23. No long-lived public email links

No signed URL, no bearer token, no public link of any kind was added.
An oversized attachment fails the EMAIL channel outright (§22) rather
than substituting a link — this avoids introducing any new
externally-reachable, long-lived, or enumerable URL surface, which
brief §30 explicitly warns against and which this checkpoint's scope
does not require.

## 24. Rich content decision

Audited (§2): no rich-text editor, HTML sanitizer, or Markdown renderer
exists anywhere in this codebase, front or back end. `communication_messages.body`/
`communication_announcements.body` remain plain text, exactly as Phase
5A.1–5A.5 left them. This checkpoint does **not** add rich-text/HTML
support — doing so would require a real server-side sanitization
boundary and a dedicated stored-XSS test suite that does not currently
exist and is explicitly out of this checkpoint's mandatory scope
(attachments are mandatory; rich content is not). Deferred to a future
checkpoint if/when a real product need for rich text emerges.

## 25. IN_APP UI

`AnnouncementController::show()` eager-loads `attachments` (added to
the existing `with([...])` call — no N+1, §28) and exposes metadata
only (`id`, `displayName`, `mimeType`, `sizeBytes`, `createdAt` — never
`storage_disk`/`storage_path`). `resources/js/Pages/App/Communications/Announcements/Show.vue`
renders an Attachments section: a list with name/size/authorized-download
link, and — only when the current actor can still edit the Announcement
(`canManageAttachments`) — a file input (upload) and a Remove action per
attachment. Upload/remove are plain Inertia `router.post()`/`router.delete()`
calls (Inertia auto-detects the `FormData` payload and switches to
multipart), matching this module's existing full-page-action convention
rather than introducing a separate AJAX/JSON endpoint style.

## 26. Conversation thread attachments — deferred

`CommunicationMessageService::send()` (Phase 5A.1) sends a thread
message atomically, with no draft/edit phase at all, and no
conversation-thread composer Vue page exists yet
(`resources/js/Pages/App/Communications/` only contains `Announcements/`).
Per brief §48's explicit allowance, this checkpoint does not add a
thread-message attachment UI or wire backend support for it — the
schema (`communication_attachments.communication_message_id`) is
already forward-compatible with a direct-message attachment path if a
future checkpoint adds one, but no such path is implemented now.

## 27. Template attachments — deferred

Not implemented. `CommunicationTemplate` (Phase 5A.4) has no
attachment concept, and adding one would require deciding a
copy-vs-reference semantic for "use template" pre-fill that doesn't
cleanly fit this checkpoint's scope (brief §26 explicitly allows
deferring this, documented as a decision rather than an oversight).

## 28. Performance

`AnnouncementController::show()`'s existing eager-load list gained
`attachments` (one additional relation, not a new query per row).
Downloads stream via `Storage::disk(...)->download()` — file bytes are
never loaded into a PHP string, and `CommunicationMail::attachments()`
streams from storage lazily via `Attachment::fromStorageDisk()` at send
time rather than reading bytes into the payload object. Attachment
count/size-limit checks (`assertWithinMessageLimits()`) run one query
per upload (bounded by `max_per_message`, itself capped at a small
default) — not a per-file N+1.

## 29. Observability / failure codes

Stable, machine-readable, and distinct from Phase 5A.5's channel-policy
suppression reasons: `attachment_type_not_allowed`,
`attachment_too_large`, `attachment_count_exceeded`,
`attachment_total_size_exceeded`, `attachment_storage_unavailable`,
`attachment_email_size_exceeded`. `AuditRecorder::school()` records
`communication_attachment.uploaded`, `.removed`, and `.downloaded` with
School id, actor, subject (the attachment row), and safe metadata
(announcement id, size, MIME type) only — never file contents, never a
storage path, never a signed URL (none exist).

## 30. Tests

New files (all under `tests/Feature/Communications/` unless noted):

- `CommunicationAttachmentServiceTest.php` — file validation (allowed/
  disallowed type, sniffed-type/extension mismatch, SVG/macro-enabled
  rejection, oversized file, per-message count limit, total-bytes
  limit, filename sanitization, empty upload via HTTP, storage-write
  failure creates no row, publish blocks upload, draft removal works).
- `CommunicationAttachmentAuthorizationTest.php` — guest denial,
  creator upload/download, unrelated-member denial, resolved-recipient
  download of a published Announcement, `communications.manage`
  override, cross-School 404 (not 403), forged Announcement id, non-
  creator upload denial, cross-Announcement removal denial.
- `AnnouncementAttachmentPublishTest.php` — message-id backfill without
  re-creating the row, no post-publish upload, no post-publish removal,
  republish does not duplicate.
- `ScheduledAnnouncementAttachmentTest.php` — attachment identity
  survives due publication unchanged, scheduler rerun doesn't
  duplicate, cancelling a schedule leaves the attachment untouched with
  no delivery side effects, rescheduling doesn't disturb it.
- `AnnouncementAttachmentEmailDeliveryTest.php` — attachment included
  within the EMAIL size threshold, oversized attachment fails only
  EMAIL while IN_APP still succeeds, IN_APP-only sends no email, the
  disabled global gate sends no mail, republish doesn't duplicate the
  email. All via `Mail::fake()`.
- `tests/Feature/Postgres/CommunicationAttachmentsRlsIsolationTest.php`
  — RLS enabled/forced, zero visibility with no context, cross-School
  `SELECT`/`INSERT`/`UPDATE`/`DELETE` all correctly denied, forged
  cross-School announcement reference rejected by the composite FK.

**Exact result:** `php artisan test --filter=Attachment` → **40 passed
(83 assertions)**. Full Communications suite
(`--testsuite=Feature --filter=Communication`) → **188 passed (566
assertions)**. Full application regression suite (`php artisan test`,
no filter) → **550 passed (1687 assertions), 0 failed** — one flaky
`AcademicYearActivationConcurrencyTest` failure (a genuine two-real-OS-
-process race test, pre-existing from Phase 0D, unrelated to this
checkpoint) was independently reproduced as flaky and confirmed passing
in isolation before being excluded from that count as environmental
noise, not a code defect.

Quality gates, all clean, no new PHPStan baseline entries: `vendor/bin/pint --test`
(476 files, PASS), `vendor/bin/phpstan analyse --memory-limit=512M`
(0 errors), `npm run type-check` (`vue-tsc --noEmit`, clean),
`npm run lint` (ESLint, clean), `npm run format:check` (Prettier,
clean after one auto-format of `Show.vue`).

## 31. Safety

No deploy, no production/staging change, no cloud resource
provisioned. No real email/SMS/WhatsApp/push transport was exercised —
every email-path test uses `Mail::fake()`. `COMMUNICATION_EMAIL_ENABLED`
remains `false` by default, unchanged from Phase 5A.3. No secret,
credential, or signed URL was introduced or logged. Storage stays
private-by-default (`local` disk, `storage_path('app/private')`) with
no new public/symlinked path.

## Deferred functionality

Unchanged from Phase 5A.1–5A.5's deferral list, plus this checkpoint's
own: complete Document Management module, public file sharing,
anonymous file portals, Dropbox/Google Drive integrations, real
SMS/WhatsApp/push providers, email provider webhooks, inbound email,
custom sender domains, marketing unsubscribe, quiet hours, approval
workflows, emergency escalation, AI drafting, AI document
summarization, OCR, automatic attachment classification,
Student-specific attachments, Guardian-specific attachment rules,
Class/Section/Grade audiences, fee/attendance/transport automation,
rich-text/HTML content (§24), conversation-thread attachment UI/backend
(§26), template attachments (§27), malware/virus scanning (§11), and
any signed/short-lived external attachment link.

## Recommended next checkpoint

Phase 5A.7 candidates, in order of recommendation:

1. **Conversation Messaging Completion** — the thread composer/UI gap
   this checkpoint documented (§26) is now the most visible remaining
   hole in the Communication Hub's own core surface (Announcements are
   fully featured; conversation threads still have no dedicated Vue
   page at all).
2. **Approval Workflow Foundation** — a natural next gate now that
   Announcements carry rich enough state (requirement, scheduling,
   attachments) to be worth reviewing before publish.
3. **Quiet Hours & Delivery Timing Policy** — extends Phase 5A.5's
   policy engine along a new axis (time-of-day) rather than a new
   surface.
4. **Email Provider Event/Webhook Foundation** — bounce/complaint
   handling, deferred until real outbound email is closer to being
   turned on.

This is a recommendation only — Phase 5A.7 has not been started.

## Retention (E21.2C, 2026-10-01)

Attachments follow their communication (E21-D3,
`docs/security/E21-RETENTION-DETERMINATION.md`). `platform:communications-prune`
deletes an announcement or thread, with its attachment rows, 3 years after
the end of the Academic Year in which it was sent. The bytes are deleted
after the commit. A failed byte delete is retried by
`platform:storage-orphans-prune` once the object is 30 days old. Delivery
telemetry expires 1 year after its terminal state; recipients and audience
snapshots stay with their content.
