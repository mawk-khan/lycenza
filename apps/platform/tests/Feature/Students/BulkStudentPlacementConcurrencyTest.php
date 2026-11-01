<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Bulk\BulkPlacementOperation;
use App\Domain\Students\Application\Bulk\BulkPlacementRequest;
use App\Domain\Students\Application\Bulk\BulkStudentPlacementService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 11.13: a bulk move against real concurrent writers, in GENUINELY
 * separate OS processes. The holder's write is uncommitted and the contender
 * is positively observed BLOCKED ON A LOCK before the holder commits
 * (ForcesConcurrentOverlap) -- never a sleep.
 *
 * - a single transfer committed first makes the batch stale: NOTHING in the
 *   batch moves;
 * - two overlapping batches: one outcome, never a partial or overlapping one;
 * - a batch committed first refuses a single transfer of one of its Students;
 * - two independent batches on the same pair of classes serialize (ascending
 *   Section lock order), they never deadlock;
 * - a Student moved by another process between preview and execution
 *   refuses the batch.
 */
class BulkStudentPlacementConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private User $admin;

    private Section $s;

    private Section $t;

    private Section $u;

    /** @var array<string, Student> */
    private array $students = [];

    /** @var array<string, StudentEnrollment> */
    private array $placements = [];

    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->admin = $this->createUser();
        $this->userIds[] = $this->admin->id;
        $this->assignSchoolRole($this->createMembership($this->admin, $this->school), 'school_admin');
        $campus = $this->createCampus($this->school);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
        $this->s = $this->createSection($year, $campus, $grade, ['code' => 'S', 'name' => 'S', 'status' => 'active']);
        $this->t = $this->createSection($year, $campus, $grade, ['code' => 'T', 'name' => 'T', 'status' => 'active']);
        $this->u = $this->createSection($year, $campus, $grade, ['code' => 'U', 'name' => 'U', 'status' => 'active']);
        foreach (['X' => $this->s, 'Y' => $this->s, 'Z' => $this->s, 'W' => $this->t] as $name => $section) {
            $this->students[$name] = $this->createStudent($this->school, ['first_name' => $name, 'status' => 'active']);
            $this->placements[$name] = $this->createStudentEnrollment($this->students[$name], $section, ['status' => 'active', 'roll_number' => $name, 'starts_on' => now()->subDays(100)->toDateString()]);
        }
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @param  list<string>  $names */
    private function request(array $names, Section $from, Section $to): BulkPlacementRequest
    {
        return new BulkPlacementRequest(BulkPlacementOperation::SectionChange, $from->id, $to->id, SchoolTimezone::today($this->school), array_map(fn (string $n) => [
            'studentId' => $this->students[$n]->id, 'enrollmentId' => $this->placements[$n]->id, 'rollNumber' => "B{$n}",
        ], $names));
    }

    /** A signed, all-Ready preview, made by this (parent) process before the race. */
    private function bulk(array $names, Section $from, Section $to): array
    {
        $request = $this->request($names, $from, $to);
        $preview = app(BulkStudentPlacementService::class)->preview($this->school, $this->admin, CampusScope::wholeSchool(), $request);
        $this->assertTrue($preview['executable'], json_encode($preview['rows']));

        return ['php', __DIR__.'/../../Support/bulk-placement-op.php', 'bulk', $this->school->id, $this->admin->id, 'section_change', $from->id, $to->id,
            SchoolTimezone::today($this->school), $preview['issuedAt'], $preview['signature'], json_encode($request->rows)];
    }

    private function single(string $name, Section $to): array
    {
        return ['php', __DIR__.'/../../Support/bulk-placement-op.php', 'single', $this->school->id, $this->admin->id, $this->placements[$name]->id, $to->id, "S{$name}", SchoolTimezone::today($this->school)];
    }

    private function sectionOf(string $name): ?string
    {
        return DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $this->students[$name]->id)->where('status', 'active')->value('section_id');
    }

    private function placementsOf(string $name): int
    {
        return DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $this->students[$name]->id)->count();
    }

    private function batches(): int
    {
        return DB::connection('pgsql_admin')->table('school_audit_events')->where('school_id', $this->school->id)->where('event_type', BulkStudentPlacementService::AUDIT_EVENT)->count();
    }

    #[Test]
    public function a_single_transfer_committed_first_refuses_the_whole_batch(): void
    {
        $batch = $this->bulk(['X', 'Y'], $this->s, $this->t);
        [$single, $bulk] = $this->raceWithHeldHolder($this->single('X', $this->u), $batch);

        $this->assertSame('ok', $single);
        $this->assertStringStartsWith('refused:BulkPlacementNotExecutedException:No Students were moved.', $bulk);
        $this->assertSame($this->u->id, $this->sectionOf('X'));
        $this->assertSame($this->s->id, $this->sectionOf('Y'), 'Y was not moved by the refused batch');
        $this->assertSame(1, $this->placementsOf('Y'));
        $this->assertSame(0, $this->batches());
    }

    #[Test]
    public function two_overlapping_batches_have_one_outcome(): void
    {
        $first = $this->bulk(['X', 'Y'], $this->s, $this->t);
        $second = $this->bulk(['Y', 'Z'], $this->s, $this->u);
        [$a, $b] = $this->raceWithHeldHolder($first, $second);

        $this->assertSame('ok', $a);
        $this->assertStringStartsWith('refused:BulkPlacementNotExecutedException:No Students were moved.', $b);
        $this->assertSame($this->t->id, $this->sectionOf('X'));
        $this->assertSame($this->t->id, $this->sectionOf('Y'));
        $this->assertSame($this->s->id, $this->sectionOf('Z'), 'the second batch moved nobody');
        $this->assertSame(2, $this->placementsOf('Y'), 'one move of Y: its old row and its new row');
        $this->assertSame(1, $this->placementsOf('Z'));
        $this->assertSame(1, $this->batches());
    }

    #[Test]
    public function a_batch_committed_first_refuses_a_single_transfer_of_its_student(): void
    {
        [$bulk, $single] = $this->raceWithHeldHolder($this->bulk(['X', 'Y'], $this->s, $this->t), $this->single('X', $this->u));

        $this->assertSame('ok', $bulk);
        $this->assertStringStartsWith('refused:InvalidEnrollmentTransitionException', $single);
        $this->assertSame($this->t->id, $this->sectionOf('X'));
        $this->assertSame(2, $this->placementsOf('X'));
    }

    #[Test]
    public function independent_batches_on_the_same_classes_serialize_and_never_deadlock(): void
    {
        // S -> T and T -> S at once: both lock {S, T} in ascending id order.
        [$a, $b] = $this->raceWithHeldHolder($this->bulk(['X', 'Y'], $this->s, $this->t), $this->bulk(['W'], $this->t, $this->s));

        $this->assertSame('ok', $a);
        $this->assertSame('ok', $b, 'the contender waited, then ran');
        $this->assertSame($this->t->id, $this->sectionOf('X'));
        $this->assertSame($this->s->id, $this->sectionOf('W'));
        $this->assertSame(2, $this->batches());
    }

    #[Test]
    public function a_student_moved_by_another_process_after_the_preview_refuses_the_batch(): void
    {
        $batch = $this->bulk(['X', 'Y', 'Z'], $this->s, $this->t);
        $other = new Process($this->single('Z', $this->u));
        $other->mustRun();
        $this->assertSame('ok', $other->getOutput());

        $run = new Process($batch);
        $run->mustRun();
        $this->assertStringStartsWith('refused:BulkPlacementNotExecutedException:No Students were moved. Z', $run->getOutput());
        $this->assertSame($this->s->id, $this->sectionOf('X'));
        $this->assertSame($this->s->id, $this->sectionOf('Y'));
        $this->assertSame($this->u->id, $this->sectionOf('Z'));
        $this->assertSame(0, $this->batches());
    }
}
