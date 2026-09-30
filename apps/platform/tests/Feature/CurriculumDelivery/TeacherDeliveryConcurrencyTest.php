<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * TCH.3 (ADR 0063 sections 16, 20): an owned teacher Curriculum Delivery
 * write racing every change that removes the teacher's authority -- the
 * TeachingAssignment ending, the membership suspended, the Employee
 * unlinked or archived, the employment ended -- between real OS processes
 * with forced, observed overlap (ForcesConcurrentOverlap).
 *
 * In each pair both orders serialize: the write first commits and the
 * change waits for it; the change first commits and the write is refused.
 * Never accepted: a write committed on authority already removed.
 */
class TeacherDeliveryConcurrencyTest extends TestCase
{
    use CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');

        foreach ($this->schoolIds as $schoolId) {
            $admin->table('teaching_assignments')->where('school_id', $schoolId)->delete();
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    private function script(string $file, string ...$args): array
    {
        return ['php', __DIR__."/../../Support/{$file}", ...$args];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->teacherWorld();
        $this->schoolIds[] = $w['school']->id;

        return $w;
    }

    private function start(array $w, User $user, int $unit, string $startedOn): array
    {
        return $this->script('teacher-delivery-op.php', 'start', $w['school']->id, $user->id, $w['section']->id, $w['offering']->id, $w['units'][$unit]->id, $startedOn);
    }

    private function deliveries(array $w): int
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => CurriculumDelivery::query()->count());
    }

    /** @return array{0: User, 1: Employee, 2: SchoolMembership} */
    private function ownedTeacher(array $w): array
    {
        [$user, $employee, $membership] = $this->teacher($w);
        $this->own($w, $employee);

        return [$user, $employee, $membership];
    }

    #[Test]
    public function a_teacher_write_racing_the_assignment_end_serializes_both_ways(): void
    {
        $w = $this->world();

        // Write first: the end waits on the held assignment row, then ends it.
        [$user, $employee] = $this->teacher($w);
        $a = $this->own($w, $employee);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->start($w, $user, 0, '2026-07-10'),
            $this->script('teaching-assignment-op.php', 'end', $w['school']->id, $w['admin']->id, $a->id, '2026-06-30', 'reassigned'),
        );
        $this->assertStringStartsWith('started:', $holder);
        $this->assertSame('ended', $contender);

        // End first: the write waits, then finds no ownership on its date.
        [$user2, $employee2] = $this->teacher($w);
        $a2 = $this->own($w, $employee2);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('teaching-assignment-op.php', 'end', $w['school']->id, $w['admin']->id, $a2->id, '2026-06-30', 'reassigned'),
            $this->start($w, $user2, 1, '2026-07-10'),
        );
        $this->assertSame(['ended', 'rejected:CURRICULUM_DELIVERY_OUTSIDE_TEACHING_ASSIGNMENT'], [$holder, $contender]);
        $this->assertSame(1, $this->deliveries($w));
    }

    #[Test]
    public function a_teacher_write_racing_membership_suspension_serializes_both_ways(): void
    {
        $w = $this->world();
        [$admin] = [$this->createUser()];
        $this->assignSchoolRole($this->createMembership($admin, $w['school']), 'school_admin');
        $this->assignSchoolRole($this->createMembership($this->createUser(), $w['school']), 'school_admin');

        [$user, , $membership] = $this->ownedTeacher($w);
        $delivery = $this->deliveryRow($w, $w['units'][0], '2026-06-01');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('teacher-delivery-op.php', 'complete', $w['school']->id, $user->id, $delivery->id, '2026-06-20'),
            $this->script('staff-account-op.php', 'suspend', $w['school']->id, $admin->id, $membership->id),
        );
        $this->assertSame(['completed', 'suspended'], [$holder, $contender]);

        [$user2, , $membership2] = $this->ownedTeacher($w);
        $delivery2 = $this->deliveryRow($w, $w['units'][1], '2026-06-01');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('staff-account-op.php', 'suspend', $w['school']->id, $admin->id, $membership2->id),
            $this->script('teacher-delivery-op.php', 'complete', $w['school']->id, $user2->id, $delivery2->id, '2026-06-20'),
        );
        $this->assertSame(['suspended', 'denied:membership_not_active'], [$holder, $contender]);
        $this->assertSame('in_progress', app(TenantContext::class)->withSchool($w['school'], fn () => $delivery2->fresh()->status));
    }

    #[Test]
    public function a_teacher_write_racing_an_unlink_an_archive_or_an_employment_end_serializes_both_ways(): void
    {
        $w = $this->world();
        $hr = $this->fullHrActor($w['school']);
        $cases = [
            'unlink' => [fn (Employee $e) => ['acting-employee-op.php', 'unlink', $w['school']->id, $hr->id, $e->id], 'unlinked', 'denied:not_linked'],
            'archive' => [fn (Employee $e) => ['acting-employee-op.php', 'archive', $w['school']->id, $hr->id, $e->id], 'archived', 'denied:employee_not_active'],
            'employment end' => [
                fn (Employee $e) => ['acting-employee-op.php', 'end-employment', $w['school']->id, $hr->id,
                    app(TenantContext::class)->withSchool($w['school'], fn () => $e->employmentRecords()->firstOrFail()->id), '2026-08-31'],
                'ended',
                'denied:no_eligible_employment',
            ],
        ];

        $unit = 0;
        foreach ($cases as $label => [$change, $changed, $denied]) {
            [$user, $employee] = $this->ownedTeacher($w);
            [$holder, $contender] = $this->raceWithHeldHolder(
                $this->start($w, $user, $unit % 3, '2026-06-01'),
                $this->script(...$change($employee)),
            );
            $this->assertStringStartsWith('started:', $holder, $label);
            $this->assertSame($changed, $contender, $label);
            $unit++;

            // The unique (Section, Unit) row is taken now: a fresh class for the reverse order.
            [$user2, $employee2] = $this->teacher($w);
            $sectionC = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'RC'.$unit, 'name' => 'RC'.$unit]);
            $this->own($w, $employee2, section: $sectionC);
            [$holder, $contender] = $this->raceWithHeldHolder(
                $this->script(...$change($employee2)),
                $this->script('teacher-delivery-op.php', 'start', $w['school']->id, $user2->id, $sectionC->id, $w['offering']->id, $w['units'][0]->id, '2026-06-01'),
            );
            $this->assertSame([$changed, $denied], [$holder, $contender], $label);
        }
    }
}
