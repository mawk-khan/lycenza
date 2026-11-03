<?php

namespace Tests\Feature\HR\Profile;

use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeProfileChangedException;
use App\Domain\HR\Application\Exceptions\EmployeeReferenceCodeConflictException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\Exceptions\ProfileRecordArchivedException;
use App\Domain\HR\Application\Profile\EmployeeProfileVersion;
use App\Domain\HR\Application\Profile\EmployeeReferenceCodeService;
use App\Domain\HR\Application\Profile\EmployeeReferenceKind;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Application\Profile\ReferenceCodeRetireReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.2A: the Staff Profile foundation through its services and HTTP --
 * reference codes (D1-03), archive-not-delete with a closed reason (S-16),
 * the self-administration guard over every profile write (S-3), stale-form
 * tokens, governed document uploads and downloads (S-12, D1-26), derived
 * experience, and who may see and do what.
 */
class StaffProfileFoundationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function signedIn(User $user): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    private function codes(): EmployeeReferenceCodeService
    {
        return app(EmployeeReferenceCodeService::class);
    }

    // --- Reference codes (D1-03) ---------------------------------------------

    #[Test]
    public function a_reference_code_is_normalized_school_unique_never_reused_and_found_by_exact_search(): void
    {
        $a = $this->createEmployee($this->school, ['full_name' => 'Asha Code']);
        $b = $this->createEmployee($this->school);

        $code = $this->codes()->record($a, EmployeeReferenceKind::ERP, '  erp-0042 ', $this->hr);
        $this->assertSame('ERP-0042', $code->value);

        $refusal = function (callable $call): string {
            try {
                $call();
            } catch (EmployeeReferenceCodeConflictException $e) {
                return $e->reason;
            }

            return 'allowed';
        };
        $this->assertSame('value_taken', $refusal(fn () => $this->codes()->record($b, EmployeeReferenceKind::ERP, 'ERP-0042', $this->hr)));
        $this->assertSame('kind_taken', $refusal(fn () => $this->codes()->record($a, EmployeeReferenceKind::ERP, 'ERP-0043', $this->hr)));
        $this->assertSame('format', $refusal(fn () => $this->codes()->record($a, EmployeeReferenceKind::LEGACY, '<script>', $this->hr)));

        $page = app(EmployeeDirectoryService::class)->search($this->school, new EmployeeDirectoryQuery(search: 'erp-0042'), $this->hr);
        $this->assertSame([$a->id], collect($page->items())->pluck('employeeId')->all(), 'exact, case-insensitive reference search');

        $this->codes()->retire($a, $code, ReferenceCodeRetireReason::REPLACED, $this->hr);
        $this->assertSame('value_taken', $refusal(fn () => $this->codes()->record($b, EmployeeReferenceKind::ERP, 'ERP-0042', $this->hr)), 'never reused');
        $this->assertSame('retired', $refusal(fn () => $this->codes()->retire($a, $this->inSchool(fn () => $code->fresh()), ReferenceCodeRetireReason::REPLACED, $this->hr)));
        $this->assertSame([], collect(app(EmployeeDirectoryService::class)->search($this->school, new EmployeeDirectoryQuery(search: 'ERP-0042'), $this->hr)->items())->all(), 'a retired code finds nobody');

        $events = $this->inSchool(fn () => SchoolAuditEvent::query()->whereIn('event_type', ['hr.reference_code.recorded', 'hr.reference_code.retired'])->get());
        $this->assertCount(2, $events);
        $this->assertStringNotContainsString('ERP-0042', $events->toJson(), 'the audit never carries the value');
    }

    #[Test]
    public function reference_codes_are_whole_school_and_never_ones_own(): void
    {
        $campus = $this->createCampus($this->school);
        $employee = $this->createEmployee($this->school);
        $campusHr = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campusHr, $this->school), 'hr_officer', $campus->id);

        $this->signedIn($campusHr)->post("/app/hr/employees/{$employee->id}/reference-codes", ['kind' => 'erp', 'value' => 'E-1'])->assertForbidden();

        $own = $this->createEmployee($this->school, ['user_id' => $this->hr->id]);
        $this->expectException(HrSelfAdministrationException::class);
        $this->codes()->record($own, EmployeeReferenceKind::ERP, 'E-2', $this->hr);
    }

    // --- Archive, never delete (S-16) ------------------------------------------

    #[Test]
    public function removing_a_record_archives_it_with_a_closed_reason_and_it_stays_as_evidence(): void
    {
        $employee = $this->createEmployee($this->school);
        $address = app(EmployeeAddressService::class)->add($employee, ['address_type' => 'current', 'address_line1' => '1 Test Lane', 'country_code' => 'IN'], $this->hr);

        $this->signedIn($this->hr)->from("/app/hr/employees/{$employee->id}")
            ->delete("/app/hr/employees/{$employee->id}/addresses/{$address->id}")->assertSessionHasErrors('reason_code');
        $this->signedIn($this->hr)->delete("/app/hr/employees/{$employee->id}/addresses/{$address->id}", ['reason_code' => 'superseded'])->assertRedirect();

        $archived = $this->inSchool(fn () => EmployeeAddress::query()->findOrFail($address->id));
        $this->assertSame(['superseded', $this->hr->id], [$archived->archive_reason, $archived->archived_by_user_id]);
        $profile = $this->signedIn($this->hr)->get("/app/hr/employees/{$employee->id}")->viewData('page')['props'];
        $this->assertSame([], $profile['workspace']['addresses'], 'an archived record is not current profile data');

        $this->expectException(ProfileRecordArchivedException::class);
        app(EmployeeAddressService::class)->update($employee, $archived, ['city' => 'Later'], $this->hr);
    }

    // --- Self-administration (S-3) -----------------------------------------------

    #[Test]
    public function nobody_writes_their_own_hr_profile_records(): void
    {
        $own = $this->createEmployee($this->school, ['user_id' => $this->hr->id]);
        $address = $this->inSchool(fn () => EmployeeAddress::query()->create(['employee_id' => $own->id, 'address_type' => 'current', 'address_line1' => '1 Own Street', 'country_code' => 'IN']));

        foreach ([
            'identity' => fn () => app(EmployeeService::class)->update($own, ['full_name' => 'Self Renamed'], $this->hr),
            'personal' => fn () => app(EmployeePersonalDetailService::class)->setDetails($own, ['nationality' => 'X'], $this->hr),
            'address' => fn () => app(EmployeeAddressService::class)->add($own, ['address_type' => 'permanent', 'address_line1' => 'x', 'country_code' => 'IN'], $this->hr),
            'archive' => fn () => app(EmployeeAddressService::class)->remove($own, $address, $this->hr, ProfileRecordArchiveReason::RECORDED_IN_ERROR),
            'document' => fn () => $this->registerHrDocument($own, $this->hr),
        ] as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: an HR officer wrote their own HR record");
            } catch (HrSelfAdministrationException) {
            }
        }
        $this->assertNull($this->inSchool(fn () => EmployeeAddress::query()->findOrFail($address->id))->archived_at);
    }

    // --- Stale-form tokens ----------------------------------------------------------

    #[Test]
    public function a_stale_or_missing_version_is_refused_on_identity_and_personal_edits(): void
    {
        $employee = $this->createEmployee($this->school, ['full_name' => 'Old Name']);
        $stale = $this->employeeIdentityVersion($employee->id);
        app(EmployeeService::class)->update($employee, ['full_name' => 'Newer Name'], $this->hr);

        try {
            app(EmployeeService::class)->update($employee, ['full_name' => 'Older Form'], $this->hr, $stale);
            $this->fail('a stale identity form overwrote a newer change');
        } catch (EmployeeProfileChangedException) {
        }
        $this->assertSame('Newer Name', $this->inSchool(fn () => Employee::query()->findOrFail($employee->id))->full_name);

        $api = fn () => $this->withHeader('Authorization', 'Bearer '.$this->hr->createToken('t')->plainTextToken);
        $url = "/api/v1/schools/{$this->school->id}/employees/{$employee->id}";
        $api()->patchJson($url, ['full_name' => 'No Version'])->assertStatus(422);
        app('auth')->forgetGuards();
        $api()->patchJson($url, ['full_name' => 'Stale', 'version' => $stale])->assertStatus(409)->assertJsonPath('error.code', 'HR_PROFILE_CHANGED');
        app('auth')->forgetGuards();
        $api()->patchJson($url, ['full_name' => 'Fresh', 'version' => $this->employeeIdentityVersion($employee->id)])->assertOk()
            ->assertJsonPath('data.version', EmployeeProfileVersion::identity($this->inSchool(fn () => Employee::query()->findOrFail($employee->id))));

        $personal = $this->employeePersonalVersion($employee->id);
        app(EmployeePersonalDetailService::class)->setDetails($employee, ['nationality' => 'First'], $this->hr, $personal);
        $this->expectException(EmployeeProfileChangedException::class);
        app(EmployeePersonalDetailService::class)->setDetails($employee, ['nationality' => 'Second'], $this->hr, $personal);
    }

    #[Test]
    public function contact_values_are_normalized_and_phones_validated_on_the_server(): void
    {
        $employee = $this->createEmployee($this->school);

        $this->signedIn($this->hr)->from("/app/hr/employees/{$employee->id}")
            ->put("/app/hr/employees/{$employee->id}/personal-detail", ['personal_phone' => '<b>123</b>', 'version' => $this->employeePersonalVersion($employee->id)])
            ->assertSessionHasErrors('personal_phone');
        $this->signedIn($this->hr)->put("/app/hr/employees/{$employee->id}/personal-detail", [
            'personal_email' => '  Asha.Personal@Example.TEST ', 'personal_phone' => '+91  98765   43210', 'version' => $this->employeePersonalVersion($employee->id),
        ])->assertRedirect();

        $detail = $this->inSchool(fn () => DB::table('employee_personal_details')->where('employee_id', $employee->id)->first());
        $this->assertSame(['asha.personal@example.test', '+91 98765 43210'], [$detail->personal_email, $detail->personal_phone]);
    }

    // --- Governed documents (S-12, D1-26) ---------------------------------------------

    #[Test]
    public function a_document_is_uploaded_through_documents_and_downloaded_only_by_its_tiers_readers(): void
    {
        Storage::fake((string) config('documents.disk'));
        $employee = $this->createEmployee($this->school);

        $this->signedIn($this->hr)->from("/app/hr/employees/{$employee->id}")->post("/app/hr/employees/{$employee->id}/hr-document-records", [
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.4\n%contract\n"), 'category' => 'other', 'classification_tier' => 'restricted',
        ])->assertSessionHasErrors('category');
        $this->signedIn($this->hr)->from("/app/hr/employees/{$employee->id}")->post("/app/hr/employees/{$employee->id}/hr-document-records", [
            'file' => UploadedFile::fake()->createWithContent('run.exe', 'MZ binary'), 'category' => 'employment_contract', 'classification_tier' => 'restricted',
        ])->assertSessionHasErrors();
        $this->signedIn($this->hr)->post("/app/hr/employees/{$employee->id}/hr-document-records", [
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.4\n%contract\n"), 'category' => 'employment_contract', 'classification_tier' => 'restricted',
        ])->assertRedirect("/app/hr/employees/{$employee->id}");

        $record = $this->inSchool(fn () => DB::table('employee_documents')->where('employee_id', $employee->id)->sole());
        $this->assertNull($record->storage_path);
        $download = "/app/hr/employees/{$employee->id}/hr-document-records/{$record->id}/download";

        $this->signedIn($this->hr)->get($download)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=contract.pdf');
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.personal.view']);
        $this->signedIn($viewer)->get($download)->assertForbidden();
        $other = $this->createEmployee($this->school);
        $this->signedIn($this->hr)->get("/app/hr/employees/{$other->id}/hr-document-records/{$record->id}/download")->assertNotFound();

        $this->signedIn($this->hr)->post("/app/hr/employees/{$employee->id}/hr-document-records/{$record->id}/archive")->assertRedirect();
        $this->assertSame('archived', $this->inSchool(fn () => DB::table('documents')->where('id', $record->document_id)->value('status')), 'the Document is archived with its record');
    }

    #[Test]
    public function a_highly_sensitive_hr_file_is_never_read_without_mfa_through_the_generic_documents_api(): void
    {
        $employee = $this->createEmployee($this->school);
        $record = $this->registerHrDocument($employee, $this->hr, 'highly_sensitive', 'identity_document');
        $api = fn () => $this->withHeader('Authorization', 'Bearer '.$this->hr->createToken('t')->plainTextToken);
        $base = "/api/v1/schools/{$this->school->id}";

        // HR.2A security review: no current MFA assurance -> refused on every generic read path.
        foreach (["{$base}/documents/{$record->document_id}/content", "{$base}/documents/{$record->document_id}", "{$base}/employees/{$employee->id}/documents/sensitive"] as $url) {
            app('auth')->forgetGuards();
            $status = $api()->getJson($url)->status();
            $this->assertContains($status, [401, 403], "{$url} served a Highly Sensitive HR file without MFA");
        }
        // And the staff web download asks for MFA too.
        $download = $this->signedIn($this->hr)->get("/app/hr/employees/{$employee->id}/hr-document-records/{$record->id}/download");
        $this->assertNotSame(200, $download->status());
    }

    // --- Profile composition and personas ------------------------------------------------

    #[Test]
    public function the_profile_derives_experience_and_shows_each_part_only_to_its_readers(): void
    {
        $employee = $this->createEmployee($this->school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'status' => 'active']);
        $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'is_primary' => true]);
        $this->inSchool(function () use ($employee): void {
            foreach ([['2015-01-01', '2018-12-31'], ['2017-01-01', '2019-12-31'], ['2020-06-01', null]] as [$from, $to]) {
                DB::table('employee_experience_records')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id, 'organization' => 'Prior School', 'job_title' => 'Teacher', 'starts_on' => $from, 'ends_on' => $to, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->codes()->record($employee, EmployeeReferenceKind::STAFF_REFERENCE, 'SR-7', $this->hr);

        $props = $this->signedIn($this->hr)->get("/app/hr/employees/{$employee->id}")->assertOk()->viewData('page')['props']['staffProfile'];
        $this->assertSame(['days' => 1826, 'years' => 4, 'months' => 11, 'openEnded' => 1], $props['experience']['previous'], '2015..2019 merged, the open-ended job not counted');
        $this->assertArrayHasKey('service', $props['experience']);
        $this->assertSame('SR-7', $props['referenceCodes'][0]['value']);
        $this->assertArrayHasKey('identity', $props['versions']);

        $personalOnly = $this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.personal.view']);
        $limited = $this->signedIn($personalOnly)->get("/app/hr/employees/{$employee->id}")->assertOk()->viewData('page')['props']['staffProfile'];
        $this->assertSame([], $limited['experience'], 'absent, never blanked');
        $this->assertSame([], $limited['versions']);
        $this->assertFalse($limited['can']['manageReferenceCodes']);
    }

    #[Test]
    public function a_manager_a_teacher_a_payroll_operator_and_a_platform_root_get_no_profile_from_their_role_alone(): void
    {
        $employee = $this->createEmployee($this->school);
        $manager = $this->createUserWithCapabilities($this->school, ['hr.leave.approve']);
        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $payroll = $this->createUserWithCapabilities($this->school, ['payroll.runs.view']);
        $root = $this->createPlatformRoot();

        foreach (['manager' => $manager, 'teacher' => $teacher, 'payroll' => $payroll] as $label => $user) {
            $status = $this->signedIn($user)->get("/app/hr/employees/{$employee->id}")->status();
            $this->assertContains($status, [403, 404], "{$label} reached the Profile");
        }
        app('auth')->forgetGuards();
        $this->assertContains($this->actingAs($root)->get("/app/hr/employees/{$employee->id}")->status(), [302, 403, 404], 'platform authority is not HR data authority');
    }

    #[Test]
    public function an_employee_without_a_user_account_has_a_full_profile_and_a_rehire_keeps_one_identity(): void
    {
        $employee = $this->createEmployee($this->school);
        $this->assertNull($employee->user_id);
        $this->codes()->record($employee, EmployeeReferenceKind::ERP, 'NOUSER-1', $this->hr);
        $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => '2021-12-31', 'status' => 'separated']);
        $this->createEmploymentRecord($employee, ['starts_on' => '2023-01-01', 'status' => 'active']);

        $props = $this->signedIn($this->hr)->get("/app/hr/employees/{$employee->id}")->assertOk()->viewData('page')['props'];
        $this->assertCount(2, $props['workspace']['employment_history'], 'two employments, one Employee');
        $this->assertSame(1, $this->inSchool(fn () => Employee::query()->count()));
        $this->assertSame('NOUSER-1', $props['staffProfile']['referenceCodes'][0]['value']);
    }

    #[Test]
    public function the_staff_profile_reader_takes_a_constant_number_of_queries(): void
    {
        $small = $this->createEmployee($this->school);
        $large = $this->createEmployee($this->school);
        foreach (['ERP' => EmployeeReferenceKind::ERP, 'SR' => EmployeeReferenceKind::STAFF_REFERENCE, 'LG' => EmployeeReferenceKind::LEGACY] as $prefix => $kind) {
            $this->codes()->record($large, $kind, "{$prefix}-1", $this->hr);
        }
        $count = function (Employee $employee): int {
            $this->signedIn($this->hr);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/app/hr/employees/{$employee->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count($small); // warm-up (session, capability cache)
        $this->assertSame($count($small), $count($large));
    }

    #[Test]
    public function a_campus_scoped_officer_cannot_see_or_write_another_campus_staff_profile(): void
    {
        $north = $this->createCampus($this->school, ['name' => 'North']);
        $south = $this->createCampus($this->school, ['name' => 'South']);
        $southStaff = $this->createEmployee($this->school);
        $employment = $this->createEmploymentRecord($southStaff, ['starts_on' => '2024-01-01', 'status' => 'active']);
        $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'is_primary' => true, 'campus_id' => $south->id]);
        $northHr = $this->createUser();
        $this->assignSchoolRole($this->createMembership($northHr, $this->school), 'hr_officer', $north->id);

        $this->assertContains($this->signedIn($northHr)->get("/app/hr/employees/{$southStaff->id}/overview")->status(), [403, 404]);
        $this->signedIn($northHr)->post("/app/hr/employees/{$southStaff->id}/reference-codes", ['kind' => 'erp', 'value' => 'X-1'])->assertForbidden();
        $api = $this->withHeader('Authorization', 'Bearer '.$northHr->createToken('t')->plainTextToken);
        $api->patchJson("/api/v1/schools/{$this->school->id}/employees/{$southStaff->id}", ['full_name' => 'X', 'version' => $this->employeeIdentityVersion($southStaff->id)])->assertNotFound();
        unset($north, $employment);
        $this->assertInstanceOf(Campus::class, $south);
    }

    #[Test]
    public function an_unauthorized_user_writes_nothing(): void
    {
        $employee = $this->createEmployee($this->school);
        $nobody = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);

        $this->expectException(AuthorizationException::class);
        app(EmployeeAddressService::class)->add($employee, ['address_type' => 'current', 'address_line1' => 'x', 'country_code' => 'IN'], $nobody);
    }
}
