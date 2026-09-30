<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherAttendanceFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * TCH.4 (ADR 0063 sections 11, 20): an owned teacher Attendance write --
 * a register submission or a record correction -- racing every change that
 * removes the teacher's authority: the TeachingAssignment ending, the
 * membership suspended, the Employee unlinked or archived, the employment
 * ended. Real OS processes with forced, observed overlap
 * (ForcesConcurrentOverlap).
 *
 * In each pair both orders serialize: the write first commits and the
 * change waits for it; the change first commits and the write is refused.
 * Never accepted: a register or correction committed on authority already
 * removed.
 */
class TeacherAttendanceConcurrencyTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesTeacherAttendanceFixtures, CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

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
        $w = $this->teacherAttendanceWorld();
        $this->schoolIds[] = $w['school']->id;

        return $w;
    }

    /** A register for the owned class A/X on $date, every Student present. */
    private function submit(array $w, User $user, string $date): array
    {
        return $this->script('teacher-attendance-op.php', 'submit', $w['school']->id, $user->id, $w['entry']->id, $date, ...array_map(fn ($e) => $e->id, $w['studentsA']));
    }

    private function correct(array $w, User $user, AttendanceRecord $record): array
    {
        return $this->script('teacher-attendance-op.php', 'correct', $w['school']->id, $user->id, $record->id, 'present', 'late');
    }

    /** The first record of a Tier 1 register on $date. */
    private function recordOn(array $w, string $date): AttendanceRecord
    {
        $session = $this->adminRegister($w, $w['entry'], $date);

        return app(TenantContext::class)->withSchool($w['school'], fn () => $session->records()->orderBy('id')->firstOrFail());
    }

    private function registers(array $w): int
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => AttendanceSession::query()->count());
    }

    private function recordStatus(array $w, AttendanceRecord $record): string
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => $record->fresh()->status);
    }

    /** @return array{0: User, 1: Employee, 2: SchoolMembership} */
    private function ownedTeacher(array $w): array
    {
        [$user, $employee, $membership] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');

        return [$user, $employee, $membership];
    }

    #[Test]
    public function a_teacher_write_racing_the_assignment_end_serializes_both_ways(): void
    {
        $w = $this->world();
        $end = fn ($a) => $this->script('teaching-assignment-op.php', 'end', $w['school']->id, $w['admin']->id, $a->id, '2026-06-30', 'reassigned');

        // Submit first: the end waits on the held assignment row, then ends it.
        [$user, $employee] = $this->teacher($w);
        $a = $this->own($w, $employee, '2026-06-01');
        [$holder, $contender] = $this->raceWithHeldHolder($this->submit($w, $user, '2026-08-31'), $end($a));
        $this->assertStringStartsWith('submitted:', $holder);
        $this->assertSame('ended', $contender);

        // End first: the submission waits, then finds no ownership on its date.
        [$user2, $employee2] = $this->teacher($w);
        $a2 = $this->own($w, $employee2, '2026-06-01');
        [$holder, $contender] = $this->raceWithHeldHolder($end($a2), $this->submit($w, $user2, '2026-08-24'));
        $this->assertSame(['ended', 'rejected:ATTENDANCE_OUTSIDE_TEACHING_ASSIGNMENT'], [$holder, $contender]);
        $this->assertSame(1, $this->registers($w));

        // End first, correction: the same refusal, the record unchanged.
        [$user3, $employee3] = $this->teacher($w);
        $a3 = $this->own($w, $employee3, '2026-06-01');
        $record = $this->recordOn($w, '2026-08-17');
        [$holder, $contender] = $this->raceWithHeldHolder($end($a3), $this->correct($w, $user3, $record));
        $this->assertSame(['ended', 'rejected:ATTENDANCE_OUTSIDE_TEACHING_ASSIGNMENT'], [$holder, $contender]);
        $this->assertSame('present', $this->recordStatus($w, $record));
    }

    #[Test]
    public function a_teacher_write_racing_membership_suspension_serializes_both_ways(): void
    {
        $w = $this->world();
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $w['school']), 'school_admin');
        $this->assignSchoolRole($this->createMembership($this->createUser(), $w['school']), 'school_admin');

        [$user, , $membership] = $this->ownedTeacher($w);
        $record = $this->recordOn($w, '2026-08-10');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->correct($w, $user, $record),
            $this->script('staff-account-op.php', 'suspend', $w['school']->id, $admin->id, $membership->id),
        );
        $this->assertSame(['corrected', 'suspended'], [$holder, $contender]);
        $this->assertSame('late', $this->recordStatus($w, $record));

        [$user2, , $membership2] = $this->ownedTeacher($w);
        $record2 = $this->recordOn($w, '2026-08-03');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('staff-account-op.php', 'suspend', $w['school']->id, $admin->id, $membership2->id),
            $this->correct($w, $user2, $record2),
        );
        $this->assertSame(['suspended', 'denied:membership_not_active'], [$holder, $contender]);
        $this->assertSame('present', $this->recordStatus($w, $record2));

        [$user3, , $membership3] = $this->ownedTeacher($w);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('staff-account-op.php', 'suspend', $w['school']->id, $admin->id, $membership3->id),
            $this->submit($w, $user3, '2026-07-27'),
        );
        $this->assertSame(['suspended', 'denied:membership_not_active'], [$holder, $contender]);
        $this->assertSame(2, $this->registers($w));
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

        // One register per (class, date): each write-first race its own Monday.
        $dates = ['2026-06-08', '2026-06-15', '2026-06-22'];
        $registers = 0;
        foreach ($cases as $label => [$change, $changed, $denied]) {
            [$user, $employee] = $this->ownedTeacher($w);
            [$holder, $contender] = $this->raceWithHeldHolder(
                $this->submit($w, $user, $dates[$registers]),
                $this->script(...$change($employee)),
            );
            $this->assertStringStartsWith('submitted:', $holder, $label);
            $this->assertSame($changed, $contender, $label);
            $registers++;

            [$user2, $employee2] = $this->ownedTeacher($w);
            [$holder, $contender] = $this->raceWithHeldHolder(
                $this->script(...$change($employee2)),
                $this->submit($w, $user2, '2026-07-06'),
            );
            $this->assertSame([$changed, $denied], [$holder, $contender], $label);
            $this->assertSame($registers, $this->registers($w), $label);
        }
    }
}
