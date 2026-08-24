# Phase 5A.R1 — Final Integration Readiness & Reconciliation Audit

**Read-only audit. No application code was modified to produce this
document — see §2 for the exact scope confirmation.**

## 1. Executive conclusion

The Communication Hub branch (12 checkpoints, 266 changed files) and
current `main`'s Phase 1A Student/Guardian work (6 checkpoints, 88
changed files) touch **only 5 shared file paths**, of which **exactly
4 produce a real textual merge conflict** (`git merge-tree` confirmed,
§5). Every one of those 4 conflicts is the same shape: both branches
independently *appended* a new, disjoint entry to the same array/list
at the same anchor point (a new capability key, a new nav flag, a new
route group, a new dashboard link). None of the four requires a design
decision — each resolves by keeping both additions.

More importantly: Phase 1A's `Student`/`Guardian` identities have **no
relationship to `User` or `SchoolMembership` at all** (§7/§8) — by
explicit, documented design ("deliberately independent of `users`").
This means Communication Hub's `SchoolMembership`-based audience model
cannot target Students/Guardians today regardless of merge order, and
nothing about *merging* changes that fact. The Communication Hub
foundation is safe to integrate on its own; Student/Guardian audience
support is unambiguously a **post-merge** extension, not a
pre-integration blocker.

**Verdict: GO** (see §29 for the two small reconciliation items to
handle in the integration branch — neither is a blocker).

## 2. Repository / audit scope confirmation

- Path: `/home/wajidkhan/sites/lycenza`
- Communication Hub branch: `feature/phase-5a-communication-hub`
- Audited HEAD: `f32349548124dcd0348107fcb80a9b868e1c9dfc` (unchanged
  throughout this audit — confirmed via `git log`/`git status` before
  and after)
- `main` / `origin/main`: `e0c4e1b90f2be87ba4903fba39237dfaa6b3e315`
  (both identical — nothing new landed on `main` since the 5A.12
  report)
- Merge-base: `6edbe2d882d9fba84bc4ac27323c6c507e4a0869` (unchanged)
- Working tree: clean except the expected untracked
  `docker-compose.override.yml` (unstaged, unmodified, still needed by
  this environment)
- No `merge`, `rebase`, `cherry-pick`, `push`, or code/migration/route/
  capability edit was performed. The only write in this session is
  this document.

## 3. Phase 5A lineage verification

All twelve SHAs (5A.1–5A.12, listed in the task) confirmed present and
unrewritten ancestors of HEAD via `git merge-base --is-ancestor`.

## 4. Git-level changeset inventory

| Line | Commits since merge-base | Files changed | Insertions |
| --- | --- | --- | --- |
| Phase 5A (`HEAD`) | 12 | 266 | 34,235 |
| `main` (Phase 1A) | 6 | 88 | 12,400 |

Files touched by **both** lines (full intersection, computed via
`comm -12` on sorted path lists — not a sample):

```
apps/platform/.env.example
apps/platform/app/Http/Controllers/App/DashboardController.php
apps/platform/database/seeders/CapabilityAndRoleSeeder.php
apps/platform/resources/js/Pages/App/Dashboard.vue
apps/platform/routes/web.php
```

**5 files total.** Everything else across both 354-file combined
changesets is exclusive to one line.

## 5. Simulated merge (`git merge-tree`, non-mutating)

`git merge-tree HEAD main` (modern two-arg form, Git 2.43) reports:

```
Auto-merging apps/platform/.env.example                          [clean]
CONFLICT (content): DashboardController.php
CONFLICT (content): CapabilityAndRoleSeeder.php
CONFLICT (content): resources/js/Pages/App/Dashboard.vue
CONFLICT (content): routes/web.php
```

**4 real textual conflicts, 0 rename/modify-delete conflicts.**
`.env.example` merges cleanly because the two branches' additions land
in genuinely non-adjacent regions of the file.

