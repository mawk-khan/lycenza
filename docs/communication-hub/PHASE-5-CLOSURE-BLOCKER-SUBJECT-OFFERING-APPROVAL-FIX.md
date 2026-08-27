# Phase 5 Closure Blocker Fix — SubjectOffering Approval Fingerprint Integrity

Narrow defect fix, not a new checkpoint. Resolves the sole blocker identified
by the Phase 5 Communications Closure Audit.

## 1. Closure audit finding

The audit's fifth fork (Phase 5B/5C/audience review) found that
`CommunicationApprovalFingerprint::snapshot()` detected academic-cohort
audience definitions for `grade` and `section` but not `subject_offering` —
so an approved SubjectOffering-audience announcement's fingerprint always
computed `academicCohort => null`, regardless of which SubjectOffering was
actually targeted. Confirmed via git blame that the fingerprint class was
last touched by the Phase 5B.3 (Grade/Section) commit and was never updated
by either Phase 5C commit that added SubjectOffering audiences, and that the
existing `AcademicCohortApprovalInvalidationTest` never exercised the
`subject_offering` case.

## 2. Root cause

Two related omissions in `CommunicationApprovalFingerprint::snapshot()`:

1. `$isAcademicCohortAudience = in_array($announcement->audience_type, ['grade', 'section'], true);`
   — the literal array never included `'subject_offering'`, even though
   `CommunicationAudienceType::SubjectOffering = 'subject_offering'` has been
   a real, distinct top-level audience type since Phase 5C.1, and
   `AnnouncementService::isAcademicCohortAudienceType()` (the analogous
   check used for authoring/editing) already correctly includes it.
2. Even had (1) been fixed alone, the captured `academicCohort` field set
   (`cohortType`, `academicYearId`, `gradeLevelId`, `sectionId`,
   `recipientKind`) never included `subjectOfferingId` — so two different
   SubjectOfferings of the same recipient kind and academic year would still
   have produced an identical fingerprint.

## 3. Affected invariant

The approval fingerprint's documented invariant: *"approval binds to WHO is
targeted... changing WHO a message targets changes what was approved"*
(`CommunicationApprovalFingerprint`'s own class docblock, and
`docs/communication-hub/PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md`).
For SubjectOffering audiences specifically, this invariant did not hold.

## 4. Why Grade/Section worked

Both were present in the original `$isAcademicCohortAudience` array from
their Phase 5B.3 introduction, and their identifying columns
(`gradeLevelId`/`sectionId`) were captured in the fingerprint payload from
the same commit — the fingerprint class was built and shipped alongside
Grade/Section, so nothing was missed for those two.

## 5. Why SubjectOffering did not

SubjectOffering was added as a third cohort type by Phase 5C.1, a later,
separate checkpoint. `AnnouncementService`'s own academic-cohort detection
(`isAcademicCohortAudienceType()`) was correctly updated at that time — but
`CommunicationApprovalFingerprint` is a separate class with its own,
independent, duplicated type-check, and that second copy was never updated
to match. No test caught it because `AcademicCohortApprovalInvalidationTest`
predates Phase 5C.1 and was never extended to cover the new cohort type.

## 6. Exact fix

`apps/platform/app/Domain/Communications/Application/Approval/CommunicationApprovalFingerprint.php`:

- `$isAcademicCohortAudience` array extended to
  `['grade', 'section', 'subject_offering']`.
- `academicCohort` snapshot array extended with
  `'subjectOfferingId' => $announcement->academicCohort->subject_offering_id`.

No other logic changed. No approval lifecycle, self-approval, Emergency
behavior, scheduling semantics, recipient snapshot logic,
preference/consent, AccountLink, or SubjectOffering roster-resolution code
was touched.

## 7. Approval-sensitive definition (post-fix)

The `academicCohort` fingerprint field for a SubjectOffering-audience
announcement now captures: `cohortType` (`subject_offering`),
`academicYearId`, `subjectOfferingId`, `recipientKind` (Student/Guardian).
(`gradeLevelId`/`sectionId` remain `null` for this cohort type, exactly
mirroring how `subjectOfferingId` remains `null` for Grade/Section.)

## 8. Why roster membership remains non-fingerprinted

Unchanged design principle, extended consistently to the third cohort type:
resolved Student/Guardian membership is never part of the approval
fingerprint for any academic-cohort audience — only the cohort *definition*
is. A Student enrolling in or dropping a SubjectOffering after approval
(`StudentSubjectEnrollment` churn) does not change which SubjectOffering was
approved, so it correctly does not invalidate the approval — proven by the
new `a_student_joining_the_approved_subject_offering_after_approval_does_not_invalidate_it`
test.

## 9. Tests added

Three new methods in `AcademicCohortApprovalInvalidationTest.php`, mirroring
the existing Grade tests exactly for the SubjectOffering case:

- `changing_the_selected_subject_offering_after_approval_invalidates_it`
- `changing_the_recipient_kind_for_subject_offering_after_approval_invalidates_it`
- `a_student_joining_the_approved_subject_offering_after_approval_does_not_invalidate_it`

Reproduction was verified genuine before the fix was trusted: with the
production fix temporarily stashed, both "invalidates" tests failed with
`Failed asserting that two strings are identical. -'draft' +'approved'` —
proving the announcement incorrectly remained `approved` after its target
SubjectOffering changed. Restoring the fix made all three pass.

## 10. Regression results

- `AcademicCohortApprovalInvalidationTest`: 7 tests / 7 assertions / 0 failures (4 original + 3 new)
- Approval/fingerprint suite: 52 tests / 146 assertions / 0 failures
- SubjectOffering audience suite: 40 tests / 120 assertions / 0 failures
- Full Communications suite: 664 tests / 1890 assertions / 0 failures
- Full application suite: 2799 tests / 9665 assertions / 0 failures — exactly the prior published baseline (2796/9662) plus this fix's 3 new tests/assertions

## 11. No schema change

Confirmed — 0 new migration files. This was a pure application-logic defect;
`communication_announcement_academic_cohorts.subject_offering_id` already
existed as a column, it simply wasn't being read into the fingerprint.

## 12. Safety

Fixed and verified on an isolated feature branch/worktree
(`feature/phase-5-subject-offering-approval-fingerprint-fix`), off
synchronized local `main` (`8cf2ed0`, unchanged from `origin/main`
throughout). Tested against an isolated Docker Compose project
(`school-os-fpfix`, dedicated ports) — no other worktree's infrastructure
was touched, confirmed via a broad post-run container check. Nothing pushed.
Phase 1E's preserved stash was not touched. Quality gates (Pint, PHPStan)
clean; Prettier/ESLint/vue-tsc/build skipped as genuinely inapplicable (zero
frontend files changed).

## 13. Phase 5 closure readiness

This was the sole blocker returned by the Phase 5 Communications Closure
Audit. With it resolved and independently regression-verified, Phase 5 has
no known remaining category-A (required-to-close) items. Formal closure
documentation (`PHASE-5-FINAL-CLOSURE.md`) should follow this fix's
integration into `main`, per the audit's own recommendation — not created
here.
