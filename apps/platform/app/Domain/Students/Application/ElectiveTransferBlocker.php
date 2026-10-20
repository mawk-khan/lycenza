<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;

/**
 * ORG.7F-3 (ADR 0076 §47): an ACTIVE elective (subject enrollment) anchored to
 * the source placement would be left on a `transferred` placement and the
 * source Campus's Offering -- the academic workflow resolves it first
 * (withdraw, cancel or transfer the elective). Never copied, deleted or
 * re-anchored here.
 *
 * Serialization: an elective enroll share-locks its anchor placement
 * (StudentSubjectEnrollmentService), and the transfer holds that placement
 * FOR UPDATE before asking -- so an elective committed first is seen, and one
 * arriving later re-reads a `transferred` anchor and refuses.
 */
final class ElectiveTransferBlocker implements StudentTransferBlocker
{
    public function blocksCampusTransfer(School $school, string $studentId, string $sourceEnrollmentId, string $sourceCampusId): bool
    {
        $sourceYearId = StudentEnrollment::query()->where('school_id', $school->id)->whereKey($sourceEnrollmentId)->value('academic_year_id');

        return StudentSubjectEnrollment::query()
            ->where('student_subject_enrollments.school_id', $school->id)
            ->where('student_subject_enrollments.status', 'active')
            ->where(fn ($q) => $q->where('student_subject_enrollments.student_enrollment_id', $sourceEnrollmentId)
                // A pre-anchor (legacy) elective of this Student on a source-Campus
                // Offering of the same year -- the rollover's legacy rule.
                ->orWhere(fn ($legacy) => $legacy->whereNull('student_subject_enrollments.student_enrollment_id')
                    ->where('student_subject_enrollments.student_id', $studentId)
                    ->where('student_subject_enrollments.academic_year_id', $sourceYearId)
                    ->whereHas('subjectOffering', fn ($o) => $o->where('subject_offerings.campus_id', $sourceCampusId))))
            ->exists();
    }
}
