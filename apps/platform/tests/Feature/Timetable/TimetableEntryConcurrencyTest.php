<?php

namespace Tests\Feature\Timetable;

use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation): the REQUIRED real-concurrency proof
 * for the teacher-double-booking guarantee -- two GENUINELY separate OS
 * processes, not two sequential calls in one PHP process, both attempt
 * to create an ACTIVE TimetableEntry for the SAME School/teacher/
 * day-of-week/Period (otherwise different, valid entries -- distinct
 * SubjectOffering + Section each). The database's own
 * `timetable_entries_teacher_slot_unique` partial unique index is what
 * makes this safe; this test proves the final state, not just that the
 * application code "looks" correct. Mirrors
 * InventoryStockConcurrencyTest.php's exact pattern -- Section/Room
 * conflicts use the IDENTICAL unique-index mechanism (proven here for
 * the teacher case) and are covered by ordinary sequential-attempt
 * tests in TimetableScheduleServiceTest.php instead of their own
 * process race.
 */
class TimetableEntryConcurrencyTest extends TestCase
{
    use CreatesTimetableFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades timetable_periods/timetable_entries
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_scheduling_the_same_teacher_slot_leave_exactly_one_active_entry(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $actor = $this->fullTimetableActor($this->school);

        $campus = $this->createCampus($this->school);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $subjectA = $this->createSubject($this->school);
        $subjectB = $this->createSubject($this->school);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $subjectA, ['is_required' => true, 'status' => 'active']);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $subjectB, ['is_required' => true, 'status' => 'active']);
        $sectionA = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'B']);
        $teacher = $this->createEmployee($this->school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        $script = __DIR__.'/../../Support/create-timetable-entry.php';

        $processA = new Process(['php', $script, $this->school->id, $offeringA->id, $sectionA->id, $teacher->id, $period->id, '1', $actor->id]);
        $processB = new Process(['php', $script, $this->school->id, $offeringB->id, $sectionB->id, $teacher->id, $period->id, '1', $actor->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $createdCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'created:')));

        $this->assertSame(1, $createdCount, 'Exactly one of the two concurrent same-teacher-slot creations must succeed.');
        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'created:')));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('TeacherAlreadyScheduledException', $rejected[0]);

        $activeCount = $context->withSchool(
            $this->school,
            fn () => TimetableEntry::query()->where('school_id', $this->school->id)->where('teacher_id', $teacher->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeCount, 'Exactly one active TimetableEntry must exist for this teacher/day/Period.');
    }
}
