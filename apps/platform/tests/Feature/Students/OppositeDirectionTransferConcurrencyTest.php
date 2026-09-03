<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2, mandatory concurrency proof #4 (carried forward from the
 * earlier concurrency reconciliation and NOT reducible).
 *
 * Phase 0H.2 gave App\Domain\Students\Application\StudentEnrollmentService
 * a Section-before-Enrollment lock order so Attendance can serialize a
 * complete-register write against membership mutation on one shared
 * Section row. `transferPlacement()` touches TWO Sections, which is
 * exactly where a naive implementation deadlocks: Student X moving
 * A -> B while Student Y concurrently moves B -> A would have each
 * process holding one Section and waiting for the other.
 *
 * `lockSections()` therefore always acquires in ASCENDING SECTION ID
 * order, so both processes above request {A, B} and both take A first
 * -- one simply waits. This test proves that with two GENUINELY
 * separate OS processes against real PostgreSQL, repeated enough times
 * that a lucky single pass cannot hide a real cycle.
 */
class OppositeDirectionTransferConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        $this->school?->delete();
        $this->school = null;

        parent::tearDown();
    }

    #[Test]
    public function opposite_direction_section_transfers_never_deadlock(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $actor = $this->createUserWithCapabilities($this->school, ['students.view', 'students.manage']);

        $campus = $this->createCampus($this->school);
        $year = $this->createAcademicYear($this->school, [
            'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31',
        ]);
        $grade = $this->createGradeLevel($this->school);

        $script = __DIR__.'/../../Support/transfer-student-placement.php';

        // Repeated rounds: a deadlock cycle is timing-dependent, so one
        // pass proves very little. Five rounds with fresh Sections and
        // Students each time (a transferred Enrollment is terminal and
        // cannot be reused).
        for ($round = 0; $round < 5; $round++) {
            $sectionA = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => "A{$round}"]);
            $sectionB = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => "B{$round}"]);

            $studentX = $this->createStudent($this->school);
            $studentY = $this->createStudent($this->school);

            $enrollmentX = $this->createStudentEnrollment($studentX, $sectionA, [
                'roll_number' => '1', 'status' => 'active', 'starts_on' => '2026-06-01',
            ]);
            $enrollmentY = $this->createStudentEnrollment($studentY, $sectionB, [
                'roll_number' => '1', 'status' => 'active', 'starts_on' => '2026-06-01',
            ]);

            // X: A -> B   and   Y: B -> A, concurrently.
            $processX = new Process(['php', $script, $this->school->id, $enrollmentX->id, $sectionB->id, '50', '2026-09-01', $actor->id]);
            $processY = new Process(['php', $script, $this->school->id, $enrollmentY->id, $sectionA->id, '51', '2026-09-01', $actor->id]);

            $processX->start();
            $processY->start();
            $processX->wait();
            $processY->wait();

            $outputs = [$processX->getOutput(), $processY->getOutput()];

            foreach ($outputs as $output) {
                $this->assertStringNotContainsString('Deadlock', $output, "Round {$round}: a deadlock occurred. Outputs: ".json_encode($outputs));
                $this->assertStringNotContainsString('40P01', $output, "Round {$round}: SQLSTATE 40P01. Outputs: ".json_encode($outputs));
                $this->assertStringStartsWith('transferred:', $output, "Round {$round}: both opposite transfers must succeed. Outputs: ".json_encode($outputs));
            }

            // Both operations landed completely -- never half-transferred.
            $context->withSchool($this->school, function () use ($enrollmentX, $enrollmentY, $sectionA, $sectionB, $studentX, $studentY, $round): void {
                $this->assertSame('transferred', StudentEnrollment::query()->findOrFail($enrollmentX->id)->status, "Round {$round}");
                $this->assertSame('transferred', StudentEnrollment::query()->findOrFail($enrollmentY->id)->status, "Round {$round}");

                // ends_on = effective_date - 1 day (inclusive semantics).
                $this->assertSame('2026-08-31', StudentEnrollment::query()->findOrFail($enrollmentX->id)->ends_on->toDateString());

                $newX = StudentEnrollment::query()->where('student_id', $studentX->id)->where('status', 'active')->firstOrFail();
                $newY = StudentEnrollment::query()->where('student_id', $studentY->id)->where('status', 'active')->firstOrFail();

                $this->assertSame($sectionB->id, $newX->section_id, "Round {$round}: X must end up in B");
                $this->assertSame($sectionA->id, $newY->section_id, "Round {$round}: Y must end up in A");
                $this->assertSame('2026-09-01', $newX->starts_on->toDateString());

                // The one-active-per-Student-per-year invariant holds.
                $this->assertSame(1, StudentEnrollment::query()->where('student_id', $studentX->id)->where('status', 'active')->count());
                $this->assertSame(1, StudentEnrollment::query()->where('student_id', $studentY->id)->where('status', 'active')->count());
            });
        }
    }
}