No other file among the 354 combined changed paths shows as
"changed in both" in the merge-tree output — every other addition
(266 + 88 − 5 duplicated ≈ 349 distinct paths) is a pure add on exactly
one side, which Git merges without any conflict.

## 6. Conflict-by-conflict detail (textual, all LOW-risk-to-resolve)

| File | Phase 5A adds | main adds | Resolution |
| --- | --- | --- | --- |
| `DashboardController.php` | `canViewCommunications` nav flag | `canViewStudents`, `canViewGuardians` nav flags | Keep all three array entries |
| `CapabilityAndRoleSeeder.php` | `communications.*` capability defs + grants to `school_admin`/`principal` | `students.*`/`guardians.*` capability defs + grants to `school_admin`/`principal` | Keep both blocks in the capability list AND both key groups in each role's grant array (§17) |
| `Dashboard.vue` | `canViewCommunications` prop + "Communication Hub" nav link | `canViewStudents`/`canViewGuardians` props + "Students"/"Guardians" nav links | Keep all three props, all three `<li>` links |
| `routes/web.php` | `/app/communications/*` route group (`app.communications.*` names) | `/app/students`, `/app/guardians`, `/app/relationships`, `/app/contacts` route groups (`app.students.*`, `app.guardians.*`, etc.) | Keep both groups — zero name/prefix overlap |

Every conflict is the identical pattern: **both branches appended to
the same array/group at the same anchor line**, with completely
disjoint content. None require a judgment call about *which* change
wins — both win, additively. This is the lowest-risk conflict shape
possible short of no conflict at all.

## 7. Phase 1A architecture discovered

### Student (`app/Domain/Students/Infrastructure/Student.php`, table `students`)

- `BelongsToSchool`, `GeneratesUuidV7`, `HasFactory` — same tenancy
  primitives Communication Hub uses, unmodified by either line (§13).
- Columns: `id, school_id, student_number, first_name, middle_name,
  last_name, date_of_birth, status`.
- **No `user_id` column. No relationship to `App\Models\User` anywhere
  in the model.** Explicit docblock: "Never linked to `users` directly
  — a future StudentUserLink (portal access) would be an explicit,
  separate join, not a column on this table."
- `student_number` unique per-School only. `unique(['id','school_id'])`
  already present, pre-positioned for a future composite FK.

### Guardian (`app/Domain/Guardians/Infrastructure/Guardian.php`, table `guardians`)

- Same tenancy primitives. Columns: `id, school_id, first_name,
  middle_name, last_name, status`.
- **No `user_id` column, no relationship to `User`.** Identical
  explicit docblock disclaimer to Student's.

### StudentGuardianRelationship (table `student_guardian_relationships`)

- The join model: `school_id, student_id, guardian_id,
  relationship_type, is_primary, is_legal_guardian,
  is_emergency_contact, is_authorized_pickup`.
