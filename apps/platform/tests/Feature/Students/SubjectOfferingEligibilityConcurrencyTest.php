<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * RES.1 (ADR 0068 §5.4): the lock-capable P3 read protects the rows its
 * answer relied on until the caller's transaction ends -- proven with two
 * genuinely separate OS processes against real PostgreSQL. The holder takes
 * lockEligibilityAsOf()'s FOR SHARE locks and keeps them uncommitted; the
 * contender (a real Students lifecycle write on one of those rows) is
 * positively observed blocked on a lock, and completes only after release.
 * Committed fixtures (no DatabaseTransactions): the processes are separate
 * PostgreSQL sessions.
 */
class SubjectOfferingEligibilityConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var array<int, School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school);
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/subject-offering-eligibility-op.php', ...$args];
    }

    /** @return array<string, mixed> a Student placed in 5 A1 from 2026-06-01, taking an elective from the same date */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'Y26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $grade = $this->createGradeLevel($school, ['code' => 'G5', 'name' => 'Grade 5', 'sequence' => 5]);
        $w = ['school' => $school];
        $w['a1'] = $this->createSection($year, $campus, $grade, ['code' => 'A1', 'name' => '5 A1']);
        $w['a2'] = $this->createSection($year, $campus, $grade, ['code' => 'A2', 'name' => '5 A2']);
        $w['required'] = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $w['elective'] = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false, 'status' => 'active']);
        $w['student'] = $this->createStudent($school);
        $w['placement'] = app(StudentEnrollmentService::class)->enroll($w['student'], $w['a1'], '1', '2026-06-01');
        $w['row'] = app(StudentSubjectEnrollmentService::class)->enroll($w['student'], $w['elective'], '2026-06-01');

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function inSchool(array $w, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($w['school'], $callback);
    }

    #[Test]
    public function an_elective_withdrawal_waits_for_the_held_eligibility_lock(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock-eligibility', $w['school']->id, $w['student']->id, $w['elective']->id, '2026-07-01'),
            $this->op('withdraw-elective', $w['school']->id, $w['row']->id, '2026-06-30'),
        );

        $this->assertSame("eligible:elective:{$w['placement']->id}", $holder, 'the held read saw the elective row before the withdrawal');
        $this->assertSame('withdrawn', $contender, 'the withdrawal waited on the FOR SHARE lock, then committed');
        $this->assertSame(['withdrawn', '2026-06-30'], $this->inSchool($w, fn () => [
            ($r = StudentSubjectEnrollment::query()->findOrFail($w['row']->id))->status, $r->ends_on->toDateString(),
        ]));
        // After the commit the same question now has the new answer.
        $this->assertFalse(app(SubjectOfferingEligibilityReadService::class)->eligibilityAsOf($w['school'], $w['student']->id, $w['elective']->id, '2026-07-01')->eligible);
    }

    #[Test]
    public function a_placement_transfer_waits_for_the_held_eligibility_lock(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock-eligibility', $w['school']->id, $w['student']->id, $w['required']->id, '2026-09-15'),
            $this->op('transfer-placement', $w['school']->id, $w['placement']->id, $w['a2']->id, '2', '2026-09-01'),
        );

        $this->assertSame("eligible:required:{$w['placement']->id}", $holder);
        $this->assertSame('transferred', $contender, 'the transfer waited on the placement row, then committed');
        $this->assertSame(2, $this->inSchool($w, fn () => StudentEnrollment::query()->where('student_id', $w['student']->id)->count()));
        $after = app(SubjectOfferingEligibilityReadService::class)->eligibilityAsOf($w['school'], $w['student']->id, $w['required']->id, '2026-09-15');
        $this->assertSame($w['a2']->id, $after->sectionId, 'the backdated transfer, once committed, answers for its own dates');
    }
}
