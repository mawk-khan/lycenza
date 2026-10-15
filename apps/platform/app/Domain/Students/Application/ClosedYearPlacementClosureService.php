<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * V11.3 (ADR 0075 section 11): staff-initiated completion of placements
 * left `active` in a CLOSED AcademicYear -- the rollover / year-close
 * leftover Data Health reports as `enrollment.active_in_closed_year`.
 *
 * It adds NO lifecycle semantics. It only orchestrates the existing
 * StudentEnrollmentService::complete() ("the academic year's teaching
 * period genuinely ended for this Student in this placement"), one row at
 * a time, with the year's own end date:
 *
 * - Eligible = same School, `active`, its year `closed`. Never a current-
 *   year or next-year placement, never a terminal one.
 * - Each row is its own complete() call: its own transaction, Section-
 *   then-Enrollment locks, compare-and-set status update and audit event
 *   (`student_enrollment.completed`). No outer transaction: one row's
 *   refusal never rolls back another's correct completion.
 * - Safe to retry: a row already ended is no longer `active`, so it is
 *   reported as "no longer open", never changed twice.
 * - At most MAX_BATCH rows per request; every requested id is re-checked
 *   against the School and the year at execution time, so ids from another
 *   School or year (or tampered ids) are reported as not eligible.
 * - Truthful results: every requested row gets exactly one outcome.
 *
 * Never automatic: nothing calls this on year close or activation (that
 * remains the open question in docs/modules/STUDENT-ENROLLMENT.md).
 */
class ClosedYearPlacementClosureService
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'enrollments.manage';

    public const int MAX_BATCH = 100;

    public const string COMPLETED = 'completed';

    public const string NO_LONGER_OPEN = 'no_longer_open';

    public const string NOT_ELIGIBLE = 'not_eligible';

    public const string DATE_CONFLICT = 'date_conflict';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentEnrollmentService $enrollments,
    ) {}

    /**
     * Closed years of this School that still hold an open placement.
     *
     * @return Collection<int, AcademicYear>
     */
    public function yearsWithOpenPlacements(School $school, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, fn () => AcademicYear::query()
            ->where('school_id', $school->id)
            ->where('status', 'closed')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('student_enrollments')
                ->whereColumn('student_enrollments.academic_year_id', 'academic_years.id')
                ->where('student_enrollments.school_id', $school->id)
                ->where('student_enrollments.status', 'active'))
            ->orderByDesc('starts_on')
            ->get());
    }

    /**
     * The preview: up to MAX_BATCH eligible placements of one closed year,
     * plus how many are eligible in total (a row total of the list being
     * prepared, ADR 0042 section 11).
     *
     * @return array{year: AcademicYear, placements: Collection<int, StudentEnrollment>, total: int}|null
     *                                                                                                    null when the year is not one of this School's closed years
     */
    public function preview(School $school, string $academicYearId, User $actor): ?array
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $academicYearId) {
            $year = $this->closedYear($school, $academicYearId);
            if ($year === null) {
                return null;
            }

            $query = $this->eligible($school, $year);

            return [
                'year' => $year,
                'placements' => (clone $query)
                    ->with(['student:id,school_id,student_number,first_name,middle_name,last_name', 'gradeLevel', 'section'])
                    ->orderBy('section_id')->orderBy('roll_number')->orderBy('id')
                    ->limit(self::MAX_BATCH)
                    ->get(),
                'total' => $query->count(),
            ];
        });
    }

    /**
     * Complete the given placements of one closed year, each through
     * StudentEnrollmentService::complete() with the year's end date.
     *
     * @param  list<string>  $enrollmentIds  at most MAX_BATCH, as previewed
     * @return array<string, string>|null enrollment id => outcome; null when the year is not one of this School's closed years
     */
    public function completeSelected(School $school, string $academicYearId, array $enrollmentIds, User $actor): ?array
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        if (count($enrollmentIds) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('At most '.self::MAX_BATCH.' placements per request.');
        }

        return $this->context->withSchool($school, function () use ($school, $academicYearId, $enrollmentIds, $actor) {
            $year = $this->closedYear($school, $academicYearId);
            if ($year === null) {
                return null;
            }

            $eligible = $this->eligible($school, $year)->whereIn('id', $enrollmentIds)->get()->keyBy('id');
            $endsOn = $year->ends_on->toDateString();
            $outcomes = [];

            foreach (array_values(array_unique($enrollmentIds)) as $id) {
                $placement = $eligible->get($id);
                if ($placement === null) {
                    $outcomes[$id] = self::NOT_ELIGIBLE;

                    continue;
                }

                try {
                    $this->enrollments->complete($placement, $endsOn, $actor);
                    $outcomes[$id] = self::COMPLETED;
                } catch (InvalidEnrollmentTransitionException) {
                    // Ended by someone else since it was read: never changed twice.
                    $outcomes[$id] = self::NO_LONGER_OPEN;
                } catch (InvalidEnrollmentDateRangeException) {
                    // Starts after its own year's end: needs a person's judgment.
                    $outcomes[$id] = self::DATE_CONFLICT;
                }
            }

            return $outcomes;
        });
    }

    private function closedYear(School $school, string $academicYearId): ?AcademicYear
    {
        return AcademicYear::query()->where('school_id', $school->id)->where('status', 'closed')->find($academicYearId);
    }

    /** @return Builder<StudentEnrollment> */
    private function eligible(School $school, AcademicYear $year)
    {
        return StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->where('status', 'active');
    }
}
