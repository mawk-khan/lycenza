# Phase 5D.2 — Final Integration / Reconciliation Gate

## 1. Source branch / SHA

`feature/phase-5d-communication-preferences-consent`, worktree
`/home/wajidkhan/sites/lycenza-phase-5d-preferences-consent`, HEAD
`256ced03051cd2b762470ae53d76850d090e4814` (confirmed exact, clean
tree).

## 2. Feature commit

`256ced0` — `feat(communications): add domain communication
preferences consent`.

## 3. Main baseline

`cc7f4a1c7487685ad5b5ef785f9a1ed894402fd3`, checked out in
`/home/wajidkhan/sites/lycenza-main-merge` — the worktree that
actually owns `main`. Confirmed unchanged since Phase 5D.1's
publication; clean tree.

## 4. origin/main

`cc7f4a1c7487685ad5b5ef785f9a1ed894402fd3` — identical to local main.
After `git fetch origin`: 0 ahead / 0 behind. No remote movement since
Phase 5D.1 was published.

## 5. Merge-base

`cc7f4a1c7487685ad5b5ef785f9a1ed894402fd3` — identical to main's own
HEAD; main has not diverged at all since the feature branch was cut.
`git merge-tree` confirmed **0 `CONFLICT` markers** before merging.

## 6. Integration branch

