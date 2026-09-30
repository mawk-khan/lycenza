<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 sections 10, 20): TeachingAssignment races between real
 * OS processes against real PostgreSQL, overlap forced and verified
 * (ForcesConcurrentOverlap: the holder's write is uncommitted and the
 * contender is observed blocked on a lock -- the assignment key's advisory
 * lock or an HR row lock -- before the holder commits).
 *
 * Never accepted: two overlapping periods for one key, two ends of one
 * assignment, or an assignment created for an Employee whose archive or
 * employment end had already committed.
 */
class TeachingAssignmentConcurrencyTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

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
            // History rows are undeletable by the runtime role; the admin
            // removes them before the School (their Employee FK is RESTRICT).
            $admin->table('teaching_assignments')->where('school_id', $schoolId)->delete();
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/teaching-assignment-op.php', ...$args];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->teachingWorld();
        $this->schoolIds[] = $w['school']->id;

        return $w;
    }

    /** @return list<string> */
    private function create(array $w, string $startsOn, ?string $endsOn = null, ?Employee $employee = null): array
    {
        return $this->script('create', $w['school']->id, $w['admin']->id, ($employee ?? $w['employee'])->id, $w['section']->id, $w['offering']->id, $startsOn, ...($endsOn === null ? [] : [$endsOn]));
    }

    private function assignmentCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => TeachingAssignment::query()->count());
    }

    #[Test]
    public function two_concurrent_overlapping_creates_produce_exactly_one_assignment(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->create($w, '2026-06-01', '2026-12-31'),
            $this->create($w, '2026-09-01'),
        );

        $this->assertStringStartsWith('created:', $holder);
        $this->assertSame('rejected:TEACHING_ASSIGNMENT_OVERLAP', $contender);
        $this->assertSame(1, $this->assignmentCount($w['school']));
    }

    #[Test]
    public function co_teachers_created_concurrently_do_not_block_each_other_into_a_rejection(): void
    {
        $w = $this->world();
        $coTeacher = $this->employedTeacher($w['school']);

        // Different keys: no shared advisory lock, both succeed.
        $first = $this->create($w, '2026-06-01');
        $second = $this->create($w, '2026-06-01', employee: $coTeacher);
        $processes = [new Process($first), new Process($second)];
        array_walk($processes, fn ($p) => $p->start());
        array_walk($processes, fn ($p) => $p->wait());

        foreach ($processes as $process) {
            $this->assertStringStartsWith('created:', trim($process->getOutput()));
        }
        $this->assertSame(2, $this->assignmentCount($w['school']));
    }

    #[Test]
    public function two_concurrent_ends_of_one_assignment_end_it_once(): void
    {
        $w = $this->world();
        $a = $this->assign($w, '2026-06-01');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end', $w['school']->id, $w['admin']->id, $a->id, '2026-09-30', 'reassigned'),
            $this->script('end', $w['school']->id, $w['admin']->id, $a->id, '2026-08-31', 'completed'),
        );

        $this->assertSame(['ended', 'rejected:TEACHING_ASSIGNMENT_ALREADY_ENDED'], [$holder, $contender]);
        $fresh = app(TenantContext::class)->withSchool($w['school'], fn () => $a->fresh());
        $this->assertSame(['2026-09-30', 'reassigned'], [$fresh->ends_on->toDateString(), $fresh->end_reason]);
    }

    #[Test]
    public function a_create_waiting_on_an_end_sees_the_shortened_period(): void
    {
        $w = $this->world();
        $a = $this->assign($w, '2026-06-01');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end', $w['school']->id, $w['admin']->id, $a->id, '2026-09-30', 'reassigned'),
            $this->create($w, '2026-10-01'),
        );

        $this->assertSame('ended', $holder);
        $this->assertStringStartsWith('created:', $contender, 'Serialized on the key: the create saw the committed end.');
        $this->assertSame(2, $this->assignmentCount($w['school']));
    }

    #[Test]
    public function an_employment_end_racing_a_create_serializes_both_ways(): void
    {
        $w = $this->world();
        $hr = $this->fullHrActor($w['school']);

        // Employment end first: the create waits on the EmploymentRecord and
        // then finds no covering employment.
        $leaver = $this->employedTeacher($w['school']);
        $leaverRecord = app(TenantContext::class)->withSchool($w['school'], fn () => $leaver->employmentRecords()->firstOrFail());
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-employment', $w['school']->id, $hr->id, $leaverRecord->id, '2026-05-31'),
            $this->create($w, '2026-06-01', employee: $leaver),
        );
        $this->assertSame(['employment-ended', 'rejected:TEACHING_ASSIGNMENT_EMPLOYEE_NOT_ASSIGNABLE'], [$holder, $contender]);

        // Create first: the employment end waits for it; the assignment
        // stands (use-time ActingEmployee eligibility governs later).
        $record = app(TenantContext::class)->withSchool($w['school'], fn () => $w['employee']->employmentRecords()->firstOrFail());
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->create($w, '2026-06-01'),
            $this->script('end-employment', $w['school']->id, $hr->id, $record->id, '2026-07-31'),
        );
        $this->assertStringStartsWith('created:', $holder);
        $this->assertSame('employment-ended', $contender);
    }

    #[Test]
    public function an_employee_archive_racing_a_create_serializes_both_ways(): void
    {
        $w = $this->world();
        $hr = $this->fullHrActor($w['school']);

        $archived = $this->employedTeacher($w['school']);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('archive', $w['school']->id, $hr->id, $archived->id),
            $this->create($w, '2026-06-01', employee: $archived),
        );
        $this->assertSame(['archived', 'rejected:TEACHING_ASSIGNMENT_EMPLOYEE_NOT_ASSIGNABLE'], [$holder, $contender]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->create($w, '2026-06-01'),
            $this->script('archive', $w['school']->id, $hr->id, $w['employee']->id),
        );
        $this->assertStringStartsWith('created:', $holder);
        $this->assertSame('archived', $contender);
    }
}
