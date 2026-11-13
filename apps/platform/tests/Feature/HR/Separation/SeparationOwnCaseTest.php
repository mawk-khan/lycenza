<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\HR\Infrastructure\SeparationClearanceItem;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-18 (C34-N3, owner decision D-C35-9): "never one's own" is person-level for every
 * separation write. The separating Employee E was linked to account OLD, then relinked to NEW (a replaced
 * account): OLD holds every separation key and is refused at record, withdraw, last-day change, cancel,
 * clearance, finalize, access-retained and complete in the service, while
 * an independent HR officer does each. A known-same account of OLD is refused too; after an approved
 * correction voids OLD's link, OLD is no longer the separating person. The decisions keep C34's own codes.
 * The raw runtime role is refused for record, withdraw, clearance, cancel, access-retained, complete and a
 * write that sets two actor columns at once; changing the last day records no actor and is service-only.
 */
class SeparationOwnCaseTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private User $approver;

    private User $old;

    private User $new;

    private Employee $employee;

    private EmploymentRecord $employment;

    private const KEYS = [
        'hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
        'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure', 'hr.separations.approve', 'school.members.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, self::KEYS);
        $this->approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->old = $this->createUserWithCapabilities($this->school, self::KEYS);
        $this->new = $this->createUser();
        $this->createMembership($this->new, $this->school);
        app(TenantContext::class)->set($this->school);
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->employment = app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2022-01-01'], $this->hr);
        $this->relink($this->employee, $this->old->id, null, $this->new->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function relink(Employee $employee, ?string ...$userIds): void
    {
        foreach ($userIds as $userId) {
            Employee::query()->whereKey($employee->id)->update(['user_id' => $userId]);
        }
    }

    private function cases(): SeparationCaseService
    {
        return app(SeparationCaseService::class);
    }

    private function code(callable $work): string
    {
        try {
            DB::transaction($work);

            return 'ok';
        } catch (SeparationException $e) {
            return $e->errorCode();
        } catch (HrSelfAdministrationException) {
            return 'HR_SELF_ADMINISTRATION';
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 300);
        }
    }

    private function fresh(EmploymentSeparationCase $case): EmploymentSeparationCase
    {
        return EmploymentSeparationCase::query()->findOrFail($case->id);
    }

    private function record(User $actor): EmploymentSeparationCase
    {
        return $this->cases()->record($this->school, $this->employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $actor);
    }

    /** Every separation write OLD attempts is refused and changes nothing; the independent officer does each. */
    #[Test]
    public function the_separating_persons_old_account_never_administers_their_case_at_any_step(): void
    {
        app(SeparationClearanceRequirementService::class)->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $this->hr);

        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->record($this->old)));
        $this->assertSame(0, EmploymentSeparationCase::query()->count());
        $case = $this->record($this->hr);

        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->withdraw($this->school, $case->id, $case->version, $this->old)));
        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->changePlannedLastDay($this->school, $case->id, $case->version, '2026-07-30', $this->old)));
        $this->assertSame(['requested', '2026-07-31'], [$this->fresh($case)->status, $this->fresh($case)->planned_last_day->toDateString()]);

        // The decisions keep C34's check and code: OLD is known to be the separating person.
        $this->assertSame('SEPARATION_SAME_PERSON_DECISION', $this->code(fn () => $this->cases()->approve($this->school, $case->id, $case->version, $this->old, $this->freshMfaProofFor($this->old))));
        $case = $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver));

        $attempted = [];
        DB::connection()->beforeExecuting(function (string $query) use (&$attempted): void {
            $attempted[] = strtolower($query);
        });
        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->cancel($this->school, $case->id, $case->version, SeparationCancelReason::RECORDED_IN_ERROR, $this->old, $this->freshMfaProofFor($this->old))));
        // Every statement sent to PostgreSQL, the refused ones included.
        $writes = array_filter($attempted, fn (string $q) => str_contains($q, 'update "employment_separation_cases"'));
        $this->assertSame([], array_values($writes), 'the service refuses before the case is written; the database guard is the backstop');
        $item = $case->clearanceItems()->firstOrFail();
        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->clearItem($this->school, $case->id, $item->id, $this->old)));
        $this->assertSame(['approved', 'pending'], [$this->fresh($case)->status, SeparationClearanceItem::query()->findOrFail($item->id)->status]);

        Carbon::setTestNow('2026-07-31 18:00:00');
        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->finalize($this->school, $case->id, $case->version, $this->old, $this->freshMfaProofFor($this->old))));
        $this->assertSame('approved', $this->fresh($case)->status);
        $case = $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));

        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->recordAccessRetained($this->school, $case->id, $case->version, $this->old)));
        $this->cases()->clearItem($this->school, $case->id, $item->id, $this->hr);
        $case = $this->fresh($case);
        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->complete($this->school, $case->id, $case->version, $this->old)));
        $this->assertSame('finalized', $this->fresh($case)->status);

        $this->assertSame('completed', $this->cases()->complete($this->school, $case->id, $case->version, $this->hr)->status, 'an independent officer completes it');
    }

    #[Test]
    public function a_known_same_account_of_the_old_account_is_refused_and_the_current_account_keeps_its_own_refusal(): void
    {
        // OLD and ALT share a duplicate Employee: ALT is known to be OLD, who is known to be the separating person.
        $alt = $this->createUserWithCapabilities($this->school, self::KEYS);
        $this->relink($this->createEmployee($this->school, ['user_id' => null]), $this->old->id, null, $alt->id);
        $case = $this->record($this->hr);

        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->withdraw($this->school, $case->id, $case->version, $alt)));

        // The current account keeps the existing current-link refusal and its code.
        $current = $this->createUserWithCapabilities($this->school, self::KEYS);
        $this->relink($this->employee, null, $current->id);
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->code(fn () => $this->cases()->withdraw($this->school, $case->id, $case->version, $current)));
        $this->assertSame('requested', $this->fresh($case)->status);
    }

    #[Test]
    public function after_an_approved_correction_voids_the_old_link_the_old_account_is_not_the_separating_person(): void
    {
        $case = $this->record($this->hr);
        $this->assertSame('SEPARATION_OWN_CASE', $this->code(fn () => $this->cases()->withdraw($this->school, $case->id, $case->version, $this->old)), 'refused before the correction');

        $link = EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->where('user_id', $this->old->id)->firstOrFail();
        $this->correctLink(Employee::query()->findOrFail($this->employee->id), $link->id);

        $this->assertSame('withdrawn', $this->cases()->withdraw($this->school, $case->id, $case->version, $this->old)->status, 'allowed after it');
    }

    #[Test]
    public function the_runtime_role_cannot_record_any_separation_write_by_the_separating_persons_old_account(): void
    {
        app(SeparationClearanceRequirementService::class)->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $this->hr);
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);

        // INSERT: a case recorded by OLD.
        $insert = fn (User $as) => $this->code(fn () => DB::insert(
            "INSERT INTO employment_separation_cases (id, school_id, employment_record_id, reason_code, notified_on, planned_last_day, requested_by_user_id, requested_at, status, version) VALUES (?, ?, ?, 'resignation', '2026-06-15', '2026-07-31', ?, now(), 'requested', 1)",
            [(string) Str::uuid7(), $this->school->id, $this->employment->id, $as->id],
        ));
        $this->assertStringContainsString('hr_employment_separation_own_case', $insert($this->old));
        $case = $this->record($this->hr);

        // UPDATE: a withdrawal recorded by OLD.
        $withdraw = fn (User $as) => $this->code(fn () => DB::update(
            "UPDATE employment_separation_cases SET status = 'withdrawn', withdrawn_by_user_id = ?, withdrawn_at = now(), version = version + 1 WHERE id = ?",
            [$as->id, $case->id],
        ));
        $this->assertStringContainsString('hr_employment_separation_own_case', $withdraw($this->old));
        $this->assertSame('requested', $this->fresh($case)->status);

        // A clearance item cleared by OLD.
        $case = $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver));
        $item = $case->clearanceItems()->firstOrFail();
        $clear = fn (User $as) => $this->code(fn () => DB::update(
            "UPDATE separation_clearance_items SET status = 'cleared', cleared_by_user_id = ?, cleared_at = now() WHERE id = ?",
            [$as->id, $item->id],
        ));
        $this->assertStringContainsString('hr_separation_clearance_own_case', $clear($this->old));
        $this->assertSame('ok', $clear($this->hr), 'the positive control');

        // A cancellation recorded by OLD; the independent officer's passes.
        $cancel = fn (User $as) => $this->code(fn () => DB::update(
            "UPDATE employment_separation_cases SET status = 'cancelled', cancelled_by_user_id = ?, cancelled_at = now(), cancel_reason = 'recorded_in_error', version = version + 1 WHERE id = ?",
            [$as->id, $case->id],
        ));
        $this->assertStringContainsString('hr_employment_separation_own_case', $cancel($this->old));

        // A raw writer setting two actor columns at once is judged on each (review L-2): a cancellation by the
        // independent officer that also names OLD as the completer is refused.
        $both = $this->code(fn () => DB::update(
            "UPDATE employment_separation_cases SET status = 'cancelled', cancelled_by_user_id = ?, cancelled_at = now(), cancel_reason = 'recorded_in_error', completed_by_user_id = ?, version = version + 1 WHERE id = ?",
            [$this->hr->id, $this->old->id, $case->id],
        ));
        $this->assertStringContainsString('hr_employment_separation_own_case', $both);
        $this->assertSame('ok', $cancel($this->hr), 'the positive control');
    }

    #[Test]
    public function the_runtime_role_cannot_record_access_retained_or_completion_by_the_old_account(): void
    {
        $case = $this->record($this->hr);
        $case = $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver));
        Carbon::setTestNow('2026-07-31 18:00:00');
        $case = $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));

        $retain = fn (User $as) => $this->code(fn () => DB::update(
            'UPDATE employment_separation_cases SET access_retained_by_user_id = ?, access_retained_at = now(), version = version + 1 WHERE id = ?', [$as->id, $case->id],
        ));
        $complete = fn (User $as) => $this->code(fn () => DB::update(
            "UPDATE employment_separation_cases SET status = 'completed', completed_by_user_id = ?, completed_at = now(), version = version + 1 WHERE id = ?", [$as->id, $case->id],
        ));
        $this->assertStringContainsString('hr_employment_separation_own_case', $retain($this->old));
        $this->assertStringContainsString('hr_employment_separation_own_case', $complete($this->old));
        $this->assertSame('finalized', $this->fresh($case)->status);
        $this->assertSame('ok', $complete($this->hr), 'the positive control');
    }
}
