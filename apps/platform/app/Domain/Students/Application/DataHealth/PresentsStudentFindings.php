<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;

/**
 * Display-safe labels for Students-module Data Health findings: name and
 * Student number only (never date of birth or contact data, the Phase 1A
 * list-row boundary). Students are eager-loaded with these columns only.
 */
trait PresentsStudentFindings
{
    /** The Student columns a finding may load. */
    private const array STUDENT_COLUMNS = ['id', 'school_id', 'student_number', 'first_name', 'middle_name', 'last_name'];

    private static function studentLabel(?Student $student): string
    {
        if ($student === null) {
            return 'Unknown Student';
        }

        $name = trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name])));

        return "{$name} ({$student->student_number})";
    }

    private static function placementDetail(StudentEnrollment $enrollment): string
    {
        $class = trim(implode(' · ', array_filter([$enrollment->gradeLevel?->name, $enrollment->section?->name])));

        return sprintf(
            '%s%s · roll %s · since %s',
            $enrollment->academicYear?->name !== null ? $enrollment->academicYear->name.' · ' : '',
            $class !== '' ? $class : 'Section',
            $enrollment->roll_number,
            $enrollment->starts_on->toDateString(),
        );
    }

    /** Enrollments directory filtered to this placement's Student and year (enrollments.view). */
    private static function enrollmentDirectoryUrl(StudentEnrollment $enrollment): string
    {
        return '/app/enrollments?'.http_build_query([
            'student_number' => $enrollment->student?->student_number,
            'academic_year_id' => $enrollment->academic_year_id,
        ]);
    }
}
