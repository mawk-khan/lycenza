<?php

namespace Tests\Feature\Hostel;

use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (checkpoint brief section 20): two
 * GENUINELY separate OS processes -- not two sequential calls in one
 * PHP process -- racing HostelResidencyService::assign(). Mirrors
 * VisitorVisitCheckInConcurrencyTest's exact pattern, including the
 * two lessons that pattern already had to learn the hard way:
 * `protected $connectionsToTransact = []` (the two subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * otherwise-uncommitted fixture rows), and cleanup via `$school->delete()`
 * in tearDown().
 *
 * Both invariants share the exact same deterministic lock order
 * (Student row, then Bed row -- HostelResidencyService's own docblock)
 * so neither race can deadlock: whichever pairing two concurrent
 * `assign()` calls target, both always acquire locks in the same
 * order, and PostgreSQL serializes them on whichever row they
 * actually share.
 */
class HostelResidencyConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades hostels/hostel_rooms/hostel_beds/students/hostel_residency_assignments
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_assigning_different_students_to_the_same_bed_leave_exactly_one_active_residency(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $campus = $this->createCampus($this->school);
        $hostel = $this->createHostel($this->school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $studentA = $context->withSchool($this->school, fn () => Student::factory()->for($this->school, 'school')->create(['status' => 'active']));
        $studentB = $context->withSchool($this->school, fn () => Student::factory()->for($this->school, 'school')->create(['status' => 'active']));

        $script = __DIR__.'/../../Support/assign-hostel-residency.php';
        $processA = new Process(['php', $script, $this->school->id, $studentA->id, $bed->id]);
        $processB = new Process(['php', $script, $this->school->id, $studentB->id, $bed->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $assignedCount = count(array_filter($outputs, fn ($o) => $o === 'assigned'));

        $this->assertSame(1, $assignedCount, 'Exactly one of the two concurrent same-Bed assignments must succeed.');
        $rejectedOutputs = array_filter($outputs, fn ($o) => $o !== 'assigned');
        $this->assertCount(1, $rejectedOutputs);
        $this->assertStringContainsString('BedAlreadyOccupiedException', reset($rejectedOutputs));

        $activeForBed = $context->withSchool(
            $this->school,
            fn () => HostelResidencyAssignment::query()->where('hostel_bed_id', $bed->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeForBed, 'The database must contain exactly one active residency for this Bed.');
    }

    #[Test]
    public function two_real_concurrent_processes_assigning_the_same_student_to_different_beds_leave_exactly_one_active_residency(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $campus = $this->createCampus($this->school);
        $hostel = $this->createHostel($this->school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedA = $this->createHostelBed($room);
        $bedB = $this->createHostelBed($room);
        $student = $context->withSchool($this->school, fn () => Student::factory()->for($this->school, 'school')->create(['status' => 'active']));

        $script = __DIR__.'/../../Support/assign-hostel-residency.php';
        $processA = new Process(['php', $script, $this->school->id, $student->id, $bedA->id]);
        $processB = new Process(['php', $script, $this->school->id, $student->id, $bedB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $assignedCount = count(array_filter($outputs, fn ($o) => $o === 'assigned'));

        $this->assertSame(1, $assignedCount, 'Exactly one of the two concurrent same-Student assignments must succeed.');
        $rejectedOutputs = array_filter($outputs, fn ($o) => $o !== 'assigned');
        $this->assertCount(1, $rejectedOutputs);
        $this->assertStringContainsString('StudentAlreadyResidentException', reset($rejectedOutputs));

        $activeForStudent = $context->withSchool(
            $this->school,
            fn () => HostelResidencyAssignment::query()->where('student_id', $student->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeForStudent, 'The database must contain exactly one active residency for this Student.');
    }
}
