<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C13 (WO-2): what the database does for the columns C13 took out of `$fillable`,
 * as the real runtime role on the `pgsql` connection, with no Eloquent. Hardening a model
 * replaces no database control, so this records which ones answer. Rows are written by
 * the real services as that role. `hr_profile_record_archive_guard` freezes a profile
 * row's Employee and School whichever School is named, and it answers before RLS would.
 */
class HrHardenedColumnRawRoleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const PROFILE = ['employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications', 'employee_experience_records', 'employee_certifications'];

    private School $a;

    private School $b;

    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createSchool();
        $this->b = $this->createSchool();
        $this->employeeB = $this->createEmployee($this->b);
        $employee = $this->createEmployee($this->a);
        $hr = $this->createUserWithCapabilities($this->a, [
            'hr.employees.personal.view', 'hr.employees.personal.manage', 'hr.employees.qualifications.view', 'hr.employees.qualifications.manage',
            'hr.employees.notes.view', 'hr.employees.notes.manage',
        ]);
        app(EmployeeAddressService::class)->add($employee, ['address_type' => 'current', 'address_line1' => '1 Road'], $hr);
        app(EmployeeEmergencyContactService::class)->add($employee, ['name' => 'A', 'relationship' => 'sibling', 'phone' => '+919800000001'], $hr);
        app(EmployeeNoteService::class)->add($employee, ['body' => 'A note', 'classification_tier' => 'sensitive'], $hr);
        app(EmployeeQualificationService::class)->add($employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $hr);
        app(EmployeeExperienceService::class)->add($employee, ['organization' => 'A School', 'job_title' => 'Teacher', 'starts_on' => '2010-01-01'], $hr);
        app(EmployeeCertificationService::class)->add($employee, ['name' => 'First aid', 'issuer' => 'A Board'], $hr);
    }

    /** @return array<string, array{0: string}> */
    public static function tables(): array
    {
        return array_combine(self::PROFILE, array_map(fn (string $t) => [$t], self::PROFILE));
    }

    private function in(?School $school, callable $work): mixed
    {
        if ($school === null) {
            app(TenantContext::class)->clearAll();
            DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

            return $work();
        }

        return app(TenantContext::class)->withSchool($school, $work);
    }

    private function row(string $table): object
    {
        return $this->in($this->a, fn () => DB::connection('pgsql')->table($table)->first());
    }

    private function failure(callable $write): ?QueryException
    {
        try {
            DB::connection('pgsql')->transaction($write);

            return null;
        } catch (QueryException $e) {
            return $e;
        }
    }

    #[Test]
    public function this_runs_as_the_runtime_role_with_no_bypass(): void
    {
        $role = DB::connection('pgsql')->selectOne('select current_user as name, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');

        $this->assertSame(['school_os_app', false, false], [$role->name, $role->rolsuper, $role->rolbypassrls]);
    }

    #[Test]
    #[DataProvider('tables')]
    public function a_row_cannot_be_moved_to_another_school(string $table): void
    {
        $row = $this->row($table);

        $e = $this->failure(fn () => $this->in($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row->id)->update(['school_id' => $this->b->id])));

        $this->assertNotNull($e);
        $this->assertMatchesRegularExpression('/never changes its Employee|row-level security/', $e->getMessage());
        $this->assertSame($this->a->id, $this->row($table)->school_id);
    }

    #[Test]
    #[DataProvider('tables')]
    public function a_row_cannot_point_at_another_schools_employee(string $table): void
    {
        $row = $this->row($table);

        $e = $this->failure(fn () => $this->in($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row->id)->update(['employee_id' => $this->employeeB->id])));

        $this->assertNotNull($e);
        $this->assertStringContainsString('never changes its Employee', $e->getMessage());
        $this->assertSame($row->employee_id, $this->row($table)->employee_id);
    }

    #[Test]
    #[DataProvider('tables')]
    public function a_row_cannot_be_moved_to_another_employee_of_the_same_school(string $table): void
    {
        $row = $this->row($table);
        $colleague = $this->createEmployee($this->a);

        $e = $this->failure(fn () => $this->in($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row->id)->update(['employee_id' => $colleague->id])));

        $this->assertNotNull($e, 'the parent is a database fact, not only a model one');
        $this->assertStringContainsString('never changes its Employee', $e->getMessage());
    }

    #[Test]
    #[DataProvider('tables')]
    public function another_school_cannot_touch_the_row_and_no_context_sees_nothing(string $table): void
    {
        $row = $this->row($table);

        $this->assertSame(0, $this->in($this->b, fn () => DB::connection('pgsql')->table($table)->where('id', $row->id)->update(['updated_at' => '2020-01-01 00:00:00'])));
        $this->assertSame(0, $this->in(null, fn () => DB::connection('pgsql')->table($table)->count()));
        $this->assertEquals($row, $this->row($table));
    }

    #[Test]
    #[DataProvider('tables')]
    public function the_runtime_role_cannot_delete_it(string $table): void
    {
        $row = $this->row($table);

        $e = $this->failure(fn () => $this->in($this->a, fn () => DB::connection('pgsql')->table($table)->where('id', $row->id)->delete()));

        $this->assertNotNull($e);
        $this->assertStringContainsString('permission denied', $e->getMessage());
    }

    #[Test]
    public function the_placement_identity_guard_still_refuses_a_new_parent(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->a), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $other = $this->createEmploymentRecord($this->createEmployee($this->a), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($this->a), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);

        $e = $this->failure(fn () => $this->in($this->a, fn () => DB::connection('pgsql')->table('employee_assignments')->where('id', $assignment->id)->update(['employment_record_id' => $other->id])));

        $this->assertNotNull($e, 'the database let a placement change its employment');
    }
}
