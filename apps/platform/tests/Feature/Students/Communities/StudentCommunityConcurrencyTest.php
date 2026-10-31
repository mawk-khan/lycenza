<?php

namespace Tests\Feature\Students\Communities;

use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\StudentCommunityService;
use App\Domain\Students\Application\Groups\StudentGroupType;
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
 * SIS 11.8: REQUIRED real-concurrency proof for Clubs and Activities -- two
 * genuinely separate OS processes against real PostgreSQL; the holder
 * commits only after the contender is OBSERVED blocked on a row lock.
 * - Same Student, same Club (and same Activity): exactly one membership.
 * - Same Student, two different Clubs: BOTH memberships (never
 *   over-constrained).
 * - Two Students, one Club President: exactly one President.
 * - Deactivation racing a join: refused, never an inactive Club with a new
 *   member.
 * The House uniqueness race stays in StudentHouseConcurrencyTest.
 * Committed fixtures (no DatabaseTransactions); removed in tearDown.
 */
class StudentCommunityConcurrencyTest extends TestCase
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

    /** @return list<Student> */
    private function students(int $n): array
    {
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $list = [];
        for ($i = 1; $i <= $n; $i++) {
            $list[] = $this->createStudent($this->school, ['first_name' => "Race{$i}", 'last_name' => 'Rao', 'student_number' => "R-{$i}"]);
        }

        return $list;
    }

    private function group(StudentGroupType $type, string $code): StudentGroup
    {
        return app(CommunityAdministrationService::class)->create($this->school, $this->admin, $type, ['name' => $code, 'code' => $code]);
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/house-op.php', ...$args];
    }

    /** @return list<string> */
    private function join(Student $student, StudentGroup $group): array
    {
        return $this->op('join', $this->school->id, $student->id, $this->admin->id, $group->id);
    }

    #[Test]
    public function the_same_student_joining_the_same_club_twice_at_once_gets_one_membership(): void
    {
        [$student] = $this->students(1);
        $chess = $this->group(StudentGroupType::CLUB, 'CHESS');
        [$holder, $contender] = $this->raceWithHeldHolder($this->join($student, $chess), $this->join($student, $chess));

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:ALREADY_MEMBER', $contender, 'the contender waited on the Student lock, then saw the membership');
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupMembership::query()->where('student_group_id', $chess->id)->count()));
    }

    #[Test]
    public function the_same_student_joining_the_same_activity_twice_at_once_gets_one_participation(): void
    {
        [$student] = $this->students(1);
        $football = $this->group(StudentGroupType::ACTIVITY, 'FOOT');
        [$holder, $contender] = $this->raceWithHeldHolder($this->join($student, $football), $this->join($student, $football));

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:ALREADY_MEMBER', $contender);
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupMembership::query()->where('student_group_id', $football->id)->count()));
    }

    #[Test]
    public function the_same_student_joining_two_different_clubs_at_once_gets_both(): void
    {
        [$student] = $this->students(1);
        $chess = $this->group(StudentGroupType::CLUB, 'CHESS');
        $debate = $this->group(StudentGroupType::CLUB, 'DEBATE');
        [$holder, $contender] = $this->raceWithHeldHolder($this->join($student, $chess), $this->join($student, $debate));

        $this->assertStringStartsWith('done:', $holder);
        $this->assertStringStartsWith('done:', $contender, 'the contender waited on the Student lock, then joined its own Club');
        $this->assertEqualsCanonicalizing([$chess->id, $debate->id], $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $student->id)->whereNull('ends_on')->pluck('student_group_id')->all()));
    }

    #[Test]
    public function two_concurrent_presidents_of_one_club_leave_exactly_one(): void
    {
        [$a, $b] = $this->students(2);
        $chess = $this->group(StudentGroupType::CLUB, 'CHESS');
        $service = app(StudentCommunityService::class);
        $ma = $service->join($this->school, $this->admin, $a->id, $chess->id);
        $mb = $service->join($this->school, $this->admin, $b->id, $chess->id);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('club-role', $this->school->id, $a->id, $this->admin->id, $ma->id, 'club_president'),
            $this->op('club-role', $this->school->id, $b->id, $this->admin->id, $mb->id, 'club_president'),
        );

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:RESPONSIBILITY_TAKEN', $contender, 'the contender waited on the Club lock, then saw the President');
        $this->assertSame([$a->id], $this->inSchool(fn () => StudentGroupResponsibility::query()->where('responsibility', 'club_president')->whereNull('ends_on')->pluck('student_id')->all()));
    }

    #[Test]
    public function a_deactivation_racing_a_join_sees_the_new_member_and_refuses(): void
    {
        [$student] = $this->students(1);
        $chess = $this->group(StudentGroupType::CLUB, 'CHESS');
        [$holder, $contender] = $this->raceWithHeldHolder($this->join($student, $chess), $this->op('deactivate-community', $this->school->id, $chess->id, $this->admin->id, 'club'));

        $this->assertStringStartsWith('done:', $holder);
        $this->assertSame('refused:COMMUNITY_HAS_MEMBERS', $contender);
        $this->assertSame('active', $this->inSchool(fn () => StudentGroup::query()->findOrFail($chess->id))->status, 'never an inactive Club with a new member');
    }
}
