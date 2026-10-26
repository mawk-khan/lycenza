<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\PlacementTargetUnavailableException;
use App\Domain\Students\Application\Exceptions\StudentOnboardingUnavailableException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ORG.7F-2 (ADR 0076 §46, owner decisions F2-D5, F2-D8, F2-D9, F2-D12,
 * F2-D13): THE atomic Campus onboarding -- a NEW Student and their current
 * placement, together or not at all.
 *
 * One transaction, in this lock order:
 *   1. the School's active year, FOR SHARE (none: refused -- never the
 *      latest, a draft or a previous year);
 *   2. the destination Section (this School, the active year, a Campus in
 *      BOTH the students.manage and the enrollments.manage scope);
 *   3. the Student number from StudentNumberAllocator (the School's counter
 *      row, FOR UPDATE) -- never typed or chosen by the actor;
 *   4. the new Student (active, the allocated number, the given names and
 *      date of birth -- write-only, F2-D13);
 *   5. the placement through StudentEnrollmentService::enroll(): Student,
 *      Section FOR UPDATE, Campus share-lock, the F-2 validity rule.
 * Any refusal rolls everything back: no Campus-less Student can survive.
 *
 * It never takes a Student id and never looks one up: it creates identity,
 * so it can never capture an existing Student (unassigned, inactive, a
 * leaver, or another Campus's) -- that stays a whole-School workflow
 * (enroll, transfer, reactivation). Every refusal a Campus actor can probe
 * is the same generic answer. No Guardian is created or linked (F2-D12).
 */
final class CampusStudentOnboardingService
{
    public function __construct(
        private readonly CurrentAcademicYearResolver $years,
        private readonly StudentNumberAllocator $numbers,
        private readonly StudentService $students,
        private readonly StudentEnrollmentService $enrollments,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string, section_id: string, roll_number: string}  $input
     */
    public function onboard(School $school, CampusScope $studentScope, CampusScope $enrollmentScope, array $input, ?User $actor = null): StudentEnrollment
    {
        if ($studentScope->isNone() || $enrollmentScope->isNone()) {
            throw new StudentOnboardingUnavailableException;
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $studentScope, $enrollmentScope, $input, $actor): StudentEnrollment {
            $yearId = $this->years->shareLockActiveId($school);
            $section = Str::isUuid($input['section_id'])
                ? Section::query()->where('school_id', $school->id)->whereKey($input['section_id'])->first()
                : null;
            if ($yearId === null || $section === null || $section->academic_year_id !== $yearId
                || ! $studentScope->covers($section->campus_id) || ! $enrollmentScope->covers($section->campus_id)) {
                throw new StudentOnboardingUnavailableException;
            }

            try {
                $student = $this->students->create($school, [
                    'student_number' => $this->numbers->allocate($school),
                    'first_name' => $input['first_name'],
                    'middle_name' => $input['middle_name'] ?? null,
                    'last_name' => $input['last_name'] ?? null,
                    'date_of_birth' => $input['date_of_birth'],
                ], $actor);
                $enrollment = $this->enrollments->enroll($student, $section, $input['roll_number'], SchoolTimezone::today($school), $actor);
            } catch (PlacementTargetUnavailableException|InvalidEnrollmentDateRangeException|DuplicateStudentNumberException) {
                throw new StudentOnboardingUnavailableException;
            } catch (DuplicateEnrollmentRollNumberException $e) {
                // The roll number is taken in the actor's own, in-scope Section:
                // ordinary placement feedback, no other Campus involved.
                throw $e;
            }

            $this->audit->school($school, 'student.campus_onboarded', actor: $actor, subject: $student, metadata: [
                'studentId' => $student->id,
                'enrollmentId' => $enrollment->id,
                'campusId' => $section->campus_id,
                'sectionId' => $section->id,
                'academicYearId' => $yearId,
            ]);

            return $enrollment->setRelation('student', $student);
        }));
    }

    /**
     * The destinations a scope pair may onboard into: active Sections of the
     * active year whose active Campus is in BOTH scopes (read-only labels for
     * the form; onboard() re-decides everything under its locks).
     *
     * @return list<array{id: string, label: string}>
     */
    public function destinations(School $school, CampusScope $studentScope, CampusScope $enrollmentScope): array
    {
        if ($studentScope->isNone() || $enrollmentScope->isNone()) {
            return [];
        }
        $yearId = $this->years->tryResolve($school)?->id;
        if ($yearId === null) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $studentScope, $enrollmentScope, $yearId): array {
            $query = Section::query()->where('sections.school_id', $school->id)->where('academic_year_id', $yearId)->where('status', 'active')
                ->whereHas('campus', fn ($q) => $q->where('status', 'active'))
                ->with(['campus', 'gradeLevel']);
            $studentScope->restrict($query, 'sections.campus_id');
            $enrollmentScope->restrict($query, 'sections.campus_id');

            return $query->get()
                ->sortBy(fn (Section $s) => [$s->campus->name, $s->gradeLevel->sequence, $s->name])
                ->map(fn (Section $s) => ['id' => (string) $s->id, 'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name}"])
                ->values()->all();
        });
    }
}
