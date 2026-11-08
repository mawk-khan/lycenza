<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceLockedCorrection;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C18 (WO-6; S-6 decided in HR.R1-C17): a School-local calendar month is locked for good,
 * and changes only by a correction someone else approves, with fresh MFA, the original kept.
 */
class StaffAttendanceMonthLockTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    /** @var array<string, mixed> */
    private array $w;

    private User $principal;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->w = $this->attendanceWorld();
        $this->principal = $this->lockHolder();
        $this->head = $this->lockHolder();
    }

    private function lockHolder(): User
    {
        return $this->createUserWithCapabilities($this->w['school'], ['hr.staff_attendance.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.lock']);
    }

    private function service(): StaffAttendanceService
    {
        return app(StaffAttendanceService::class);
    }

    private function lock(string $month = '2026-10', ?User $by = null): void
    {
        $by ??= $this->principal;
        $this->service()->lockMonth($this->w['school'], $month, $by, $this->freshMfaProofFor($by));
    }

    private function request(StaffAttendanceRecord $record, ?string $first, ?string $second, ?User $by = null, ?string $key = null): StaffAttendanceLockedCorrection
    {
        return $this->service()->requestLockedCorrection($this->w['school'], $record->id, $record->version, $first, $second, 'late_information', $key ?? (string) Str::uuid(), $by ?? $this->w['clerk']);
    }

    private function refusal(callable $command): string
    {
        try {
            $command();
        } catch (StaffAttendanceException $e) {
            return $e->errorCode();
        }

        return 'accepted';
    }

    private function row(string $id): StaffAttendanceRecord
    {
        return $this->inSchool($this->w['school'], fn () => StaffAttendanceRecord::query()->findOrFail($id));
    }

    private function audits(string $type): int
    {
        return $this->inSchool($this->w['school'], fn () => DB::table('school_audit_events')->where('event_type', $type)->count());
    }

    #[Test]
    public function a_locked_month_takes_no_record_register_or_ordinary_correction_and_other_months_stay_open(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();

        $this->assertSame('STAFF_ATTENDANCE_MONTH_LOCKED', $this->refusal(fn () => $this->recordAttendance($this->w, '2026-10-02', 'present', 'present')));
        $this->assertSame('STAFF_ATTENDANCE_MONTH_LOCKED', $this->refusal(fn () => $this->service()->recordRegister($this->w['school'], '2026-10-02', [['employment_record_id' => $this->w['employment']->id, 'first_half' => 'present', 'second_half' => 'absent']], $this->w['clerk'])));
        $this->assertSame('STAFF_ATTENDANCE_MONTH_LOCKED', $this->refusal(fn () => $this->correctAttendance($this->w, $record, 'absent', 'present')));
        $this->assertSame(['present', 'present', 1], [$this->row($record->id)->first_half_status, $this->row($record->id)->second_half_status, $this->row($record->id)->version]);

        $this->assertSame('accepted', $this->refusal(fn () => $this->recordAttendance($this->w, '2026-09-30', 'present', 'present')));
    }

    #[Test]
    public function locking_needs_the_lock_key_its_own_fresh_code_a_begun_month_and_happens_once(): void
    {
        $this->expectsRefusal(AuthorizationException::class, fn () => $this->service()->lockMonth($this->w['school'], '2026-10', $this->w['clerk'], $this->freshMfaProofFor($this->w['clerk'])));
        $this->expectsRefusal(LogicException::class, fn () => $this->service()->lockMonth($this->w['school'], '2026-10', $this->principal, $this->freshMfaProofFor($this->head)));
        $this->assertSame('STAFF_ATTENDANCE_MONTH_NOT_ENDED', $this->refusal(fn () => $this->lock('2026-11')), 'the current month: the rest of it could never be recorded');
        $this->assertSame('STAFF_ATTENDANCE_MONTH_NOT_ENDED', $this->refusal(fn () => $this->lock('2026-12')));
        $this->assertSame('STAFF_ATTENDANCE_MONTH_INVALID', $this->refusal(fn () => $this->lock('2026-13')));

        $this->lock();
        $this->assertSame('STAFF_ATTENDANCE_MONTH_ALREADY_LOCKED', $this->refusal(fn () => $this->lock('2026-10', $this->head)));
        $this->assertSame(1, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->count()));
        $this->assertSame(1, $this->audits('staff_attendance.month_locked'));
    }

    #[Test]
    public function a_campus_grant_of_the_lock_key_locks_nothing(): void
    {
        $campus = $this->createCampus($this->w['school']);
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->w['school']), $this->createFixtureRole(['hr.staff_attendance.view', 'hr.staff_attendance.lock']), $campus->id);

        $this->expectsRefusal(AuthorizationException::class, fn () => $this->service()->lockMonth($this->w['school'], '2026-10', $user, $this->freshMfaProofFor($user)));
        $this->assertSame(0, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->count()));
    }

    #[Test]
    public function a_locked_month_changes_only_by_another_lock_key_holder_approving_a_request_and_stays_locked(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();

        $request = $this->request($record, 'absent', 'present', $this->principal);
        $this->assertSame(['present', 1], [$this->row($record->id)->first_half_status, $this->row($record->id)->version], 'a request changes nothing');

        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_NOT_INDEPENDENT', $this->refusal(fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->principal, $this->freshMfaProofFor($this->principal))));
        $this->expectsRefusal(AuthorizationException::class, fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->w['clerk'], $this->freshMfaProofFor($this->w['clerk'])));
        $this->expectsRefusal(LogicException::class, fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->principal)));

        $approved = $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head));

        $this->assertSame('approved', $approved->status);
        $this->assertSame(['absent', 'present', 2], [$this->row($record->id)->first_half_status, $this->row($record->id)->second_half_status, $this->row($record->id)->version]);
        $correction = $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_corrections')->where('staff_attendance_record_id', $record->id)->sole());
        $this->assertSame([$request->id, $this->head->id, 'present', 'absent', 1, 2], [$correction->locked_correction_id, $correction->corrected_by_user_id, $correction->before_first_half_status, $correction->after_first_half_status, $correction->from_version, $correction->to_version]);
        $this->assertSame(1, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->count()), 'the month stays locked');
        $this->assertSame([1, 1, 1], [$this->audits('staff_attendance.locked_correction_requested'), $this->audits('staff_attendance.locked_correction_approved'), $this->audits('staff_attendance.corrected')]);

        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_DECIDED', $this->refusal(fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head))));
        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_DECIDED', $this->refusal(fn () => $this->service()->refuseLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head))));
        $this->assertSame('STAFF_ATTENDANCE_MONTH_LOCKED', $this->refusal(fn () => $this->correctAttendance($this->w, $this->row($record->id), 'present', 'present')));
    }

    #[Test]
    public function a_refusal_changes_nothing_and_is_final(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();
        $request = $this->request($record, 'absent', null);

        $this->service()->refuseLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head));

        $this->assertSame(['present', 1], [$this->row($record->id)->first_half_status, $this->row($record->id)->version]);
        $this->assertSame(0, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_corrections')->count()));
        $this->assertSame(1, $this->audits('staff_attendance.locked_correction_refused'));
        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_DECIDED', $this->refusal(fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->principal, $this->freshMfaProofFor($this->principal))));
    }

    #[Test]
    public function the_employee_concerned_never_decides_even_through_an_ended_link(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();
        $request = $this->request($record, 'absent', 'present');
        $hr = $this->createUserWithCapabilities($this->w['school'], ['hr.employees.manage', 'hr.employees.view']);
        $employee = $this->inSchool($this->w['school'], fn () => Employee::query()->findOrFail($this->w['employment']->employee_id));
        $linked = app(EmployeeService::class)->linkUser($employee, $this->head->id, $hr);
        app(EmployeeService::class)->unlinkUser($linked, $hr);

        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_NOT_INDEPENDENT', $this->refusal(fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head))));
        $this->assertSame(1, $this->row($record->id)->version);
    }

    #[Test]
    public function a_request_starts_from_the_record_as_it_is_and_a_retry_never_files_twice(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->assertSame('STAFF_ATTENDANCE_MONTH_OPEN', $this->refusal(fn () => $this->request($record, 'absent', 'present')));
        $this->lock();

        $this->assertSame('STAFF_ATTENDANCE_VERSION_STALE', $this->refusal(fn () => $this->service()->requestLockedCorrection($this->w['school'], $record->id, 7, 'absent', 'present', 'late_information', (string) Str::uuid(), $this->w['clerk'])));
        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_UNCHANGED', $this->refusal(fn () => $this->request($record, 'present', 'present')));
        $this->assertSame('STAFF_ATTENDANCE_CLEAR_REASON', $this->refusal(fn () => $this->request($record, null, null)));

        $key = (string) Str::uuid();
        $first = $this->request($record, 'absent', 'present', null, $key);
        $this->assertSame($first->id, $this->request($record, 'absent', 'present', null, $key)->id, 'the same form, retried, is the same request');
        $this->assertSame('STAFF_ATTENDANCE_FORM_REUSED', $this->refusal(fn () => $this->request($record, 'present', 'absent', null, $key)));
        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_PENDING', $this->refusal(fn () => $this->request($record, 'present', 'absent')));
        $this->assertSame(1, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->count()));
        $this->assertSame(1, $this->audits('staff_attendance.locked_correction_requested'));
    }

    #[Test]
    public function nobody_requests_a_correction_of_their_own_attendance(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();
        $hr = $this->createUserWithCapabilities($this->w['school'], ['hr.employees.manage', 'hr.employees.view']);
        $employee = $this->inSchool($this->w['school'], fn () => Employee::query()->findOrFail($this->w['employment']->employee_id));
        app(EmployeeService::class)->linkUser($employee, $this->w['clerk']->id, $hr);

        $this->assertSame('STAFF_ATTENDANCE_OWN_RECORD', $this->refusal(fn () => $this->request($record, 'absent', 'present')));
        $linked = $this->inSchool($this->w['school'], fn () => Employee::query()->findOrFail($this->w['employment']->employee_id));
        app(EmployeeService::class)->unlinkUser($linked, $hr);
        $this->assertSame('STAFF_ATTENDANCE_OWN_RECORD', $this->refusal(fn () => $this->request($record, 'absent', 'present')), 'an ended link still counts (rule 145)');
        $this->assertSame(0, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->count()));
    }

    #[Test]
    public function a_suspended_school_neither_locks_requests_nor_decides(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->recordAttendance($this->w, '2026-09-30', 'present', 'present');
        $this->lock();
        $request = $this->request($record, 'absent', 'present');
        DB::table('schools')->where('id', $this->w['school']->id)->update(['status' => 'suspended']);

        $this->expectsRefusal(SchoolNotOperationalException::class, fn () => $this->lock('2026-09'));
        $this->expectsRefusal(SchoolNotOperationalException::class, fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head)));
        $this->expectsRefusal(SchoolNotOperationalException::class, fn () => $this->request($this->row($record->id), 'absent', 'absent', null, (string) Str::uuid()));
        $this->assertSame([1, 1], [$this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->count()), $this->row($record->id)->version]);
    }

    #[Test]
    public function a_revoked_lock_key_decides_nothing_even_after_the_page_offered_it(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();
        $request = $this->request($record, 'absent', 'present');
        $this->assertSame([$request->id], $this->service()->decidable($this->w['school'], [$request->id], $this->head));

        $revoked = $this->inSchool($this->w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $this->head->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertGreaterThan(0, $revoked, 'the fixture really revoked the grant');

        $this->expectsRefusal(AuthorizationException::class, fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head)));
        $this->assertSame(1, $this->row($record->id)->version);
    }

    #[Test]
    public function payroll_sees_the_approved_change_as_a_changed_source(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();
        $reader = app(PayrollAbsenceEvidenceReader::class);
        $before = $this->inSchool($this->w['school'], fn () => $reader->read($this->w['school'], $this->w['employment']->id, '2026-10-01', '2026-10-31')->fingerprint);

        $request = $this->request($record, 'absent', 'present');
        $this->assertSame($before, $this->inSchool($this->w['school'], fn () => $reader->read($this->w['school'], $this->w['employment']->id, '2026-10-01', '2026-10-31')->fingerprint), 'a request is not evidence');
        $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head));

        $this->assertNotSame($before, $this->inSchool($this->w['school'], fn () => $reader->read($this->w['school'], $this->w['employment']->id, '2026-10-01', '2026-10-31')->fingerprint));
    }

    /** @param  class-string<\Throwable>  $class */
    private function expectsRefusal(string $class, callable $command): void
    {
        try {
            $command();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());

            return;
        }
        $this->fail("Expected {$class}.");
    }

    #[Test]
    public function a_known_same_person_never_decides_and_is_never_offered_the_decision(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->lock();
        // The principal and the head once held uncorrected links to one Employee: as far as anything here knows, one person.
        $hr = $this->createUserWithCapabilities($this->w['school'], ['hr.employees.manage', 'hr.employees.view']);
        $other = $this->inSchool($this->w['school'], fn () => Employee::query()->findOrFail($this->currentEmployment($this->w['school'])->employee_id));
        $employees = app(EmployeeService::class);
        $employees->unlinkUser($employees->linkUser($other, $this->head->id, $hr), $hr);
        $employees->linkUser($other->fresh() ?? $other, $this->principal->id, $hr);
        $request = $this->request($record, 'absent', 'present', $this->principal);

        $this->assertSame([], $this->service()->decidable($this->w['school'], [$request->id], $this->head));
        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_NOT_INDEPENDENT', $this->refusal(fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $this->freshMfaProofFor($this->head))));
        $this->assertSame(1, $this->row($record->id)->version);
    }

    #[Test]
    public function an_open_month_correction_re_reads_its_authority_even_when_the_cache_still_says_yes(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->assertTrue(Gate::forUser($this->w['clerk'])->allows('capability', ['hr.staff_attendance.manage', $this->w['school']]), 'cache warmed');
        $this->inSchool($this->w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $this->w['clerk']->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertTrue(Gate::forUser($this->w['clerk'])->allows('capability', ['hr.staff_attendance.manage', $this->w['school']]), 'the cached floor still says yes');

        $this->expectsRefusal(AuthorizationException::class, fn () => $this->correctAttendance($this->w, $record, 'absent', 'present'));
        $this->assertSame(0, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_corrections')->count()));
    }
}
