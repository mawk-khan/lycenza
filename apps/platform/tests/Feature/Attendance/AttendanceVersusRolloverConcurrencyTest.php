<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2 final-integration gate: settles the ONE lock-ordering
 * question this checkpoint's audit surfaced.
 *
 * Phase 0H.2 gave StudentEnrollmentService a Section-before-Enrollment
 * order so Attendance and SIS serialize on a shared Section row.
 * `EnrollmentRolloverItemExecutionService` is the one runtime path that
 * still looks inverted: it takes a `lockForUpdate()` on the SOURCE
 * StudentEnrollment (for drift detection) and only afterwards calls
 * `StudentEnrollmentService::enroll()`, which locks the TARGET Section.
 * That is Enrollment -> Section.
 *
 * Analysis says this cannot cycle against Attendance, because rollover
 * is inherently CROSS-AcademicYear: the Enrollment it holds belongs to
 * the SOURCE year's Section, while the Section it waits for belongs to
 * the TARGET year. Attendance only ever holds ONE Section and locks
 * only the Enrollment rows of that same Section AND AcademicYear
 * (StudentEnrollmentRosterReadService filters on both), so it can never
 * be the holder of rollover's source Enrollment while also holding
 * rollover's target Section.
 *
 * Analysis is not proof, so this test contends the two paths with two
 * genuinely separate OS processes and requires a coherent, deadlock-free
 * outcome either way round.
 *
 * SIS 5.2 (ADR 0077 §7): a rollover target is always a DRAFT year, and
 * Attendance registers only inside an active year, so the two can no longer
 * meet on the TARGET Section at all. The overlap that remains is the SOURCE
 * side: Attendance locks the source Section and its Enrollment rows while
 * the rollover item locks the source Enrollment FOR UPDATE and then waits
 * for the (draft) target Section. That is what this test now contends.
 */
class AttendanceVersusRolloverConcurrencyTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school);
        }
        $this->schools = [];

        parent::tearDown();
    }

    #[Test]
    public function attendance_and_a_concurrent_rollover_into_the_same_section_never_deadlock(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $context = app(TenantContext::class);

            // The SOURCE year is the current, ACTIVE one (Attendance can
            // only register a past date inside an active year); the TARGET
            // is next year's draft (SIS 5.2: a rollover target stays draft).
            $school = $this->createSchool();
            $this->schools[] = $school;
            $campus = $this->createCampus($school);
            $sourceYear = $this->createAcademicYear($school, [
                'code' => 'SRC', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31',
            ]);
            $targetYear = $this->createAcademicYear($school, [
                'code' => 'TGT', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31',
            ]);
            $sourceGrade = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
            $targetGrade = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
            $sourceSection = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5A', 'code' => '5A', 'status' => 'active']);
            $targetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6B', 'code' => '6B', 'status' => 'active']);

            // Attendance needs a real class on the SOURCE Section.
            $subject = $this->createSubject($school, ['code' => "M{$round}"]);
            $offering = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subject, [
                'is_required' => true, 'status' => 'active',
            ]);
            $teacher = $this->createEmployee($school, ['record_status' => 'active']);
            $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '10:00:00']);
            $entry = $this->createTimetableEntry($offering, $sourceSection, $teacher, $period, ['day_of_week' => 1]);
            $actor = $this->fullAttendanceActor($school);

            // Two Students in the source Section: the register covers both,
            // and one of them is being promoted out of it.
            $sitting = $this->enrollStudent($sourceSection, '1', '2026-06-01');
            $promotedPlacement = $this->enrollStudent($sourceSection, '007', '2026-06-01');
            $promotedId = $promotedPlacement->student_id;

            $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
            app(EnrollmentRolloverPlanService::class)->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
            $context->withSchool($school, fn () => app(EnrollmentRolloverDryRunService::class)->run($plan->fresh()));
            $item = $context->withSchool($school, fn () => EnrollmentRolloverItem::query()
                ->where('plan_id', $plan->id)->where('student_id', $promotedId)->firstOrFail());
            app(EnrollmentRolloverPlanService::class)->setItemDecision(
                $context->withSchool($school, fn () => $plan->fresh()), $item, 'promote', null, 'explicit', '011',
            );
            // The other Student stays behind (excluded from this rollover).
            $stays = $context->withSchool($school, fn () => EnrollmentRolloverItem::query()
                ->where('plan_id', $plan->id)->where('student_id', $sitting->student_id)->firstOrFail());
            app(EnrollmentRolloverPlanService::class)->setItemDecision(
                $context->withSchool($school, fn () => $plan->fresh()), $stays, 'exclude', null, 'preserve_source',
            );
            $summary = $context->withSchool($school, fn () => app(EnrollmentRolloverDryRunService::class)->run($plan->fresh()));
            $this->assertTrue($summary['validated'], "Round {$round}: fixture must produce a validated plan");

            // Race: Attendance register for source Section 5A vs the
            // rollover that locks the promoted Student's 5A placement and
            // then places them in 6B.
            $submit = new Process(['php', __DIR__.'/../../Support/submit-attendance-register.php',
                $school->id, $entry->id, self::MONDAY, $actor->id, "{$sitting->id}:present,{$promotedPlacement->id}:present"]);
            $rollover = new Process(['php', __DIR__.'/../../Support/execute-rollover-item.php',
                $school->id, $item->id]);

            $submit->start();
            $rollover->start();
            $submit->wait();
            $rollover->wait();

            $submitOutput = $submit->getOutput();
            $rolloverOutput = $rollover->getOutput();
            $ctx = "Round {$round}. submit={$submitOutput} rollover={$rolloverOutput}";

            foreach ([$submitOutput, $rolloverOutput] as $output) {
                $this->assertStringNotContainsString('Deadlock', $output, $ctx);
                $this->assertStringNotContainsString('40P01', $output, $ctx);
            }

            // The SIS side must always succeed -- Attendance never blocks
            // a legitimate membership mutation.
            $this->assertMatchesRegularExpression('/^(succeeded|reconciled):/', $rolloverOutput, $ctx);

            // Promotion preserves the source placement (the operator
            // completes it later), so the roster Attendance locks is the
            // same either way round: the register always lands whole.
            $this->assertStringStartsWith('submitted:', $submitOutput, $ctx);
            $this->assertSame(1, $context->withSchool($school, fn () => AttendanceSession::query()->count()), $ctx);
            $this->assertSame(2, $context->withSchool($school, fn () => AttendanceRecord::query()->count()), $ctx);

            // Either way the promotion landed exactly once.
            $this->assertSame(1, $context->withSchool($school, fn () => StudentEnrollment::query()
                ->where('student_id', $promotedId)
                ->where('academic_year_id', $targetYear->id)
                ->where('status', 'active')->count()), $ctx);
        }
    }
}
