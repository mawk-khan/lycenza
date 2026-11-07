<?php

namespace Tests\Feature\HR;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * HR.R1-C10 (HRC-3, ADR 0071 §26.7, rule 95): every write of a Highly Sensitive HR
 * document needs a fresh MFA code, on every route that can make one. The HR
 * register had the step-up; the generic Documents API, which reaches the same
 * tier, did not. Reproduced on `82e70a8a`: an upload answered 201 and an archive 204
 * with no code, a person could file on their own record, and a generic archive left
 * the HR record active over an archived Document.
 */
class HighlySensitiveHrDocumentWriteTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private User $writer;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('documents.disk'));
        $this->school = $this->createSchool();
        $this->writer = $this->createUserWithCapabilities($this->school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view', 'hr.employees.documents.manage', 'hr.employees.documents.view', 'hr.employees.view']);
        $this->employee = $this->createEmployee($this->school);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function base(): string
    {
        return "/api/v1/schools/{$this->school->id}";
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\n%hrc-3\n");
    }

    /** @param  array<string, mixed>  $data */
    private function api(User $as, string $path, array $data = [], bool $idempotent = false): TestResponse
    {
        app('auth')->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.$as->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        if ($idempotent) {
            $headers['Idempotency-Key'] = (string) Str::uuid7();
        }

        return $this->withHeaders($headers)->post($this->base().$path, $data);
    }

    private function genericUpload(User $as, Employee $employee, array $extra = []): TestResponse
    {
        return $this->api($as, "/employees/{$employee->id}/documents", ['file' => $this->file(), 'classification_tier' => 'highly_sensitive'] + $extra);
    }

    private function registerUpload(User $as, array $extra = []): TestResponse
    {
        return $this->api($as, "/employees/{$this->employee->id}/hr-document-records", ['file' => $this->file(), 'category' => 'identity_document', 'classification_tier' => 'highly_sensitive'] + $extra, idempotent: true);
    }

    private function documents(): int
    {
        return (int) $this->inSchool(fn () => DB::table('documents')->where('employee_id', $this->employee->id)->count());
    }

    private function registered(User $as): EmployeeDocument
    {
        return $this->registerHrDocument($this->employee, $as, 'highly_sensitive', 'identity_document');
    }

    private function states(EmployeeDocument $record): array
    {
        return $this->inSchool(fn () => [Document::query()->whereKey($record->document_id)->value('status'), EmployeeDocument::query()->whereKey($record->id)->value('status')]);
    }

    #[Test]
    public function no_route_writes_the_tier_without_a_code(): void
    {
        $this->mfaEnrolled($this->writer);

        foreach ([$this->genericUpload($this->writer, $this->employee), $this->registerUpload($this->writer)] as $response) {
            $response->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        }
        $this->assertSame(0, $this->documents());
    }

    #[Test]
    public function a_wrong_code_and_a_spent_code_write_nothing(): void
    {
        $this->mfaEnrolled($this->writer);
        $this->genericUpload($this->writer, $this->employee, ['mfa_code' => '000000'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $code = $this->freshMfaCode($this->writer);
        $this->genericUpload($this->writer, $this->employee, ['mfa_code' => $code])->assertCreated();
        $this->assertSame(1, $this->documents());

        $this->genericUpload($this->writer, $this->employee, ['mfa_code' => $code])->assertStatus(422);
        $this->assertSame(1, $this->documents(), 'a recovery code spends once');
    }

    #[Test]
    public function an_account_with_no_factor_cannot_prove_anything(): void
    {
        $this->genericUpload($this->writer, $this->employee, ['mfa_code' => '123456'])->assertStatus(422);
        $this->assertSame(0, $this->documents());
    }

    #[Test]
    public function an_authorized_writer_with_a_code_succeeds_on_both_routes(): void
    {
        $this->genericUpload($this->writer, $this->employee, ['mfa_code' => $this->freshMfaCode($this->writer)])->assertCreated();
        $this->registerUpload($this->writer, ['mfa_code' => $this->freshMfaCode($this->writer)])->assertCreated();

        $this->assertSame(2, $this->documents());
        $this->assertSame(2, (int) $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'document.created')->where('metadata->classificationTier', 'highly_sensitive')->count()));
    }

    #[Test]
    public function the_lower_tier_takes_no_step_up(): void
    {
        $this->api($this->writer, "/employees/{$this->employee->id}/documents", ['file' => $this->file(), 'classification_tier' => 'sensitive'])->assertCreated();
    }

    #[Test]
    public function a_capability_short_of_the_tier_is_refused_and_spends_no_code(): void
    {
        $reader = $this->createUserWithCapabilities($this->school, ['hr.employees.documents.manage', 'hr.employees.documents.view', 'hr.employees.view']);
        $code = $this->freshMfaCode($reader);

        $this->genericUpload($reader, $this->employee, ['mfa_code' => $code])->assertForbidden();

        $this->assertSame(0, $this->documents());
        $this->assertSame(0, UserMfaRecoveryCode::query()->where('user_id', $reader->id)->whereNotNull('consumed_at')->count(), 'refused before the code is spent');
        $this->api($reader, "/employees/{$this->employee->id}/documents", ['file' => $this->file(), 'classification_tier' => 'sensitive'])->assertCreated();
    }

    #[Test]
    public function roles_without_the_capability_are_refused(): void
    {
        foreach (['staff_self_service', 'teacher', 'payroll_officer', 'line_manager', 'hr_officer'] as $role) {
            $user = $this->createUser();
            $this->assignSchoolRole($this->createMembership($user, $this->school), $role);
            $this->genericUpload($user, $this->employee, ['mfa_code' => $this->freshMfaCode($user)])->assertForbidden();
        }
        $this->assertSame(0, $this->documents());
    }

    #[Test]
    public function a_generic_archive_needs_the_code_and_keeps_the_record_and_the_document_together(): void
    {
        $record = $this->registered($this->writer);
        $path = "/documents/{$record->document_id}/archive";

        $this->api($this->writer, $path)->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->assertSame(['active', 'active'], $this->states($record));

        $this->api($this->writer, $path, ['mfa_code' => $this->freshMfaCode($this->writer)])->assertNoContent();

        $this->assertSame(['archived', 'archived'], $this->states($record));
        $this->assertSame(1, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'hr.employee_document.archived')->where('subject_id', $record->id)->count()));
    }

    #[Test]
    public function a_restricted_register_record_archived_generically_stays_in_step(): void
    {
        $record = $this->registerHrDocument($this->employee, $this->writer, 'restricted', 'employment_contract');

        $this->api($this->writer, "/documents/{$record->document_id}/archive")->assertNoContent();

        $this->assertSame(['archived', 'archived'], $this->states($record));
    }

    #[Test]
    public function a_generic_document_outside_the_register_is_archived_with_the_code(): void
    {
        $id = $this->genericUpload($this->writer, $this->employee, ['mfa_code' => $this->freshMfaCode($this->writer)])->json('data.document_id');

        $this->api($this->writer, "/documents/{$id}/archive")->assertStatus(422);
        $this->api($this->writer, "/documents/{$id}/archive", ['mfa_code' => $this->freshMfaCode($this->writer)])->assertNoContent();

        $this->assertSame('archived', $this->inSchool(fn () => Document::query()->whereKey($id)->value('status')));
    }

    #[Test]
    public function nobody_files_on_or_archives_from_their_own_record_on_the_generic_route(): void
    {
        $other = $this->createUserWithCapabilities($this->school, ['hr.employees.sensitive.manage', 'hr.employees.documents.manage']);
        $own = $this->createEmployee($this->school, ['user_id' => $this->writer->id]);
        $record = $this->registerHrDocument($own, $other, 'highly_sensitive', 'identity_document');

        $this->genericUpload($this->writer, $own, ['mfa_code' => $this->freshMfaCode($this->writer)])
            ->assertForbidden()->assertJsonPath('error.code', HrSelfAdministrationException::CODE);
        $this->api($this->writer, "/employees/{$own->id}/documents", ['file' => $this->file(), 'classification_tier' => 'sensitive'])
            ->assertForbidden()->assertJsonPath('error.code', HrSelfAdministrationException::CODE);
        $this->assertSame(1, (int) $this->inSchool(fn () => DB::table('documents')->where('employee_id', $own->id)->count()));

        $this->api($this->writer, "/documents/{$record->document_id}/archive", ['mfa_code' => $this->freshMfaCode($this->writer)])
            ->assertForbidden()->assertJsonPath('error.code', HrSelfAdministrationException::CODE);
        $this->assertSame(['active', 'active'], $this->states($record));
        $this->assertSame(0, UserMfaRecoveryCode::query()->where('user_id', $this->writer->id)->whereNotNull('consumed_at')->count(), 'a refused target spends no code');
    }

    #[Test]
    public function another_school_and_a_forged_id_reach_nothing(): void
    {
        $record = $this->registered($this->writer);
        $other = $this->createSchool();
        $outsider = $this->createUserWithCapabilities($other, ['hr.employees.sensitive.manage', 'hr.employees.documents.manage']);
        $code = $this->freshMfaCode($outsider);

        $this->assertContains($this->api($outsider, "/documents/{$record->document_id}/archive", ['mfa_code' => $code])->status(), [403, 404]);
        $this->assertContains($this->genericUpload($outsider, $this->employee, ['mfa_code' => $this->freshMfaCode($outsider)])->status(), [403, 404]);

        // Through the outsider's own School: the same ids are unknown there, and answering so spends no code.
        $own = "/api/v1/schools/{$other->id}";
        app('auth')->forgetGuards();
        $token = $outsider->createToken('t')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $this->withHeaders($headers)->post("{$own}/documents/{$record->document_id}/archive", ['mfa_code' => $this->freshMfaCode($outsider)])->assertNotFound();
        $this->withHeaders($headers)->post("{$own}/employees/{$this->employee->id}/documents", ['file' => $this->file(), 'classification_tier' => 'highly_sensitive', 'mfa_code' => $this->freshMfaCode($outsider)])->assertNotFound();
        $this->assertSame(0, UserMfaRecoveryCode::query()->where('user_id', $outsider->id)->whereNotNull('consumed_at')->count(), 'a forged id spends no code');

        // An insider, a forged id.
        $this->api($this->writer, '/documents/'.Str::uuid7().'/archive', ['mfa_code' => $this->freshMfaCode($this->writer)])->assertNotFound();
        $this->api($this->writer, '/employees/'.Str::uuid7().'/documents', ['file' => $this->file(), 'classification_tier' => 'highly_sensitive', 'mfa_code' => $this->freshMfaCode($this->writer)])->assertNotFound();
        $this->assertSame(['active', 'active'], $this->states($record));
        $this->assertSame(1, $this->documents());
    }

    #[Test]
    public function the_generic_route_takes_only_the_file_types_the_register_takes(): void
    {
        $response = $this->api($this->writer, "/employees/{$this->employee->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('sheet.docx', "PK\x03\x04 not scanned"), 'classification_tier' => 'sensitive',
        ]);

        $this->assertContains($response->status(), [415, 422], (string) $response->getContent());
        $this->assertSame(0, $this->documents());
    }

    #[Test]
    public function documents_never_names_an_hr_class_for_this(): void
    {
        foreach ([app_path('Domain/Documents/Application/DocumentService.php'), app_path('Domain/Documents/Http/Controllers/DocumentController.php')] as $file) {
            $this->assertDoesNotMatchRegularExpression('/^use App\\\\Domain\\\\HR\\\\(Application|Http)/m', (string) file_get_contents($file), basename($file).' depends on HR, which depends on Documents');
        }
    }

    #[Test]
    public function an_archived_employee_keeps_the_same_write_rules(): void
    {
        app(EmployeeService::class)->archive($this->employee, $this->fullHrActor($this->school));

        $this->genericUpload($this->writer, $this->employee)->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->assertSame(0, $this->documents());
    }

    #[Test]
    public function reclassifying_in_or_out_of_the_tier_needs_the_code(): void
    {
        $restricted = $this->registerHrDocument($this->employee, $this->writer, 'restricted', 'employment_contract');
        $high = $this->registerHrDocument($this->employee, $this->writer, 'highly_sensitive', 'employment_contract');
        $patch = function (EmployeeDocument $record, string $tier, array $extra = []) {
            app('auth')->forgetGuards();

            return $this->withHeaders(['Authorization' => 'Bearer '.$this->writer->createToken('t')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid7(), 'Accept' => 'application/json'])
                ->patchJson($this->base()."/employees/{$this->employee->id}/hr-document-records/{$record->id}", ['classification_tier' => $tier] + $extra);
        };
        $this->mfaEnrolled($this->writer);

        $patch($restricted, 'highly_sensitive')->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $patch($high, 'restricted')->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
        $this->assertSame('restricted', $this->inSchool(fn () => $restricted->fresh()->classification_tier));
        $this->assertSame('highly_sensitive', $this->inSchool(fn () => $high->fresh()->classification_tier));

        $patch($high, 'restricted', ['mfa_code' => $this->freshMfaCode($this->writer)])->assertOk();
    }

    #[Test]
    public function the_retained_record_outlives_an_archive_and_the_bytes_stay(): void
    {
        $record = $this->registered($this->writer);
        $path = $this->inSchool(fn () => Document::query()->whereKey($record->document_id)->value('storage_path'));

        app(EmployeeDocumentService::class)->archive($this->employee, $record, $this->writer);

        Storage::disk((string) config('documents.disk'))->assertExists($path);
        $this->assertSame(['archived', 'archived'], $this->states($record));
    }
}