`integration/phase-5d-communication-preferences-consent`, dedicated
new worktree `/home/wajidkhan/sites/lycenza-phase-5d2-integration`,
created fresh from current local `main` (initial HEAD verified equal
to main's `cc7f4a1`).

## 7. Conflicts

None — `git merge --no-ff` produced merge commit `6e98723` with zero
textual or semantic conflicts. Every file in the 27-file delta
resolved as a clean addition/modification from the feature side, since
main's content was untouched since the branch point.

## 8. Preference architecture (re-verified at integration tip)

`communication_domain_preferences` — current-state-only, mutated in
place via `updateOrCreate()`, one deterministic row per
`(school_id, guardian_id|student_id, channel)` (partial unique indexes
`cdp_one_current_per_guardian_channel`/`_student_channel`, confirmed
present in the migration). Row absence means "inherit existing
default," never "opted out."

## 9. Consent-event architecture (re-verified)

`communication_domain_consent_events` — APPEND-ONLY at the database
privilege level. Confirmed directly against the integration tip's
schema: the `school_os_app` runtime role holds exactly `INSERT` and
`SELECT` on this table — no `UPDATE`/`DELETE` grant exists at all,
confirmed both before and after the targeted rollback/reapply (§28).
Current status is derived (latest `recorded_at`, tie-break `id`), never
stored as a separate mutable column.

## 10. Backward compatibility

Confirmed via `default_backward_compatibility_no_preference_or_consent_row_still_delivers`
passing against the real `AnnouncementService::publish()` path at the
integration tip: a Guardian with a valid email, no preference row, and
no consent event still receives a real OPTIONAL email delivery,
identical to pre-5D.2 behavior.

## 11. Guardian EMAIL behavior

Unchanged from the feature branch: `snapshotAndDeliverGuardianRecipients()`
checks domain preference then domain consent (OPTIONAL only) between
the existing school-channel-policy check and the existing
`GuardianEmailAddressResolver` destination resolution. Re-verified by
direct source inspection at the integration tip (`grep` for the exact
insertion point, §9 of the audit above) and by all 14
`GuardianEmailPreferenceConsentDeliveryTest` cases passing.

## 12. Guardian IN_APP behavior

Re-confirmed unchanged: `deliverInAppForLinkedDomainParty()` (the sole
IN_APP path for a linked Guardian/Student) contains no reference to
`domainPreferences`/`domainConsents` anywhere — grep-verified directly
against the merged source. IN_APP continues to be governed exclusively
by the linked SchoolMembership's own `CommunicationPreference`, proven
by `in_app_eligibility_follows_the_linked_membership_preference_unaffected_by_guardian_email_preference`.

## 13. Student behavior

Re-confirmed on the integrated codebase: no `StudentEmailAddressResolver`
or `StudentContact` class exists anywhere in the repository. The
Student detail page shows only a static, honest note; no interactive
preference/consent controls are offered for Student EMAIL, and
`DomainCommunicationPreferenceReadModel::forStudentEmail()` always
reports `endpointAvailable: false`.

## 14. OPTIONAL semantics

An explicit preference opt-out or a withdrawn consent record each
independently suppress OPTIONAL Guardian EMAIL, with distinct reason
codes (`recipient_preference_disabled`, `consent_withdrawn`); the
logical recipient snapshot always still contains the Guardian.

## 15. REQUIRED semantics

REQUIRED bypasses both the domain preference opt-out and a withdrawn
consent — the SAME unified bypass rule already established for
SchoolMembership preferences, applied identically to both new
recipient-level signals rather than inventing a second, different
rule. Documented explicitly, in the Phase 5D.2 doc and reiterated here,
as an application-level engineering default — **not** a legal or
regulatory claim about when a REQUIRED notice may lawfully override a
withdrawn consent in any jurisdiction.

## 16. Emergency semantics

Emergency is always REQUIRED (pre-existing invariant, unchanged) and
therefore inherits §15's bypass. Verified unaffected: quiet hours,
AccountLink resolution, and endpoint availability logic are untouched
(`emergency_communication_is_unaffected_by_guardian_preference` passes
at the integration tip). Emergency creates no endpoint, no AccountLink,
and does not turn Student email into a supported channel.

## 17. Suppression reasons

Confirmed distinct and stable at the integration tip:
`recipient_preference_disabled` (reused), `consent_withdrawn` (new),
`school_optional_channel_disabled`/`school_required_channel_disabled`
(school policy, unchanged), `recipient_destination_unavailable`
(endpoint, unchanged). None of the four is ever misclassified as a
send/provider/retry failure — all four are recorded in the
policy-decision ledger BEFORE any delivery/attempt row could exist.

## 18. School channel-policy interaction

Verified by `school_channel_policy_disabled_is_reported_over_preference`:
a School with EMAIL disabled and a Guardian opted in still records
`school_optional_channel_disabled` — the school-level check runs
strictly first and is never obscured by the newer preference/consent
layer.

## 19. Endpoint availability

Verified by `opted_in_guardian_with_no_email_endpoint_is_reported_as_destination_unavailable`:
an opted-in, consent-granted Guardian with no eligible `GuardianContact`
email still produces `recipient_destination_unavailable`, never a
false preference/consent suppression. `GuardianEmailAddressResolver`
remains the sole destination-truth source, untouched by this
checkpoint.

## 20. Scheduling

Because `PublishScheduledAnnouncements` calls the identical
`AnnouncementService::publish()` at due time with no separate
scheduled-delivery code path (confirmed by source inspection,
unchanged from the pre-5D.2 codebase), a preference/consent change
made between scheduling and the due time is naturally picked up —
verified by
`a_preference_change_after_scheduling_but_before_publication_is_applied`.

## 21. Approval

`CommunicationApprovalFingerprint` contains no reference to preference
or consent state (grep-confirmed at the integration tip) — it never
did, and this checkpoint added nothing to it. Verified by
`an_approved_announcement_remains_valid_after_a_preference_change`: an
approved Required announcement's status remains `approved` after both
a preference opt-out and a consent withdrawal are recorded for its
target Guardian.

## 22. Historical immutability

`communication_delivery_policy_decisions` and `communication_deliveries`
rows, once created, are never revisited. Verified by
`a_later_preference_change_never_rewrites_a_historical_delivery`: a
prior delivery's `sent` status is unaffected by a subsequent preference
change; only a later, separate Announcement reflects the new state.

## 23. GuardianContact lifecycle

Preference/consent rows reference only `(school_id, guardian_id,
channel)` — never a `GuardianContact` row id (schema-level guarantee,
confirmed in both migrations' column lists). Verified end-to-end
through the real `GuardianContactService::create()`/`setPrimary()`
write path by `preference_survives_a_guardian_contact_change`: an
opt-out survives a primary-email-address change.

## 24. AccountLink independence

Neither new table has any foreign key or code path referencing
`StudentGuardianAccountLink` or `SchoolMembership` (grep-confirmed). A
Guardian's EMAIL preference/consent is fully independent of whether
they have ever linked a School OS account — AccountLink remains
relevant only for IN_APP.

## 25. Authorization / UI

`GuardianCommunicationPreferenceController`'s two write actions
require BOTH `communications.manage` AND `guardians.manage` — verified
directly in source and by
`an_actor_with_only_communications_manage_cannot_update_the_preference`/
`an_actor_with_only_guardians_manage_cannot_update_the_preference`
(each denied) and
`an_actor_with_both_capabilities_can_update_the_preference` (allowed).
No role-name check anywhere. A cross-School Guardian id resolves to
404 (`SchoolScope`-scoped `findOrFail()`), verified by
`a_cross_school_guardian_id_is_not_found`. The Guardian/Student detail
pages show status only, never a decrypted contact value in the new
section — verified by
`the_guardian_detail_page_never_exposes_a_plaintext_contact_value_via_the_preference_state`.

## 26. Audit / privacy

`communication.domain_preference.updated`,
`communication.consent.granted`, `communication.consent.withdrawn` —
all via the existing `AuditRecorder::school()`, metadata limited to
guardian/student id, channel, and (for preference) the new value —
never a contact address or message content (source-confirmed; no
`GuardianContact`/email field is ever passed to `AuditRecorder` from
either new service).

## 27. Performance

Both services' batched read methods
(`currentPreferencesForGuardians()`, `currentStatusesForGuardians()`,
the latter a single PostgreSQL `DISTINCT ON` query) are used by
`AnnouncementService` — confirmed by direct source inspection (one
call per audience chunk, inside the existing per-channel loop, outside
the per-Guardian loop) and by
`publishing_optional_email_to_many_guardians_uses_a_bounded_query_count`
(20 Guardians, <350 total queries end-to-end) plus the two dedicated
service-level bounded-query tests (20 Guardians, <5 queries each).

## 28. RLS / security

Both new tables have RLS enabled and forced — confirmed via direct
`pg_class` inspection AFTER a full rollback/reapply cycle (§30 below),
not merely trusted from the migration source. Raw-SQL tests prove:
no-context zero rows, cross-School read denial, cross-School insert
rejection (RLS `WITH CHECK` + composite Guardian/Student FK), the
partial-unique-index "one current preference" guarantee, and — for the
append-only ledger — unconditional UPDATE/DELETE denial regardless of
School context (subsuming the cross-School case, since no School can
mutate it at all). Full application RLS suite: **121 tests, 268
assertions**, 0 failures (up from the pre-5D.2 109 by exactly the 12
new RLS tests).

## 29. Migrations

**112 total** at the integration tip (109 pre-5D.2 + the 3 new Phase
5D.2 migrations). Fresh `platform:test-db-reset --force` run against
the isolated `school_os_test` database: **0 failures.** Targeted
rollback (`migrate:rollback --step=3`) of exactly the 3 new
migrations, in correct reverse order, verified both new tables
dropped; reapply verified both restored with RLS enabled+forced and
the append-only privilege grant (`INSERT`/`SELECT` only, no
`UPDATE`/`DELETE`) correctly re-applied. No historical migration was
edited or rolled back.

## 30. Focused tests

**50 tests, 105 assertions**, all passing — exact match to the feature
branch's own report.

## 31. Communications regression

Full Communications suite: **663 tests, 1897 assertions**, 0 failures
— exact match to the feature branch's report.

## 32. Full regression

Two consecutive clean full-suite runs (`vendor/bin/phpunit`, each
preceded by a full `platform:test-db-reset --force`):

- Run 1: **2561 tests, 8835 assertions, 0 failures, 0 errors.**
- Run 2: **2561 tests, 8835 assertions, 0 failures, 0 errors.**

Exact match to the feature branch's own report — the merge introduced
no regression anywhere in the application. (See §34 for one
infrastructure-only transient failure encountered and resolved before
these clean runs.)

## 33. Quality gates

Pint ✅ (1015 files, no style issues). PHPStan ✅ (0 errors, no new
baseline entries). Prettier ✅. `vue-tsc --noEmit` ✅. ESLint ✅ (0
errors; the same 2 pre-existing, unrelated `Pagination.vue` `v-html`
warnings as every prior report). `npm run build` ✅.

## 34. Infrastructure safety (incident disclosed, not hidden)

Before any testing began, `docker ps` showed the shared `school-os`
Postgres/Redis/MinIO containers **stopped** (all three exited with the
same status roughly 31 hours prior, consistent with a host/WSL restart
rather than a crash — two unrelated projects on this machine had
already been freshly started by other activity). Their named data
volumes (`school-os_postgres_data`/`_redis_data`/`_minio_data`) were
intact and untouched. Per this repository's own documented hazard
(worktree-relative Compose bind paths can cause unwanted recreation),
the exact worktree the containers were originally bound to was
identified via `docker inspect`'s compose working-dir label
(`/home/wajidkhan/sites/lycenza-phase-1c-integration`) and its
`docker compose config` bind paths were confirmed to match exactly
before running **`docker compose up -d postgres redis minio`** from
that specific directory only (no rebuild, no `-v`, no other services)
— **explicitly confirmed with the user before running**, since this is
a Docker lifecycle action. Docker reported `Starting`/`Started` (never
`Creating`/`Recreated`), confirming the existing containers/volumes
were reattached, not recreated.

One residual effect surfaced during the first full-suite run: the
MinIO `school-os-local` bucket (required by the Documents module, not
auto-provisioned by MinIO itself — see
`docs/modules/DOCUMENTS.md`'s own documented `mc mb local/school-os-local`
provisioning step) was empty/absent after the restart, causing the
same 5 Documents-MinIO test failures seen in the Phase 5D.1 backend
report for an unrelated reason. It was recreated with the exact
documented command; all 6 Documents-MinIO tests then passed, and both
full clean runs above reflect that fix. **Nothing Communications-
related, no application code, and no test file was touched to resolve
either issue** — both were pre-existing local Docker/MinIO state, not
caused by and not part of the Phase 5D.2 diff.

A second, purely cosmetic observation: after the restart, the shared
Postgres/Redis containers' HOST-exposed ports changed (25432/26379
instead of the previous 5432/6379) — apparently due to environment-
variable-driven port mapping differing between the worktree used to
restart them and whichever worktree last started them. This did not
affect any testing in this gate, which exclusively used the
containers' internal Docker network names (`postgres`/`redis` on
`school-os_default`), never the host-mapped ports.

Post-test verification: all three shared containers remain healthy,
same container names, same named volumes, no further restarts.

## 35. Deferred scope

Everything Phase 5D.2 already deferred (self-service portals, public
unsubscribe pages, account invitations, a Student email endpoint,
SMS/WhatsApp/Push delivery, provider webhook handling, marketing
automation, a legal-policy engine, consent-document storage, AI
consent inference, Phase 5E provider integration) remains deferred.
This gate adds no new scope.

## 36. Publication readiness

Local integration is complete and verified clean end-to-end,
identically to the feature branch's own report, plus independent
re-verification of every architectural claim against the merged
source and a live database. See the top-level session report for the
Go/No-Go verdict and next recommendation.
