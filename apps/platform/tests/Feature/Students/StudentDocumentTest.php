<?php

namespace Tests\Feature\Students;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * SIS.EXP-3 (OD-SIS-1): Student record documents -- the Students-owned
 * record over Documents' storage, whole-School `students.documents.*` plus
 * whole-School `students.view`, Highly Sensitive (current MFA to read, a
 * fresh code to write), staff web only.
 */
class StudentDocumentTest extends TestCase
{
    use CreatesGuardianPortalFixtures;
    use CreatesTenancyFixtures;
    use GroupTestHelpers;
    use ProvidesSensitiveActionMfa;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00\x43\x00";

    private School $school;

    private User $admin;

    private Student $student;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('documents.disk');
        Storage::fake($this->disk);
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->student = $this->createStudent($this->school, ['first_name' => 'Aarav', 'last_name' => 'Test', 'student_number' => 'S-1']);
    }

    private function as(User $user, ?School $school = null): static
    {
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    private function url(?Student $student = null, string $suffix = ''): string
    {
        return '/app/students/'.($student ?? $this->student)->id.'/documents'.$suffix;
    }

    private function pdf(string $name = 'record.pdf', string $content = self::PDF): UploadedFile
    {
        return $this->file($name, $content);
    }

    /**
     * A REAL uploaded file: Laravel's fake file reports a MIME type from its
     * name, which would hide the server-side content sniffing under test.
     */
    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sisexp3');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param  array<string, mixed>  $overrides */
    private function upload(User $as, array $overrides = [], ?Student $student = null): TestResponse
    {
        $this->as($as);

        return $this->post($this->url($student), array_merge([
            'file' => $this->pdf(),
            'category' => 'prior_school_record',
            'title' => 'Transfer certificate',
            'document_date' => '2026-03-31',
            'form_key' => (string) new UuidV7,
            'mfa_code' => $this->freshMfaCode($as),
        ], $overrides));
    }

    /** @return list<StudentDocument> */
    private function records(?School $school = null): array
    {
        $school ??= $this->school;

        return app(TenantContext::class)->withSchool($school, fn () => StudentDocument::query()->where('school_id', $school->id)->get()->all());
    }

    private function onlyRecord(): StudentDocument
    {
        $records = $this->records();
        $this->assertCount(1, $records);

        return $records[0];
    }

    private function document(StudentDocument $record): Document
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Document::query()->findOrFail($record->document_id));
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('school_id', $this->school->id)->orderBy('occurred_at')->pluck('event_type')->all());
    }

    /** @param  list<string>  $capabilities */
    private function member(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    #[Test]
    public function an_administrator_uploads_lists_and_downloads_a_document(): void
    {
        $this->upload($this->admin)->assertRedirect($this->url())->assertSessionHasNoErrors();

        $record = $this->onlyRecord();
        $document = $this->document($record);
        $this->assertSame('prior_school_record', $record->category->value);
        $this->assertSame('Transfer certificate', $record->title);
        $this->assertSame($this->student->id, $document->student_id);
        $this->assertSame('highly_sensitive', $document->classification_tier);
        $this->assertSame('application/pdf', $document->mime_type);
        // The storage key is generated: never the client file name.
        $this->assertStringNotContainsString('record', $document->storage_path);
        $this->assertMatchesRegularExpression('#^schools/'.$this->school->id.'/documents/student/'.$this->student->id.'/[0-9a-f-]{36}\.pdf$#', $document->storage_path);
        Storage::disk($this->disk)->assertExists($document->storage_path);

        // The list needs current MFA, then shows the record and audits the listing once.
        $this->flushSession();
        $this->as($this->admin);
        $this->get($this->url())->assertStatus(401);
        $page = $this->withMfaAssurance($this->admin)->get($this->url())->assertOk()->viewData('page')['props'];
        $this->assertSame(['Transfer certificate'], array_column($page['documents']['data'], 'title'));
        $this->assertSame(['name' => 'record.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => strlen(self::PDF)], $page['documents']['data'][0]['file']);
        $this->assertTrue($page['canManage']);

        $download = $this->get($this->url(suffix: "/{$record->id}/download"))->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertSame(self::PDF, $download->streamedContent());

        $actions = $this->auditActions();
        foreach (['document.created', 'student_document.uploaded', 'document.sensitive_list_viewed', 'document.sensitive_content_accessed'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    #[Test]
    public function audits_carry_ids_and_codes_never_the_title_file_name_or_content(): void
    {
        $this->upload($this->admin, ['title' => 'Secret Title Value', 'file' => $this->pdf('Private-Name.pdf')]);
        $metadata = app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('school_id', $this->school->id)->get()->pluck('metadata')->toJson());
        foreach (['Secret Title Value', 'Private-Name', '%PDF'] as $absent) {
            $this->assertStringNotContainsString($absent, $metadata);
        }
        $uploaded = app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'student_document.uploaded')->firstOrFail());
        $this->assertEqualsCanonicalizing(['studentId', 'studentDocumentId', 'documentId', 'category', 'replacesStudentDocumentId'], array_keys($uploaded->metadata));
    }

    #[Test]
    public function only_pdf_jpeg_and_png_whose_content_matches_are_accepted(): void
    {
        $refused = [
            'html named pdf' => $this->pdf('note.pdf', '<html><body><script>alert(1)</script></body></html>'),
            'svg' => $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'executable' => $this->file('run.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff".str_repeat("\x00", 64)),
            'png named pdf' => $this->file('scan.pdf', (string) base64_decode(self::PNG_BASE64)),
            'archive' => $this->file('bundle.zip', "PK\x03\x04".str_repeat("\x00", 40)),
            'word (allowed elsewhere, never for Students)' => $this->file('letter.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 512)),
        ];
        foreach ($refused as $label => $file) {
            $this->upload($this->admin, ['file' => $file])->assertSessionHasErrors('file');
            $this->assertSame([], $this->records(), "{$label}: nothing recorded");
        }
        $this->assertSame([], Storage::disk($this->disk)->allFiles(), 'no object was written for a refused file');

        $this->upload($this->admin, ['file' => $this->file('form.png', (string) base64_decode(self::PNG_BASE64))])->assertSessionHasNoErrors();
        $this->upload($this->admin, ['file' => $this->file('scan.jpg', self::JPEG.str_repeat("\x00", 64)."\xFF\xD9")])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->records());
    }

    #[Test]
    public function an_oversized_file_is_refused_before_storage(): void
    {
        config(['documents.max_file_size_mb' => 1]);
        $this->upload($this->admin, ['file' => $this->pdf('big.pdf', self::PDF.str_repeat('0', 1024 * 1024 + 10))])->assertSessionHasErrors('file');
        $this->assertSame([], $this->records());
        $this->assertSame([], Storage::disk($this->disk)->allFiles());
    }

    #[Test]
    public function a_hostile_file_name_and_title_are_stored_as_plain_display_text_only(): void
    {
        $this->upload($this->admin, [
            'file' => $this->pdf("../../etc/pass\x07wd\u{202E}.pdf"),
            'title' => "  <b>Report</b>\x00 \t  2025 ",
        ])->assertSessionHasNoErrors();
        $record = $this->onlyRecord();
        $document = $this->document($record);
        $this->assertSame('<b>Report</b> 2025', $record->title, 'control characters and repeated whitespace removed; markup is just text (escaped on render)');
        $this->assertSame('passwd.pdf', $document->original_filename);
        $this->assertStringNotContainsString('..', $document->storage_path);

        $this->upload($this->admin, ['title' => "   \x01 "])->assertSessionHasErrors('title');
        $this->upload($this->admin, ['title' => str_repeat('x', 121)])->assertSessionHasErrors('title');
    }

    #[Test]
    public function every_write_needs_a_fresh_code_and_nothing_is_stored_without_one(): void
    {
        $this->upload($this->admin, ['mfa_code' => ''])->assertSessionHasErrors('mfa_code');
        $this->upload($this->admin, ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->assertSame([], $this->records());
        $this->assertSame([], Storage::disk($this->disk)->allFiles());

        $this->upload($this->admin);
        $record = $this->onlyRecord();
        $this->put($this->url(suffix: "/{$record->id}"), ['category' => 'consent_form', 'title' => 'X', 'version' => 1])->assertSessionHasErrors('mfa_code');
        $this->post($this->url(suffix: "/{$record->id}/archive"), ['reason' => 'superseded', 'version' => 1])->assertSessionHasErrors('mfa_code');
        $this->assertFalse($this->onlyRecord()->isArchived());
    }

    #[Test]
    public function a_retried_upload_replays_and_a_reused_form_key_is_refused(): void
    {
        $key = (string) new UuidV7;
        $this->upload($this->admin, ['form_key' => $key])->assertSessionHasNoErrors();
        $this->upload($this->admin, ['form_key' => $key])->assertSessionHasNoErrors();
        // The same form re-sent with another file or title (Back, then a new upload) is refused, never silently dropped.
        $this->upload($this->admin, ['form_key' => $key, 'file' => $this->pdf('other.pdf', self::PDF.'% another')])->assertSessionHasErrors('refusal');
        $this->upload($this->admin, ['form_key' => $key, 'title' => 'Another title'])->assertSessionHasErrors('refusal');
        $this->assertCount(1, $this->records(), 'a retry never creates a second record');
        $this->assertCount(1, Storage::disk($this->disk)->allFiles(), 'nor leaves a second object');

        $other = $this->createStudent($this->school, ['student_number' => 'S-2']);
        $this->upload($this->admin, ['form_key' => $key], $other)->assertSessionHasErrors('refusal');
        $colleague = $this->member(['students.view', 'students.documents.view', 'students.documents.manage']);
        $this->upload($colleague, ['form_key' => $key])->assertSessionHasErrors('refusal');
        $this->assertCount(1, $this->records());
        $this->assertCount(1, Storage::disk($this->disk)->allFiles());
    }

    #[Test]
    public function edits_are_plain_versioned_and_stale_forms_are_refused(): void
    {
        $this->upload($this->admin);
        $record = $this->onlyRecord();
        $this->put($this->url(suffix: "/{$record->id}"), ['category' => 'consent_form', 'title' => 'Trip consent', 'document_date' => '2026-07-01', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertRedirect($this->url())->assertSessionHasNoErrors();
        $fresh = $this->onlyRecord();
        $this->assertSame(['consent_form', 'Trip consent', '2026-07-01', 2], [$fresh->category->value, $fresh->title, $fresh->document_date?->toDateString(), $fresh->version]);
        $audit = app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'student_document.updated')->firstOrFail());
        $this->assertEqualsCanonicalizing(['category', 'title', 'document_date'], $audit->metadata['changedFields']);

        // The same form submitted again is stale now: nothing changes.
        $this->put($this->url(suffix: "/{$record->id}"), ['category' => 'correspondence', 'title' => 'Late', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertSessionHasErrors('refusal');
        $this->assertSame('Trip consent', $this->onlyRecord()->title);
    }

    #[Test]
    public function archive_is_final_keeps_the_file_readable_and_records_a_closed_reason(): void
    {
        $this->upload($this->admin);
        $record = $this->onlyRecord();
        $this->post($this->url(suffix: "/{$record->id}/archive"), ['reason' => 'free text', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('reason');
        $this->post($this->url(suffix: "/{$record->id}/archive"), ['reason' => 'superseded', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertRedirect($this->url())->assertSessionHasNoErrors();

        $archived = $this->onlyRecord();
        $this->assertTrue($archived->isArchived());
        $this->assertSame(['superseded', $this->admin->id, 2], [$archived->archive_reason?->value, $archived->archived_by_user_id, $archived->version]);
        $this->assertSame('archived', $this->document($archived)->status);
        Storage::disk($this->disk)->assertExists($this->document($archived)->storage_path);
        $this->assertContains('student_document.archived', $this->auditActions());

        // Archived: not in the default list, in the archived list, still downloadable, never edited or archived again.
        $this->withMfaAssurance($this->admin);
        $this->assertSame([], $this->get($this->url())->viewData('page')['props']['documents']['data']);
        $this->assertSame(['superseded'], array_column(array_column($this->get($this->url().'?status=archived')->viewData('page')['props']['documents']['data'], 'archived'), 'reason'));
        $this->get($this->url(suffix: "/{$record->id}/download"))->assertOk();
        $this->put($this->url(suffix: "/{$record->id}"), ['category' => 'consent_form', 'title' => 'X', 'version' => 2, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
        $this->post($this->url(suffix: "/{$record->id}/archive"), ['reason' => 'superseded', 'version' => 2, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_starts_with((string) $r->getName(), 'app.students.documents.') && in_array('DELETE', $r->methods(), true)), 'no hard-delete route');
    }

    #[Test]
    public function the_principal_has_access_and_teachers_campus_holders_and_students_view_alone_do_not(): void
    {
        [$principal] = [$this->createUser()];
        $this->assignSchoolRole($this->createMembership($principal, $this->school), 'principal');
        $this->upload($principal)->assertSessionHasNoErrors();
        $record = $this->onlyRecord();
        $this->withMfaAssurance($principal)->get($this->url())->assertOk();

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $campus = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $deniedRoute = [
            'teacher' => $teacher,
            'campus-scoped principal' => $this->member(['students.view', 'students.documents.view', 'students.documents.manage'], $campus->id),
            'students.view and students.manage only' => $this->member(['students.view', 'students.manage']),
        ];
        foreach ($deniedRoute as $label => $user) {
            $this->as($user)->withMfaAssurance($user);
            $this->assertSame(403, $this->get($this->url())->status(), "{$label}: list");
            $this->assertSame(403, $this->get($this->url(suffix: "/{$record->id}/download"))->status(), "{$label}: download");
            $this->assertSame(403, $this->upload($user)->status(), "{$label}: upload");
        }

        // Document authority WITHOUT whole-School Student visibility: the Student is not found.
        $noStudent = $this->member(['students.documents.view', 'students.documents.manage']);
        $this->as($noStudent)->withMfaAssurance($noStudent);
        $this->get($this->url())->assertNotFound();
        $this->get($this->url(suffix: "/{$record->id}/download"))->assertNotFound();
        $this->assertSame(404, $this->upload($noStudent)->status());

        // A viewer may list and download but never write.
        $viewer = $this->member(['students.view', 'students.documents.view']);
        $this->as($viewer)->withMfaAssurance($viewer);
        $this->assertFalse($this->get($this->url())->assertOk()->viewData('page')['props']['canManage']);
        $this->get($this->url(suffix: "/{$record->id}/download"))->assertOk();
        $this->assertSame(403, $this->upload($viewer)->status());
        $this->assertCount(1, $this->records());
    }

    #[Test]
    public function guardians_platform_and_network_actors_reach_nothing(): void
    {
        $this->upload($this->admin);
        $record = $this->onlyRecord();
        $check = function (User $user, string $label) use ($record): void {
            $this->actingAs($user)->withMfaAssurance($user);
            foreach ([$this->url(), $this->url(suffix: "/{$record->id}/download")] as $url) {
                $this->assertNotSame(200, $this->get($url)->status(), "{$label}: {$url}");
            }
        };

        // A Guardian of THIS Student, signed in to the portal.
        $guardianUser = $this->createUser();
        $membership = $this->createMembership($guardianUser, $this->school);
        $guardian = $this->createGuardian($this->school);
        $this->createStudentGuardianRelationship($this->student, $guardian, ['is_legal_guardian' => true]);
        app(AccountLinkService::class)->linkGuardian($this->school, $guardian, $membership, $this->admin);
        $this->grantGuardianPortal($this->school, $membership, $guardianUser);
        $this->actingAs($guardianUser)->post("/app/schools/{$this->school->id}/activate");
        $check($guardianUser, 'guardian');

        $check($this->createPlatformRoot(), 'platform root');

        $root = $this->createPlatformRoot();
        $group = $this->createGroup([], 'Northwind Network');
        app(SchoolGroupGovernanceService::class)->addSchool($root, $group, $this->school->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        $manager = $this->createUser();
        app(NetworkAccessService::class)->grant($root, $group, $manager->email, 'network_manager', null, null, NetworkRoleChangeReason::APPOINTMENT, $this->freshProof($root));
        $check($manager, 'network manager');
    }

    #[Test]
    public function another_school_or_another_student_never_reaches_a_document(): void
    {
        $this->upload($this->admin);
        $record = $this->onlyRecord();

        [$otherAdmin, $otherSchool] = $this->createSchoolAdmin();
        $this->as($otherAdmin, $otherSchool)->withMfaAssurance($otherAdmin);
        $this->get($this->url())->assertNotFound();
        $this->get($this->url(suffix: "/{$record->id}/download"))->assertNotFound();
        $otherStudent = $this->createStudent($otherSchool, ['student_number' => 'O-1']);
        $this->get($this->url($otherStudent, "/{$record->id}/download"))->assertNotFound();

        // Same School, another Student: the record is this Student's only.
        $sibling = $this->createStudent($this->school, ['student_number' => 'S-9']);
        $this->as($this->admin)->withMfaAssurance($this->admin);
        $this->get($this->url($sibling, "/{$record->id}/download"))->assertNotFound();
        $this->assertSame([], $this->get($this->url($sibling))->viewData('page')['props']['documents']['data']);
        $this->post($this->url($sibling, "/{$record->id}/archive"), ['reason' => 'superseded', 'version' => 1, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertNotFound();
        $this->assertFalse($this->onlyRecord()->isArchived());
    }

    #[Test]
    public function the_generic_documents_paths_and_the_api_never_serve_a_student_document(): void
    {
        $this->upload($this->admin);
        $record = $this->onlyRecord();

        foreach ([
            fn () => app(DocumentReadService::class)->metadata($this->school, $record->document_id, $this->admin),
            fn () => app(DocumentReadService::class)->content($this->school, $record->document_id, $this->admin),
            fn () => app(DocumentService::class)->create($this->school, new CreateDocumentData(DocumentOwner::student($this->student->id), 'highly_sensitive', $this->pdf()), $this->admin),
        ] as $call) {
            try {
                $call();
                $this->fail('the generic path served a Student document');
            } catch (DocumentOwnerTypeNotSupportedException) {
                $this->addToAssertionCount(1);
            }
        }

        $apiRoutes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/'));
        $this->assertTrue($apiRoutes->every(fn ($r) => ! str_contains($r->uri(), 'students/{student}/documents')), 'no API route for Student documents');
    }

    #[Test]
    public function student_360_shows_counts_only_and_only_to_document_viewers(): void
    {
        $this->upload($this->admin, ['category' => 'prior_school_record']);
        $this->upload($this->admin, ['category' => 'consent_form', 'title' => 'Trip']);
        $this->as($this->admin);
        $sections = $this->get("/app/students/{$this->student->id}")->assertOk()->viewData('page')['props']['sections'];
        $this->assertSame(2, $sections['documents']['active']);
        $this->assertSame(['consent_form' => 1, 'prior_school_record' => 1], collect($sections['documents']['categories'])->pluck('count', 'value')->sortKeys()->all());
        $this->assertStringNotContainsString('Trip', (string) json_encode($sections['documents']), 'no title on the profile');

        $profileOnly = $this->member(['students.view']);
        $this->as($profileOnly);
        $this->assertArrayNotHasKey('documents', $this->get("/app/students/{$this->student->id}")->assertOk()->viewData('page')['props']['sections']);
    }

    #[Test]
    public function the_list_and_summary_use_a_constant_number_of_queries(): void
    {
        $count = function (): int {
            $this->as($this->admin)->withMfaAssurance($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($this->url())->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $profile = function (): int {
            $this->as($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/app/students/{$this->student->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $emptyList = $count();
        $emptyProfile = $profile();
        $this->upload($this->admin);
        $oneList = $count();
        for ($i = 0; $i < 6; $i++) {
            $this->upload($this->admin, ['title' => "Doc {$i}"]);
        }
        $this->assertSame($oneList, $count(), 'seven documents cost what one does');
        $this->assertSame($emptyProfile + 0, $profile(), 'the profile summary is one aggregate whatever the count');
        $this->assertLessThanOrEqual($emptyList + 3, $oneList);
    }

    #[Test]
    public function the_database_keeps_the_record_on_its_own_student_school_and_history(): void
    {
        $this->upload($this->admin);
        $record = $this->onlyRecord();
        $table = fn () => DB::table('student_documents');
        $sibling = $this->createStudent($this->school, ['student_number' => 'S-3']);

        // Same-Student composite key: a record cannot point at another Student's Document.
        $siblingDocument = $this->createDocumentForStudent($sibling);
        $this->refused(fn () => $table()->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'student_id' => $this->student->id, 'document_id' => $siblingDocument->id,
            'category' => 'consent_form', 'title' => 'X', 'form_key' => (string) Str::uuid7(), 'upload_fingerprint' => str_repeat('0', 64), 'uploaded_by_user_id' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]), 'student_documents_document_id_student_id_school_id_foreign');
        // Closed category, plain bounded title, archive shape.
        foreach ([[['category' => 'aadhaar'], 'student_documents_category_check'], [['title' => ' padded '], 'student_documents_title_check'],
            [['title' => ''], 'student_documents_title_check'], [['archive_reason' => 'superseded'], 'student_documents_archive_shape_check']] as [$bad, $rule]) {
            $this->refused(fn () => $table()->where('id', $record->id)->update($bad + ['version' => 2]), $rule);
        }
        // Identity is frozen, the version advances by one, an archived record is final.
        $this->refused(fn () => $table()->where('id', $record->id)->update(['student_id' => $sibling->id, 'version' => 2]), 'student_documents');
        $this->refused(fn () => $table()->where('id', $record->id)->update(['title' => 'Y', 'version' => 5]), 'advances its version by one');
        app(TenantContext::class)->withSchool($this->school, fn () => $table()->where('id', $record->id)->update(['archived_at' => now(), 'archived_by_user_id' => $this->admin->id, 'archive_reason' => 'superseded', 'version' => 2]));
        $this->refused(fn () => $table()->where('id', $record->id)->update(['title' => 'Z', 'version' => 3]), 'archived Student document is final');

        // The runtime role cannot delete a record.
        $this->refused(fn () => $table()->where('id', $record->id)->delete(), 'permission denied');

        // Forced RLS: another School's context sees nothing, and no context fails closed.
        [, $other] = $this->createSchoolAdmin();
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => DB::table('student_documents')->count()));
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_documents')->count()));
        $this->assertSame(0, DB::table('student_documents')->count());
    }

    /** A write the database itself must refuse (a savepoint keeps the test transaction usable). */
    private function refused(\Closure $write, string $rule): void
    {
        try {
            app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction($write));
            $this->fail("the database accepted a write it must refuse ({$rule})");
        } catch (QueryException $e) {
            $this->assertStringContainsString($rule, $e->getMessage());
        }
    }
}
