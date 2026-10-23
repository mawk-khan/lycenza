<?php

namespace Tests\Feature\Transport;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-4 (ADR 0076 §72): rider imports race each other and the single
 * assignment in GENUINELY separate OS processes against real PostgreSQL.
 * Students are locked in ascending id order (the single path's own lock), so
 * opposite file orders never deadlock; the one-active-assignment rule holds;
 * a losing batch is refused whole or finds its rows already assigned; nothing
 * is ever half-committed and no uniqueness error escapes.
 */
class TransportRiderImportConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $actor;

    /** @var array<string, TransportRoute> */
    private array $route = [];

    /** @var array<string, Student> */
    private array $student = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->route['one'] = $this->createTransportRoute($this->school, ['code' => 'ONE', 'status' => 'active']);
        $this->route['two'] = $this->createTransportRoute($this->school, ['code' => 'TWO', 'status' => 'active']);
        foreach (range(1, 6) as $n) {
            $this->student["R{$n}"] = $this->createStudent($this->school, ['student_number' => "R{$n}", 'status' => 'active']);
        }
        $this->actor = $this->createUser();
        $this->assignSchoolRole($this->createMembership($this->actor, $this->school), 'transport_coordinator');
    }

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);
        parent::tearDown();
    }

    /** @param  list<string>  $lines */
    private function import(array $lines): Process
    {
        return new Process(['php', __DIR__.'/../../Support/import-transport-riders.php', $this->school->id, $this->actor->id, base64_encode("student_number,route_code\n".implode("\n", $lines)."\n")]);
    }

    private function single(string $student, string $route): Process
    {
        return new Process(['php', __DIR__.'/../../Support/assign-transport-student.php', $this->school->id, $this->student[$student]->id, $this->route[$route]->id]);
    }

    /** @return list<string> */
    private function race(Process $a, Process $b): array
    {
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();

        return [$a->getOutput(), $b->getOutput()];
    }

    /** @return array<string, string> student number => route code of their active assignment */
    private function active(): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => TransportStudentAssignment::query()->where('transport_student_assignments.status', 'active')
            ->join('students', 'students.id', '=', 'transport_student_assignments.student_id')
            ->join('transport_routes', 'transport_routes.id', '=', 'transport_student_assignments.route_id')
            ->orderBy('students.student_number')->pluck('transport_routes.code', 'students.student_number')->all());
    }

    #[Test]
    public function the_same_import_twice_in_opposite_orders_assigns_each_rider_once_without_deadlock(): void
    {
        $forward = array_map(fn ($n) => "R{$n},ONE", range(1, 6));
        $outputs = $this->race($this->import($forward), $this->import(array_reverse($forward)));

        sort($outputs);
        $this->assertSame(['committed:0:6', 'committed:6:0'], $outputs, 'one batch assigns all six; the other finds them already assigned');
        $this->assertSame(array_fill_keys(['R1', 'R2', 'R3', 'R4', 'R5', 'R6'], 'ONE'), $this->active());
    }

    #[Test]
    public function two_conflicting_imports_leave_exactly_one_batch_whole_and_nothing_of_the_other(): void
    {
        $outputs = $this->race(
            $this->import(['R1,ONE', 'R2,ONE', 'R3,ONE']),
            $this->import(['R3,TWO', 'R4,TWO', 'R5,TWO']),
        );

        $won = array_values(array_filter($outputs, fn ($o) => str_starts_with($o, 'committed:3:0')));
        $lost = array_values(array_filter($outputs, fn ($o) => str_contains($o, 'TransportRiderImportNotCommittableException')));
        $this->assertCount(1, $won, json_encode($outputs));
        $this->assertCount(1, $lost, json_encode($outputs));
        $active = $this->active();
        $this->assertCount(3, $active, 'never a partial batch');
        $this->assertContains($active, [['R1' => 'ONE', 'R2' => 'ONE', 'R3' => 'ONE'], ['R3' => 'TWO', 'R4' => 'TWO', 'R5' => 'TWO']]);
    }

    #[Test]
    public function an_import_and_a_single_assignment_of_the_same_student_converge(): void
    {
        $outputs = $this->race($this->import(['R1,ONE', 'R2,ONE']), $this->single('R1', 'two'));

        $active = $this->active();
        if (str_starts_with($outputs[0], 'committed:2:0')) {
            $this->assertStringStartsWith('rejected:', $outputs[1], 'the single assignment finds R1 assigned');
            $this->assertSame(['R1' => 'ONE', 'R2' => 'ONE'], $active);
        } else {
            $this->assertStringContainsString('TransportRiderImportNotCommittableException', $outputs[0], json_encode($outputs));
            $this->assertSame('assigned', $outputs[1]);
            $this->assertSame(['R1' => 'TWO'], $active, 'the batch kept nothing, not even R2');
        }
    }
}