- `attach()`/`sync()`/`detach()` on the `BelongsToMany` are explicitly
  documented as NOT the supported mutation path (they bypass
  `BelongsToSchool`'s `school_id` auto-fill) — only
  `StudentGuardianRelationshipService` or direct model creation is
  supported.

### GuardianContact (table `guardian_contacts`)

- A Guardian's email/mobile, but **not a plain column**:
  `encrypted_value` (Laravel `encrypted` cast, APP_KEY-based) +
  `lookup_hash` (keyed HMAC digest, `CONTACT_LOOKUP_HMAC_KEY`) for
  exact-match search (ADR 0028, "searchable encrypted PII"). Both
  columns are `$hidden`. There is **no plaintext contact column
  anywhere**.
- This is architecturally different from how Communication Hub
  resolves an email destination today
  (`EmailAddressResolver` reads `User.email`, a plain column) — see
  §11.

### Relationship to `SchoolMembership`

**None.** `SchoolMembership` is not referenced by `Student`,
`Guardian`, `StudentGuardianRelationship`, or `GuardianContact` in any
direction. `SchoolMembership` itself was not modified by `main` at all
(§9).

## 8. Identity architecture comparison

| Question | Answer |
| --- | --- |
| Does Student map directly to User? | **No** — no `user_id`, no relation, by explicit design |
| Does Guardian map directly to User? | **No** — same |
| Can Student exist without User? | **Yes** — it always does today; there is no code path that creates a User alongside a Student |
| Can Guardian exist without User? | **Yes** — same |
| How does SchoolMembership relate to Student? | **Not at all** |
| How does SchoolMembership relate to Guardian? | **Not at all** |
| Are guardian relationships school-scoped? | **Yes** — `school_id` on every table, RLS-enabled |
| Are students school-scoped? | **Yes** — same |
| What stable key should Communication Hub eventually target? | Whatever key a **future** `StudentUserLink`/`GuardianUserLink` (or equivalent identity bridge) introduces — it does not exist yet on either line |

## 9. Audience-integration readiness (discovery only — not implemented)

**Student audience:** Not currently mappable to a
`CommunicationRecipient` (which requires a `User` id). Blocked purely
by the missing identity bridge, not by tenancy or schema shape — the
tenant/School-scoping conventions already match.

**Guardian audience:** Same blocker, plus a second one: even with an
identity bridge, `GuardianContact.encrypted_value` is not a plain
column `EmailAddressResolver`/`EmailChannelDriver` could read the same
way they read `User.email` today — a real email-destination-resolution
adapter would be new work, not just a new audience resolver.

**Student → Guardian audience** (e.g. "notify all Guardians of Grade 5
students"): `StudentGuardianRelationship` carries enough tenant-safe
structure (`is_primary`, `is_legal_guardian`, `is_emergency_contact`)
to express real product rules, but is equally blocked by the missing
identity bridge — a relationship to a Guardian is not a relationship
to a recipient until Guardian resolves to *someone with an inbox*.

**Missing pieces, concretely:**
1. A `StudentUserLink` (student portal account) and/or
   `GuardianUserLink` (guardian portal account) — neither exists on
   either line today.
2. A GuardianContact → email-destination resolution path compatible
   with (or parallel to) `EmailAddressResolver`.
3. A new `CommunicationAudienceResolver` implementation (the registry
   seam already exists and is designed for exactly this —
   `CommunicationAudienceResolverRegistry`, Phase 5A.2 — no redesign
   needed there).

None of this is required for Phase 5A's own merge to succeed.

## 10. SchoolMembership compatibility

`main` did not modify `app/Models/SchoolMembership.php` at all. Every
Phase 5A assumption (active-membership scope, capability resolution
keyed on membership, recipient/preference/read-state modeling) remains
exactly as valid post-merge as it is today. The one Phase 5A change to
this file (`scopeActive()`, a local Eloquent scope, purely additive)
applies cleanly with no interaction from Phase 1A.

## 11. Migration inventory & compatibility

| Line | New migrations | Tables created/altered |
| --- | --- | --- |
| `main` | 4 | `students`, `guardians`, `student_guardian_relationships`, `guardian_contacts` |
| Phase 5A | 27 | 15 new Communications tables + 12 alter/index/precision migrations |

**Timestamp-prefix collisions found (4):**

| Shared prefix | `main` migration | Phase 5A migration |
| --- | --- | --- |
| `2026_08_23_100000` | `create_students_table.php` | `create_communication_threads_table.php` |
| `2026_08_23_100100` | `create_guardians_table.php` | `create_communication_thread_participants_table.php` |
| `2026_08_23_110000` | `create_student_guardian_relationships_table.php` | `create_communication_delivery_timing_policies_table.php` |
| `2026_08_23_120000` | `create_guardian_contacts_table.php` | `add_dispatch_mode_to_communication_announcements_table.php` |

These are **filename collisions in prefix only** — the full filenames
differ, so Git will add both files without conflict and Laravel's
`migrations` table (keyed by the full filename) will record both as
distinct rows. Verified by grep: **zero FK or table-name
cross-references in either direction** between the two migration sets
— no Phase 5A migration mentions `students`/`guardians`, no `main`
migration mentions `communication`. The alphabetical tie-break within
each identical prefix (`c` before `s`, `a` before `c`) is therefore
functionally irrelevant — whichever runs first, the other has no
dependency on it.

**Fresh-migration verdict:** based on direct inspection (constraint
names, FK targets, table names), a chronologically merged migration
run is expected to succeed with no ordering failure. This was **not**
executed end-to-end against a live database in this audit (that
requires the actual merged tree, which does not exist as a checkout
yet) — the integration gate must still run the literal
`fresh database → migrate` verification as machine-checked proof
(§20/§37).

**Recommended (non-blocking) cleanup:** rename the 4 colliding-prefix
Phase 5A migrations to a later timestamp in the integration branch,
purely for human readability — never required for correctness.

## 12. Table/column collision audit

No overlapping columns, constraints, indexes, or composite-FK targets
found between the two lines' schemas. Communications tables reference
`schools`, `users`, `school_memberships`, `communication_templates`,
and each other. Phase 1A tables reference only `schools`. No shared
child table, no shared constraint name, no shared index name.

## 13. RLS / tenancy compatibility

Both lines use **the identical, unmodified** primitives:
`App\Support\Tenancy\TenantRls::enable()`/`makeAppendOnly()`,
`App\Support\Tenancy\BelongsToSchool`, `App\Support\Tenancy\TenantContext`.
Neither branch touched any of these three files — confirmed by their
absence from both changed-file lists. Every new Phase 1A table
(`students`, `guardians`, `student_guardian_relationships`,
`guardian_contacts`) uses `TenantRls::enable()` exactly like every
Phase 5A table. No divergence in RLS policy naming, `school_id`
convention, or composite-FK pattern (Phase 1A's
`unique(['id','school_id'])` pre-positioning on `students` mirrors
Phase 5A's own convention exactly).

## 14. Route compatibility

Zero name or prefix collisions. Phase 1A: `/app/students`,
`/app/guardians`, `/app/relationships`, `/app/contacts` (route names
`app.students.*`, `app.guardians.*`, `app.relationships.*`,
`app.contacts.*`). Phase 5A: `/app/communications/*` (route names
`app.communications.*`). Both append their route groups at the same
point in `routes/web.php` (end of the authenticated middleware group),
producing the textual conflict in §6 — resolved by keeping both
groups, order irrelevant.

## 15. Capability / role compatibility

Combined new capability keys — **zero literal collisions**:

- Phase 1A: `students.view`, `students.manage`, `guardians.view`,
  `guardians.manage`
- Phase 5A: `communications.view`, `.send`, `.reply`, `.manage`,
  `.audit.view`, `.announce`, `.templates.manage`, `.emergency`,
  `.approve`

Both lines independently append their new capability definitions
*and* their grants to `school_admin`/`principal` at the same anchor
points in `CapabilityAndRoleSeeder.php` (§6/§17) — a textual conflict,
zero semantic collision. `platform_super_admin`'s grant array is
untouched by both lines.

## 16. Role-grant semantic check

A naive, careless conflict resolution (e.g. blindly picking "ours" or
"theirs" instead of a proper 3-way reconciliation) **could** silently
drop one line's capability grants from `school_admin`/`principal`,
since both branches edit the same array literal. This is exactly why
§6's resolution must be additive-merge, never a wholesale pick — flag
this explicitly for whoever performs the integration merge. The fix is
mechanical (keep both key lists in each role's array) but must not be
automated blindly.

## 17. Model/class collisions

No duplicate class basename found across the two changesets beyond the
3 already-covered PHP files (`DashboardController.php`,
`CapabilityAndRoleSeeder.php`, `web.php`) — verified by diffing sorted
basename lists of every PHP file either branch touched. No enum, DTO,
service, or controller name collision. No shared service-provider or
bootstrap file touched by either line.

## 18. Audit architecture compatibility

`main` did not touch `AuditRecorder`, `SchoolAuditEvent`,
`PlatformAuditEvent`, or either audit migration. Phase 5A.11/5A.12's
audit integration (event names, metadata allowlist, the new
`(school_id, subject_type, subject_id)` index) remains fully
compatible — nothing to reconcile.

## 19. School timezone compatibility

`main` did not touch `app/Models/School.php`, any `schools` table
migration, or `SchoolTimezone`. Phase 5A's scheduling/timing
dependency on `schools.timezone` is unaffected.

## 20. Config / dependency compatibility

- `main` added `config/privacy.php` (new file, Guardian-contact
  encryption/lookup settings) and appended two env vars to
  `phpunit.xml` — both additive, zero key overlap with Phase 5A's
  `config/communications.php`.
- **Neither branch modified `composer.json`, `composer.lock`,
  `package.json`, or `package-lock.json`.** No dependency-version
  reconciliation is required at all.

## 21. Scheduler / console compatibility

`main` did not touch `routes/console.php` or `app/Console/` at all.
Phase 5A's two new commands
(`PublishScheduledAnnouncements`, `RedispatchDueCommunicationDeliveries`)
and their `routes/console.php` schedule registrations apply with zero
conflict.

## 22. Frontend / navigation compatibility

Only `Dashboard.vue` is shared (§6, trivial). No shared layout,
TypeScript route-helper, or shared component file. Note (non-blocking,
post-merge polish opportunity): `main` introduced generic
`resources/js/Components/{EmptyState,Pagination,StatusBadge}.vue`
components; several Phase 5A pages (e.g. `Approvals/Index.vue`,
`Failed.vue`) currently hand-roll equivalent inline markup. Adopting
the shared components would be a reasonable post-merge UI-consistency
follow-up, never required for correctness.

## 23. Test-infrastructure compatibility

`main` modified `tests/Concerns/CreatesTenancyFixtures.php` (purely
additive: `createStudent`/`createGuardian`/
`createStudentGuardianRelationship`/`createGuardianContact` fixture
helpers, appended at the end of the trait) and added 4 new factories.
Phase 5A never touches that file and added its own separate
`tests/Concerns/CreatesCommunicationFixtures.php`. No collision, no
shared-file conflict, no semantic interaction. `phpunit.xml`'s
additive env-var change (§20) is main-only and requires no
reconciliation on Phase 5A's part.

## 24. Test health

**Phase 5A (current HEAD):** re-ran the Communications suite as the
representative, non-destructive check this audit calls for —
**415 passed, 1246 assertions, 0 failures**, identical to the 5A.12
checkpoint's own final numbers, confirming HEAD is unchanged and
stable. (Full 785-test regression is already fresh from the 5A.12
report and was not re-run in full here, per the audit's own "not
intended to repeat all 785 tests" guidance.)

**`main` (Phase 1A):** not executed in this audit. Running it would
require checking out `main` in this single working directory, which
would leave the branch in a different state than instructed
("remain on `feature/phase-5a-communication-hub`"). Deferred to the
integration gate, where a dedicated integration-branch checkout makes
this safe.

## 25. Semantic conflicts beyond Git

| # | Description | Git sees it? | Real risk |
| --- | --- | --- | --- |
| 1 | `CapabilityAndRoleSeeder.php` role-grant arrays edited by both branches | Yes (textual) | LOW — additive, but a careless resolution could drop one side's grants (§16) |
| 2 | Migration timestamp-prefix collisions (4 pairs) | No (different filenames) | LOW — cosmetic only, zero FK/ordering dependency (§11) |
| 3 | Duplicate reusable-component opportunity (`EmptyState`/`Pagination`/`StatusBadge` vs Phase 5A's inline markup) | No | NONE — cosmetic, optional follow-up |
| 4 | Communication Hub's audience model cannot reach Student/Guardian identities post-merge without new work | N/A | NONE for this merge — a known, already-documented (Phase 5A.2) deferred scope, not introduced by Phase 1A |

No conflict was found where Git would merge cleanly but the *combined
runtime behavior* would be wrong. This is a notably clean pair of
branches to integrate.

## 26. Integration strategy options

**Option A — Merge `main` into the Phase 5A branch.** Pros: keeps the
Phase 5A branch as the working surface, simple `git merge main`. Cons:
pollutes the Phase 5A branch's own checkpoint history with a merge
commit before it has ever been reviewed as a whole against `main`;
harder to cleanly abandon if reconciliation reveals something
unexpected.

**Option B — Rebase Phase 5A onto current `main`.** Pros: linear
history. Cons: rewrites 12 already-reviewed checkpoint commits,
destroying the exact SHAs every prior phase report referenced
(explicitly discouraged by the task's own §33 bias, and this audit
agrees given the branch's size and reviewed-checkpoint value) —
significant traceability loss for essentially no benefit given how few
real conflicts exist.

**Option C — Dedicated integration branch created from current `main`, merging (not cherry-picking) `feature/phase-5a-communication-hub` into it.** Pros:
`main` and the Phase 5A branch both remain untouched and independently
re-visitable; the integration branch is the disposable/iterable
surface for the 4 trivial reconciliations and the full regression
pass; a clean single merge commit (not a rewrite) preserves all 12
checkpoint SHAs' ancestry; if reconciliation reveals something
unexpected, the integration branch can simply be discarded and
recreated. Cons: one extra branch to manage temporarily.

**Recommendation: Option C.** The conflict surface is small enough
that Option A would also "work," but Option C costs almost nothing
extra here and is strictly safer — it keeps `feature/phase-5a-communication-hub`
as a stable, re-auditable reference throughout integration, exactly as
this checkpoint's own instructions prioritize ("preserve auditability
and checkpoint history").

## 27. Student/Guardian audience sequencing

**Recommendation: after Phase 5A foundation merges, as its own later
checkpoint.** Rationale, per §9: the blocker (no identity bridge from
Student/Guardian to a recipient-capable identity) exists **independent
of merge order** — it is not created or removed by integrating now.
Delaying Phase 5A's merge to first build Student/Guardian audience
support would gain nothing (the bridge doesn't exist regardless) while
extending an already-large branch's un-merged lifetime, which is
exactly the risk this audit exists to reduce. Merge the foundation on
unified `main` first; build the identity bridge and the corresponding
`CommunicationAudienceResolver` implementation as a focused follow-up
checkpoint against the now-unified codebase, where it can also decide
the GuardianContact email-resolution question properly.

## 28. Provider webhook recommendation

**Remains deferred**, reconfirmed. No transactional email provider
(Postmark, SES, SendGrid, etc.) exists anywhere in current `main` —
`main`'s only new config file (`config/privacy.php`) concerns PII
encryption, not email delivery. `COMMUNICATION_EMAIL_ENABLED=false`
remains the default on the Phase 5A line, untouched by `main`. Building
a provider-webhook contract now would mean inventing a payload shape
against a provider nobody has selected — exactly what this repo's ADRs
already warn against.

## 29. Integration blockers

**BLOCKER:** none found.

**REQUIRED RECONCILIATION** (handle in the integration branch before
the merge commit is considered done):
1. `DashboardController.php` — keep all 3 nav-flag lines.
2. `CapabilityAndRoleSeeder.php` — keep both capability-definition
   blocks and both grant-array key groups for `school_admin` and
   `principal` (§16 — do this carefully, additively).
3. `Dashboard.vue` — keep all 3 props and all 3 nav `<li>` links.
4. `routes/web.php` — keep both route groups.
5. Run the full merged-migration `fresh database` verification (§11,
   §37) as machine-checked proof, not just this audit's static
   analysis.

**POST-MERGE FOLLOW-UP** (safe to defer past the integration gate):
1. Rename the 4 timestamp-colliding Phase 5A migrations for human
   readability (§11).
2. Adopt `main`'s shared `EmptyState`/`Pagination`/`StatusBadge`
   components in Phase 5A's Vue pages where it hand-rolls equivalents
   (§22).
3. Student/Guardian audience resolver + identity bridge + Guardian
   email-destination resolution (§9/§27) — its own future checkpoint.

**NO ACTION** (fully compatible as-is): RLS/tenancy primitives, audit
architecture, School/timezone model, scheduler/console, dependencies
(composer/npm), test-infrastructure fixtures, `packages/contracts`/
`packages/shared-types`, all documentation files, all capability KEYS
(no literal collisions), all Communications-domain schema (zero
overlap with Phase 1A schema).

## 30. Recommended integration test plan (for the later gate — not executed now)

**Database:** fresh migrate from zero on the merged tree; verify every
migration succeeds in merged chronological order (§11/§12); confirm
RLS enabled+forced on every tenant table from both lines; confirm every
composite FK (Phase 5A's Communications tables, Phase 1A's
Student/Guardian tables) rejects cross-School references.

**Identity:** `User`, `SchoolMembership`, `Student`, `Guardian`,
`StudentGuardianRelationship`, `GuardianContact` — full RLS isolation
suites from both lines re-run together; confirm `SchoolMembership`
behavior is unchanged by Phase 1A's presence.

**Communication Hub (all 12 checkpoints):** announcements,
conversations, attachments, templates, scheduling, preferences, quiet
hours, emergency, approvals, audit, analytics — full existing suite
(785 tests as of 5A.12) re-run on the merged tree.

**Cross-domain:** multi-school identity isolation across both domains
simultaneously; capability grants for `school_admin`/`principal`
include the FULL reconciled set (both `communications.*` and
`students.*`/`guardians.*`); navigation renders all reconciled links
correctly gated; no route name collision at runtime
(`php artisan route:list` clean).

**Full regression:** complete PHP suite (both lines' tests together),
Pint, PHPStan (no baseline additions), Prettier, `vue-tsc --noEmit`,
ESLint, `npm run build`.

## 31. Migration test strategy for the final gate

1. Empty database → migrate every merged migration from zero via
   `--database=pgsql_admin` → must succeed with zero errors.
2. If repository conventions support it, additionally verify: existing
   pre-integration (Phase 1A-only) database + running only the newly
   merged Phase 5A migrations succeeds without touching pre-existing
   `students`/`guardians` data.
3. Never perform either against a shared/production-shaped database —
   isolated test database only, per `TestDatabaseGuard`'s existing
   fail-closed discipline (CLAUDE.md rules 50–54).

## 32. Rollback / safety strategy

Because Option C (§26) never touches `main` or
`feature/phase-5a-communication-hub` directly, rollback is trivial at
every stage: delete the integration branch and start over. Only once
the integration branch has passed the full test plan (§30) and been
explicitly reviewed should it be merged into `main` — and that final
step is its own separately-authorized gate, not part of this audit or
even part of "Phase 5A Final Integration."

## 33. Verdict

## GO

No material integration blocker exists. The two lines diverge on only
5 files, all 4 real textual conflicts are trivial disjoint-append
cases, there are zero schema/FK/RLS/capability-key collisions, and the
one deferred feature (Student/Guardian audience) was already
architecturally out of scope for Phase 5A and remains cleanly
separable as future work. Proceed to the integration gate using Option
C, addressing the 4 REQUIRED RECONCILIATION items in §29 as part of
that gate's own merge step.
