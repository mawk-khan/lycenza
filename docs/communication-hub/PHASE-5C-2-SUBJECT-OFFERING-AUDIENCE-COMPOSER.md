# Phase 5C.2 — SubjectOffering Audience Composer UI

## 1. Objective

Make Phase 5C.1's already-complete backend SubjectOffering audience
type reachable through the existing Announcement composer
(`resources/js/Pages/App/Communications/Announcements/Create.vue`) and
detail view (`.../Show.vue`) — no new page, no new backend semantics.
See `docs/communication-hub/PHASE-5C-1-SUBJECT-OFFERING-AUDIENCES.md`
for the domain contract this checkpoint exposes.

## 2. Extends the existing Grade/Section picker code, not a new component

There is no dedicated `GradePicker.vue`/`SectionPicker.vue` component
in this codebase — the Grade/Section search-then-select picker lives
inline in `Create.vue`, built on three already-generic refs
(`selectedCohort`, `cohortQuery`, `cohortResults`) and two functions
(`cohortSearchEndpoint()`, `searchCohort()`). Phase 5C.2 adds
`subject_offering` as a third branch in each, and reuses the identical
chip-select/search-dropdown markup Grade/Section already render — no
new `SubjectOfferingPicker.vue` component was created, since the
existing structure was already the right size for a third case and
splitting it out was not required by any repository convention. The
`cohortRecipientKind` (Student/Guardian) radio group is reused
unmodified.

## 3. Submission payload

Unchanged `academic_cohort` object shape from Phase 5C.1
(`academic_year_id`, `subject_offering_id`, `recipient_kind`) is now
built by the composer when `audienceType === 'subject_offering'`. The
existing pattern of explicitly nulling `grade_level_id`/`section_id`/
`subject_offering_id` for whichever two don't match the current
`audienceType` (already how Grade/Section's payload worked before this
checkpoint) means switching audience types never leaks a stale
selection into the request — client-side, this is defense-in-depth
only: `AnnouncementService::syncAcademicCohort()`'s SubjectOffering
branch unconditionally ignores `grade_level_id`/`section_id` from the
selection DTO regardless of what a malformed client sends, proven by
`Tests\Feature\App\CommunicationSubjectOfferingAudienceHubTest::a_stale_grade_level_id_submitted_alongside_subject_offering_is_never_persisted`.

## 4. Required/Elective is descriptive only

The SubjectOffering search endpoint's `label` already carries a
`[required]`/`[elective]` suffix (Phase 5C.1,
`CommunicationAudienceSearchController::subjectOfferings()`) — the
composer displays whatever string the server returns and does not
parse, branch on, or duplicate this distinction anywhere in Vue.

## 5. Inactive offerings

The SubjectOffering search endpoint only returns `status = 'active'`
offerings (same gate `syncAcademicCohort()` itself enforces at
authoring time) — inactive offerings are not searchable, so there was
no "show inactive state in the picker" UI to build. This mirrors
Grade/Section's identical existing behavior exactly. An offering that
becomes inactive *after* an audience already targets it still resolves
to zero recipients dynamically (Phase 5C.1 §6) — the composer's Show
page displays this through the same generic `preview.domain` counts
every other audience type uses, no SubjectOffering-specific empty-state
text.

## 6. Preview / detail display

`AnnouncementController::academicCohortPreview()` gained one new field,
`subjectOfferingLabel` (Subject name/code + "— Required"/"— Elective"),
alongside the pre-existing `gradeLevelName`/`sectionName`. No new
recipient-count logic — `domainAudiencePreview()` (unchanged) already
reports `studentCount`/`guardianCount` generically for every academic-
cohort audience type via the same resolver-registry call. The composer
never counts recipients client-side.

## 7. Edit mode

Not implemented — no existing Announcement audience type (Grade,
Section, Student, Guardian, ...) has an audience-editing UI anywhere in
this codebase; `Show.vue` is read/action-only (publish, schedule,
cancel), never posts to `update()`. Phase 5C.2 does not introduce one
for SubjectOffering either, consistent with "reuse existing patterns,
don't invent a new form architecture."

## 8. Authorization / privacy

Unchanged from Phase 5C.1: `communications.announce` gates the picker
and composer; no academic capability is required. The picker's search
response carries only Subject name/code, GradeLevel/Campus name, and
required/elective — never Student identities. `localStorage`/
`sessionStorage` are not used anywhere in the composer, for
SubjectOffering or any other audience type.
