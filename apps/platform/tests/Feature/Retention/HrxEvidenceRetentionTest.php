<?php

namespace Tests\Feature\Retention;

use App\Domain\HR\Application\Retention\EmployeeRecordRetentionService;
use App\Domain\Leave\Application\Retention\LeaveEvidenceRetentionService;
use App\Domain\StaffAttendance\Application\Retention\StaffAttendanceEvidenceRetentionService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\TenantRetentionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Retention\Concerns\CreatesHrxRetentionFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * HRX.6 (E21-D9, ADR 0065 §27): one Employee's Leave and Staff Attendance
 * evidence expires 8 calendar years after the Employee's final separation,
 * through the existing employee retention run, in a causally complete
 * order; School configuration, audit, outbox and Payroll's snapshots stay;
 * the Employee root follows only through HR's own purge once nothing else
 * references it.
 */
class HrxEvidenceRetentionTest extends TestCase
{
    use CreatesHrxRetentionFixtures, CreatesTimetableFixtures, PurgesCommittedHrxFixtures {
        CreatesHrxRetentionFixtures::inSchool insteadof CreatesTimetableFixtures;
    }

    /** @var array<int, string> COMMITTED fixtures: the retention identity's connection cannot see an open test transaction. */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
        config(['retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8, 'retention.hold_school_ids' => []]);
    }

    protected function tearDown(): void
    {
        config(['retention.hold_school_ids' => []]);
        app(RetentionHolds::class)->synchronize();
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    private function prune(array $options = ['--only' => 'evidence']): PendingCommand
    {
        return $this->artisan('platform:employee-retention-prune', $options);
    }

    private function employeeExists(School $school, string $employeeId): bool
    {
        return $this->inSchool($school, fn () => DB::table('employees')->where('id', $employeeId)->exists());
    }

    private function cutoff(School $school): string
    {
        return CarbonImmutable::now($school->timezone)->subYearsNoOverflow(8)->toDateString();
    }

    private function schoolRows(School $school, string $table): int
    {
        return (int) $this->inSchool($school, fn () => DB::table($table)->where('school_id', $school->id)->count());
    }

    #[Test]
    public function the_whole_unit_expires_in_causal_order_and_configuration_audit_and_outbox_stay(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);
        foreach (self::HRX_EVIDENCE as $table) {
            $this->assertGreaterThan(0, $before[$table], "the fixture writes {$table}");
        }
        $configuration = $this->configurationRows($w['school']);
        $audit = $this->schoolRows($w['school'], 'school_audit_events');
        $outbox = DB::table('domain_event_outbox')->where('school_id', $w['school']->id)->count();
        $this->assertGreaterThan(0, $outbox);

        $this->prune()
            ->expectsOutputToContain('Deleted leave evidence of 1 Employee(s) and staff attendance evidence of 1 Employee(s) (dependency-blocked: 0, held: 0, errors: 0)')
            ->expectsOutputToContain('the employment evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0')
            ->assertSuccessful();

        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), $this->hrxRows($w['school'], $leaver['employeeId']));
        $this->assertFalse($this->employeeExists($w['school'], $leaver['employeeId']), 'after the HRX purge, HRX no longer keeps the Employee');
        $this->assertSame($configuration, $this->configurationRows($w['school']), 'School configuration is tenant lifetime');
        $this->assertSame($audit, $this->schoolRows($w['school'], 'school_audit_events'), 'audit has its own retention (D1)');
        $this->assertSame($outbox, DB::table('domain_event_outbox')->where('school_id', $w['school']->id)->count(), 'the outbox is never touched');
        // The current manager keeps everything.
        $this->assertSame(1, (int) $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $w['manager']['employment']->id)->count()));
        $this->assertSame(['employee', 'employee'], [RetentionMetrics::family(RetentionMetrics::LEAVE_EVIDENCE), RetentionMetrics::family(RetentionMetrics::STAFF_ATTENDANCE_EVIDENCE)], 'counts join the employee metric family');

        // Idempotent: a second run finds nothing and does not fail.
        $this->prune()->expectsOutputToContain('Deleted leave evidence of 0 Employee(s) and staff attendance evidence of 0 Employee(s) (dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_counts_the_whole_chain_and_deletes_nothing(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);

        $this->prune(['--only' => 'evidence', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: would delete leave evidence of 1 Employee(s) and staff attendance evidence of 1 Employee(s) (dependency-blocked: 0')
            ->expectsOutputToContain('the employment evidence of 1 Employee(s)')
            ->assertSuccessful();

        $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']));
        $this->assertTrue($this->employeeExists($w['school'], $leaver['employeeId']));
    }

    #[Test]
    public function a_recent_current_or_rehired_leaver_keeps_everything(): void
    {
        $w = $this->pastWorld();
        ['recent' => $recent, 'current' => $current, 'rehired' => $rehired] = $this->pastLeavers($w, ['recent' => '2019-06-30', 'current' => null, 'rehired' => '2016-04-30']);
        $this->createEmploymentRecord($this->inSchool($w['school'], fn () => $rehired['employment']->employee()->first()), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $snapshot = fn () => array_map(fn (array $l) => $this->hrxRows($w['school'], $l['employeeId']), [$recent, $current, $rehired]);
        $before = $snapshot();

        $this->prune()->expectsOutputToContain('Deleted leave evidence of 0 Employee(s) and staff attendance evidence of 0 Employee(s)')->assertSuccessful();

        $this->assertSame($before, $snapshot());
    }

    #[Test]
    public function a_late_write_is_new_evidence_that_keeps_its_unit_and_the_employee(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        // 2019: a correction of the 2015 record -- younger than the cutoff.
        $this->at('2019-06-01 10:00:00');
        $record = $this->inSchool($w['school'], fn () => StaffAttendanceRecord::query()->where('employee_id', $leaver['employeeId'])->firstOrFail());
        $this->correctAttendance(['school' => $w['school'], 'clerk' => $w['clerk']], $record, 'present', 'present', 'late_information');
        $this->travelBack();
        $this->assertGreaterThan($this->cutoff($w['school']), '2019-06-01', 'the correction is younger than the cutoff');

        $this->prune()
            ->expectsOutputToContain('Deleted leave evidence of 1 Employee(s) and staff attendance evidence of 0 Employee(s) (dependency-blocked: 1')
            ->expectsOutputToContain('the employment evidence of 0 Employee(s)')
            ->assertSuccessful();

        $rows = $this->hrxRows($w['school'], $leaver['employeeId']);
        $this->assertSame([1, 2], [$rows['staff_attendance_records'], $rows['staff_attendance_corrections']], 'the record keeps its whole history');
        $this->assertSame(0, $rows['leave_requests'], 'Leave is its own unit with its own (expired) clock');
        $this->assertTrue($this->employeeExists($w['school'], $leaver['employeeId']), 'unexpired HRX evidence keeps the Employee');
    }

    #[Test]
    public function expired_unpurged_hrx_evidence_blocks_employee_deletion_until_purged_and_other_domains_still_block(): void
    {
        $w = $this->pastWorld();
        ['leaver' => $leaver, 'teacher' => $teacher] = $this->pastLeavers($w, ['leaver' => '2016-04-30', 'teacher' => '2016-04-30']);
        // A timetable row references the teacher: Timetable keeps it, independently of HRX.
        $year = $this->createAcademicYear($w['school'], ['starts_on' => '2015-04-01', 'ends_on' => '2016-03-31', 'status' => 'active']);
        $campus = $this->createCampus($w['school']);
        $grade = $this->createGradeLevel($w['school']);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);
        $teacherModel = $this->inSchool($w['school'], fn () => $teacher['employment']->employee()->first());
        $this->createTimetableEntry($offering, $this->createSection($year, $campus, $grade, ['code' => 'A']), $teacherModel, $this->createTimetablePeriod($w['school']), ['day_of_week' => 1]);

        $cutoff = $this->cutoff($w['school']);
        $records = app(EmployeeRecordRetentionService::class);

        // HR alone, before the HRX participants: the expired-but-unpurged evidence blocks.
        $this->assertSame(['eligible' => 2, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 2, 'errors' => 0], $records->pruneEvidence($w['school'], $cutoff, 100, false));
        $this->assertTrue($this->employeeExists($w['school'], $leaver['employeeId']));

        $this->assertSame(2, app(LeaveEvidenceRetentionService::class)->prune($w['school'], $cutoff, 100, false)['deleted']);
        $this->assertSame(2, app(StaffAttendanceEvidenceRetentionService::class)->prune($w['school'], $cutoff, 100, false)['deleted']);

        // HRX no longer blocks; the timetable still does, on its own.
        $this->assertSame(['eligible' => 2, 'deleted' => 1, 'unresolved' => 0, 'dependency_blocked' => 1, 'errors' => 0], $records->pruneEvidence($w['school'], $cutoff, 100, false));
        $this->assertFalse($this->employeeExists($w['school'], $leaver['employeeId']));
        $this->assertTrue($this->employeeExists($w['school'], $teacher['employeeId']), 'other domains still block independently');
        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), $this->hrxRows($w['school'], $teacher['employeeId']));
    }

    #[Test]
    public function a_managers_decision_stays_with_the_requesters_request_and_keeps_the_managers_root(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w, '2019-06-30');
        $manager = $w['manager']['employment'];
        $this->inSchool($w['school'], function () use ($manager): void {
            DB::table('employment_records')->where('id', $manager->id)->update(['status' => 'separated', 'ends_on' => '2016-04-30']);
            DB::table('employees')->where('id', $manager->employee_id)->update(['user_id' => null]);
        });

        $leaverBefore = $this->hrxRows($w['school'], $leaver['employeeId']);

        $this->prune()->expectsOutputToContain('Deleted leave evidence of 1 Employee(s)')->assertSuccessful();

        $this->assertSame(0, (int) $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $manager->id)->count()), "the manager's own Leave evidence went");
        $this->assertSame(1, (int) $this->inSchool($w['school'], fn () => DB::table('leave_decisions')->where('decider_employee_id', $manager->employee_id)->count()), "the decision belongs to the requester's request");
        $this->assertSame($leaverBefore, $this->hrxRows($w['school'], $leaver['employeeId']), 'the recent leaver keeps everything');
        $this->assertTrue($this->employeeExists($w['school'], $manager->employee_id), 'the decision keeps the manager root until that request expires');
    }

    #[Test]
    public function a_held_school_counts_only_and_another_school_is_isolated(): void
    {
        $a = $this->pastWorld();
        $leaverA = $this->pastLeaver($a);
        $b = $this->pastWorld();
        $leaverB = $this->pastLeaver($b);
        config(['retention.hold_school_ids' => [$b['school']->id]]);
        $beforeB = $this->hrxRows($b['school'], $leaverB['employeeId']);
        $beforeA = $this->hrxRows($a['school'], $leaverA['employeeId']);

        // E21-RH.2 fail closed: a configured hold the database does not record yet refuses every destructive HRX unit.
        // One refused unit per unheld School and participant (the run walks every School, committed residue included).
        $errors = 2 * (School::query()->count() - 1);
        $this->prune()->expectsOutputToContain("Deleted leave evidence of 0 Employee(s) and staff attendance evidence of 0 Employee(s) (dependency-blocked: 0, held: 2, errors: {$errors})")->assertSuccessful();
        $this->assertSame($beforeA, $this->hrxRows($a['school'], $leaverA['employeeId']), 'nothing deleted while the hold state is stale');

        // The operator records the hold (maintenance connection); the run then proceeds for the other School only.
        $this->artisan('platform:retention-holds-sync')->assertSuccessful();
        $this->prune()->expectsOutputToContain('Deleted leave evidence of 1 Employee(s) and staff attendance evidence of 1 Employee(s) (dependency-blocked: 0, held: 2, errors: 0)')->assertSuccessful();

        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), $this->hrxRows($a['school'], $leaverA['employeeId']));
        $this->assertSame($beforeB, $this->hrxRows($b['school'], $leaverB['employeeId']), 'the held School keeps everything');
    }

    #[Test]
    public function a_reviewed_erasure_case_never_shortens_d9_and_runs_through_the_same_participants(): void
    {
        $w = $this->pastWorld();
        ['old' => $old, 'recent' => $recent] = $this->pastLeavers($w, ['old' => '2016-04-30', 'recent' => '2019-06-30']);
        $cases = app(ErasureCaseService::class);
        $approved = fn (string $employeeId) => $cases->decide($cases->open($w['school'], 'employee', $employeeId, 'written')->id, 'approve', 'request_valid');

        $before = $this->hrxRows($w['school'], $recent['employeeId']);
        $plan = collect($cases->execute($approved($recent['employeeId'])->id, false))->keyBy(fn (ErasureCategory $c) => $c->category);
        $this->assertSame('period_running', $plan['employee_evidence']->reason, 'an erasure request never shortens D9');
        $this->assertSame($before, $this->hrxRows($w['school'], $recent['employeeId']));

        $cases->execute($approved($old['employeeId'])->id, false);
        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), $this->hrxRows($w['school'], $old['employeeId']));
        $this->assertFalse($this->employeeExists($w['school'], $old['employeeId']));
    }

    #[Test]
    public function both_hrx_categories_are_adopted_and_closure_readiness_no_longer_waits_for_them(): void
    {
        foreach (['leave_evidence', 'staff_attendance_evidence'] as $category) {
            $this->assertSame(TenantRetentionCatalog::ADOPTED, TenantRetentionCatalog::CATEGORIES[$category][0]);
        }
        $this->assertNotContains(TenantRetentionCatalog::MECHANISM_PENDING, array_column(TenantRetentionCatalog::CATEGORIES, 0), 'every adopted period has its mechanism again');
        $this->assertContains('payroll_run_hrx_inputs', TenantRetentionCatalog::CATEGORIES['payroll_ledger'][2], 'the HRX snapshot is Payroll evidence');
    }
}
