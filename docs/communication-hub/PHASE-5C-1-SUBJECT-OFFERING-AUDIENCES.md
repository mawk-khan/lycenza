# Phase 5C.1 — SubjectOffering Communication Audiences

## 1. Objective

Let a School target a SubjectOffering's current roster (required or
elective) as an Announcement audience, resolved exclusively through
Phase 1C's `App\Domain\Students\Application\SubjectOfferingRosterReadService`
— never by Communications re-deriving Student subject participation
itself. Backend/domain/API scope only (no composer UI in this
checkpoint — see §9).

## 2. Prior attempt and the dependency it was blocked on

Phase 5C.1 was attempted once before this checkpoint and correctly
stopped rather than inventing a shadow relationship: at that point,
`subject_offerings` (Phase 0D) was a Grade-level offering *definition*
(Subject × GradeLevel × Campus × AcademicYear) with `is_required` as
its only per-Student-relevant flag — there was no table anywhere
containing both a Student id and a SubjectOffering id, so Communications
had no data to resolve a roster from and refused to infer one from
Section/GradeLevel proxying. This gap, why it existed, and the
checkpoint that closed it are documented in
`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
§2/§3/§26. That checkpoint added exactly one new table
(`student_subject_enrollments`, for elective participation only —
required participation remains derived from `student_enrollments`,
never materialized) and the `SubjectOfferingRosterReadService` contract
this checkpoint consumes. No Phase 1C source file was touched to
support this checkpoint.

## 3. Extends the existing audience abstraction — no parallel system

This checkpoint adds a THIRD case to the existing Grade/Section
academic-cohort audience model established by Phase 5B.3
(`docs/communication-hub/PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md`), not
a new one:

- `App\Domain\Communications\Domain\CommunicationAudienceType::SubjectOffering`
  (`'subject_offering'`) — a new top-level `audience_type`, additive to
  `communication_announcements`' CHECK constraint exactly like every
  prior audience-type addition in this lineage.
- `App\Domain\Communications\Domain\CommunicationAcademicCohortType::SubjectOffering`
  (`'subject_offering'`) — a new `cohort_type` on the SAME
  `communication_announcement_academic_cohorts` row shape Grade/Section
  already use, with a new nullable `subject_offering_id` column
  (mutually exclusive with `grade_level_id`/`section_id`, database-
  enforced). No new table.
- `App\Domain\Communications\Application\Audience\SubjectOfferingAudienceResolver`
  — a new resolver registered in `CommunicationAudienceResolverRegistry`
  (`App\Providers\PlatformServiceProvider`), following the identical
  shape/discipline as `GradeAudienceResolver`/`SectionAudienceResolver`.

Still no `GuardiansOfSubjectOffering` case — the Student-vs-Guardian
projection is the SAME `recipient_kind` field on the cohort row, reused
unmodified (`App\Domain\Communications\Application\Audience\GuardianProjectionResolver`).

## 4. Communications never re-derives roster membership

**`SubjectOfferingAudienceResolver` calls
`SubjectOfferingRosterReadService::currentRosterStudentIds()` and never
queries `student_enrollments`/`student_subject_enrollments` directly,
and never branches on `SubjectOffering::is_required`.** The single
roster-service call already handles both required (derived from the
Student's current active `StudentEnrollment`) and elective (explicit
active `StudentSubjectEnrollment`) offerings — Communications is
deliberately ignorant of which kind it is targeting. See
`Tests\Feature\Communications\SubjectOfferingAudienceResolverTest::the_resolver_delegates_to_the_real_roster_service_output_exactly`,
which asserts the Communications-resolved roster is byte-for-byte the
same array the roster service reports directly, independently of
Communications.

## 5. Resolved fresh at every publication, never frozen

Same discipline as Grade/Section (`PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md`
§4): a SubjectOffering audience is resolved fresh at draft preview,
manual publish, and scheduled due-publish — never fixed to whoever
satisfied the roster when the audience was authored. A Student who
withdraws their elective enrollment, or whose `StudentEnrollment`
changes Grade/Campus/AcademicYear, drops out of the audience on the
next resolution with zero Communications-side state to update. See
`a_student_who_leaves_the_offerings_grade_before_publication_is_excluded_at_publish_time`
and `a_student_who_withdraws_their_elective_enrollment_is_excluded_from_the_current_audience`.

## 6. Inactive offering: zero recipients, no second policy

`SubjectOfferingRosterReadService` itself short-circuits an inactive
SubjectOffering to `[]`/`0` (Phase 1C.1A). `SubjectOfferingAudienceResolver`
does not duplicate this check — it simply reflects whatever the roster
service returns, so an inactive offering naturally resolves to an
empty audience through the SAME generic "empty audience" handling every
other audience type already has, with no SubjectOffering-specific
exception, deletion, or rewrite of the audience definition. Reactivating
the offering restores the ordinary audience on the next resolution —
proven by
`an_offering_deactivated_after_the_audience_was_authored_resolves_empty_without_touching_the_definition`
and `reactivating_the_offering_restores_the_ordinary_audience`.

A SubjectOffering must be `active` at AUTHORING time, mirroring
GradeLevel/Section's identical "not selectable while inactive" gate in
`AnnouncementService::syncAcademicCohort()` — this is a selection-time
gate only, distinct from the resolver's own always-on inactive
short-circuit above.

## 7. Tenancy

A caller-supplied `academic_year_id`/`subject_offering_id` is never
trusted at face value: `AnnouncementService::syncAcademicCohort()`
re-verifies same-School ownership and cross-validates the offering's
own `academic_year_id` against the caller-supplied one (defense-in-
depth, same reasoning as Section) before ever persisting a cohort row.
The composite `(subject_offering_id, school_id)` foreign key against
`subject_offerings` independently rejects a cross-School id at INSERT
time. A cross-School offering id and a random nonexistent id fail
identically (`InvalidAcademicCohortException`, no enumeration signal) —
see `drafting_a_subject_offering_cohort_with_another_schools_offering_id_is_rejected`
and its random-UUID counterpart.

## 8. Authorization

No new capability. Audience creation/update and the SubjectOffering
picker search endpoint are gated by the SAME `communications.announce`
capability every other audience type already uses — never
`academics.subjects.view`/`.manage`. `SubjectOfferingRosterReadService`
is authorization-neutral (no capability check inside it, matching
every other Application service in this codebase per
`PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md` §15/§17) — academic
authorization is never transitively required to send a Communications
message.

## 9. Deferred to a later checkpoint

- **Composer UI**: no Vue changes in this checkpoint — added by Phase
  5C.2 (`docs/communication-hub/PHASE-5C-2-SUBJECT-OFFERING-AUDIENCE-COMPOSER.md`).
  A School user can now reach this audience type through the
  Communications Hub UI.
- Teacher/staff academic audiences, Timetable audiences, Attendance-
  derived audiences, Exam groups, Fee-defaulter audiences, arbitrary
  SQL/filter-builder audiences, automatic AI-generated audience
  criteria, provider/channel changes — none in scope, same exclusions
  as every prior Communications checkpoint.

## 10. Privacy

The `audience/subject-offerings/search` picker endpoint
(`App\Domain\Communications\Http\Controllers\CommunicationAudienceSearchController::subjectOfferings()`)
returns only Subject name/code, GradeLevel name, Campus name, and
required/elective — never Student membership. A School user only sees
who is actually on the roster after creating/previewing the audience,
through the same `previewAudience()`/publish-time snapshot path every
other audience type uses.
