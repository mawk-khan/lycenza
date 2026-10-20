<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;

/**
 * The Enrollment API's one response shape, shared by the whole-School
 * placement actions (StudentEnrollmentController) and the Campus-aware
 * reads (EnrollmentDirectoryController, ORG.7F-1).
 */
trait PresentsStudentEnrollment
{
    /**
     * Deliberately excludes `dateOfBirth`/Guardian PII/encrypted
     * contact values/lookup hashes -- the Enrollment API operates on
     * academic placement only (CLAUDE.md section 12/49). Used for both
     * directory rows and single-record detail; there is no broader
     * "full" student shape exposed through this controller at all.
     *
     * @return array<string, mixed>
     */
    private function presentSummary(StudentEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student' => [
                'id' => $enrollment->student->id,
                'studentNumber' => $enrollment->student->student_number,
                'firstName' => $enrollment->student->first_name,
                'middleName' => $enrollment->student->middle_name,
                'lastName' => $enrollment->student->last_name,
            ],
            'academicYear' => $this->presentRef($enrollment->academicYear),
            'campus' => $this->presentRef($enrollment->campus),
            'gradeLevel' => $this->presentRef($enrollment->gradeLevel),
            'section' => $this->presentRef($enrollment->section),
            'rollNumber' => $enrollment->roll_number,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(StudentEnrollment $enrollment): array
    {
        return [
            ...$this->presentSummary($enrollment),
            'createdAt' => $enrollment->created_at->toIso8601String(),
            'updatedAt' => $enrollment->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRef(AcademicYear|Campus|GradeLevel|Section $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'code' => $model->code,
        ];
    }
}
