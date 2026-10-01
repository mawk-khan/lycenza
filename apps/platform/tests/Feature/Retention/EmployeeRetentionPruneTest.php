<?php

namespace Tests\Feature\Retention;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Application\Retention\EmployeeRecordRetentionService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * E21.2E (E21-D9, project-adopted, pending legal ratification): ancillary HR
 * details go 2 calendar years after the Employee's final separation;
 * employment and payroll evidence, with the Employee, goes 8 years after it.
 * Only when the separation is unambiguous, the School is not held, and
 * nothing else still needs the Employee.
 */
class EmployeeRetentionPruneTest extends TestCase
{
    use CreatesTenancyFixtures, CreatesTimetableFixtures;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('documents.disk');
        Storage::fake($this->disk);
        config([
            'retention.employee_ancillary_years' => 2,
            'retention.employee_evidence_years' => 8,
            'retention.hold_school_ids' => [],
        ]);
    }

    private function at(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 12:00:00', 'UTC'));
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** An Employee whose only employment ended on $endsOn. */
    private function leaver(School $school, ?string $endsOn, string $status = 'separated'): Employee
    {
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['status' => $status, 'starts_on' => '2015-01-01', 'ends_on' => $endsOn]);

        return $employee;
    }

    private function withAncillary(Employee $employee): void
    {
        $this->createEmployeeAddress($employee);
        $this->createEmployeeEmergencyContact($employee);
        $this->inSchool($employee->school, function () use ($employee): void {
            $owner = ['school_id' => $employee->school_id, 'employee_id' => $employee->id];
            EmployeeNote::factory()->create($owner + ['author_user_id' => $this->createUser()->id]);
            EmployeeQualification::factory()->create($owner);
            EmployeeExperience::factory()->create($owner);
            EmployeeCertification::factory()->create($owner);
        });
    }

    /** @return list<string> the two stored objects (HR document, Employee-owned Document) */
    private function withDocuments(Employee $employee): array
    {
        $base = "schools/{$employee->school_id}/documents/employee/{$employee->id}";
        $hrPath = "{$base}/".Str::uuid7().'.pdf';
        $docPath = "{$base}/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($hrPath, 'bytes');
        Storage::disk($this->disk)->put($docPath, 'bytes');
        $this->inSchool($employee->school, function () use ($employee, $hrPath, $docPath): void {
            EmployeeDocument::factory()->create(['school_id' => $employee->school_id, 'employee_id' => $employee->id, 'storage_disk' => $this->disk, 'storage_path' => $hrPath]);
            Document::query()->forceCreate([
                'id' => (string) Str::uuid7(), 'school_id' => $employee->school_id, 'employee_id' => $employee->id, 'classification_tier' => 'internal',
                'storage_disk' => $this->disk, 'storage_path' => $docPath, 'original_filename' => 'contract.pdf', 'mime_type' => 'application/pdf',
                'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'archived',
            ]);
        });

        return [$hrPath, $docPath];
    }

    private function rows(School $school, string $table, string $employeeId): int
    {
        return $this->inSchool($school, fn () => DB::table($table)->where('employee_id', $employeeId)->count());
    }

    private function exists(School $school, string $employeeId): bool
    {
        return $this->inSchool($school, fn () => DB::table('employees')->where('id', $employeeId)->exists());
    }

    private function prune(array $options = []): PendingCommand
    {
        return $this->artisan('platform:employee-retention-prune', $options);
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured_and_a_shorter_evidence_period_is_refused(): void
    {
        $school = $this->createSchool();
        $employee = $this->leaver($school, '2020-06-30');
        $this->withAncillary($employee);
        $this->at('2090-01-01');

        config(['retention.employee_ancillary_years' => null, 'retention.employee_evidence_years' => null]);
        $this->prune()->expectsOutputToContain('not configured')->assertSuccessful();
        config(['retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 1]);
        $this->prune()->assertFailed();

        $this->assertSame(1, $this->rows($school, 'employee_addresses', $employee->id));
        $this->assertTrue($this->exists($school, $employee->id));
    }

    #[Test]
    public function ancillary_details_expire_two_calendar_years_after_final_separation(): void
    {
        $school = $this->createSchool();
        $leaver = $this->leaver($school, '2024-06-30');
        $this->withAncillary($leaver);
        $this->createEmployeePersonalDetail($leaver);
        [$hrPath] = $this->withDocuments($leaver);
        $current = $this->createEmployee($school);
        $this->createEmploymentRecord($current, ['status' => 'active', 'starts_on' => '2015-01-01', 'ends_on' => null]);
        $this->withAncillary($current);

        $this->at('2026-06-30');
        $this->prune(['--only' => 'ancillary'])->expectsOutputToContain('ancillary HR details of 0 Employee(s)')->assertSuccessful();
        $this->assertSame(1, $this->rows($school, 'employee_addresses', $leaver->id), 'exactly two years: kept');

        $this->at('2026-07-01');
        $this->prune(['--only' => 'ancillary'])->expectsOutputToContain('ancillary HR details of 1 Employee(s)')->assertSuccessful();

        foreach (EmployeeRecordRetentionService::ANCILLARY_TABLES as $table) {
            $this->assertSame(0, $this->rows($school, $table, $leaver->id), "{$table} expired");
            $this->assertSame(1, $this->rows($school, $table, $current->id), "{$table}: a current Employee is never eligible");
        }
        // The employment evidence stays for its own, longer period.
        $this->assertTrue($this->exists($school, $leaver->id));
        $this->assertSame(1, $this->rows($school, 'employment_records', $leaver->id));
        $this->assertSame(1, $this->rows($school, 'employee_personal_details', $leaver->id));
        $this->assertSame(1, $this->rows($school, 'employee_documents', $leaver->id));
        Storage::disk($this->disk)->assertExists($hrPath);
    }

    #[Test]
    public function a_rehire_or_a_future_employment_stops_the_clock_and_the_last_separation_restarts_it(): void
    {
        $school = $this->createSchool();
        $rehired = $this->leaver($school, '2020-06-30');
        $this->createEmploymentRecord($rehired, ['status' => 'active', 'starts_on' => '2023-01-01', 'ends_on' => null]);
        $future = $this->leaver($school, '2020-06-30');
        $this->createEmploymentRecord($future, ['status' => 'pre_joining', 'starts_on' => '2030-01-01', 'ends_on' => null]);
        $returnedAndLeft = $this->leaver($school, '2020-06-30');
        $this->createEmploymentRecord($returnedAndLeft, ['status' => 'retired', 'starts_on' => '2023-01-01', 'ends_on' => '2025-03-31']);
        foreach ([$rehired, $future, $returnedAndLeft] as $employee) {
            $this->withAncillary($employee);
        }

        $this->at('2026-07-01');
        $this->prune(['--only' => 'ancillary'])->expectsOutputToContain('ancillary HR details of 0 Employee(s)')->assertSuccessful();

        $this->at('2027-04-01');
        $this->prune(['--only' => 'ancillary'])->expectsOutputToContain('ancillary HR details of 1 Employee(s)')->assertSuccessful();
        $this->assertSame(0, $this->rows($school, 'employee_notes', $returnedAndLeft->id));
        $this->assertSame(1, $this->rows($school, 'employee_notes', $rehired->id));
        $this->assertSame(1, $this->rows($school, 'employee_notes', $future->id));
    }

    #[Test]
    public function an_ambiguous_separation_keeps_everything_and_is_reported_unresolved(): void
    {
        $school = $this->createSchool();
        $neverEmployed = $this->createEmployee($school);
        $noEndDate = $this->leaver($school, null);
        foreach ([$neverEmployed, $noEndDate] as $employee) {
            $this->withAncillary($employee);
        }

        $this->at('2090-01-01');
        $this->prune()->expectsOutputToContain('ancillary HR details of 0 Employee(s) (unresolved separation: 2')
            ->expectsOutputToContain('employment evidence of 0 Employee(s) (unresolved separation: 2')->assertSuccessful();

        $this->assertSame(1, $this->rows($school, 'employee_addresses', $neverEmployed->id));
        $this->assertSame(1, $this->rows($school, 'employee_addresses', $noEndDate->id));
    }

    #[Test]
    public function employment_evidence_goes_eight_years_after_separation_only_when_nothing_else_needs_the_employee(): void
    {
        $school = $this->createSchool();
        $free = $this->leaver($school, '2024-06-30');
        $this->withAncillary($free);
        $this->createEmployeePersonalDetail($free);
        [$hrPath, $docPath] = $this->withDocuments($free);

        $linked = $this->leaver($school, '2024-06-30');
        $this->inSchool($school, fn () => DB::table('employees')->where('id', $linked->id)->update(['user_id' => $this->createUser()->id]));

        // A timetable teacher is still referenced by School scheduling rows: never cascaded.
        $teacher = $this->leaver($school, '2024-06-30');
        $year = $this->createAcademicYear($school, ['starts_on' => '2024-04-01', 'ends_on' => '2025-03-31', 'status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $this->createTimetableEntry($offering, $this->createSection($year, $campus, $grade, ['code' => 'A']), $teacher, $this->createTimetablePeriod($school), ['day_of_week' => 1]);

        $this->at('2032-06-30');
        $this->prune(['--only' => 'evidence'])->expectsOutputToContain('employment evidence of 0 Employee(s)')->assertSuccessful();
        $this->assertTrue($this->exists($school, $free->id), 'exactly eight years: kept');

        $this->at('2032-07-01');
        $this->prune(['--only' => 'evidence'])->expectsOutputToContain('employment evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 2')->assertSuccessful();

        $this->assertFalse($this->exists($school, $free->id));
        foreach (['employment_records', 'employee_personal_details', 'employee_documents', 'documents', ...EmployeeRecordRetentionService::ANCILLARY_TABLES] as $table) {
            $this->assertSame(0, $this->rows($school, $table, $free->id), "{$table} goes with the Employee");
        }
        Storage::disk($this->disk)->assertMissing($hrPath);
        Storage::disk($this->disk)->assertMissing($docPath);

        $this->assertTrue($this->exists($school, $linked->id), 'a linked User is never unlinked by retention');
        $this->assertTrue($this->exists($school, $teacher->id));
    }

    #[Test]
    public function payroll_configuration_goes_with_the_evidence_but_payroll_results_keep_everything(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        [$structure, $basicComponentId] = $this->inSchool($school, function () use ($school, $actor): array {
            $structures = app(SalaryStructureService::class);
            $components = app(SalaryComponentService::class);
            $structure = $structures->createDraft($school, 'GRADE1', 'Grade I', $actor);
            $basic = $components->create($school, 'BASIC', 'Basic', 'earning', null, $actor);
            $line = $structures->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $actor);

            return [$structures->activate($structure, $actor), $line->id];
        });

        $configured = $this->createEmployee($school);
        $configuredEmployment = $this->createEmploymentRecord($configured, ['status' => 'active', 'starts_on' => '2025-01-01', 'ends_on' => null]);
        $paid = $this->createEmployee($school);
        $paidEmployment = $this->createEmploymentRecord($paid, ['status' => 'active', 'starts_on' => '2025-01-01', 'ends_on' => null]);
        foreach ([$configuredEmployment, $paidEmployment] as $employment) {
            $this->inSchool($school, fn () => app(CompensationService::class)->assign(
                $school, $employment, $structure, Carbon::parse('2025-01-01'), [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor,
            ));
        }
        // Only $paid is in the calculated run: it now has payroll results (ledger-bound evidence).
        $this->inSchool($school, fn () => DB::table('employment_records')->where('id', $configuredEmployment->id)->update(['status' => 'separated', 'ends_on' => '2025-01-31']));
        $this->inSchool($school, function () use ($school, $actor): void {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse('2025-02-01'), null, $actor), $actor);
            $runs = app(PayrollRunService::class);
            $runs->calculate($runs->createRun($period, $actor), $actor);
        });
        $this->inSchool($school, fn () => DB::table('employment_records')->where('id', $paidEmployment->id)->update(['status' => 'separated', 'ends_on' => '2025-02-28']));
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('payroll_run_results')->where('employee_id', $paid->id)->count()));

        $this->at('2034-01-01');
        $this->prune(['--only' => 'evidence'])
            ->expectsOutputToContain('payroll records of 1 Employee(s) and the employment evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 2')
            ->assertSuccessful();

        // The configured-only Employee: compensation (and its append-only values) and the root are gone.
        $this->assertFalse($this->exists($school, $configured->id));
        $this->assertSame(0, $this->inSchool($school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $configuredEmployment->id)->count()));
        // The paid Employee: payroll results are ledger evidence (D8 has no expiry), so everything stays.
        $this->assertTrue($this->exists($school, $paid->id));
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $paidEmployment->id)->count()));
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('payroll_run_results')->where('employee_id', $paid->id)->count()));
    }

    #[Test]
    public function a_held_school_keeps_everything_and_another_school_is_unaffected_by_it(): void
    {
        $held = $this->createSchool();
        $other = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        $heldEmployee = $this->leaver($held, '2024-06-30');
        $this->withAncillary($heldEmployee);
        [$heldPath] = $this->withDocuments($heldEmployee);
        $otherEmployee = $this->leaver($other, '2024-06-30');
        $this->withAncillary($otherEmployee);
        [$otherPath] = $this->withDocuments($otherEmployee);

        // Under the held School's context the other School's Employee does not exist (RLS).
        $this->assertSame(0, $this->inSchool($held, fn () => DB::table('employees')->where('id', $otherEmployee->id)->count()));

        $this->at('2032-07-01');
        $this->prune(['--dry-run' => true])->expectsOutputToContain('Dry run: would delete ancillary HR details of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0, held: 1')
            ->expectsOutputToContain('the employment evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0, held: 1')->assertSuccessful();
        $this->assertTrue($this->exists($other, $otherEmployee->id), 'a dry run deletes nothing');

        $this->prune()->expectsOutputToContain('the employment evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0, held: 1')->assertSuccessful();

        $this->assertTrue($this->exists($held, $heldEmployee->id));
        $this->assertSame(1, $this->rows($held, 'employee_addresses', $heldEmployee->id));
        Storage::disk($this->disk)->assertExists($heldPath);
        $this->assertFalse($this->exists($other, $otherEmployee->id));
        Storage::disk($this->disk)->assertMissing($otherPath);

    }

    #[Test]
    public function a_leap_day_separation_expires_on_the_first_of_march(): void
    {
        $school = $this->createSchool();
        $employee = $this->leaver($school, '2028-02-29');
        $this->withAncillary($employee);

        $this->at('2030-02-28');
        $this->prune(['--only' => 'ancillary'])->expectsOutputToContain('ancillary HR details of 0 Employee(s)')->assertSuccessful();

        $this->at('2030-03-01');
        $this->prune(['--only' => 'ancillary'])->expectsOutputToContain('ancillary HR details of 1 Employee(s)')->assertSuccessful();
    }

    #[Test]
    public function employee_documents_bytes_are_removed_from_real_minio(): void
    {
        config(['documents.disk' => 's3']);
        $this->disk = 's3';
        $school = $this->createSchool();
        $employee = $this->leaver($school, '2024-06-30');
        $paths = $this->withDocuments($employee);

        try {
            $this->at('2032-07-01');
            $this->prune(['--only' => 'evidence'])->expectsOutputToContain('the employment evidence of 1 Employee(s) (unresolved separation: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
            foreach ($paths as $path) {
                $this->assertFalse(Storage::disk('s3')->exists($path));
            }
        } finally {
            Storage::disk('s3')->delete($paths);
        }
    }
}
