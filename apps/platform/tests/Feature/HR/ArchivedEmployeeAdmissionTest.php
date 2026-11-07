<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C9 (HRC-12, owner decision 2026-11-07): an archived Employee is restored
 * first. Every path to a new employment refuses an archived identity, on the row
 * it locked; the database refuses it too. Restoring changes `record_status` and
 * nothing else, and the rehire that follows is an ordinary new employment.
 */
class ArchivedEmployeeAdmissionTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private Employee $employee;

    private EmploymentRecord $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 06:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school);
        $this->old = app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $this->hr);
        app(EmploymentService::class)->end($this->old, '2022-12-31', $this->hr, 'separated');
        app(EmployeeService::class)->archive($this->employee, $this->hr);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function employmentCount(?Employee $employee = null): int
    {
        return (int) $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', ($employee ?? $this->employee)->id)->count());
    }

    private function rehire(?Employee $employee = null): EmploymentRecord
    {
        return app(EmployeeLifecycleService::class)->rehire($employee ?? $this->employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $this->hr);
    }

    private function assertRefused(string $reason, callable $write): void
    {
        try {
            $write();
            $this->fail("Expected the {$reason} refusal.");
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return $this->inSchool(fn () => SchoolAuditEvent::query()->where('subject_id', $this->employee->id)->orderBy('id')->pluck('event_type')->all());
    }

    private function rawInsert(string $employeeId, string $startsOn = '2026-06-01'): ?string
    {
        try {
            DB::transaction(fn () => $this->inSchool(fn () => DB::table('employment_records')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employeeId, 'employment_type' => 'permanent',
                'staff_type' => 'teaching', 'starts_on' => $startsOn, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ])));

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    #[Test]
    public function an_archived_identity_receives_no_employment_through_the_services(): void
    {
        $this->assertRefused('employee_archived', fn () => $this->rehire());
        $this->assertRefused('employee_archived', fn () => app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $this->hr));

        $this->assertSame(1, $this->employmentCount());
    }

    #[Test]
    public function the_answer_comes_from_the_locked_row_and_never_from_a_stale_model(): void
    {
        $stale = $this->inSchool(fn () => Employee::query()->findOrFail($this->employee->id));
        $stale->record_status = 'active';

        $this->assertRefused('employee_archived', fn () => $this->rehire($stale));
        $this->assertSame(1, $this->employmentCount());
    }

    #[Test]
    public function the_web_and_the_api_refuse_it_with_the_controlled_error(): void
    {
        $this->actingAs($this->hr);
        $this->withSession(['active_school_id' => $this->school->id]);
        $this->post("/app/hr/employees/{$this->employee->id}/rehire", ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-06-01'])
            ->assertRedirect()->assertSessionHasErrors(['refusal' => 'This Employee is archived. Restore the Employee first, then record the new employment.']);
        $this->post("/app/hr/employees/{$this->employee->id}/employment-records", ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-06-01'])
            ->assertRedirect()->assertSessionHasErrors('refusal');

        app('auth')->forgetGuards();
        $token = $this->hr->createToken('test-device')->plainTextToken;
        $api = "/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}";
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid7()])
            ->postJson("{$api}/rehire", ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-06-01'])
            ->assertStatus(422)->assertJsonPath('error.code', 'HR_INVALID_EMPLOYMENT_TERMS');
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid7()])
            ->postJson("{$api}/employment-records", ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-06-01'])
            ->assertStatus(422);

        $this->assertSame(1, $this->employmentCount());
    }

    #[Test]
    public function the_runtime_role_cannot_insert_one_or_move_one_onto_an_archived_identity(): void
    {
        $this->assertSame('23514', $this->rawInsert($this->employee->id));
        $this->assertSame(1, $this->employmentCount());

        $active = $this->createEmployee($this->school);
        $this->assertNull($this->rawInsert($active->id), 'positive control: the same insert for an active identity');

        // Moving an existing employment onto the archived identity was already refused (retention_guard_end_record).
        $record = $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $active->id)->firstOrFail());
        try {
            DB::transaction(fn () => $this->inSchool(fn () => DB::table('employment_records')->where('id', $record->id)->update(['employee_id' => $this->employee->id])));
            $this->fail('An employment never changes hands.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('the identity and start of a record are fixed', $e->getMessage());
        }
    }

    #[Test]
    public function restoring_then_rehiring_is_an_ordinary_new_employment_and_nothing_old_returns(): void
    {
        $number = $this->employee->employee_number;
        $position = $this->createPosition($this->school);
        $oldAssignment = $this->createEmployeeAssignment($this->old, $position, ['starts_on' => '2020-01-01', 'ends_on' => '2022-12-31', 'is_primary' => false]);

        $restored = app(EmployeeService::class)->restore($this->employee, $this->hr);
        $this->assertSame('active', $restored->record_status);
        $this->assertSame(1, $this->employmentCount(), 'restoring creates no employment');

        $new = $this->rehire();

        $this->assertNotSame($this->old->id, $new->id);
        $this->assertSame($this->employee->id, $new->employee_id);
        $this->inSchool(function () use ($new, $number, $oldAssignment): void {
            $this->assertSame(1, Employee::query()->where('employee_number', $number)->count(), 'never a duplicate identity');
            $old = EmploymentRecord::query()->findOrFail($this->old->id);
            $this->assertSame(['separated', '2022-12-31'], [$old->status, $old->ends_on->toDateString()], 'the old employment is not reopened');
            $this->assertSame(0, EmployeeAssignment::query()->where('employment_record_id', $new->id)->count(), 'no placement returns');
            $this->assertSame('2022-12-31', $oldAssignment->fresh()->ends_on->toDateString());
        });

        $this->assertSame(['employee.archived', 'employee.restored'], array_values(array_filter($this->auditActions(), fn (string $a) => in_array($a, ['employee.archived', 'employee.restored'], true))),
            'the archive event stays beside the restoration');
    }

    #[Test]
    public function restoring_leaves_the_account_alone(): void
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        $this->assignSchoolRole($membership, 'teacher');
        $this->inSchool(fn () => DB::table('employees')->where('id', $this->employee->id)->update(['user_id' => $user->id]));
        DB::table('users')->where('id', $user->id)->update(['disabled_at' => now()]);
        $membership->forceFill(['status' => 'suspended'])->save();
        $roles = fn () => DB::table('membership_role_assignments')->where('school_membership_id', $membership->id)->whereNull('revoked_at')->count();
        $before = $roles();

        app(EmployeeService::class)->restore($this->employee, $this->hr);

        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('disabled_at'));
        $this->assertSame('suspended', $membership->fresh()->status);
        $this->assertSame($before, $roles());
    }

    #[Test]
    public function a_death_is_never_undone_by_restoring(): void
    {
        $employee = $this->createEmployee($this->school);
        $this->createEmploymentRecord($employee, ['status' => 'deceased', 'starts_on' => '2020-01-01', 'ends_on' => '2024-01-31']);

        app(EmployeeService::class)->archive($employee, $this->hr);
        $this->assertRefused('employee_deceased', fn () => $this->rehire($employee));

        app(EmployeeService::class)->restore($employee, $this->hr);
        $this->assertRefused('employee_deceased', fn () => $this->rehire($employee));
        $this->assertSame('23514', $this->rawInsert($employee->id));
        $this->assertSame(1, $this->employmentCount($employee));
    }

    #[Test]
    public function an_employee_still_employed_is_refused_as_before_once_restored(): void
    {
        $employee = $this->createEmployee($this->school);
        $this->createEmploymentRecord($employee, ['status' => 'separated', 'starts_on' => '2019-01-01', 'ends_on' => '2019-12-31']);
        $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => null]);

        app(EmployeeService::class)->archive($employee, $this->hr);
        $this->assertRefused('employee_archived', fn () => $this->rehire($employee));

        app(EmployeeService::class)->restore($employee, $this->hr);
        $this->expectException(EmploymentOverlapException::class);
        $this->rehire($employee);
    }

    #[Test]
    public function restoring_twice_changes_and_records_nothing_twice(): void
    {
        $stale = $this->inSchool(fn () => Employee::query()->findOrFail($this->employee->id));
        app(EmployeeService::class)->restore($this->employee, $this->hr);

        $this->assertSame('active', app(EmployeeService::class)->restore($stale, $this->hr)->record_status);

        $this->assertSame(1, count(array_filter($this->auditActions(), fn (string $a) => $a === 'employee.restored')));
    }

    /** @return array<string, array{0: string}> */
    public static function rolesWithoutLifecycleAuthority(): array
    {
        return [
            'staff self-service' => ['staff_self_service'], 'teacher' => ['teacher'], 'line manager' => ['line_manager'],
            'payroll officer' => ['payroll_officer'], 'accountant' => ['accountant'], 'front office' => ['front_office'],
        ];
    }

    #[Test]
    #[DataProvider('rolesWithoutLifecycleAuthority')]
    public function a_role_without_the_lifecycle_capability_neither_restores_nor_rehires(string $role): void
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), $role);

        try {
            app(EmployeeService::class)->restore($this->employee, $user);
            $this->fail('restore must be refused');
        } catch (AuthorizationException) {
        }
        try {
            app(EmployeeLifecycleService::class)->rehire($this->employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $user);
            $this->fail('rehire must be refused');
        } catch (AuthorizationException) {
        }

        $this->assertSame('archived', $this->inSchool(fn () => $this->employee->fresh()->record_status));
        $this->assertSame(1, $this->employmentCount());
    }

    #[Test]
    public function an_hr_officer_restores_by_capability_but_never_their_own_record(): void
    {
        $officer = $this->createUser();
        $this->assignSchoolRole($this->createMembership($officer, $this->school), 'hr_officer');

        $this->assertSame('active', app(EmployeeService::class)->restore($this->employee, $officer)->record_status);

        $own = $this->createEmployee($this->school, ['user_id' => $officer->id]);
        app(EmployeeService::class)->archive($own, $this->hr);
        try {
            app(EmployeeService::class)->restore($own, $officer);
            $this->fail('nobody restores their own record');
        } catch (HrSelfAdministrationException) {
        }
        $this->assertSame('archived', $this->inSchool(fn () => $own->fresh()->record_status));
    }

    #[Test]
    public function another_school_cannot_restore_or_rehire_and_a_forged_id_is_unknown(): void
    {
        $other = $this->createSchool();
        $outsider = $this->fullHrActor($other);

        $this->actingAs($outsider);
        $this->withSession(['active_school_id' => $other->id]);
        $this->post("/app/hr/employees/{$this->employee->id}/restore")->assertNotFound();
        $this->post("/app/hr/employees/{$this->employee->id}/rehire", ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-06-01'])->assertNotFound();
        $this->post('/app/hr/employees/'.Str::uuid7().'/restore')->assertNotFound();

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$outsider->createToken('test-device')->plainTextToken)
            ->postJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/restore")->assertNotFound();

        $this->assertSame('archived', $this->inSchool(fn () => $this->employee->fresh()->record_status));
    }

    #[Test]
    public function a_purged_identity_cannot_be_restored(): void
    {
        $gone = $this->inSchool(fn () => Employee::query()->findOrFail($this->employee->id));
        $gone->id = (string) Str::uuid7();

        $this->expectException(ModelNotFoundException::class);
        app(EmployeeService::class)->restore($gone, $this->hr);
    }
}
