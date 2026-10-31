<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Application\SectionVersion;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\ApplicantDocument;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Guardians\Application\GuardianProfileVersion;
use App\Domain\Guardians\Application\StudentGuardianRelationshipVersion;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\StudentProfileVersion;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * SIS 11.H1 (SIS 11.R1 findings L-1, L-2, L-4): the Applicant-document
 * relationship is enforced by the database; the Applicant Documents entry
 * points refuse an actor without the capability themselves; verifying a
 * Student document needs the right to view it.
 */
class PostExpansionHardeningTest extends TestCase
{
    use CreatesTenancyFixtures;
    use ProvidesSensitiveActionMfa;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private School $school;

    private User $admin;

    private Campus $campus;

    private Campus $otherCampus;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('documents.disk'));
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->campus = $this->createCampus($this->school, ['code' => 'MN']);
        $this->otherCampus = $this->createCampus($this->school, ['code' => 'NO']);
    }

    private function in(callable $callback, ?School $school = null): mixed
    {
        return app(TenantContext::class)->withSchool($school ?? $this->school, $callback);
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sish1');
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

    private function application(?Campus $campus = null, string $status = 'submitted'): AdmissionApplication
    {
        $applicant = $this->createApplicant($this->school);

        return $this->createAdmissionApplication($applicant, $this->createAcademicYear($this->school, ['code' => 'AY-'.Str::random(6)]), $campus ?? $this->campus, $this->createGradeLevel($this->school, ['code' => 'G'.Str::random(4)]), ['status' => $status]);
    }

    private function upload(User $as, AdmissionApplication $application): TestResponse
    {
        $this->actingAs($as)->post("/app/schools/{$this->school->id}/activate");

        return $this->post("/app/admissions/{$application->id}/documents", [
            'file' => $this->pdf(), 'category' => 'birth_certificate', 'title' => 'Birth certificate',
            'form_key' => (string) new UuidV7, 'mfa_code' => $this->freshMfaCode($as),
        ]);
    }

    // ---- L-1 -------------------------------------------------------------

    #[Test]
    public function l1_an_applicant_record_must_name_an_existing_document_of_its_own_applicant_and_school(): void
    {
        $application = $this->application();
        $this->upload($this->admin, $application)->assertSessionHasNoErrors();
        $record = $this->in(fn () => ApplicantDocument::query()->sole());
        $other = $this->application();
        $this->upload($this->admin, $other)->assertSessionHasNoErrors();
        $othersDocument = $this->in(fn () => ApplicantDocument::query()->where('admission_application_id', $other->id)->value('document_id'));
        [, $foreignSchool] = $this->createSchoolAdmin();

        $insert = fn (string $documentId) => DB::transaction(fn () => DB::table('applicant_documents')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'applicant_id' => $application->applicant_id,
            'admission_application_id' => $application->id, 'document_id' => $documentId, 'category' => 'consent_form',
            'title' => 'Forged', 'form_key' => (string) Str::uuid7(), 'upload_fingerprint' => str_repeat('a', 64),
            'uploaded_by_user_id' => $this->admin->id, 'verification_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->in(function () use ($insert, $othersDocument): void {
            foreach (['a Document that does not exist' => (string) Str::uuid7(), "another Applicant's Document" => $othersDocument] as $label => $documentId) {
                try {
                    $insert($documentId);
                    $this->fail("{$label} was accepted");
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
        $this->assertSame(1, $this->in(fn () => ApplicantDocument::query()->where('admission_application_id', $application->id)->count()));
        $this->assertNotNull($record->document_id);

        // The dead (id, applicant_id, school_id) key is gone; (id, school_id) carries the reference.
        $this->assertNull(DB::selectOne("select 1 as x from pg_constraint where conname = 'documents_id_applicant_school_unique'"));
        $this->assertNotNull(DB::selectOne("select 1 as x from pg_constraint where conname = 'applicant_documents_document_fk'"));

        // A record never names another School's Document (same-School key).
        [$foreignAdmin, $foreignSchool] = $this->createSchoolAdmin();
        $foreignApplication = $this->createAdmissionApplication($this->createApplicant($foreignSchool), $this->createAcademicYear($foreignSchool), $this->createCampus($foreignSchool), $this->createGradeLevel($foreignSchool), ['status' => 'submitted']);
        $this->actingAs($foreignAdmin)->post("/app/schools/{$foreignSchool->id}/activate");
        $this->post("/app/admissions/{$foreignApplication->id}/documents", [
            'file' => $this->pdf(), 'category' => 'birth_certificate', 'title' => 'Theirs',
            'form_key' => (string) new UuidV7, 'mfa_code' => $this->freshMfaCode($foreignAdmin),
        ])->assertSessionHasNoErrors();
        $theirs = $this->in(fn () => ApplicantDocument::query()->value('document_id'), $foreignSchool);
        $this->in(function () use ($insert, $theirs): void {
            try {
                $insert($theirs);
                $this->fail("another School's Document was accepted");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    // ---- L-2 -------------------------------------------------------------

    #[Test]
    public function l2_the_applicant_document_entry_points_refuse_an_actor_without_the_capability(): void
    {
        $application = $this->application();
        $this->upload($this->admin, $application)->assertSessionHasNoErrors();
        $record = $this->in(fn () => ApplicantDocument::query()->sole());
        $outsider = $this->member(['students.view', 'students.documents.view', 'students.documents.manage']);

        $documents = app(DocumentService::class);
        $reads = app(DocumentReadService::class);
        $refused = function (callable $call, string $label): void {
            try {
                $call();
                $this->fail("{$label}: an actor without the capability was served");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        };
        $refused(fn () => $documents->createForApplicant($this->school, $application->applicant_id, $this->pdf(), $outsider, fn () => null), 'create');
        $refused(fn () => $documents->archiveForApplicant($this->school, $application->applicant_id, $record->document_id, $outsider, fn () => null), 'archive');
        $refused(fn () => $reads->applicantContent($this->school, $application->applicant_id, $record->document_id, $outsider), 'read');

        $this->assertSame('active', $this->in(fn () => Document::query()->findOrFail($record->document_id))->status);
        $this->assertSame(1, $this->in(fn () => Document::query()->whereNotNull('applicant_id')->count()), 'nothing was stored for the outsider');

        // A holder on another Campus passes the Documents floor; the Admissions
        // caller still refuses the application (NOT FOUND, as before).
        $north = $this->member(['admissions.view', 'admissions.documents.view', 'admissions.documents.manage'], $this->otherCampus->id);
        $this->actingAs($north)->post("/app/schools/{$this->school->id}/activate");
        $this->withMfaAssurance($north)->get("/app/admissions/{$application->id}/documents/{$record->id}/download")->assertNotFound();
    }

    // ---- L-4 -------------------------------------------------------------

    #[Test]
    public function l4_verifying_a_student_document_needs_the_right_to_view_it(): void
    {
        $student = $this->createStudent($this->school);
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/students/{$student->id}/documents", [
            'file' => $this->pdf(), 'category' => 'birth_certificate', 'title' => 'Birth certificate',
            'form_key' => (string) new UuidV7, 'mfa_code' => $this->freshMfaCode($this->admin),
        ])->assertSessionHasNoErrors();
        $record = $this->in(fn () => StudentDocument::query()->sole());

        $verifyOnly = $this->member(['students.view', 'students.documents.verify']);
        $this->actingAs($verifyOnly)->post("/app/schools/{$this->school->id}/activate");
        $this->assertSame(403, $this->post("/app/students/{$student->id}/documents/{$record->id}/decision", [
            'decision' => 'verified', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($verifyOnly),
        ])->status());
        $this->assertNull($this->in(fn () => StudentDocument::query()->findOrFail($record->id))->verified_by_user_id);

        $viewer = $this->member(['students.view', 'students.documents.view']);
        $this->actingAs($viewer)->post("/app/schools/{$this->school->id}/activate");
        $this->assertSame(403, $this->post("/app/students/{$student->id}/documents/{$record->id}/decision", [
            'decision' => 'verified', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($viewer),
        ])->status(), 'view without verify');

        $verifier = $this->member(['students.view', 'students.documents.view', 'students.documents.verify']);
        $this->actingAs($verifier)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/students/{$student->id}/documents/{$record->id}/decision", [
            'decision' => 'verified', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($verifier),
        ])->assertSessionHasNoErrors();
        $this->assertSame($verifier->id, $this->in(fn () => StudentDocument::query()->findOrFail($record->id))->verified_by_user_id);
    }

    // ---- Stale-update protection -------------------------------------------

    private function api(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('h1')->plainTextToken);
    }

    #[Test]
    public function a_stale_section_edit_is_refused_and_the_newer_change_survives(): void
    {
        $section = $this->createSection($this->createAcademicYear($this->school), $this->campus, $this->createGradeLevel($this->school), ['name' => 'A', 'code' => 'A']);
        $opened = $this->in(fn () => SectionVersion::of(Section::query()->findOrFail($section->id)));
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        // B saves first; A's form (opened before) is refused, never applied over it.
        $this->put("/app/school-setup/sections/{$section->id}", ['name' => 'B-name', 'capacity' => 30, 'version' => $opened])->assertSessionHasNoErrors();
        $this->put("/app/school-setup/sections/{$section->id}", ['name' => 'A-name', 'capacity' => 10, 'version' => $opened])->assertSessionHasErrors('refusal');
        $this->put("/app/school-setup/sections/{$section->id}", ['status' => 'inactive', 'version' => str_repeat('0', 64)])->assertSessionHasErrors('refusal');
        $this->put("/app/school-setup/sections/{$section->id}", ['name' => 'No version'])->assertSessionHasErrors('version');
        $fresh = $this->in(fn () => Section::query()->findOrFail($section->id));
        $this->assertSame(['B-name', 30, 'active'], [$fresh->name, $fresh->capacity, $fresh->status]);

        // API: the same contract, the repository's 409 envelope.
        $this->api($this->admin)->patchJson("/api/v1/schools/{$this->school->id}/sections/{$section->id}", ['name' => 'Stale', 'version' => $opened])
            ->assertStatus(409)->assertJsonPath('error.code', 'SECTION_CHANGED');
        $current = $this->api($this->admin)->getJson("/api/v1/schools/{$this->school->id}/sections/{$section->id}")->json('data.version');
        $this->api($this->admin)->patchJson("/api/v1/schools/{$this->school->id}/sections/{$section->id}", ['name' => 'Fresh', 'version' => $current])
            ->assertOk()->assertJsonPath('data.name', 'Fresh');

        // Another School's administrator never reaches it.
        [$otherAdmin, $otherSchool] = $this->createSchoolAdmin();
        $this->api($otherAdmin)->patchJson("/api/v1/schools/{$otherSchool->id}/sections/{$section->id}", ['name' => 'X', 'version' => $current])->assertNotFound();
    }

    #[Test]
    public function a_stale_relationship_edit_is_refused_and_never_creates_an_impossible_primary_state(): void
    {
        $student = $this->createStudent($this->school);
        $relA = $this->createStudentGuardianRelationship($student, $this->createGuardian($this->school), ['is_primary' => true]);
        $relB = $this->createStudentGuardianRelationship($student, $this->createGuardian($this->school));
        $openedB = $this->in(fn () => StudentGuardianRelationshipVersion::of($relB->fresh()));
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        // Someone makes B the primary Guardian; a form for B opened before is stale.
        $this->post("/app/relationships/{$relB->id}/primary")->assertRedirect();
        $this->put("/app/relationships/{$relB->id}", ['relationship_type' => 'other', 'is_legal_guardian' => false, 'version' => $openedB])->assertSessionHasErrors('refusal');
        $this->put("/app/relationships/{$relB->id}", ['relationship_type' => 'other'])->assertSessionHasErrors('version');

        // An extra is_primary in the payload is never written by an edit.
        $current = $this->in(fn () => StudentGuardianRelationshipVersion::of($relA->fresh()));
        $this->put("/app/relationships/{$relA->id}", ['relationship_type' => 'father', 'is_primary' => true, 'version' => $current])->assertSessionHasNoErrors();
        $primaries = $this->in(fn () => StudentGuardianRelationship::query()->where('student_id', $student->id)->where('is_primary', true)->pluck('id')->all());
        $this->assertSame([$relB->id], $primaries, 'exactly one primary, the newer choice');

        // API: 409 envelope for a stale version; a fresh one saves.
        $this->api($this->admin)->patchJson("/api/v1/schools/{$this->school->id}/student-guardian-relationships/{$relB->id}", ['is_emergency_contact' => true, 'version' => $openedB])
            ->assertStatus(409)->assertJsonPath('error.code', 'GUARDIAN_RELATIONSHIP_CHANGED');
        $fresh = $this->in(fn () => StudentGuardianRelationshipVersion::of($relB->fresh()));
        $this->api($this->admin)->patchJson("/api/v1/schools/{$this->school->id}/student-guardian-relationships/{$relB->id}", ['is_emergency_contact' => true, 'version' => $fresh])
            ->assertOk()->assertJsonPath('data.isEmergencyContact', true);
    }

    #[Test]
    public function the_student_and_guardian_api_patches_refuse_a_stale_missing_or_forged_version(): void
    {
        $student = $this->createStudent($this->school, ['first_name' => 'Asha', 'last_name' => 'Rao']);
        $guardian = $this->createGuardian($this->school, ['first_name' => 'Lata']);
        $api = $this->api($this->admin);
        $studentUrl = "/api/v1/schools/{$this->school->id}/students/{$student->id}";
        $guardianUrl = "/api/v1/schools/{$this->school->id}/guardians/{$guardian->id}";

        $studentRead = $api->getJson($studentUrl)->assertOk()->json('data.version');
        $this->assertSame($this->in(fn () => StudentProfileVersion::of($student->fresh())), $studentRead);
        $api->patchJson($studentUrl, ['last_name' => 'First', 'version' => $studentRead])->assertOk();
        $api->patchJson($studentUrl, ['last_name' => 'Stale', 'version' => $studentRead])->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_PROFILE_CHANGED');
        $api->patchJson($studentUrl, ['last_name' => 'Forged', 'version' => str_repeat('f', 64)])->assertStatus(409);
        $api->patchJson($studentUrl, ['last_name' => 'Missing'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['version']]]);
        $this->assertSame('First', $this->in(fn () => $student->fresh()->last_name));

        $guardianRead = $api->getJson($guardianUrl)->assertOk()->json('data.version');
        $this->assertSame($this->in(fn () => GuardianProfileVersion::of($guardian->fresh())), $guardianRead);
        $api->patchJson($guardianUrl, ['last_name' => 'One', 'version' => $guardianRead])->assertOk();
        $api->patchJson($guardianUrl, ['last_name' => 'Stale', 'version' => $guardianRead])->assertStatus(409)->assertJsonPath('error.code', 'GUARDIAN_PROFILE_CHANGED');
        $api->patchJson($guardianUrl, ['last_name' => 'Missing'])->assertStatus(422);
        $this->assertSame('One', $this->in(fn () => $guardian->fresh()->last_name));

        // Another School's id is NOT FOUND before any version is judged.
        [$otherAdmin, $otherSchool] = $this->createSchoolAdmin();
        $this->api($otherAdmin)->patchJson("/api/v1/schools/{$otherSchool->id}/students/{$student->id}", ['last_name' => 'X', 'version' => $studentRead])->assertNotFound();
    }
}
