<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeCategoryService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeUserLinkNotEditableException;
use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C13 (WO-2, GOV-6): a service-bound model takes its owner, parent, state and actor
 * columns from its writer, whatever a caller hands it. Production has no strict mode, so
 * these run with it off, the way a real request would, and assert what is in the row
 * afterwards: a test that only caught an exception would prove nothing about the row.
 * Before C13 the strip lists alone stood between a caller's array and these columns.
 */
class HrServiceBoundModelTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private School $foreign;

    private User $hr;

    private Employee $employee;

    private Employee $other;

    /** @var array<string, mixed> */
    private array $forged;

    protected function setUp(): void
    {
        parent::setUp();
        // Production: no strict mode and no callback. TestCase::setUp() restores both for the next test class.
        Model::preventSilentlyDiscardingAttributes(false);
        Model::handleDiscardedAttributeViolationUsing(null);
        Storage::fake((string) config('documents.disk'));
        $this->school = $this->createSchool();
        $this->foreign = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view', 'hr.employees.personal.manage',
            'hr.employees.qualifications.view', 'hr.employees.qualifications.manage', 'hr.employees.notes.view', 'hr.employees.notes.manage',
            'hr.employees.documents.view', 'hr.employees.documents.manage', 'hr.departments.view', 'hr.departments.manage',
            'hr.positions.view', 'hr.positions.manage', 'hr.categories.view', 'hr.categories.manage', 'hr.leave.view', 'hr.leave.configure',
        ]);
        $this->employee = $this->createEmployee($this->school);
        $this->other = $this->createEmployee($this->school);
        $this->forged = [
            'school_id' => $this->foreign->id, 'employee_id' => $this->other->id, 'status' => 'inactive', 'is_primary' => true, 'verification_status' => 'verified',
            'verified_at' => '2020-01-01 00:00:00', 'author_user_id' => $this->createUser()->id, 'archived_at' => '2020-01-01 00:00:00', 'id' => '00000000-0000-7000-8000-000000000001',
        ];
    }

    protected function tearDown(): void
    {
        Model::preventSilentlyDiscardingAttributes(true);
        parent::tearDown();
    }

    private function assertOwned(Model $row): void
    {
        $stored = (array) DB::table($row->getTable())->where('id', $row->getKey())->first();

        $this->assertNotSame([], $stored, 'the row was written');
        $this->assertSame($this->school->id, $stored['school_id']);
        if (array_key_exists('employee_id', $stored)) {
            $this->assertSame($this->employee->id, $stored['employee_id']);
        }
        if (array_key_exists('archived_at', $stored)) {
            $this->assertNull($stored['archived_at']);
        }
        if (array_key_exists('verification_status', $stored)) {
            $this->assertSame('unverified', $stored['verification_status']);
            $this->assertNull($stored['verified_at']);
        }
        if (array_key_exists('is_primary', $stored)) {
            $this->assertFalse((bool) $stored['is_primary']);
        }
        if (array_key_exists('status', $stored)) {
            $this->assertSame('active', $stored['status']);
        }
        if (array_key_exists('author_user_id', $stored)) {
            $this->assertSame($this->hr->id, $stored['author_user_id']);
        }
        $this->assertNotSame($this->forged['id'], $stored['id']);
    }

    #[Test]
    public function a_profile_record_takes_its_owner_from_the_writer_on_create_and_on_update(): void
    {
        $e = $this->employee;
        $hr = $this->hr;

        $address = app(EmployeeAddressService::class)->add($e, [...$this->forged, 'address_type' => 'current', 'address_line1' => '1 Road'], $hr);
        $this->assertOwned($address);
        $this->assertOwned(app(EmployeeAddressService::class)->update($e, $address, [...$this->forged, 'city' => 'Pune'], $hr));

        $contact = app(EmployeeEmergencyContactService::class)->add($e, [...$this->forged, 'name' => 'A', 'relationship' => 'sibling', 'phone' => '+919800000001'], $hr);
        $this->assertOwned($contact);
        $this->assertOwned(app(EmployeeEmergencyContactService::class)->update($e, $contact, [...$this->forged, 'name' => 'B'], $hr));

        $note = app(EmployeeNoteService::class)->add($e, [...$this->forged, 'body' => 'A note', 'classification_tier' => 'sensitive'], $hr);
        $this->assertOwned($note);
        $this->assertOwned(app(EmployeeNoteService::class)->update($e, $note, [...$this->forged, 'body' => 'Edited'], $hr));

        $qualification = app(EmployeeQualificationService::class)->add($e, [...$this->forged, 'qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $hr);
        $this->assertOwned($qualification);
        $this->assertOwned(app(EmployeeQualificationService::class)->update($e, $qualification, [...$this->forged, 'qualification_name' => 'MEd'], $hr));

        $experience = app(EmployeeExperienceService::class)->add($e, [...$this->forged, 'organization' => 'A School', 'job_title' => 'Teacher', 'starts_on' => '2010-01-01'], $hr);
        $this->assertOwned($experience);
        $this->assertOwned(app(EmployeeExperienceService::class)->update($e, $experience, [...$this->forged, 'organization' => 'B School'], $hr));

        $certification = app(EmployeeCertificationService::class)->add($e, [...$this->forged, 'name' => 'First aid', 'issuer' => 'A Board'], $hr);
        $this->assertOwned($certification);
        $this->assertOwned(app(EmployeeCertificationService::class)->update($e, $certification, [...$this->forged, 'name' => 'CPR'], $hr));

        $this->assertOwned(app(EmployeePersonalDetailService::class)->setDetails($e, [...$this->forged, 'nationality' => 'Indian'], $hr));
    }

    #[Test]
    public function the_personal_details_writer_updates_the_one_row_and_only_its_fields(): void
    {
        $service = app(EmployeePersonalDetailService::class);
        $first = $service->setDetails($this->employee, ['nationality' => 'Indian'], $this->hr);
        $second = $service->setDetails($this->employee, [...$this->forged, 'preferred_language' => 'Marathi'], $this->hr);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('employee_personal_details')->where('employee_id', $this->employee->id)->count());
        $this->assertSame(['Indian', 'Marathi'], [$second->fresh()->nationality, $second->fresh()->preferred_language]);
    }

    #[Test]
    public function a_verification_is_set_by_its_operation_alone(): void
    {
        $service = app(EmployeeQualificationService::class);
        $row = $service->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);

        $this->assertSame('verified', $service->verify($this->employee, $row, $this->hr)->fresh()->verification_status);
        $this->assertSame('rejected', $service->reject($this->employee, $row->fresh(), $this->hr)->fresh()->verification_status);
    }

    #[Test]
    public function a_material_edit_still_resets_a_verification_where_a_dropped_key_would_be_silent(): void
    {
        // The writer used to add the reset to its array. Once those columns left `$fillable`, production would have
        // dropped it with no error and left an edited credential verified. Strict mode is off here, as in production.
        $qualifications = app(EmployeeQualificationService::class);
        $qualification = $qualifications->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);
        $qualifications->verify($this->employee, $qualification, $this->hr);
        $edited = $qualifications->update($this->employee, $qualification->fresh(), ['qualification_name' => 'MEd'], $this->hr);
        $this->assertSame(['MEd', 'unverified', null], [$edited->qualification_name, $edited->verification_status, $edited->verified_at]);

        $certifications = app(EmployeeCertificationService::class);
        $certification = $certifications->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board'], $this->hr);
        $certifications->verify($this->employee, $certification, $this->hr);
        $editedCertification = $certifications->update($this->employee, $certification->fresh(), ['name' => 'CPR'], $this->hr);
        $this->assertSame(['CPR', 'unverified', null], [$editedCertification->name, $editedCertification->verification_status, $editedCertification->verified_at]);

        $audit = DB::table('school_audit_events')->where('event_type', 'hr.qualification.updated')->where('school_id', $this->school->id)->orderByDesc('id')->first();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(['qualification_name', 'verification_status', 'verified_at'], $metadata['fields'], 'the audit names the reset, as it did');
        $this->assertTrue($metadata['verificationReset']);
    }

    #[Test]
    public function an_empty_edit_of_a_verified_record_changes_nothing(): void
    {
        $service = app(EmployeeQualificationService::class);
        $qualification = $service->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);
        $service->verify($this->employee, $qualification, $this->hr);

        $this->assertSame('verified', $service->update($this->employee, $qualification->fresh(), [], $this->hr)->verification_status);
    }

    #[Test]
    public function reference_data_takes_its_state_and_parents_from_its_operations(): void
    {
        $campus = $this->createCampus($this->school);
        $parent = $this->createDepartment($this->school);

        $department = app(DepartmentService::class)->create($this->school, [...$this->forged, 'name' => 'Science', 'code' => 'sci'], $this->hr, $campus, $parent);
        $this->assertSame([$this->school->id, 'active', $campus->id, $parent->id, 'SCI'], [$department->school_id, $department->status, $department->campus_id, $department->parent_department_id, $department->code]);
        $updated = app(DepartmentService::class)->update($department, [...$this->forged, 'campus_id' => null, 'parent_department_id' => null, 'name' => 'Sciences'], $this->hr)->fresh();
        $this->assertSame(['Sciences', 'active', $campus->id, $parent->id], [$updated->name, $updated->status, $updated->campus_id, $updated->parent_department_id]);
        $this->assertSame('inactive', app(DepartmentService::class)->archive($updated, $this->hr)->fresh()->status);
        $this->assertSame('active', app(DepartmentService::class)->reactivate($updated->fresh(), $this->hr)->fresh()->status);
        $this->assertNull(app(DepartmentService::class)->reparent($updated->fresh(), null, $this->hr)->fresh()->parent_department_id);

        $position = app(PositionService::class)->create($this->school, [...$this->forged, 'name' => 'Teacher', 'code' => 'tch'], $this->hr);
        $this->assertSame([$this->school->id, 'active'], [$position->school_id, $position->status]);
        $this->assertSame('active', app(PositionService::class)->update($position, [...$this->forged, 'name' => 'Senior'], $this->hr)->fresh()->status);
        $this->assertSame('inactive', app(PositionService::class)->archive($position->fresh(), $this->hr)->fresh()->status);

        $category = app(EmployeeCategoryService::class)->create($this->school, [...$this->forged, 'name' => 'Teaching', 'code' => 'tch'], $this->hr);
        $this->assertSame([$this->school->id, 'active'], [$category->school_id, $category->status]);
        $this->assertSame('active', app(EmployeeCategoryService::class)->update($category, [...$this->forged, 'name' => 'Faculty'], $this->hr)->fresh()->status);
    }

    #[Test]
    public function a_leave_type_keeps_its_status_and_takes_its_school_from_the_writer(): void
    {
        $type = app(LeaveTypeService::class)->create($this->school, 'CL', 'Casual', true, true, true, $this->hr);
        $this->assertSame([$this->school->id, 'active'], [$type->school_id, $type->status]);

        $updated = app(LeaveTypeService::class)->update($this->school, $type->id, ['name' => 'Casual leave', 'status' => 'inactive', 'school_id' => $this->foreign->id], $this->hr)->fresh();

        $this->assertSame(['Casual leave', 'active', $this->school->id], [$updated->name, $updated->status, $updated->school_id]);
    }

    #[Test]
    public function a_document_takes_nothing_from_its_caller_but_its_four_fields(): void
    {
        $document = $this->registerHrDocument($this->employee, $this->hr);
        $updated = app(EmployeeDocumentService::class)->update($this->employee, $document, [...$this->forged, 'uploaded_by_user_id' => $this->createUser()->id, 'issued_on' => '2026-01-01'], $this->hr);

        $stored = DB::table('employee_documents')->where('id', $updated->id)->first();
        $this->assertSame([$this->school->id, $this->employee->id, $this->hr->id, 'active'], [$stored->school_id, $stored->employee_id, $stored->uploaded_by_user_id, $stored->status]);
        $this->assertSame('2026-01-01', substr((string) $stored->issued_on, 0, 10));
    }

    #[Test]
    public function the_employee_link_never_moves_through_an_edit_and_the_number_is_never_supplied(): void
    {
        $employee = app(EmployeeService::class)->create($this->school, ['full_name' => 'Named Person'], $this->hr);
        $this->assertNotNull($employee->employee_number);
        $number = $employee->employee_number;

        $updated = app(EmployeeService::class)->update($employee, ['full_name' => 'Renamed', 'employee_number' => 'FORGED-1', 'record_status' => 'archived', 'school_id' => $this->foreign->id], $this->hr)->fresh();
        $this->assertSame(['Renamed', $number, 'active', $this->school->id], [$updated->full_name, $updated->employee_number, $updated->record_status, $updated->school_id]);

        $this->expectException(EmployeeUserLinkNotEditableException::class);
        app(EmployeeService::class)->update($employee, ['user_id' => $this->createUser()->id], $this->hr);
    }

    #[Test]
    public function a_link_is_written_by_its_operation_and_recorded_in_its_history(): void
    {
        $account = $this->createUser();
        $this->createMembership($account, $this->school);

        $linked = app(EmployeeService::class)->linkUser($this->employee, $account->id, $this->hr);
        $this->assertSame($account->id, $linked->fresh()->user_id);
        $this->assertSame(1, DB::table('employee_account_links')->where('employee_id', $this->employee->id)->count());

        $this->assertNull(app(EmployeeService::class)->unlinkUser($linked->fresh(), $this->hr)->fresh()->user_id);
        $this->assertSame(1, DB::table('employee_account_links')->where('employee_id', $this->employee->id)->whereNotNull('ended_at')->count());
    }

    #[Test]
    public function a_model_with_no_fillable_column_refuses_even_outside_strict_mode(): void
    {
        $this->expectException(MassAssignmentException::class);

        new EmployeeAssignment(['employment_record_id' => $this->foreign->id]);
    }

    #[Test]
    public function the_upload_path_still_stores_a_row_through_the_real_writer(): void
    {
        $record = app(EmployeeDocumentService::class)->register(
            $this->employee,
            UploadedFile::fake()->createWithContent('record.pdf', "%PDF-1.4\n%hr-document fixture\n"),
            EmployeeDocumentCategory::EMPLOYMENT_CONTRACT,
            'restricted',
            [],
            $this->hr,
        );

        $this->assertSame([$this->school->id, $this->employee->id, $this->hr->id], [$record->school_id, $record->employee_id, $record->uploaded_by_user_id]);
    }
}
