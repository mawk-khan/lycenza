<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.7F-1 (ADR 0076 §45): the Campus-aware Enrollments directory.
 *
 * - Whole School: every Enrollment of the School, any year and status
 *   (unchanged).
 * - Campus set: only CURRENT placements (the active Enrollment in the
 *   School's active year) on in-scope Campuses of Students whose current
 *   operational Campus is in scope -- applied in SQL by StudentAccess before
 *   the page is read. History, draft/future years and other Campuses are
 *   never listed; filters narrow only. The filter options are cut to the
 *   same reach (the active year, in-scope Campuses and their Sections).
 */
class EnrollmentDirectoryController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, StudentAccess $access, CurrentAcademicYearResolver $years): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('enrollments.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'campus_id' => ['sometimes', 'uuid'],
            'grade_level_id' => ['sometimes', 'uuid'],
            'section_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'withdrawn', 'transferred', 'cancelled'])],
            'student_number' => ['sometimes', 'string', 'max:255'],
            'student_name' => ['sometimes', 'string', 'max:255'],
            'roll_number' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = collect($validated)->except('page')->all();
        /** @var LengthAwarePaginator<int, StudentEnrollment> $paginator */
        $paginator = $access->enrollmentDirectory($school, $scope, $filters)->paginate(20)->withQueryString();
        $campusView = ! $scope->isWholeSchool();
        $studentScope = $access->scopeOf($context->actor(), 'students.view', $school);

        return Inertia::render('App/Enrollments/Index', [
            'enrollments' => $paginator->through(fn (StudentEnrollment $e) => $this->presentSummary($e)),
            'campusView' => $campusView,
            // Where a row's Student link may lead (the destination re-checks).
            'studentLink' => $studentScope->isWholeSchool() ? 'profile' : ($studentScope->isNone() ? 'none' : 'overview'),
            'filters' => [
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'campus_id' => $validated['campus_id'] ?? '',
                'grade_level_id' => $validated['grade_level_id'] ?? '',
                'section_id' => $validated['section_id'] ?? '',
                'status' => $validated['status'] ?? '',
                'student_number' => $validated['student_number'] ?? '',
                'student_name' => $validated['student_name'] ?? '',
                'roll_number' => $validated['roll_number'] ?? '',
            ],
            'academicYears' => $this->academicYearOptions($school, $scope, $years),
            'campuses' => $this->campusOptions($school, $scope),
            'gradeLevels' => $this->gradeLevelOptions(),
            'sections' => $this->sectionOptions($school, $scope, $years),
        ]);
    }

    /**
     * Whole School: every year. Campus set: the active year only (the only
     * year a Campus actor's directory holds).
     *
     * @return array<int, array<string, string>>
     */
    private function academicYearOptions(School $school, CampusScope $scope, CurrentAcademicYearResolver $years): array
    {
        if (! $scope->isWholeSchool()) {
            $active = $years->tryResolve($school);

            return $active === null ? [] : [['id' => $active->id, 'name' => $active->name]];
        }

        return AcademicYear::query()->orderByDesc('starts_on')->get()
            ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function campusOptions(School $school, CampusScope $scope): array
    {
        return $scope->restrict(Campus::query()->where('school_id', $school->id), 'campuses.id')->orderBy('name')->get()
            ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function gradeLevelOptions(): array
    {
        return GradeLevel::query()->orderBy('sequence')->get()
            ->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])
            ->all();
    }

    /**
     * Section filter options, labelled unambiguously across years and
     * Campuses. Campus set: only the active year's Sections on in-scope
     * Campuses.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionOptions(School $school, CampusScope $scope, CurrentAcademicYearResolver $years): array
    {
        $query = Section::query()->where('sections.school_id', $school->id)->with(['academicYear', 'campus', 'gradeLevel']);
        if (! $scope->isWholeSchool()) {
            $query->where('academic_year_id', $years->tryResolve($school)?->id);
            $scope->restrict($query, 'sections.campus_id');
        }

        return $query->get()
            ->sortByDesc(fn (Section $s) => $s->academicYear->starts_on)
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name} · {$s->academicYear->name}",
                'academicYearId' => $s->academic_year_id,
                'campusId' => $s->campus_id,
                'gradeLevelId' => $s->grade_level_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Deliberately excludes dateOfBirth/Guardian PII (the Phase 1B.6
     * presenter, unchanged).
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
            'academicYear' => ['id' => $enrollment->academicYear->id, 'name' => $enrollment->academicYear->name],
            'campus' => ['id' => $enrollment->campus->id, 'name' => $enrollment->campus->name],
            'gradeLevel' => ['id' => $enrollment->gradeLevel->id, 'name' => $enrollment->gradeLevel->name],
            'section' => ['id' => $enrollment->section->id, 'name' => $enrollment->section->name],
            'rollNumber' => $enrollment->roll_number,
            'status' => $enrollment->status,
            'startsOn' => $enrollment->starts_on->toDateString(),
            'endsOn' => $enrollment->ends_on?->toDateString(),
        ];
    }
}
