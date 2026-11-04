<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseReadService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\User;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.12 (owner decisions HR-061, 2026-11-04): the separation case end to
 * end, its authorization (allow and deny), maker/checker, "never one's own",
 * cross-School isolation, stale forms, the notice-period transition, the
 * clearance snapshot, the owners' obligations and the database backstops.
 */
class SeparationCaseServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private User $approver;

    private Employee $employee;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
            'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure',
        ]);
        $this->approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        // The separation tables are tenant-owned (forced RLS): this test reads them as its School.
        app(TenantContext::class)->set($this->school);
        $this->employee = $this->createEmployee($this->school);
        $this->employment = app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2022-01-01'], $this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cases(): SeparationCaseService
    {
        return app(SeparationCaseService::class);
    }

    private function record(string $lastDay = '2026-07-31', SeparationReason $reason = SeparationReason::RESIGNATION, ?User $actor = null): EmploymentSeparationCase
    {
        return $this->cases()->record($this->school, $this->employment->id, $reason, '2026-06-15', $lastDay, $actor ?? $this->hr);
    }

    private function approve(EmploymentSeparationCase $case, ?User $actor = null): EmploymentSeparationCase
    {
        $actor ??= $this->approver;

        return $this->cases()->approve($this->school, $case->id, $case->version, $actor, $this->freshMfaProofFor($actor));
    }

    private function employmentStatus(): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => (string) EmploymentRecord::query()->whereKey($this->employment->id)->value('status'));
    }

    private function code(callable $work): string
    {
        try {
            $work();
        } catch (SeparationException $e) {
            return $e->errorCode();
        } catch (HrSelfAdministrationException) {
            return 'HR_SELF_ADMINISTRATION';
        } catch (AuthorizationException) {
            return 'FORBIDDEN';
        }
        $this->fail('Expected a refusal.');
    }

    #[Test]
    public function a_recorded_separation_is_approved_by_another_person_then_finalized_and_completed(): void
    {
        app(SeparationClearanceRequirementService::class)->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $this->hr);

        $case = $this->record();
        $this->assertSame('requested', $case->status);
        $this->assertSame('active', $this->employmentStatus(), 'recording changes nothing');

        $case = $this->approve($case);
        $this->assertSame('approved', $case->status);
        $this->assertSame('notice_period', $this->employmentStatus());
        $this->assertSame(1, $case->clearanceItems()->count(), 'the checklist is taken at approval');

        $this->assertSame('SEPARATION_LAST_DAY_NOT_REACHED', $this->code(fn () => $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr))));

        Carbon::setTestNow('2026-07-31 18:00:00');
        $case = $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
        $this->assertSame('finalized', $case->status);
        $this->assertSame('2026-07-31', $case->final_ends_on->toDateString());
        $ended = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->find($this->employment->id));
        $this->assertSame('separated', $ended->status);
        $this->assertSame('2026-07-31', $ended->ends_on->toDateString());

        $this->assertSame('SEPARATION_CLEARANCE_PENDING', $this->code(fn () => $this->cases()->complete($this->school, $case->id, $case->version, $this->hr)));

        $item = $case->clearanceItems()->firstOrFail();
        $this->cases()->clearItem($this->school, $case->id, $item->id, $this->hr);
        $case = $this->cases()->complete($this->school, $case->id, $case->refresh()->version, $this->hr);
        $this->assertSame('completed', $case->status);

        $kinds = DB::table('employment_separation_events')->where('case_id', $case->id)->orderBy('occurred_at')->orderBy('id')->pluck('kind')->all();
        $this->assertSame(['requested', 'approved', 'finalized', 'clearance_cleared', 'completed'], $kinds);
    }

    #[Test]
    public function a_retirement_ends_the_employment_as_retired(): void
    {
        $case = $this->approve($this->record('2026-06-15', SeparationReason::RETIREMENT));
        $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));

        $this->assertSame('retired', $this->employmentStatus());
    }

    #[Test]
    public function whoever_recorded_the_separation_never_decides_it(): void
    {
        $both = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.manage', 'hr.separations.approve']);
        $case = $this->record(actor: $both);

        $this->assertSame('SEPARATION_PARTICIPANT_DECISION', $this->code(fn () => $this->approve($case, $both)));
        $this->assertSame('SEPARATION_PARTICIPANT_DECISION', $this->code(fn () => $this->cases()->reject($this->school, $case->id, $case->version, $both)));
        $this->assertSame('requested', $case->refresh()->status);
    }

    #[Test]
    public function only_the_approve_key_decides_and_the_line_manager_leave_key_never_does(): void
    {
        $case = $this->record();
        $lineManager = $this->createUserWithCapabilities($this->school, ['hr.leave.approve', 'hr.separations.view']);
        $admin = $this->createUserWithCapabilities($this->school, ['school.settings.manage', 'school.members.manage', 'hr.employees.assignments.manage']);

        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->approve($case, $this->hr)), 'HR records, never approves');
        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->approve($case, $lineManager)), 'hr.leave.approve is not separation authority');
        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->approve($case, $admin)), 'no administrative key implies it');
        $this->assertSame('active', $this->employmentStatus());
    }

    #[Test]
    public function recording_needs_the_manage_key(): void
    {
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.employees.assignments.manage']);

        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->record(actor: $viewer)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->record(actor: $this->approver)));
    }

    #[Test]
    public function nobody_records_decides_or_finalizes_their_own_separation(): void
    {
        $self = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.manage', 'hr.separations.approve', 'hr.employees.assignments.manage']);
        app(TenantContext::class)->withSchool($this->school, fn () => Employee::query()->whereKey($this->employee->id)->update(['user_id' => $self->id]));

        $this->assertSame('HR_SELF_ADMINISTRATION', $this->code(fn () => $this->record(actor: $self)));

        $case = $this->record();
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->code(fn () => $this->approve($case, $self)));
        $case = $this->approve($case);
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->code(fn () => $this->cases()->finalize($this->school, $case->id, $case->version, $self, $this->freshMfaProofFor($self))));
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->code(fn () => $this->cases()->cancel($this->school, $case->id, $case->version, SeparationCancelReason::RECORDED_IN_ERROR, $self, $this->freshMfaProofFor($self))));
    }

    #[Test]
    public function another_schools_case_is_not_found(): void
    {
        $case = $this->record();
        $other = $this->createSchool();
        $otherApprover = $this->createUserWithCapabilities($other, ['hr.separations.view', 'hr.separations.approve']);

        $this->assertSame('SEPARATION_NOT_FOUND', $this->code(fn () => $this->cases()->approve($other, $case->id, 1, $otherApprover, $this->freshMfaProofFor($otherApprover))));
        $this->assertSame('SEPARATION_NOT_FOUND', $this->code(fn () => app(SeparationCaseReadService::class)->detail($other, $otherApprover, $case->id)));
        $this->assertSame('FORBIDDEN', $this->code(fn () => app(SeparationCaseReadService::class)->detail($this->school, $otherApprover, $case->id)));
        $this->assertSame(0, app(SeparationCaseReadService::class)->page($other, $otherApprover, [])['total']);
    }

    #[Test]
    public function a_rejected_or_withdrawn_separation_leaves_the_employment_untouched_and_is_final(): void
    {
        $case = $this->cases()->reject($this->school, $this->record()->id, 1, $this->approver);
        $this->assertSame('rejected', $case->status);
        $this->assertSame('active', $this->employmentStatus());
        $this->assertSame('SEPARATION_INVALID_STATE', $this->code(fn () => $this->approve($case)));

        $again = $this->record();
        $this->assertSame('withdrawn', $this->cases()->withdraw($this->school, $again->id, 1, $this->hr)->status);
        $this->assertSame('active', $this->employmentStatus());
    }

    #[Test]
    public function one_live_case_per_employment_and_only_for_an_active_employment(): void
    {
        $this->record();
        $this->assertSame('SEPARATION_CASE_EXISTS', $this->code(fn () => $this->record()));

        $ended = $this->createEmployee($this->school);
        $endedEmployment = app(EmploymentService::class)->create($ended, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);
        app(EmploymentService::class)->end($endedEmployment, '2026-06-01', $this->hr);
        $this->assertSame('SEPARATION_EMPLOYMENT_NOT_ACTIVE', $this->code(fn () => $this->cases()->record($this->school, $endedEmployment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $this->hr)));
    }

    #[Test]
    public function dates_are_validated_on_the_schools_calendar(): void
    {
        $this->assertSame('SEPARATION_NOTIFIED_IN_FUTURE', $this->code(fn () => $this->cases()->record($this->school, $this->employment->id, SeparationReason::RESIGNATION, '2026-06-16', '2026-07-31', $this->hr)));
        $this->assertSame('SEPARATION_LAST_DAY_BEFORE_NOTICE', $this->code(fn () => $this->record('2026-06-14')));
        $this->assertSame('SEPARATION_INVALID_DATE', $this->code(fn () => $this->record('31/07/2026')));
    }

    #[Test]
    public function a_stale_form_is_refused_and_a_changed_last_day_keeps_its_before_and_after(): void
    {
        $case = $this->record();
        $this->assertSame('SEPARATION_STALE', $this->code(fn () => $this->cases()->changePlannedLastDay($this->school, $case->id, $case->version + 1, '2026-08-31', $this->hr)));

        $changed = $this->cases()->changePlannedLastDay($this->school, $case->id, $case->version, '2026-08-31', $this->hr);
        $this->assertSame('2026-08-31', $changed->planned_last_day->toDateString());
        $event = DB::table('employment_separation_events')->where('case_id', $case->id)->where('kind', 'planned_day_changed')->first();
        $this->assertSame(['2026-07-31', '2026-08-31'], [$event->planned_last_day_before, $event->planned_last_day_after]);

        $this->assertSame('SEPARATION_STALE', $this->code(fn () => $this->approve($case)));
    }

    #[Test]
    public function an_approved_last_day_is_a_decided_term_that_hr_alone_cannot_move(): void
    {
        $case = $this->approve($this->record('2026-09-30'));

        $this->assertSame('SEPARATION_INVALID_STATE', $this->code(fn () => $this->cases()->changePlannedLastDay($this->school, $case->id, $case->version, '2026-06-15', $this->hr)));
        $this->assertSame('SEPARATION_LAST_DAY_NOT_REACHED', $this->code(fn () => $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr))));

        try {
            DB::transaction(fn () => DB::update("UPDATE employment_separation_cases SET planned_last_day = '2026-06-15', version = version + 1 WHERE id = ?", [$case->id]));
            $this->fail('the database moved an approved last day');
        } catch (QueryException) {
            $this->assertSame('2026-09-30', $case->refresh()->planned_last_day->toDateString());
        }
    }

    #[Test]
    public function cancelling_an_approved_separation_returns_the_employment_to_active(): void
    {
        $case = $this->approve($this->record());
        $cancelled = $this->cases()->cancel($this->school, $case->id, $case->version, SeparationCancelReason::RESIGNATION_RETRACTED, $this->hr, $this->freshMfaProofFor($this->hr));

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('resignation_retracted', $cancelled->cancel_reason);
        $this->assertSame('active', $this->employmentStatus());
        $this->record(); // a new resignation may be recorded later
    }

    #[Test]
    public function clearance_is_a_snapshot_taken_at_approval_by_staff_type_and_cleared_only_by_its_responsibility(): void
    {
        $requirements = app(SeparationClearanceRequirementService::class);
        $requirements->create($this->school, 'id_card', 'ID card returned', ClearanceResponsibility::SCHOOL_ADMINISTRATION, true, null, $this->hr);
        $requirements->create($this->school, 'lab', 'Laboratory handover', ClearanceResponsibility::HR, false, StaffType::TEACHING, $this->hr);
        $requirements->create($this->school, 'kitchen', 'Kitchen handover', ClearanceResponsibility::HR, true, StaffType::NON_TEACHING, $this->hr);

        $case = $this->approve($this->record());
        $items = $case->clearanceItems()->orderBy('sequence')->get();
        $this->assertSame(['ID_CARD', 'LAB'], $items->pluck('code')->all(), 'only the applicable staff types');

        $retired = $requirements->create($this->school, 'late', 'Added later', ClearanceResponsibility::HR, true, null, $this->hr);
        $requirements->retire($this->school, $items[0]->requirement_id, $this->hr);
        $this->assertSame(2, $case->clearanceItems()->count(), 'a later configuration change never rewrites an approved case');
        $this->assertSame('active', $retired->refresh()->status);

        $idCard = $items[0];
        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->cases()->clearItem($this->school, $case->id, $idCard->id, $this->hr)), 'HR cannot clear a School-administration item');
        $admin = $this->createUserWithCapabilities($this->school, ['school.settings.manage']);
        $this->cases()->clearItem($this->school, $case->id, $idCard->id, $admin);
        $this->assertSame('SEPARATION_CLEARANCE_ALREADY_CLEARED', $this->code(fn () => $this->cases()->clearItem($this->school, $case->id, $idCard->id, $admin)));
    }

    #[Test]
    public function the_subject_never_clears_their_own_item(): void
    {
        app(SeparationClearanceRequirementService::class)->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::SCHOOL_ADMINISTRATION, true, null, $this->hr);
        $case = $this->approve($this->record());
        $self = $this->createUserWithCapabilities($this->school, ['school.settings.manage']);
        app(TenantContext::class)->withSchool($this->school, fn () => Employee::query()->whereKey($this->employee->id)->update(['user_id' => $self->id]));

        $this->assertSame('HR_SELF_ADMINISTRATION', $this->code(fn () => $this->cases()->clearItem($this->school, $case->id, $case->clearanceItems()->firstOrFail()->id, $self)));
    }

    #[Test]
    public function remaining_school_access_blocks_completion_until_offboarded_or_recorded_as_kept(): void
    {
        $user = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        app(TenantContext::class)->withSchool($this->school, fn () => Employee::query()->whereKey($this->employee->id)->update(['user_id' => $user->id]));
        $case = $this->approve($this->record('2026-06-15'));
        $case = $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));

        $access = collect(app(SeparationObligations::class)->forCase($this->school, $case))->firstWhere('code', SeparationObligations::SCHOOL_ACCESS);
        $this->assertSame(SeparationObligation::OPEN, $access->state, 'separation never suspends access by itself');
        $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $this->code(fn () => $this->cases()->complete($this->school, $case->id, $case->version, $this->hr)));

        $this->assertSame('FORBIDDEN', $this->code(fn () => $this->cases()->recordAccessRetained($this->school, $case->id, $case->version, $this->hr)));
        $members = $this->createUserWithCapabilities($this->school, ['school.members.manage']);
        $case = $this->cases()->recordAccessRetained($this->school, $case->id, $case->version, $members);
        $this->assertSame('completed', $this->cases()->complete($this->school, $case->id, $case->version, $this->hr)->status);
        $this->assertSame('active', DB::table('school_memberships')->where('user_id', $user->id)->value('status'));
    }

    #[Test]
    public function owners_that_record_no_staff_obligation_are_shown_as_not_integrated_never_clear(): void
    {
        $case = $this->approve($this->record());
        $byCode = collect(app(SeparationObligations::class)->forCase($this->school, $case))->keyBy('code');

        foreach (array_keys(SeparationObligations::NOT_INTEGRATED) as $code) {
            $this->assertSame(SeparationObligation::NOT_INTEGRATED, $byCode[$code]->state, $code);
        }
        $this->assertSame(SeparationObligation::CLEAR, $byCode['leave_pending_requests']->state);
        $this->assertSame(SeparationObligation::CLEAR, $byCode['timetable_entries']->state);
        $this->assertSame(SeparationObligation::CLEAR, $byCode['transport_driver_routes']->state);
    }

    #[Test]
    public function a_separating_managers_reports_are_left_without_a_manager_never_reassigned(): void
    {
        $reportEmployee = $this->createEmployee($this->school);
        $report = app(EmploymentService::class)->create($reportEmployee, ['employment_type' => 'permanent', 'starts_on' => '2023-01-01'], $this->hr);
        $this->setReportingLineFixture($report, $this->employment, '2024-01-01');

        $case = $this->approve($this->record('2026-06-15'));
        $before = collect(app(SeparationObligations::class)->forCase($this->school, $case))->firstWhere('code', SeparationObligations::DIRECT_REPORTS);
        $this->assertSame([SeparationObligation::ATTENTION, 1], [$before->state, $before->count]);

        Carbon::setTestNow('2026-06-16 09:00:00');
        $case = $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
        $after = collect(app(SeparationObligations::class)->forCase($this->school, $case))->firstWhere('code', SeparationObligations::DIRECT_REPORTS);
        $this->assertSame([SeparationObligation::ATTENTION, 1], [$after->state, $after->count]);
        $this->assertSame(0, DB::table('reporting_relationships')->where('subordinate_employment_id', $report->id)->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', '2026-06-15'))->count(), 'no successor is invented');
    }

    #[Test]
    public function only_a_death_is_recorded_directly_and_never_over_a_case_in_progress(): void
    {
        $direct = app(EmployeeLifecycleService::class);
        foreach (['separated', 'terminated', 'retired'] as $status) {
            $this->assertSame('SEPARATION_USE_CASE', $this->code(fn () => $direct->separate($this->employment, '2026-06-15', $this->hr, $this->freshMfaProofFor($this->hr), $status)));
        }

        $this->record();
        $this->assertSame('SEPARATION_CASE_IN_PROGRESS', $this->code(fn () => $direct->separate($this->employment, '2026-06-15', $this->hr, $this->freshMfaProofFor($this->hr), 'deceased')));
    }

    #[Test]
    public function the_database_refuses_self_decision_skipped_states_rewritten_terms_and_deletion(): void
    {
        $case = $this->record();
        $raw = fn (string $sql, array $bindings = []) => app(TenantContext::class)->withSchool($this->school, fn () => DB::update($sql, $bindings));

        foreach ([
            ["UPDATE employment_separation_cases SET status = 'approved', decided_by_user_id = requested_by_user_id, decided_at = now(), version = version + 1 WHERE id = ?", 'maker/checker'],
            ["UPDATE employment_separation_cases SET status = 'finalized', version = version + 1 WHERE id = ?", 'skipped state'],
            ["UPDATE employment_separation_cases SET reason_code = 'retirement', version = version + 1 WHERE id = ?", 'immutable terms'],
            ["UPDATE employment_separation_cases SET planned_last_day = '2026-08-31' WHERE id = ?", 'version advances'],
        ] as [$sql, $why]) {
            try {
                DB::transaction(fn () => $raw($sql, [$case->id]));
                $this->fail("The database accepted: {$why}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            DB::transaction(fn () => $raw("UPDATE employment_records SET status = 'notice_period' WHERE id = ?", [$this->employment->id]));
            $this->fail('notice_period without an approved case');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::transaction(fn () => app(TenantContext::class)->withSchool($this->school, fn () => DB::delete('DELETE FROM employment_separation_cases WHERE id = ?', [$case->id])));
            $this->fail('the runtime role deleted separation evidence');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function the_database_verifier_checks_the_separation_backstops(): void
    {
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');

        $this->assertSame(CheckResult::PASS, $checks['hr_separation_invariants']->status);
    }
}
