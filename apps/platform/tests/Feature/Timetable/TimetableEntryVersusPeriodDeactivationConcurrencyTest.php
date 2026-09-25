<?php

namespace Tests\Feature\Timetable;

use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation) reconciliation: the REQUIRED
 * real-concurrency proof that the "an active TimetableEntry must never
 * reference an inactive Period" invariant holds even across the TWO
 * DIFFERENT services that each touch it --
 * App\Domain\Timetable\Application\TimetableScheduleService::create()
 * and App\Domain\Timetable\Application\TimetablePeriodService::deactivate().
 * Two GENUINELY separate OS processes, not two sequential calls in one
 * PHP process: process A creates an active TimetableEntry against
 * Period P; process B concurrently deactivates P. Before the fix
 * documented on both services' docblocks, each side's own check
 * (`TimetablePeriodService::assertNotReferencedByActiveEntry()` /
 * `TimetableScheduleService::assertPeriodSchedulable()`, formerly
 * `assertSchedulable()`, ran BEFORE any lock/transaction even opened)
 * could each pass against stale/not-yet-committed state, leaving an
 * active entry referencing an inactive Period. Both methods now
 * acquire the IDENTICAL `App\Support\Concurrency\TenantLock::forSchool($school,
 * 'timetable.periods')` lock key, so exactly one of two coherent
 * outcomes is possible -- never both succeeding.
 *
 * Mirrors TimetablePeriodConcurrencyTest.php's exact pattern, including
 * the CACHE_STORE=database environment override for both subprocesses
 * (see that test's own docblock for why phpunit.xml's default
 * CACHE_STORE=array cannot serialize two genuinely separate OS
 * processes) and `protected $connectionsToTransact = []`.
 */
class TimetableEntryVersusPeriodDeactivationConcurrencyTest extends TestCase
{
    use CreatesTimetableFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades timetable_periods/timetable_entries
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_creating_an_entry_and_deactivating_its_period_never_leave_an_active_entry_referencing_an_inactive_period(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $actor = $this->fullTimetableActor($this->school);

        $campus = $this->createCampus($this->school);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $subject = $this->createSubject($this->school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($this->school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        $env = ['CACHE_STORE' => 'database'];

        $createScript = __DIR__.'/../../Support/create-timetable-entry.php';
        $deactivateScript = __DIR__.'/../../Support/deactivate-timetable-period.php';

        $processA = new Process(
            ['php', $createScript, $this->school->id, $offering->id, $section->id, $teacher->id, $period->id, '1', $actor->id],
            null,
            $env,
        );
        $processB = new Process(
            ['php', $deactivateScript, $this->school->id, $period->id, $actor->id],
            null,
            $env,
        );
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $entryOutput = $processA->getOutput();
        $deactivateOutput = $processB->getOutput();

        $entryCreated = str_starts_with($entryOutput, 'created:');
        $periodDeactivated = str_starts_with($deactivateOutput, 'deactivated:');

        $this->assertFalse(
            $entryCreated && $periodDeactivated,
            "Both operations must never succeed together -- entry output: {$entryOutput}, deactivate output: {$deactivateOutput}",
        );

        if ($entryCreated) {
            // Entry creation won the race: the concurrent deactivation
            // must have been rejected because it saw the (by-then
            // committed) active entry referencing this Period.
            $this->assertStringContainsString('TimetablePeriodReferencedException', $deactivateOutput);
        } else {
            // Deactivation won the race: the concurrent entry creation
            // must have been rejected because it saw the (by-then
            // committed) inactive Period.
            $this->assertTrue($periodDeactivated, "Deactivation must have succeeded if entry creation didn't: {$deactivateOutput}");
            $this->assertStringContainsString('TimetablePeriodNotAvailableException', $entryOutput);
        }

        // The actual invariant, verified directly against the database
        // regardless of which side won: an active TimetableEntry
        // referencing an inactive Period must never exist.
        $invalidStateExists = $context->withSchool(
            $this->school,
            fn () => TimetableEntry::query()
                ->join('timetable_periods', 'timetable_periods.id', '=', 'timetable_entries.period_id')
                ->where('timetable_entries.period_id', $period->id)
                ->where('timetable_entries.status', 'active')
                ->where('timetable_periods.status', 'inactive')
                ->exists(),
        );
        $this->assertFalse($invalidStateExists, 'No active TimetableEntry may ever reference an inactive Period.');

        // Cross-check the Period's own final status against which side won.
        $finalPeriodStatus = $context->withSchool(
            $this->school,
            fn () => TimetablePeriod::query()->where('id', $period->id)->value('status'),
        );
        $this->assertSame($periodDeactivated ? 'inactive' : 'active', $finalPeriodStatus);
    }
}
