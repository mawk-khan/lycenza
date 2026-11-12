<?php

namespace Tests\Feature\HR;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\EmployeeDocumentPolicy;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeCategoryService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Application\Profile\EmployeeReferenceCodeService;
use App\Domain\HR\Application\Profile\EmployeeReferenceKind;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Application\Profile\ReferenceCodeRetireReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCancelReason;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C13 (WO-1, C11-N1, C12-N1, rule 86): every School business effect of the HR
 * profile, reference-data and task writers re-checks the School when it executes.
 * Each case calls the service directly, so no route or middleware can be what refuses,
 * and asserts the refusal AND that nothing was written or audited. Reproduced on
 * `ceff7576`: every refusal case failed there, the writes landing on a suspended School.
 */
class HrProfileSchoolCheckTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private Employee $employee;

    private EmploymentRecord $employment;

    private TaskCategory $category;

    /** @var array<string, mixed> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        Storage::fake((string) config('documents.disk'));
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view', 'hr.employees.personal.manage',
            'hr.employees.qualifications.view', 'hr.employees.qualifications.manage', 'hr.employees.notes.view', 'hr.employees.notes.manage',
            'hr.employees.documents.view', 'hr.employees.documents.manage', 'hr.employees.sensitive.view', 'hr.employees.sensitive.manage',
            'hr.departments.view', 'hr.departments.manage', 'hr.positions.view', 'hr.positions.manage', 'hr.categories.view', 'hr.categories.manage',
            'hr.employees.assignments.manage', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage',
        ]);
        $this->employee = $this->createEmployee($this->school);
        $this->employment = $this->createEmploymentRecord($this->employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($this->employment, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $this->category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function writers(): array
    {
        $names = [
            'address add', 'address update', 'address remove',
            'contact add', 'contact update', 'contact remove', 'contact primary',
            'note add', 'note update', 'note remove',
            'qualification add', 'qualification update', 'qualification remove', 'qualification verify', 'qualification reject',
            'experience add', 'experience update', 'experience remove',
            'certification add', 'certification update', 'certification remove', 'certification verify', 'certification reject',
            'personal details',
            'document register', 'document update', 'document archive', 'document archive registered', 'generic document upload', 'generic document archive',
            'department create', 'department update', 'department archive', 'department reactivate', 'department reparent',
            'position create', 'position update', 'position archive', 'position reactivate',
            'category create', 'category update', 'category archive', 'category reactivate',
            'reference record', 'reference retire',
            'task category create', 'task category retire', 'task template create', 'task template version', 'task template retire',
            'task assign', 'task reassign', 'task review', 'task cancel',
        ];

        return array_combine($names, array_map(fn (string $n) => [$n], $names));
    }

    /** @return array<string, mixed> */
    private function templateTerms(array $override = []): array
    {
        return ['title' => 'Daily check', 'category_id' => $this->category->id, 'completion_mode' => 'self', 'frequency' => 'daily', 'weekdays' => 127,
            'due_days' => 0, 'applies_teaching' => true, 'applies_non_teaching' => false, 'applies_outsourced' => false, 'effective_from' => '2026-06-15', ...$override];
    }

    /** @return array<string, mixed> */
    private function taskTerms(): array
    {
        return ['title' => 'Prepare the board', 'category_id' => $this->category->id, 'completion_mode' => 'reviewed', 'available_on' => '2026-06-15', 'due_on' => '2026-06-20'];
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('record.pdf', "%PDF-1.4\n%hr-document fixture\n");
    }

    /** The rows a writer needs, made while the School is active. */
    private function prepare(string $writer): void
    {
        $e = $this->employee;
        $hr = $this->hr;
        $this->made = match (true) {
            str_starts_with($writer, 'address') && $writer !== 'address add' => ['row' => $this->createEmployeeAddress($e)],
            str_starts_with($writer, 'contact') && $writer !== 'contact add' => ['row' => $this->createEmployeeEmergencyContact($e)],
            str_starts_with($writer, 'note') && $writer !== 'note add' => ['row' => $this->createEmployeeNote($e, ['author_user_id' => $hr->id])],
            str_starts_with($writer, 'qualification') && $writer !== 'qualification add' => ['row' => $this->createEmployeeQualification($e)],
            str_starts_with($writer, 'experience') && $writer !== 'experience add' => ['row' => $this->createEmployeeExperience($e)],
            str_starts_with($writer, 'certification') && $writer !== 'certification add' => ['row' => $this->createEmployeeCertification($e)],
            $writer === 'generic document archive' => ['row' => $this->createDocumentForEmployee($e, ['classification_tier' => 'sensitive'])],
            in_array($writer, ['document update', 'document archive', 'document archive registered'], true) => ['row' => app(EmployeeDocumentService::class)->register($e, $this->file(), EmployeeDocumentCategory::EMPLOYMENT_CONTRACT, 'restricted', [], $hr)],
            $writer === 'department reparent' => ['row' => $this->createDepartment($this->school), 'other' => $this->createDepartment($this->school)],
            $writer === 'department reactivate' => ['row' => $this->createDepartment($this->school, ['status' => 'inactive'])],
            in_array($writer, ['department update', 'department archive'], true) => ['row' => $this->createDepartment($this->school)],
            $writer === 'position reactivate' => ['row' => $this->createPosition($this->school, ['status' => 'inactive'])],
            in_array($writer, ['position update', 'position archive'], true) => ['row' => $this->createPosition($this->school)],
            $writer === 'category reactivate' => ['row' => $this->createEmployeeCategory($this->school, ['status' => 'inactive'])],
            in_array($writer, ['category update', 'category archive'], true) => ['row' => $this->createEmployeeCategory($this->school)],
            $writer === 'reference retire' => ['row' => app(EmployeeReferenceCodeService::class)->record($e, EmployeeReferenceKind::ERP, 'ERP-1', $hr)],
            in_array($writer, ['task template version', 'task template retire'], true) => ['row' => app(TaskCatalogueService::class)->createTemplate($this->school, 'daily', $this->templateTerms(), $hr)],
            in_array($writer, ['task reassign', 'task cancel'], true) => ['row' => app(TaskAssignmentService::class)->assign($this->school, $this->employment->id, $this->taskTerms(), $hr), 'other' => $this->secondEmployment()],
            $writer === 'task review' => ['row' => $this->submittedTask()],
            default => [],
        };
    }

    private function secondEmployment(): EmploymentRecord
    {
        $record = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($record, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);

        return $record;
    }

    private function submittedTask(): WorkforceTask
    {
        $user = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $record = $this->createEmploymentRecord($this->createEmployee($this->school, ['user_id' => $user->id]), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($record, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $task = app(TaskAssignmentService::class)->assign($this->school, $record->id, $this->taskTerms(), $this->hr);

        return app(TaskWorkflowService::class)->submit($this->school, $task->id, $task->version, null, $user);
    }

    private function run_(string $writer): void
    {
        $e = $this->employee;
        $hr = $this->hr;
        $row = $this->made['row'] ?? null;
        $other = $this->made['other'] ?? null;
        $reason = ProfileRecordArchiveReason::NO_LONGER_CURRENT;
        $name = 'Never '.substr(md5($writer), 0, 6);

        match ($writer) {
            'address add' => app(EmployeeAddressService::class)->add($e, ['address_type' => 'current', 'address_line1' => '1 Road', 'city' => 'Pune', 'country_code' => 'IN'], $hr),
            'address update' => app(EmployeeAddressService::class)->update($e, $row, ['city' => $name], $hr),
            'address remove' => app(EmployeeAddressService::class)->remove($e, $row, $hr, $reason),
            'contact add' => app(EmployeeEmergencyContactService::class)->add($e, ['name' => $name, 'relationship' => 'sibling', 'phone' => '+919800000001'], $hr),
            'contact update' => app(EmployeeEmergencyContactService::class)->update($e, $row, ['name' => $name], $hr),
            'contact remove' => app(EmployeeEmergencyContactService::class)->remove($e, $row, $hr, $reason),
            'contact primary' => app(EmployeeEmergencyContactService::class)->setPrimary($e, $row, $hr),
            'note add' => app(EmployeeNoteService::class)->add($e, ['body' => $name, 'classification_tier' => 'sensitive'], $hr),
            'note update' => app(EmployeeNoteService::class)->update($e, $row, ['body' => $name], $hr),
            'note remove' => app(EmployeeNoteService::class)->remove($e, $row, $hr, $reason),
            'qualification add' => app(EmployeeQualificationService::class)->add($e, ['qualification_type' => 'degree', 'qualification_name' => $name, 'institution' => 'A College'], $hr),
            'qualification update' => app(EmployeeQualificationService::class)->update($e, $row, ['qualification_name' => $name], $hr),
            'qualification remove' => app(EmployeeQualificationService::class)->remove($e, $row, $hr, $reason),
            'qualification verify' => app(EmployeeQualificationService::class)->verify($e, $row, $hr, 1),
            'qualification reject' => app(EmployeeQualificationService::class)->reject($e, $row, $hr, 1),
            'experience add' => app(EmployeeExperienceService::class)->add($e, ['organization' => $name, 'job_title' => 'Teacher', 'starts_on' => '2010-01-01'], $hr),
            'experience update' => app(EmployeeExperienceService::class)->update($e, $row, ['organization' => $name], $hr),
            'experience remove' => app(EmployeeExperienceService::class)->remove($e, $row, $hr, $reason),
            'certification add' => app(EmployeeCertificationService::class)->add($e, ['name' => $name, 'issuer' => 'A Board'], $hr),
            'certification update' => app(EmployeeCertificationService::class)->update($e, $row, ['name' => $name], $hr),
            'certification remove' => app(EmployeeCertificationService::class)->remove($e, $row, $hr, $reason),
            'certification verify' => app(EmployeeCertificationService::class)->verify($e, $row, $hr, 1),
            'certification reject' => app(EmployeeCertificationService::class)->reject($e, $row, $hr, 1),
            'personal details' => app(EmployeePersonalDetailService::class)->setDetails($e, ['nationality' => 'Indian'], $hr),
            'document register' => app(EmployeeDocumentService::class)->register($e, $this->file(), EmployeeDocumentCategory::EMPLOYMENT_CONTRACT, 'restricted', [], $hr),
            'document update' => app(EmployeeDocumentService::class)->update($e, $row, ['issued_on' => '2026-01-01'], $hr),
            'document archive' => app(EmployeeDocumentService::class)->archive($e, $row, $hr),
            'document archive registered' => app(EmployeeDocumentService::class)->archiveRegistered($this->school, Document::query()->findOrFail($row->document_id), $hr),
            'generic document upload' => app(DocumentService::class)->create($this->school, new CreateDocumentData(DocumentOwner::employee($e->id), 'sensitive', $this->file()), $hr),
            'generic document archive' => app(DocumentService::class)->archive($this->school, $row, $hr),
            'department create' => app(DepartmentService::class)->create($this->school, ['name' => $name, 'code' => 'NEVER'], $hr),
            'department update' => app(DepartmentService::class)->update($row, ['name' => $name], $hr),
            'department archive' => app(DepartmentService::class)->archive($row, $hr),
            'department reactivate' => app(DepartmentService::class)->reactivate($row, $hr),
            'department reparent' => app(DepartmentService::class)->reparent($row, $other, $hr),
            'position create' => app(PositionService::class)->create($this->school, ['name' => $name, 'code' => 'NEVER'], $hr),
            'position update' => app(PositionService::class)->update($row, ['name' => $name], $hr),
            'position archive' => app(PositionService::class)->archive($row, $hr),
            'position reactivate' => app(PositionService::class)->reactivate($row, $hr),
            'category create' => app(EmployeeCategoryService::class)->create($this->school, ['name' => $name, 'code' => 'NEVER'], $hr),
            'category update' => app(EmployeeCategoryService::class)->update($row, ['name' => $name], $hr),
            'category archive' => app(EmployeeCategoryService::class)->archive($row, $hr),
            'category reactivate' => app(EmployeeCategoryService::class)->reactivate($row, $hr),
            'reference record' => app(EmployeeReferenceCodeService::class)->record($e, EmployeeReferenceKind::LEGACY, 'NEVER-1', $hr),
            'reference retire' => app(EmployeeReferenceCodeService::class)->retire($e, $row, ReferenceCodeRetireReason::cases()[0], $hr),
            'task category create' => app(TaskCatalogueService::class)->createCategory($this->school, 'never', 'Never', $hr),
            'task category retire' => app(TaskCatalogueService::class)->retireCategory($this->school, $this->category->id, $hr),
            'task template create' => app(TaskCatalogueService::class)->createTemplate($this->school, 'never', $this->templateTerms(), $hr),
            'task template version' => app(TaskCatalogueService::class)->addVersion($this->school, $row->id, $this->templateTerms(['effective_from' => '2026-07-01']), $hr),
            'task template retire' => app(TaskCatalogueService::class)->retireTemplate($this->school, $row->id, $hr),
            'task assign' => app(TaskAssignmentService::class)->assign($this->school, $this->employment->id, $this->taskTerms(), $hr),
            'task reassign' => app(TaskAssignmentService::class)->reassign($this->school, $row->id, $row->version, $other->id, $hr),
            'task review' => app(TaskWorkflowService::class)->review($this->school, $row->id, $row->version, 'complete', null, $hr),
            'task cancel' => app(TaskWorkflowService::class)->cancel($this->school, $row->id, $row->version, TaskCancelReason::NO_LONGER_NEEDED, $hr),
        };
    }

    private function suspend(): void
    {
        DB::table('schools')->where('id', $this->school->id)->update(['status' => 'suspended']);
    }

    private function auditCount(): int
    {
        return DB::table('school_audit_events')->where('school_id', $this->school->id)->count();
    }

    #[Test]
    #[DataProvider('writers')]
    public function a_suspended_school_refuses_the_write_when_it_executes_and_leaves_nothing(string $writer): void
    {
        $this->prepare($writer);
        $this->suspend();
        $audit = $this->auditCount();
        $row = isset($this->made['row']) ? $this->made['row']->fresh()?->getAttributes() : null;

        $refused = false;
        try {
            $this->run_($writer);
        } catch (SchoolNotOperationalException $e) {
            $refused = true;
            $this->assertSame(404, $e->getStatusCode(), 'the refusal says nothing about the School');
        }

        $this->assertTrue($refused, "{$writer} reached the write on a suspended School");
        $this->assertSame($audit, $this->auditCount(), 'nothing was audited');
        if ($row !== null) {
            $this->assertEquals($row, $this->made['row']->fresh()?->getAttributes(), 'the row is exactly as it was');
        }
    }

    #[Test]
    #[DataProvider('writers')]
    public function an_operational_school_keeps_the_write(string $writer): void
    {
        $this->prepare($writer);
        $audit = $this->auditCount();

        $this->run_($writer);

        $this->assertGreaterThan($audit, $this->auditCount(), "{$writer} is audited once it lands");
    }

    #[Test]
    public function the_refused_upload_stores_no_file(): void
    {
        $this->suspend();
        $disk = Storage::disk((string) config('documents.disk'));
        $before = count($disk->allFiles());

        try {
            app(EmployeeDocumentService::class)->register($this->employee, $this->file(), EmployeeDocumentCategory::EMPLOYMENT_CONTRACT, 'restricted', [], $this->hr);
            $this->fail('A suspended School took a document.');
        } catch (SchoolNotOperationalException) {
            // Refused before DocumentService::store() wrote the object.
        }

        $this->assertCount($before, $disk->allFiles());
    }

    #[Test]
    public function the_generic_route_refuses_before_the_bytes_are_stored(): void
    {
        $this->suspend();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->hr);

        $this->expectException(SchoolNotOperationalException::class);

        app(EmployeeDocumentPolicy::class)->beforeUpload($request, $this->school, $this->employee->id, 'sensitive');
    }

    #[Test]
    public function an_actor_without_a_document_capability_learns_nothing_of_the_school_on_the_generic_route(): void
    {
        $this->suspend();
        $stranger = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $stranger);

        // Asks nothing of the School: the service's capability refusal is what they get.
        app(EmployeeDocumentPolicy::class)->beforeUpload($request, $this->school, $this->employee->id, 'sensitive');

        $this->expectException(AuthorizationException::class);
        app(DocumentService::class)->create($this->school, new CreateDocumentData(DocumentOwner::employee($this->employee->id), 'sensitive', $this->file()), $stranger);
    }

    #[Test]
    public function a_refused_direct_generic_upload_leaves_no_bytes_no_row_and_no_audit(): void
    {
        $this->suspend();
        $disk = Storage::disk((string) config('documents.disk'));
        $files = count($disk->allFiles());
        $rows = DB::table('documents')->where('school_id', $this->school->id)->count();
        $audit = $this->auditCount();

        try {
            app(DocumentService::class)->create($this->school, new CreateDocumentData(DocumentOwner::employee($this->employee->id), 'sensitive', $this->file()), $this->hr);
            $this->fail('A suspended School took a document.');
        } catch (SchoolNotOperationalException) {
            // Below the policy's early check: the object was written and compensated.
        }

        $this->assertCount($files, $disk->allFiles());
        $this->assertSame($rows, DB::table('documents')->where('school_id', $this->school->id)->count());
        $this->assertSame($audit, $this->auditCount());
    }

    #[Test]
    public function a_suspended_school_starts_no_generation_run_and_reopens_none(): void
    {
        $generation = app(TaskGenerationService::class);
        $this->suspend();

        try {
            $generation->run($this->school);
            $this->fail('A suspended School started a run.');
        } catch (SchoolNotOperationalException) {
            $this->assertSame(0, DB::table('workforce_task_generation_runs')->where('school_id', $this->school->id)->count());
        }

        DB::table('schools')->where('id', $this->school->id)->update(['status' => 'active']);
        $finished = $generation->run($this->school);
        $this->assertSame('completed', $finished->status);
        $this->suspend();

        try {
            $generation->run($this->school, $this->hr);
            $this->fail('A suspended School reopened a run.');
        } catch (SchoolNotOperationalException) {
            $this->assertSame('completed', DB::table('workforce_task_generation_runs')->where('id', $finished->id)->value('status'));
        }
    }

    #[Test]
    public function another_schools_actor_is_refused_for_that_reason_and_learns_nothing_of_this_school(): void
    {
        $this->suspend();
        $other = $this->createSchool();
        $stranger = $this->createUserWithCapabilities($other, ['hr.employees.personal.view', 'hr.employees.personal.manage']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeAddressService::class)->add($this->employee, ['address_type' => 'current', 'address_line1' => '1 Road'], $stranger);
    }

    #[Test]
    public function a_campus_grant_of_a_whole_school_key_is_refused_for_that_reason(): void
    {
        $this->suspend();
        $campus = $this->createCampus($this->school);
        $actor = $this->createUser();
        $this->assignSchoolRole($this->createMembership($actor, $this->school), 'hr_officer', $campus->id);

        $this->expectException(AuthorizationException::class);

        app(EmployeeNoteService::class)->add($this->employee, ['body' => 'Never', 'classification_tier' => 'sensitive'], $actor);
    }

    #[Test]
    public function no_ambient_school_context_is_needed_for_the_refusal(): void
    {
        $this->suspend();
        app(TenantContext::class)->clearAll();

        $this->expectException(SchoolNotOperationalException::class);

        app(EmployeeNoteService::class)->add($this->employee, ['body' => 'Never', 'classification_tier' => 'sensitive'], $this->hr);
    }

    #[Test]
    public function an_import_of_personal_details_stays_neutral_on_a_suspended_school(): void
    {
        $this->suspend();
        $before = DB::table('employee_personal_details')->where('school_id', $this->school->id)->count();

        $result = app(EmployeeImportService::class)->import($this->school, $this->hr, [['full_name' => 'Never Written', 'date_of_birth' => '1990-01-01']]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('unavailable', $result->rows[0]->errors[0]['code']);
        $this->assertSame('This row could not be processed right now.', $result->rows[0]->errors[0]['message']);
        $this->assertSame($before, DB::table('employee_personal_details')->where('school_id', $this->school->id)->count());
    }

    #[Test]
    public function an_actor_without_the_capability_is_refused_for_that_reason_before_the_school_is_consulted(): void
    {
        $this->suspend();
        $stranger = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);

        $this->expectException(AuthorizationException::class);

        app(DepartmentService::class)->create($this->school, ['name' => 'Never', 'code' => 'NEVER'], $stranger);
    }
}
