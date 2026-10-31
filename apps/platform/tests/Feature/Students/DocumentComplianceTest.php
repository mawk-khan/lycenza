<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Admissions\Infrastructure\ApplicantDocument;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Students\Application\Documents\DocumentCompliance;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Domain\Students\Infrastructure\StudentDocumentRequirement;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * SIS 11.5 (owner decisions 2026-10-31): document requirements, derived
 * compliance, verification, pre-admission documents and the cohort
 * checklist -- all over the one Documents repository.
 */
class DocumentComplianceTest extends TestCase
{
    use CreatesTenancyFixtures;
    use ProvidesSensitiveActionMfa;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private School $school;

    private User $admin;

    private AcademicYear $year;

    private Campus $campus;

    private Campus $otherCampus;

    private GradeLevel $grade;

    private Section $section;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('documents.disk'));
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->year = $this->createAcademicYear($this->school, ['code' => 'AY-DC', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $this->campus = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MN']);
        $this->otherCampus = $this->createCampus($this->school, ['name' => 'North', 'code' => 'NO']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 1', 'code' => 'G1']);
        $this->section = $this->createSection($this->year, $this->campus, $this->grade, ['name' => 'A']);
        $this->student = $this->createStudent($this->school, ['first_name' => 'Aarav', 'last_name' => 'Rao', 'student_number' => 'S-1']);
        $this->createStudentEnrollment($this->student, $this->section, ['starts_on' => '2026-04-01']);
    }

    private function as(User $user, ?School $school = null): static
    {
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    private function in(callable $callback, ?School $school = null): mixed
    {
        return app(TenantContext::class)->withSchool($school ?? $this->school, $callback);
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sis115');
        file_put_contents($path, self::PDF);

        return new UploadedFile($path, 'record.pdf', null, null, true);
    }

    /** @param  list<string>  $capabilities */
    private function member(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    private function role(string $key): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), $key);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function rule(array $overrides = []): TestResponse
    {
        $this->as($this->admin);

        return $this->post('/app/students/document-requirements', array_merge([
            'subject' => 'student', 'category' => 'birth_certificate', 'required' => true,
            'academic_year_id' => $this->year->id, 'effective_from' => '2026-04-01',
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function uploadStudent(User $as, array $overrides = []): TestResponse
    {
        $this->as($as);

        return $this->post("/app/students/{$this->student->id}/documents", array_merge([
            'file' => $this->pdf(), 'category' => 'birth_certificate', 'title' => 'Birth certificate',
            'form_key' => (string) new UuidV7, 'mfa_code' => $this->freshMfaCode($as),
        ], $overrides));
    }

    private function application(string $status = 'submitted', ?Campus $campus = null, ?Applicant $applicant = null): AdmissionApplication
    {
        $applicant ??= $this->createApplicant($this->school, ['first_name' => 'Isha', 'last_name' => 'Nair', 'date_of_birth' => '2020-01-05']);

        return $this->createAdmissionApplication($applicant, $this->year, $campus ?? $this->campus, $this->grade, ['status' => $status]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function uploadApplicant(User $as, AdmissionApplication $application, array $overrides = []): TestResponse
    {
        $this->as($as);

        return $this->post("/app/admissions/{$application->id}/documents", array_merge([
            'file' => $this->pdf(), 'category' => 'birth_certificate', 'title' => 'Birth certificate',
            'form_key' => (string) new UuidV7, 'mfa_code' => $this->freshMfaCode($as),
        ], $overrides));
    }

    /** @return array<string, string> category => state */
    private function states(?Student $student = null): array
    {
        $student ??= $this->student;
        $rows = app(DocumentCompliance::class)->forStudents($this->school, [$student->id])[$student->id];

        return array_column($rows, 'state', 'category');
    }

    private function studentRecord(): StudentDocument
    {
        return $this->in(fn () => StudentDocument::query()->where('student_id', $this->student->id)->whereNull('archived_at')->latest('created_at')->firstOrFail());
    }

    /** @return list<string> */
    private function audits(): array
    {
        return $this->in(fn () => SchoolAuditEvent::query()->where('school_id', $this->school->id)->pluck('event_type')->all());
    }

    #[Test]
    public function the_requirable_types_are_a_closed_list_without_health_photo_or_identity_documents(): void
    {
        $this->assertSame(['birth_certificate', 'prior_school_record', 'enrollment_support', 'consent_form'], StudentDocumentCategory::requirableValues());
        $this->assertSame('Birth certificate', StudentDocumentCategory::BIRTH_CERTIFICATE->label());
        foreach (StudentDocumentCategory::cases() as $category) {
            foreach (['medical', 'health', 'immunis', 'photo', 'aadhaar', 'passport', 'visa', 'frro', 'biometric', 'caste', 'religion', 'disability', 'other'] as $banned) {
                $this->assertStringNotContainsString($banned, $category->value);
            }
        }
    }

    #[Test]
    public function an_administrator_manages_rules_and_their_terms_never_change(): void
    {
        $this->rule()->assertRedirect('/app/students/document-requirements')->assertSessionHasNoErrors();
        $rule = $this->in(fn () => StudentDocumentRequirement::query()->sole());
        $this->assertContains('student_document_requirement.created', $this->audits());

        // Closed dimensions: unknown type, a closed year, another School's Campus.
        $this->rule(['category' => 'medical_certificate'])->assertSessionHasErrors('category');
        $closed = $this->createAcademicYear($this->school, ['code' => 'AY-OLD', 'status' => 'closed', 'starts_on' => '2024-04-01', 'ends_on' => '2025-03-31']);
        $this->rule(['academic_year_id' => $closed->id])->assertSessionHasErrors('academic_year_id');
        $foreign = $this->createCampus($this->createSchool());
        $this->rule(['campus_id' => $foreign->id])->assertSessionHasErrors('campus_id');

        $this->in(function () use ($rule): void {
            try {
                DB::transaction(fn () => DB::table('student_document_requirements')->where('id', $rule->id)->update(['required' => false]));
                $this->fail('terms are immutable');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
            try {
                DB::transaction(fn () => DB::table('student_document_requirements')->where('id', $rule->id)->delete());
                $this->fail('no runtime DELETE');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        });

        $this->post("/app/students/document-requirements/{$rule->id}/retire")->assertRedirect();
        $this->assertNotNull($this->in(fn () => StudentDocumentRequirement::query()->findOrFail($rule->id))->retired_at);
        $this->assertContains('student_document_requirement.retired', $this->audits());

        // Only `students.documents.manage` (whole School) manages rules.
        foreach ([$this->role('teacher'), $this->role('admissions_officer'), $this->member(['students.view', 'students.documents.view'])] as $user) {
            $this->as($user);
            $this->get('/app/students/document-requirements')->assertForbidden();
            $this->post('/app/students/document-requirements', ['subject' => 'student', 'category' => 'consent_form', 'required' => true, 'academic_year_id' => $this->year->id])->assertForbidden();
        }
        $this->assertSame(1, $this->in(fn () => StudentDocumentRequirement::query()->count()));
    }

    #[Test]
    public function compliance_is_derived_from_rules_placements_and_the_current_document(): void
    {
        $this->assertSame([], $this->states(), 'no rule, no checklist');
        $this->rule();
        $this->rule(['category' => 'consent_form', 'required' => false]);
        // Not applicable: another Grade, another Campus, a retired rule.
        $this->rule(['category' => 'prior_school_record', 'grade_level_id' => $this->createGradeLevel($this->school, ['code' => 'G9'])->id]);
        $this->rule(['category' => 'enrollment_support', 'campus_id' => $this->otherCampus->id]);
        $this->assertSame(['birth_certificate' => 'missing', 'consent_form' => 'missing'], $this->states());

        $this->uploadStudent($this->admin)->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->states()['birth_certificate']);

        $record = $this->studentRecord();
        $this->as($this->admin)->post("/app/students/{$this->student->id}/documents/{$record->id}/decision", ['decision' => 'rejected', 'reason' => 'illegible', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $this->states()['birth_certificate']);

        // A rejected document is fixed by a replacement (the old one is superseded).
        $this->uploadStudent($this->admin, ['replaces' => $record->id])->assertSessionHasNoErrors();
        $this->assertSame('superseded', $this->in(fn () => StudentDocument::query()->findOrFail($record->id))->archive_reason->value);
        $this->assertSame('pending', $this->states()['birth_certificate']);
        $replacement = $this->studentRecord();
        $this->post("/app/students/{$this->student->id}/documents/{$replacement->id}/decision", ['decision' => 'verified', 'version' => $replacement->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->assertSame('verified', $this->states()['birth_certificate']);

        $page = $this->withMfaAssurance($this->admin)->get("/app/students/{$this->student->id}/documents")->assertOk()->viewData('page')['props'];
        $this->assertSame(['required' => 1, 'verified' => 1, 'missing' => 0, 'pending' => 0, 'rejected' => 0], $page['complianceSummary']);

        // A Student who left before a rule's effective date is never out of compliance with it.
        $leaver = $this->createStudent($this->school, ['first_name' => 'Old', 'last_name' => 'Leaver', 'student_number' => 'S-2']);
        $this->createStudentEnrollment($leaver, $this->section, ['starts_on' => '2026-04-01', 'ends_on' => '2026-05-01', 'status' => 'withdrawn']);
        $this->rule(['category' => 'prior_school_record', 'effective_from' => '2026-06-01']);
        $this->assertArrayNotHasKey('prior_school_record', $this->states($leaver));
        $this->assertArrayHasKey('birth_certificate', $this->states($leaver));
    }

    #[Test]
    public function verification_needs_its_own_capability_a_current_version_and_happens_once(): void
    {
        $this->uploadStudent($this->admin)->assertSessionHasNoErrors();
        $record = $this->studentRecord();
        $url = "/app/students/{$this->student->id}/documents/{$record->id}/decision";

        $manager = $this->member(['students.view', 'students.documents.view', 'students.documents.manage']);
        $this->as($manager)->post($url, ['decision' => 'verified', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($manager)])->assertForbidden();

        $this->as($this->admin);
        $this->post($url, ['decision' => 'verified', 'version' => $record->version])->assertSessionHasErrors('mfa_code');
        $this->post($url, ['decision' => 'rejected', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('reason');
        $this->post($url, ['decision' => 'rejected', 'reason' => 'free text', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('reason');
        $this->post($url, ['decision' => 'verified', 'version' => $record->version + 5, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
        $this->post($url, ['decision' => 'verified', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->post($url, ['decision' => 'rejected', 'reason' => 'illegible', 'version' => $record->version + 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');

        $fresh = $this->in(fn () => StudentDocument::query()->findOrFail($record->id));
        $this->assertSame('verified', $fresh->verification_status->value);
        $this->assertSame($this->admin->id, $fresh->verified_by_user_id);
        $verified = $this->in(fn () => SchoolAuditEvent::query()->where('event_type', 'student_document.verified')->sole());
        $this->assertEqualsCanonicalizing(['studentId', 'studentDocumentId', 'category', 'reason'], array_keys($verified->metadata));

        // A non-requirable category is never decided.
        $this->uploadStudent($this->admin, ['category' => 'correspondence', 'title' => 'Letter'])->assertSessionHasNoErrors();
        $letter = $this->in(fn () => StudentDocument::query()->where('title', 'Letter')->sole());
        $this->assertNull($letter->verification_status);
        $this->post("/app/students/{$this->student->id}/documents/{$letter->id}/decision", ['decision' => 'verified', 'version' => $letter->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
    }

    #[Test]
    public function applicant_documents_follow_the_applications_campus_and_verification_stays_whole_school(): void
    {
        $application = $this->application();
        $officer = $this->role('admissions_officer');
        $this->uploadApplicant($officer, $application)->assertRedirect("/app/admissions/{$application->id}/documents")->assertSessionHasNoErrors();
        $record = $this->in(fn () => ApplicantDocument::query()->sole());
        $this->assertSame('pending', $record->verification_status->value);
        $this->assertSame('applicant', $this->in(fn () => Document::query()->findOrFail($record->document_id))->owner_type);

        $page = $this->withMfaAssurance($officer)->get("/app/admissions/{$application->id}/documents")->assertOk()->viewData('page')['props'];
        $this->assertTrue($page['canManage']);
        $this->assertFalse($page['canVerify'], 'admissions officers never verify');
        $this->get("/app/admissions/{$application->id}/documents/{$record->id}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post("/app/admissions/{$application->id}/documents/{$record->id}/decision", ['decision' => 'verified', 'version' => 1, 'mfa_code' => $this->freshMfaCode($officer)])->assertForbidden();

        // A Campus-scoped officer reaches only its Campus's applications: elsewhere is NOT FOUND.
        $northOfficer = $this->member(['admissions.view', 'admissions.manage', 'admissions.documents.view', 'admissions.documents.manage'], $this->otherCampus->id);
        $this->as($northOfficer)->withMfaAssurance($northOfficer);
        $this->get("/app/admissions/{$application->id}/documents")->assertNotFound();
        $this->get("/app/admissions/{$application->id}/documents/{$record->id}/download")->assertNotFound();
        $this->assertSame(404, $this->uploadApplicant($northOfficer, $application)->status());
        $north = $this->application(campus: $this->otherCampus);
        $this->uploadApplicant($northOfficer, $north)->assertSessionHasNoErrors();
        // ...and another application's record id never opens through this one (IDOR).
        $northRecord = $this->in(fn () => ApplicantDocument::query()->where('applicant_id', $north->applicant_id)->sole());
        $this->as($officer)->withMfaAssurance($officer);
        $this->get("/app/admissions/{$application->id}/documents/{$northRecord->id}/download")->assertNotFound();

        // Teachers, librarians and Student-document holders without the Admissions keys reach nothing.
        foreach (['teacher' => $this->role('teacher'), 'librarian' => $this->role('librarian'), 'student documents' => $this->member(['students.view', 'students.documents.view', 'students.documents.manage', 'students.documents.verify'])] as $label => $user) {
            $this->as($user)->withMfaAssurance($user);
            $this->assertSame(403, $this->get("/app/admissions/{$application->id}/documents")->status(), $label);
            $this->assertSame(403, $this->get("/app/admissions/{$application->id}/documents/{$record->id}/download")->status(), $label);
        }

        // The administrator verifies; a closed application collects nothing new.
        $this->as($this->admin)->post("/app/admissions/{$application->id}/documents/{$record->id}/decision", ['decision' => 'verified', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->assertContains('applicant_document.verified', $this->audits());
        $rejected = $this->application('rejected');
        $this->uploadApplicant($this->admin, $rejected)->assertSessionHasErrors('file');
        // A closed application's documents are history: never decided or archived afterwards.
        $pending = $this->application();
        $this->uploadApplicant($this->admin, $pending)->assertSessionHasNoErrors();
        $late = $this->in(fn () => ApplicantDocument::query()->where('admission_application_id', $pending->id)->sole());
        $this->post("/app/admissions/{$pending->id}/withdraw")->assertSessionHasNoErrors();
        $this->post("/app/admissions/{$pending->id}/documents/{$late->id}/decision", ['decision' => 'verified', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
        $this->post("/app/admissions/{$pending->id}/documents/{$late->id}/archive", ['reason' => 'uploaded_in_error', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
        $this->assertSame('pending', $this->in(fn () => ApplicantDocument::query()->findOrFail($late->id))->verification_status->value);

        // Another School's administrator never reaches it.
        [$otherAdmin, $otherSchool] = $this->createSchoolAdmin();
        $this->as($otherAdmin, $otherSchool)->withMfaAssurance($otherAdmin);
        $this->get("/app/admissions/{$application->id}/documents")->assertNotFound();
        $this->get("/app/admissions/{$application->id}/documents/{$record->id}/download")->assertNotFound();
    }

    #[Test]
    public function a_record_is_bound_to_the_application_it_was_collected_through(): void
    {
        // One Applicant applying on two Campuses (two applications).
        $main = $this->application();
        $applicant = $this->in(fn () => Applicant::query()->findOrFail($main->applicant_id));
        $north = $this->application(campus: $this->otherCampus, applicant: $applicant);
        $this->uploadApplicant($this->admin, $north, ['title' => 'North birth certificate'])->assertSessionHasNoErrors();
        $northRecord = $this->in(fn () => ApplicantDocument::query()->sole());
        $this->assertSame($north->id, $northRecord->admission_application_id);

        $mainOfficer = $this->member(['admissions.view', 'admissions.documents.view', 'admissions.documents.manage'], $this->campus->id);
        $this->as($mainOfficer)->withMfaAssurance($mainOfficer);
        $page = $this->get("/app/admissions/{$main->id}/documents")->assertOk()->viewData('page')['props'];
        $this->assertSame([], $page['documents'], 'another Campus\'s application of the same Applicant stays invisible');
        $this->get("/app/admissions/{$main->id}/documents/{$northRecord->id}/download")->assertNotFound();
        $this->as($this->admin)->withMfaAssurance($this->admin);
        $this->post("/app/admissions/{$main->id}/documents/{$northRecord->id}/decision", ['decision' => 'verified', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertNotFound();

        // The database pins a record to an application of the same Applicant.
        $other = $this->application();
        $this->in(function () use ($northRecord, $other): void {
            try {
                DB::transaction(fn () => DB::table('applicant_documents')->where('id', $northRecord->id)->update(['admission_application_id' => $other->id, 'version' => 2]));
                $this->fail('a record never changes application');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    #[Test]
    public function conversion_moves_the_current_documents_to_the_student_without_a_copy(): void
    {
        $application = $this->application('accepted');
        $this->uploadApplicant($this->admin, $application)->assertSessionHasNoErrors();
        $this->uploadApplicant($this->admin, $application, ['category' => 'consent_form', 'title' => 'Consent'])->assertSessionHasNoErrors();
        $records = $this->in(fn () => ApplicantDocument::query()->orderBy('category')->get());
        $consent = $records->firstWhere('category', StudentDocumentCategory::CONSENT_FORM);
        $this->as($this->admin)->post("/app/admissions/{$application->id}/documents/{$consent->id}/archive", ['reason' => 'uploaded_in_error', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $birth = $records->firstWhere('category', StudentDocumentCategory::BIRTH_CERTIFICATE);
        $this->post("/app/admissions/{$application->id}/documents/{$birth->id}/decision", ['decision' => 'verified', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $documentsBefore = $this->in(fn () => Document::query()->count());

        $section = $this->in(fn () => Section::query()->findOrFail($this->section->id));
        $result = $this->in(fn () => app(AdmissionConversionService::class)->convert($this->in(fn () => AdmissionApplication::query()->findOrFail($application->id)), $section, '07', '2026-06-01', actor: $this->admin));
        $studentId = $result->student->id;

        $this->assertSame($documentsBefore, $this->in(fn () => Document::query()->count()), 'no copy: the same Document rows');
        $moved = $this->in(fn () => StudentDocument::query()->where('student_id', $studentId)->sole());
        $this->assertSame($birth->document_id, $moved->document_id);
        $this->assertSame($birth->id, $moved->source_applicant_document_id);
        $this->assertSame('verified', $moved->verification_status->value, 'verification travels with the document');
        $document = $this->in(fn () => Document::query()->findOrFail($birth->document_id));
        $this->assertSame('student', $document->owner_type);
        $this->assertNotNull($this->in(fn () => ApplicantDocument::query()->findOrFail($birth->id))->moved_to_student_at);
        $this->assertSame('applicant', $this->in(fn () => Document::query()->findOrFail($consent->document_id))->owner_type, 'archived history stays with the Applicant');
        $this->assertContains('applicant_document.moved_to_student', $this->audits());

        // Admissions staff gain no Student-document access from the move.
        $officer = $this->role('admissions_officer');
        $this->as($officer)->withMfaAssurance($officer);
        $page = $this->get("/app/admissions/{$application->id}/documents")->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $page['movedCount']);
        $this->assertSame([$consent->id], array_column($page['documents'], 'id'));
        $this->get("/app/admissions/{$application->id}/documents/{$birth->id}/download")->assertNotFound();
        $this->assertSame(403, $this->get("/app/students/{$studentId}/documents")->status());

        $this->as($this->admin)->withMfaAssurance($this->admin);
        $studentPage = $this->get("/app/students/{$studentId}/documents")->assertOk()->viewData('page')['props'];
        $this->assertTrue($studentPage['documents']['data'][0]['fromAdmission']);
        $this->get("/app/students/{$studentId}/documents/{$moved->id}/download")->assertOk();
    }

    #[Test]
    public function the_cohort_checklist_shows_states_only_to_whole_school_document_viewers(): void
    {
        $this->rule();
        $other = $this->createStudent($this->school, ['first_name' => 'Bina', 'last_name' => 'Shah', 'student_number' => 'S-3']);
        $this->createStudentEnrollment($other, $this->section, ['starts_on' => '2026-04-01']);
        $this->uploadStudent($this->admin)->assertSessionHasNoErrors();

        // Current MFA is required: a viewer without a factor gets the MFA page, never the rows.
        $unenrolled = $this->member(['students.view', 'students.documents.view']);
        $this->as($unenrolled);
        $this->assertSame(403, $this->get('/app/students/document-compliance')->status());
        $this->as($this->admin)->withMfaAssurance($this->admin);
        $response = $this->get('/app/students/document-compliance')->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(2, $props['rows']['total']);
        $json = json_encode($props['rows']);
        foreach (['Birth certificate.pdf', 'record.pdf', $this->studentRecord()->id, $this->studentRecord()->document_id, 'title', 'documentId', 'download'] as $absent) {
            $this->assertStringNotContainsString($absent, (string) $json, 'states only');
        }
        $this->assertSame(['Bina Shah'], array_column(array_column($this->get('/app/students/document-compliance?status=missing&category=birth_certificate')->viewData('page')['props']['rows']['data'], 'student'), 'displayName'));
        $this->assertSame(['Aarav Rao'], array_column(array_column($this->get('/app/students/document-compliance?status=pending')->viewData('page')['props']['rows']['data'], 'student'), 'displayName'));
        $this->assertSame(0, $this->get('/app/students/document-compliance?campus_id='.$this->createCampus($this->createSchool())->id)->viewData('page')['props']['rows']['total'], 'a foreign id matches nothing');
        $this->get('/app/students/document-compliance?status=anything')->assertSessionHasErrors('status');

        // Bounded: a constant number of queries for a page.
        DB::enableQueryLog();
        $this->get('/app/students/document-compliance')->assertOk();
        $few = count(DB::getQueryLog());
        for ($i = 0; $i < 6; $i++) {
            $s = $this->createStudent($this->school, ['student_number' => "S-X{$i}"]);
            $this->createStudentEnrollment($s, $this->section, ['starts_on' => '2026-04-01']);
        }
        DB::flushQueryLog();
        $this->get('/app/students/document-compliance')->assertOk();
        $this->assertSame($few, count(DB::getQueryLog()), 'the query count does not grow with the cohort');
        DB::disableQueryLog();

        // Campus readers, document managers without whole-School Students, teachers: refused.
        $principalAtCampus = $this->member(['students.view', 'students.documents.view'], $this->campus->id);
        foreach (['Campus principal' => $principalAtCampus, 'documents without students' => $this->member(['students.documents.view']), 'teacher' => $this->role('teacher'), 'admissions officer' => $this->role('admissions_officer')] as $label => $user) {
            $this->as($user)->withMfaAssurance($user);
            $this->assertSame(403, $this->get('/app/students/document-compliance')->status(), $label);
        }
        // The checklist is not an exporter (browser-only).
        $this->as($this->admin)->withMfaAssurance($this->admin);
        $this->assertSame(404, $this->postJson('/app/students/reports/students.document_compliance/export', ['format' => 'csv'])->status());
        $this->assertSame(404, $this->get('/app/students/reports/students.document_compliance/print')->status());
    }
}
