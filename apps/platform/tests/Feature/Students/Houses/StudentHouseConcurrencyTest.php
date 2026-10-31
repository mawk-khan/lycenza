<?php

namespace Tests\Feature\Students\Houses;

use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 11.7: REQUIRED real-concurrency proof -- two genuinely separate OS
 * processes against real PostgreSQL. The holder commits only after the
 * contender is OBSERVED blocked on a row lock (pg_stat_activity), never
 * timed with sleeps.
 * - One Student, two Houses from the same form: the contender waits on the
 *   Student lock, then is refused as stale; exactly one current membership.
 * - Two Students, one House Captain: the contender waits on the House lock,
 *   then is refused; exactly one current Captain.
 * - Deactivation racing an assignment: the deactivation waits for the
 *   assignment, then sees its member and refuses.
 * Committed fixtures (no DatabaseTransactions); removed in tearDown.
 */
class StudentHouseConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $admin;

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @return array{0: list<Student>, 1: StudentGroup, 2: StudentGroup} */
    private function fixtures(int $students): array
    {
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $list = [];
        for ($i = 1; $i <= $students; $i++) {
            $list[] = $this->createStudent($this->school, ['first_name' => "Race{$i}", 'last_name' => 'Rao', 'student_number' => "R-{$i}"]);
        }
        $houses = app(HouseAdministrationService::class);

        return [$list, $houses->create($this->school, $this->admin, ['name' => 'Red', 'code' => 'RED']), $houses->create($this->school, $this->admin, ['name' => 'Blue', 'code' => 'BLUE'])];
    }

    private function version(Student $student): string
    {
        return app(StudentHouseService::class)->history($this->school, $this->admin, $student->id)['version'];
    }

    /** @return list<string> */
    private function assign(Student $student, StudentGroup $house, string $version): array
    {
        return ['php', __DIR__.'/../../../Support/house-op.php', 'assign', $this->school->id, $student->id, $this->admin->id, $house->id, '-', $version];
    }

    /** @return list<string> */
    private function captain(Student $student): array
    {
        return ['php', __DIR__.'/../../../Support/house-op.php', 'responsibility', $this->school->id, $student->id, $this->admin->id, 'house_captain', $this->version($student)];
    }

    #[Test]
    public function two_concurrent_assignments_of_one_student_leave_exactly_one_current_house(): void
    {
        [[$student], $red, $blue] = $this->fixtures(1);
        $opened = $this->version($student);
        [$holder, $contender] = $this->raceWithHeldHolder($this->assign($student, $red, $opened), $this->assign($student, $blue, $opened));

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:STUDENT_HOUSE_CHANGED', $contender, 'the contender waited on the Student lock, then saw the new membership');
        $current = $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $student->id)->whereNull('ends_on')->get());
        $this->assertCount(1, $current);
        $this->assertSame($red->id, $current->first()->student_group_id);
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupMembership::query()->count()));
    }

    #[Test]
    public function two_concurrent_captain_assignments_in_one_house_leave_exactly_one_captain(): void
    {
        [[$a, $b], $red] = $this->fixtures(2);
        foreach ([$a, $b] as $s) {
            app(StudentHouseService::class)->assign($this->school, $this->admin, $s->id, $red->id, null, $this->version($s));
        }
        [$holder, $contender] = $this->raceWithHeldHolder($this->captain($a), $this->captain($b));

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:RESPONSIBILITY_TAKEN', $contender, 'the contender waited on the House lock, then saw the Captain');
        $captains = $this->inSchool(fn () => StudentGroupResponsibility::query()->where('responsibility', 'house_captain')->whereNull('ends_on')->pluck('student_id')->all());
        $this->assertSame([$a->id], $captains);
    }

    #[Test]
    public function a_deactivation_racing_an_assignment_sees_the_new_member_and_refuses(): void
    {
        [[$student], $red] = $this->fixtures(1);
        $deactivate = ['php', __DIR__.'/../../../Support/house-op.php', 'deactivate', $this->school->id, $red->id, $this->admin->id];
        [$holder, $contender] = $this->raceWithHeldHolder($this->assign($student, $red, $this->version($student)), $deactivate);

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:HOUSE_HAS_MEMBERS', $contender);
        $this->assertSame('active', $this->inSchool(fn () => StudentGroup::query()->findOrFail($red->id))->status, 'a House never loses a member it is about to have');
    }
}
