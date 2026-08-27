<?php

namespace Database\Factories;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AdmissionApplication>
 *
 * Same reasoning as SubjectOfferingFactory/SectionFactory: five
 * independent parents (school, applicant, academic year, campus, grade
 * level) that must all share one School are supplied explicitly by the
 * caller, not defaulted here.
 */
class AdmissionApplicationFactory extends Factory
{
    protected $model = AdmissionApplication::class;

    public function definition(): array
    {
        return [
            'status' => 'draft',
            'decision_note' => null,
            'converted_student_id' => null,
            'converted_student_enrollment_id' => null,
            'converted_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(['status' => 'submitted']);
    }

    public function accepted(): static
    {
        return $this->state(['status' => 'accepted']);
    }

    public function rejected(): static
    {
        return $this->state(['status' => 'rejected']);
    }

    public function withdrawn(): static
    {
        return $this->state(['status' => 'withdrawn']);
    }

    /**
     * `$student`/`$enrollment` must belong to the SAME School as the
     * application (the composite FKs enforce this at insert time
     * regardless -- this state does not itself verify it, matching
     * `createStudentSubjectEnrollment()`-style fixture helpers that
     * trust the caller to pass same-School fixtures, same as every
     * other multi-parent factory state in this codebase).
     */
    public function converted(Student $student, StudentEnrollment $enrollment): static
    {
        return $this->state([
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => Carbon::now(),
        ]);
    }
}
