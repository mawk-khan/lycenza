<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarDatesFixedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearDependentsOutsideException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 5.1 (MC-3): a draft-year date correction against an activation, and
 * against a placement, in two real processes with a forced, observed overlap.
 * The correction holds the year FOR UPDATE; activation re-reads it FOR UPDATE
 * and a placement reads it FOR SHARE, so they serialize: whichever commits
 * first decides, and no active year is ever re-dated and no placement is left
 * outside the corrected dates.
 */
class DraftCalendarConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @return array{school: School, year: AcademicYear, section: Section, student: Student, admin: User, today: CarbonImmutable} */
    private function world(): array
    {
        $school = $this->createSchool();
        $today = CarbonImmutable::parse(SchoolTimezone::today($school));
        $year = $this->createAcademicYear($school, ['code' => 'AY-RACE', 'status' => 'draft', 'starts_on' => $today->subDays(30)->toDateString(), 'ends_on' => $today->addMonths(10)->toDateString()]);
        $section = $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school), ['status' => 'active']);

        return [
            'school' => $school, 'year' => $year, 'section' => $section, 'today' => $today,
            'student' => $this->createStudent($school),
            'admin' => $this->createUserWithCapabilities($school, ['academics.years.view', 'academics.years.manage']),
        ];
    }

    /** @return array<int, string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/draft-calendar-op.php', ...$args];
    }

    /** @return array{0: string, 1: string, 2: string, 3: int} [status, starts_on, ends_on, placements] */
    private function outcome(array $w): array
    {
        return app(TenantContext::class)->withSchool($w['school'], function () use ($w): array {
            $year = AcademicYear::query()->findOrFail($w['year']->id);

            return [$year->status, $year->starts_on->toDateString(), $year->ends_on->toDateString(), StudentEnrollment::query()->where('academic_year_id', $year->id)->count()];
        });
    }

    #[Test]
    public function an_activation_that_commits_first_refuses_the_waiting_correction(): void
    {
        $w = $this->world();
        $original = [$w['year']->starts_on->toDateString(), $w['year']->ends_on->toDateString()];

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('activate', $w['school']->id, $w['year']->id),
            $this->op('correct', $w['school']->id, $w['year']->id, $w['today']->subDays(10)->toDateString(), $original[1], $w['admin']->id),
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:'.AcademicCalendarDatesFixedException::class, $contender);
        $this->assertSame(['active', ...$original, 0], $this->outcome($w));
    }

    #[Test]
    public function a_correction_that_commits_first_is_what_the_waiting_activation_activates(): void
    {
        $w = $this->world();
        $corrected = $w['today']->subDays(10)->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('correct', $w['school']->id, $w['year']->id, $corrected, $w['year']->ends_on->toDateString(), $w['admin']->id),
            $this->op('activate', $w['school']->id, $w['year']->id),
        );

        $this->assertSame('corrected', $holder);
        $this->assertSame('activated', $contender);
        $this->assertSame(['active', $corrected, $w['year']->ends_on->toDateString(), 0], $this->outcome($w));
    }

    #[Test]
    public function a_correction_that_commits_first_refuses_a_waiting_placement_now_outside(): void
    {
        $w = $this->world();
        $start = CarbonImmutable::parse($w['year']->starts_on->toDateString());

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('correct', $w['school']->id, $w['year']->id, $start->addDays(5)->toDateString(), $w['year']->ends_on->toDateString(), $w['admin']->id),
            $this->op('enroll', $w['school']->id, $w['student']->id, $w['section']->id, $start->addDay()->toDateString()),
        );

        $this->assertSame('corrected', $holder);
        $this->assertSame('rejected:'.InvalidEnrollmentDateRangeException::class, $contender);
        $this->assertSame(['draft', $start->addDays(5)->toDateString(), $w['year']->ends_on->toDateString(), 0], $this->outcome($w));
    }

    #[Test]
    public function a_placement_that_commits_first_refuses_the_waiting_correction(): void
    {
        $w = $this->world();
        $start = CarbonImmutable::parse($w['year']->starts_on->toDateString());

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('enroll', $w['school']->id, $w['student']->id, $w['section']->id, $start->addDay()->toDateString()),
            $this->op('correct', $w['school']->id, $w['year']->id, $start->addDays(5)->toDateString(), $w['year']->ends_on->toDateString(), $w['admin']->id),
        );

        $this->assertSame('enrolled', $holder);
        $this->assertSame('rejected:'.AcademicYearDependentsOutsideException::class, $contender);
        $this->assertSame(['draft', $start->toDateString(), $w['year']->ends_on->toDateString(), 1], $this->outcome($w));
    }
}
