<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * TCH.2: the administrative read side of TeachingAssignments, under
 * `teaching.assignments.view`. Fresh reads, tenant-scoped (SchoolScope +
 * RLS); an id of another School is the same 404 as an unknown one.
 *
 * The projection is directory-tier only: Employee number and name, the
 * Section and Subject labels, the dates and the end facts. No HR profile,
 * contact or account field, and no user ids.
 *
 * `state` is derived, never stored -- upcoming / current / past relative to
 * today in the School's timezone; the stored dates stay authoritative.
 */
class TeachingAssignmentReadService
{
    use AuthorizesCapability;

    public const int MAX_PER_PAGE = 100;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{academic_year_id?: string, employee_id?: string, section_id?: string, subject_offering_id?: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(School $school, array $filters, int $page, int $perPage, User $actor): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_VIEW, $school);
        $today = $this->today($school);

        return $this->context->withSchool($school, fn () => TeachingAssignment::query()
            ->where('school_id', $school->id)
            ->with(['employee', 'section', 'subjectOffering.subject'])
            ->when(isset($filters['academic_year_id']), fn ($q) => $q->where('academic_year_id', $filters['academic_year_id']))
            ->when(isset($filters['employee_id']), fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->when(isset($filters['section_id']), fn ($q) => $q->where('section_id', $filters['section_id']))
            ->when(isset($filters['subject_offering_id']), fn ($q) => $q->where('subject_offering_id', $filters['subject_offering_id']))
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'page', max($page, 1))
            ->through(fn (TeachingAssignment $a) => self::present($a, $today)));
    }

    /**
     * TCH-E (ADR 0063 section 45): an AcademicYear's elective teaching assignments, the same projection without a
     * Section (an elective is Offering-wide). Under `teaching.assignments.view`.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listElective(School $school, string $academicYearId, int $page, int $perPage, User $actor): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_VIEW, $school);
        $today = $this->today($school);

        return $this->context->withSchool($school, fn () => ElectiveTeachingAssignment::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->with(['employee', 'subjectOffering.subject'])
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'page', max($page, 1))
            ->through(fn (ElectiveTeachingAssignment $a) => self::presentElective($a, $today)));
    }

    /** @return array<string, mixed> */
    public static function presentElective(ElectiveTeachingAssignment $a, string $today): array
    {
        $startsOn = $a->starts_on->toDateString();
        $endsOn = $a->ends_on?->toDateString();

        return [
            'id' => $a->id,
            'employee' => ['id' => $a->employee_id, 'employeeNumber' => $a->employee?->employee_number, 'fullName' => $a->employee?->full_name],
            'subjectOffering' => ['id' => $a->subject_offering_id, 'subjectName' => $a->subjectOffering?->subject?->name, 'subjectCode' => $a->subjectOffering?->subject?->code],
            'academicYearId' => $a->academic_year_id,
            'startsOn' => $startsOn,
            'endsOn' => $endsOn,
            'state' => match (true) {
                $endsOn !== null && $endsOn < $startsOn => 'past', // S7: voided by an employment end, never owned a day
                $today < $startsOn => 'upcoming',
                $endsOn !== null && $today > $endsOn => 'past',
                default => 'current',
            },
            'endedAt' => $a->ended_at?->toIso8601String(),
            'endReason' => $a->end_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function find(School $school, string $assignmentId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_VIEW, $school);

        return $this->context->withSchool($school, fn () => self::present(
            TeachingAssignment::query()->where('school_id', $school->id)
                ->with(['employee', 'section', 'subjectOffering.subject'])
                ->findOrFail($assignmentId),
            $this->today($school),
        ));
    }

    /** @return array<string, mixed> */
    public static function present(TeachingAssignment $a, string $today): array
    {
        $startsOn = $a->starts_on->toDateString();
        $endsOn = $a->ends_on?->toDateString();

        return [
            'id' => $a->id,
            'employee' => [
                'id' => $a->employee_id,
                'employeeNumber' => $a->employee?->employee_number,
                'fullName' => $a->employee?->full_name,
            ],
            'section' => [
                'id' => $a->section_id,
                'name' => $a->section?->name,
                'code' => $a->section?->code,
            ],
            'subjectOffering' => [
                'id' => $a->subject_offering_id,
                'subjectName' => $a->subjectOffering?->subject?->name,
                'subjectCode' => $a->subjectOffering?->subject?->code,
            ],
            'academicYearId' => $a->academic_year_id,
            'startsOn' => $startsOn,
            'endsOn' => $endsOn,
            'state' => match (true) {
                $endsOn !== null && $endsOn < $startsOn => 'past', // S7: voided by an employment end, never owned a day
                $today < $startsOn => 'upcoming',
                $endsOn !== null && $today > $endsOn => 'past',
                default => 'current',
            },
            'endedAt' => $a->ended_at?->toIso8601String(),
            'endReason' => $a->end_reason,
        ];
    }

    public function today(School $school): string
    {
        return CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
    }
}
