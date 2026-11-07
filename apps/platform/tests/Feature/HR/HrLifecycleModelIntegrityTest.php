<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Support\LifecycleModels;
use Tests\TestCase;

/**
 * HR.R1-C11 (HRC-10, GOV-6): a lifecycle model takes its state, actor and parent
 * columns only from the service that owns them. Reproduced on `33ac0b6b` at the model:
 * `fill(['status' => ...])` was accepted. It was never reachable from a request,
 * because every service strips or intersects its input, so protection rested on each
 * hand-written list staying complete. The model no longer accepts those fields at all.
 */
class HrLifecycleModelIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const PROTECTED = [
        'school_id', 'status', 'employment_record_id', 'employee_id', 'starts_on', 'ends_on',
        'prepared_by_user_id', 'approved_by_user_id', 'posted_by_user_id', 'approved_at', 'posted_at', 'submitted_by_user_id',
        'created_by_user_id', 'started_by_user_id', 'assigned_by_user_id', 'requested_by_user_id', 'requested_at', 'completed_at', 'retired_at',
        'reassigned_from_task_id', 'cursor_employment_id', 'batch_count',
    ];

    /** A date range is the business data of a request or a period; nowhere else may it be mass-assigned. */
    private const DATED_BY_DESIGN = ['starts_on', 'ends_on'];

    private School $school;

    private User $hr;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
    }

    private function signedIn(User $user): self
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    #[Test]
    public function no_hardened_model_accepts_a_protected_column(): void
    {
        foreach (LifecycleModels::HARDENED as $class) {
            /** @var Model $model */
            $model = new $class;
            $this->assertSame([], array_values(array_diff(array_intersect(self::PROTECTED, $model->getFillable()), $class === EmploymentRecord::class ? [] : self::DATED_BY_DESIGN)), "{$class} still accepts a protected column");
        }
        $this->assertSame(['probation_ends_on'], (new EmploymentRecord)->getFillable());
        $this->assertSame([], (new PayrollRun)->getFillable());
        $this->assertSame([], (new LeaveRequest)->getFillable());
    }

    #[Test]
    public function a_totally_guarded_model_outside_the_nine_still_refuses(): void
    {
        // The handler replaces Eloquent's own throw, so without its second condition this would pass silently.
        $this->expectException(MassAssignmentException::class);

        (new EmployeeAccountLink)->fill(['school_id' => $this->school->id]);
    }

    #[Test]
    public function the_service_ignores_every_injected_field_and_writes_only_what_it_owns(): void
    {
        $other = $this->createSchool();

        app(EmploymentService::class)->update($this->employment, [
            'probation_ends_on' => '2026-12-31', 'status' => 'separated', 'ends_on' => '2026-01-01', 'employee_id' => (string) Str::uuid7(), 'school_id' => $other->id, 'starts_on' => '2020-01-01',
        ], $this->hr);

        $row = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->findOrFail($this->employment->id));
        $this->assertSame('2026-12-31', $row->probation_ends_on->toDateString());
        $this->assertSame(['active', null, $this->employment->employee_id, $this->school->id, '2024-01-01'], [$row->status, $row->ends_on, $row->employee_id, $row->school_id, $row->starts_on->toDateString()]);
    }

    #[Test]
    public function the_api_writes_the_one_editable_field_and_ignores_the_rest(): void
    {
        $injected = ['status' => 'separated', 'ends_on' => '2026-01-01', 'school_id' => $this->createSchool()->id];

        $this->withHeaders(['Authorization' => 'Bearer '.$this->hr->createToken('t')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid7()])
            ->patchJson("/api/v1/schools/{$this->school->id}/employees/{$this->employment->employee_id}/employment-records/{$this->employment->id}", $injected + ['probation_ends_on' => '2026-12-31'])
            ->assertOk();

        $row = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->findOrFail($this->employment->id));
        $this->assertSame('2026-12-31', $row->probation_ends_on->toDateString());
        $this->assertSame(['active', null, $this->school->id], [$row->status, $row->ends_on, $row->school_id]);
    }

    #[Test]
    public function the_web_path_ignores_injected_fields(): void
    {
        $leaver = $this->createEmployee($this->school);
        $this->createEmploymentRecord($leaver, ['status' => 'separated', 'starts_on' => '2020-01-01', 'ends_on' => '2020-12-31']);
        $foreign = $this->createSchool();

        $this->signedIn($this->hr)->post("/app/hr/employees/{$leaver->id}/employment-records", [
            'employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2026-01-01',
            'status' => 'separated', 'ends_on' => '2026-02-01', 'school_id' => $foreign->id, 'employee_id' => $this->employment->employee_id,
        ])->assertSessionHasNoErrors();

        $row = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $leaver->id)->where('starts_on', '2026-01-01')->firstOrFail());
        $this->assertSame([$this->school->id, $leaver->id, null], [$row->school_id, $row->employee_id, $row->ends_on]);
        $this->assertNotSame('separated', $row->status);
    }

    #[Test]
    public function another_schools_employment_and_a_forged_pair_are_unknown(): void
    {
        $foreign = $this->createSchool();
        $theirs = $this->createEmploymentRecord($this->createEmployee($foreign), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $stranger = $this->createEmployee($this->school);
        $api = fn (string $employee, string $employment) => "/api/v1/schools/{$this->school->id}/employees/{$employee}/employment-records/{$employment}";
        $headers = fn () => ['Authorization' => 'Bearer '.$this->hr->createToken('t')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid7()];

        $this->withHeaders($headers())->patchJson($api($theirs->employee_id, $theirs->id), ['probation_ends_on' => '2026-12-31'])->assertNotFound();
        $this->withHeaders($headers())->patchJson($api($stranger->id, $this->employment->id), ['probation_ends_on' => '2026-12-31'])->assertNotFound();

        $this->assertNull(app(TenantContext::class)->withSchool($foreign, fn () => EmploymentRecord::query()->findOrFail($theirs->id))->probation_ends_on);
        $this->assertNull(app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->findOrFail($this->employment->id))->probation_ends_on);
    }

    #[Test]
    public function a_finished_employment_keeps_its_ending_and_the_audit_names_only_what_changed(): void
    {
        $ended = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'separated', 'starts_on' => '2020-01-01', 'ends_on' => '2020-12-31']);

        app(EmploymentService::class)->update($ended, ['probation_ends_on' => '2020-06-30', 'status' => 'active', 'ends_on' => null], $this->hr);

        $row = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->findOrFail($ended->id));
        $this->assertSame(['separated', '2020-12-31'], [$row->status, $row->ends_on->toDateString()]);

        $audit = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('school_audit_events')->where('event_type', 'hr.employment.updated')->where('subject_id', $ended->id)->sole());
        $this->assertSame(['probation_ends_on'], json_decode($audit->metadata, true)['fields']);
    }

    #[Test]
    public function an_import_cannot_set_a_lifecycle_field(): void
    {
        $result = app(EmployeeImportService::class)->import($this->school, $this->hr, [['full_name' => 'Imported', 'status' => 'separated']]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('validation', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function the_runtime_role_is_confined_to_its_school(): void
    {
        $role = DB::selectOne('select current_user as name, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');
        $this->assertSame(['school_os_app', false, false], [$role->name, $role->rolsuper, $role->rolbypassrls]);

        $change = fn () => DB::table('employment_records')->where('id', $this->employment->id)->update(['probation_ends_on' => '2026-12-31']);
        $this->assertSame(0, app(TenantContext::class)->withSchool($this->createSchool(), $change), 'another School does not see the row');
        $this->assertSame(0, $change(), 'no School context sees nothing');
    }

    #[Test]
    public function the_runtime_role_cannot_delete_an_employment_in_its_own_school(): void
    {
        try {
            DB::transaction(fn () => app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employment_records')->where('id', $this->employment->id)->delete()));
            $this->fail('The database must refuse it.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode(), 'refused by privilege, not by an unrelated error');
        }
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->whereKey($this->employment->id)->count()));
    }

    #[Test]
    public function the_lifecycle_still_works_through_its_own_writers(): void
    {
        app(EmploymentService::class)->end($this->employment, '2026-06-30', $this->hr, 'separated');

        $row = app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->findOrFail($this->employment->id));
        $this->assertSame(['separated', '2026-06-30'], [$row->status, $row->ends_on->toDateString()]);
    }

    #[Test]
    public function the_runtime_role_cannot_move_or_rewrite_an_employment(): void
    {
        $refused = function (array $values): string {
            try {
                DB::transaction(fn () => app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employment_records')->where('id', $this->employment->id)->update($values)));
            } catch (QueryException $e) {
                return $e->getMessage();
            }

            return '';
        };

        $this->assertStringContainsString('identity and start of a record are fixed', $refused(['starts_on' => '2020-01-01']));
        $this->assertStringContainsString('identity and start of a record are fixed', $refused(['employee_id' => $this->createEmployee($this->school)->id]));
        $this->assertStringContainsString('employment terms change only by appending employment_terms', $refused(['staff_type' => 'non_teaching']));
    }

    #[Test]
    public function only_the_terms_trigger_writes_the_projection(): void
    {
        // The projection guard allows a write at trigger depth 2. So a future trigger that updated this table would
        // pass it unseen: this is what stands in the way of that. It reads function source, so dynamic SQL would
        // escape it. Two functions reach this table that way: `retention_lock_rows` locks, `retention_first_reference` reads.
        $writers = DB::select("select p.proname from pg_proc p where p.pronamespace = 'public'::regnamespace and p.prosrc ~* 'update\\s+(only\\s+)?(\"?public\"?\\.)?\"?employment_records\"?\\M'");

        $this->assertSame(['hr_employment_terms_project'], array_column($writers, 'proname'));
    }
}
