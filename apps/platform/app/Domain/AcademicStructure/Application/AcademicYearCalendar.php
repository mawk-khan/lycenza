<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * E21.2C (E21-D3): which Academic Year a School-local date falls in, for
 * retention triggers keyed on "the academic year in which it happened".
 *
 * A date resolves only when EXACTLY ONE of the School's years contains it,
 * both ends inclusive. Academic Years may leave gaps between them, and
 * their non-overlap is an application rule only (AcademicYearService). A
 * shared boundary day therefore sits in two years. A date in no year, or
 * in several, answers null, and the caller fails closed: it keeps the
 * record. The answer never depends on which year is active today.
 *
 * Read-only. Resolved inside the given School's own context.
 */
final class AcademicYearCalendar
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, array{starts_on: string, ends_on: string}> the School's years, oldest first
     */
    public function years(School $school): array
    {
        return $this->context->withSchool($school, fn () => AcademicYear::query()
            ->where('school_id', $school->id)
            ->orderBy('starts_on')
            ->get(['starts_on', 'ends_on'])
            ->map(fn (AcademicYear $y) => ['starts_on' => $y->starts_on->toDateString(), 'ends_on' => $y->ends_on->toDateString()])
            ->all());
    }

    /**
     * @param  array<int, array{starts_on: string, ends_on: string}>  $years  from years()
     * @return string|null the end date (Y-m-d) of the one year containing $date, or null (none or ambiguous)
     */
    public static function endOfYearContaining(array $years, string $date): ?string
    {
        $matches = array_values(array_filter($years, fn (array $y) => $y['starts_on'] <= $date && $date <= $y['ends_on']));

        return count($matches) === 1 ? $matches[0]['ends_on'] : null;
    }
}
