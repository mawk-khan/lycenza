<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\PlacementTargetUnavailableException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 4.1 (MC-2): a placement and a close of its Section's Academic Year, in
 * two real processes with a forced, observed overlap. The placement holds the
 * year FOR SHARE until it commits, so the two serialize: a close that commits
 * first is seen and the placement refused; a placement that commits first is
 * one created while the year was open, and the close then proceeds. No
 * placement ever commits into a year that was already closed.
 */
class PlacementYearConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @return array{school: School, year: AcademicYear, section: Section, student: Student} */
    private function world(): array
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['code' => 'AY-RACE', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $section = $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school), ['status' => 'active']);
        $student = $this->createStudent($school);

        return compact('school', 'year', 'section', 'student');
    }

    /** @return array<int, string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/placement-year-op.php', ...$args];
    }

    /** @return array{0: string, 1: int} [year status, placements in it] */
    private function outcome(array $w): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => [
            AcademicYear::query()->findOrFail($w['year']->id)->status,
            StudentEnrollment::query()->where('academic_year_id', $w['year']->id)->count(),
        ]);
    }

    #[Test]
    public function a_close_that_commits_first_refuses_the_waiting_placement(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('close', $w['school']->id, $w['year']->id),
            $this->op('enroll', $w['school']->id, $w['student']->id, $w['section']->id, '2026-06-01'),
        );

        $this->assertSame('closed', $holder);
        $this->assertSame('rejected:'.PlacementTargetUnavailableException::class, $contender);
        $this->assertSame(['closed', 0], $this->outcome($w));
    }

    #[Test]
    public function a_placement_that_commits_first_makes_the_close_wait(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('enroll', $w['school']->id, $w['student']->id, $w['section']->id, '2026-06-01'),
            $this->op('close', $w['school']->id, $w['year']->id),
        );

        $this->assertSame('enrolled', $holder);
        $this->assertSame('closed', $contender);
        $this->assertSame(['closed', 1], $this->outcome($w));
    }
}
