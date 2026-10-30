<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Students\Application\Certificates\StudentCertificateEligibility;
use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Domain\Students\Application\Certificates\StudentCertificateVoidReason;
use App\Domain\Students\Application\Exceptions\StudentCertificateUnavailableException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * SIS.EXP-4 (OD-SIS-2): Leaving and Transfer certificates -- eligibility from
 * the canonical exit, an immutable numbered snapshot, the generated PDF as a
 * private Student document, void and reissue, whole-School authority with
 * fresh MFA, and the legal production gate.
 */
class StudentCertificateTest extends TestCase
{
    use CreatesFeesFixtures;
    use CreatesFinanceFixtures;
    use CreatesGuardianPortalFixtures;
    use CreatesTenancyFixtures;
    use GroupTestHelpers;
    use ProvidesSensitiveActionMfa;

    private School $school;

    private User $admin;

    private Campus $campus;

    private AcademicYear $year;

    private Section $section;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('documents.disk');
        Storage::fake($this->disk);
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->campus = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->section = $this->createSection($this->year, $this->campus, $grade, ['name' => 'A', 'code' => 'A']);
    }

    private function placements(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function current(Student $s): StudentEnrollment
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $s->id)->where('status', 'active')->firstOrFail());
    }

    /** A Student placed from 1 June and withdrawn on 15 September with $reason. */
    private function leaver(string $reason = 'left_school', string $first = 'Asha', ?string $number = null): Student
    {
        $s = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Rao', 'student_number' => $number ?? 'S-'.Str::random(5)]);
        $this->placements()->enroll($s, $this->section, (string) random_int(1, 99999), '2026-06-01', $this->admin);
        $this->placements()->withdraw($this->current($s), '2026-09-15', $reason, $this->admin, $this->freshProof($this->admin));

        return $this->reload($s);
    }

    private function reload(Student $s): Student
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->findOrFail($s->id));
    }

    private function url(Student $s, string $suffix = ''): string
    {
        return "/app/students/{$s->id}/certificates{$suffix}";
    }

    private function as(User $user, ?School $school = null): static
    {
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    /** @return array<string, mixed> */
    private function page(Student $s, ?User $as = null): array
    {
        $this->as($as ?? $this->admin);

        return $this->get($this->url($s))->assertOk()->viewData('page')['props'];
    }

    private function issue(Student $s, string $type = 'leaving', ?User $as = null, array $overrides = []): TestResponse
    {
        $as ??= $this->admin;
        $props = $this->page($s, $as);
        $fingerprint = collect($props['preview']['types'] ?? [])->firstWhere('value', $type)['fingerprint'] ?? str_repeat('0', 64);

        return $this->post($this->url($s), array_merge([
            'type' => $type, 'preview_fingerprint' => $fingerprint, 'form_key' => $props['formKey'], 'confirm' => '1',
            'mfa_code' => $this->freshMfaCode($as),
        ], $overrides));
    }

    /** @return list<StudentCertificate> */
    private function certificates(?School $school = null): array
    {
        $school ??= $this->school;

        return app(TenantContext::class)->withSchool($school, fn () => StudentCertificate::query()->where('school_id', $school->id)->orderBy('issued_at')->orderBy('id')->get()->all());
    }

    /** @param  list<string>  $capabilities */
    private function member(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    #[Test]
    public function eligibility_comes_only_from_a_recorded_exit_and_never_from_an_active_placement(): void
    {
        $placed = $this->createStudent($this->school, ['student_number' => 'P-1']);
        $this->placements()->enroll($placed, $this->section, '1', '2026-06-01', $this->admin);
        $never = $this->createStudent($this->school, ['student_number' => 'N-1']);
        $duplicate = $this->leaver('left_school');
        app(StudentService::class)->changeStatus($duplicate, 'inactive', 'duplicate_record', $this->admin, $this->freshProof($this->admin));
        $returned = $this->leaver('left_school');
        app(StudentService::class)->changeStatus($returned, 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));
        app(StudentService::class)->changeStatus($this->reload($returned), 'active', 'returned_to_school', $this->admin, $this->freshProof($this->admin));

        $expect = fn (Student $s) => $this->page($s)['preview']['refusal'];
        $this->assertStringContainsString('active placement', $expect($placed));
        $this->assertStringContainsString('never been placed', $expect($never));
        $this->assertStringContainsString('not an exit', $expect($duplicate));
        $this->assertStringContainsString('returned', $expect($returned));
        $this->issue($placed)->assertSessionHasErrors('refusal');
        $this->assertSame([], $this->certificates(), 'nothing was issued for an ineligible Student');

        $left = $this->leaver('left_school');
        $this->assertSame(['leaving'], array_column($this->page($left)['preview']['types'], 'value'), 'a non-transfer exit offers the Leaving Certificate only');
        $transferred = $this->leaver('transferred_out');
        $this->assertSame(['leaving', 'transfer'], array_column($this->page($transferred)['preview']['types'], 'value'));
        $this->issue($left, 'transfer')->assertSessionHasErrors('refusal');
        $this->assertSame([], $this->certificates());
    }

    #[Test]
    public function issuing_freezes_a_snapshot_numbers_it_and_stores_a_private_pdf_student_document(): void
    {
        $s = $this->leaver('transferred_out', 'Asha', 'S-100');
        $this->issue($s, 'transfer')->assertRedirect($this->url($s))->assertSessionHasNoErrors();

        [$c] = $this->certificates();
        $this->assertSame(['transfer', 1, 'TC-000001', 'issued'], [$c->certificate_type->value, $c->serial, $c->display_number, $c->status]);
        $this->assertSame(['Asha Rao', 'S-100', $this->school->name, 'Main', '2026-27', 'Grade 5', 'A', '2026-06-01', '2026-09-15', 'transferred_out'],
            [$c->student_name, $c->student_number, $c->school_name, $c->campus_name, $c->academic_year_name, $c->grade_name, $c->section_name, $c->joined_on?->toDateString(), $c->left_on->toDateString(), $c->exit_reason]);

        $document = app(TenantContext::class)->withSchool($this->school, fn () => Document::query()->findOrFail($c->document_id));
        $this->assertSame(['highly_sensitive', 'application/pdf', $s->id], [$document->classification_tier, $document->mime_type, $document->student_id]);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk($this->disk)->get($document->storage_path));
        $record = app(TenantContext::class)->withSchool($this->school, fn () => StudentDocument::query()->where('document_id', $document->id)->firstOrFail());
        $this->assertSame(['issued_certificate', 'Transfer Certificate TC-000001'], [$record->category->value, $record->title]);

        // Download: the Student document route, behind current MFA, attachment only.
        $history = $this->page($s)['history'];
        $this->assertSame(['TC-000001'], array_column($history, 'number'));
        $this->withMfaAssurance($this->admin);
        $download = $this->get($history[0]['downloadUrl'])->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());

        // Audit: ids, codes and the MFA time -- never a snapshot value.
        $audit = app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'student_certificate.issued')->firstOrFail());
        $this->assertEqualsCanonicalizing(['certificateId', 'studentId', 'type', 'serial', 'sourceEnrollmentId', 'documentId', 'supersedesCertificateId', 'mfaVerifiedAt'], array_keys($audit->metadata));
        $this->assertStringNotContainsString('Asha', (string) json_encode($audit->metadata));
    }

    #[Test]
    public function numbers_are_per_school_and_type_sequential_never_reused_and_voids_keep_their_number(): void
    {
        $a = $this->leaver('transferred_out');
        $b = $this->leaver('left_school');
        $this->issue($a, 'leaving');
        $this->issue($a, 'transfer');
        $this->issue($b, 'leaving');
        $this->assertSame(['LC-000001', 'TC-000001', 'LC-000002'], array_map(fn ($c) => $c->display_number, $this->certificates()));

        $first = $this->certificates()[0];
        $this->as($this->admin);
        $this->post($this->url($a, "/{$first->id}/void"), ['reason' => 'issued_in_error', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->issue($a, 'leaving');
        $all = $this->certificates();
        $this->assertSame('LC-000003', end($all)->display_number, 'a voided number is never reused');

        [, $other] = $this->createSchoolAdmin();
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => DB::table('student_certificate_sequences')->count()), 'another School has its own counters');
    }

    #[Test]
    public function a_retry_replays_a_reused_form_is_refused_and_an_exit_gets_one_issued_certificate(): void
    {
        $s = $this->leaver();
        $props = $this->page($s);
        $body = ['type' => 'leaving', 'preview_fingerprint' => $props['preview']['types'][0]['fingerprint'], 'form_key' => $props['formKey'], 'confirm' => '1'];
        $this->post($this->url($s), $body + ['mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->post($this->url($s), $body + ['mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->certificates(), 'the retry replays the first certificate');
        $this->assertCount(1, Storage::disk($this->disk)->allFiles());

        // A new form for the same exit and type is refused: void or reissue instead.
        $this->issue($s)->assertSessionHasErrors('refusal');
        // The same key for another Student is refused.
        $other = $this->leaver();
        $this->post($this->url($other), ['type' => 'leaving', 'preview_fingerprint' => $this->page($other)['preview']['types'][0]['fingerprint'], 'form_key' => $props['formKey'], 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertSessionHasErrors('refusal');
        $this->assertCount(1, $this->certificates());
    }

    #[Test]
    public function a_stale_preview_or_lifecycle_change_is_refused_without_consuming_a_number(): void
    {
        $s = $this->leaver();
        $props = $this->page($s);
        $fingerprint = $props['preview']['types'][0]['fingerprint'];
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('students')->where('id', $s->id)->update(['first_name' => 'Renamed']));

        $this->post($this->url($s), ['type' => 'leaving', 'preview_fingerprint' => $fingerprint, 'form_key' => $props['formKey'], 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertSessionHasErrors('refusal');
        $this->assertSame([], $this->certificates());

        // Returned to the School between preview and issue: re-evaluated under the lock.
        $props = $this->page($s);
        app(StudentService::class)->changeStatus($this->reload($s), 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));
        app(StudentService::class)->changeStatus($this->reload($s), 'active', 'returned_to_school', $this->admin, $this->freshProof($this->admin));
        $this->post($this->url($s), ['type' => 'leaving', 'preview_fingerprint' => $props['preview']['types'][0]['fingerprint'], 'form_key' => $props['formKey'], 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertSessionHasErrors('refusal');
        $this->assertSame([], $this->certificates());
        $this->assertSame(0, (int) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_certificate_sequences')->value('last_serial')), 'no number was consumed');
    }

    #[Test]
    public function issue_needs_a_fresh_code_and_an_explicit_confirmation(): void
    {
        $s = $this->leaver();
        $this->issue($s, overrides: ['mfa_code' => ''])->assertSessionHasErrors('mfa_code');
        $this->issue($s, overrides: ['confirm' => ''])->assertSessionHasErrors('confirm');
        $this->assertSame([], $this->certificates());
        $this->assertSame([], Storage::disk($this->disk)->allFiles());
    }

    #[Test]
    public function an_issued_certificate_never_changes_and_later_edits_do_not_reach_it(): void
    {
        $s = $this->leaver();
        $this->issue($s);
        [$c] = $this->certificates();

        app(TenantContext::class)->withSchool($this->school, function () use ($s): void {
            DB::table('students')->where('id', $s->id)->update(['first_name' => 'Changed']);
            DB::table('schools')->where('id', $this->school->id)->update(['name' => 'Renamed School']);
        });
        $fresh = $this->certificates()[0];
        $this->assertSame(['Asha Rao', $c->school_name], [$fresh->student_name, $fresh->school_name], 'the snapshot is frozen');

        $refused = function (array $change, string $rule) use ($c): void {
            try {
                app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction(fn () => DB::table('student_certificates')->where('id', $c->id)->update($change)));
                $this->fail("the database accepted {$rule}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($rule, $e->getMessage());
            }
        };
        $refused(['student_name' => 'Edited'], 'never edited');
        $refused(['serial' => 99], 'never edited');
        $refused(['status' => 'issued', 'display_number' => 'LC-000099'], 'never edited');
        $refused(['status' => 'voided'], 'student_certificates_void_shape_check');
        try {
            app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction(fn () => DB::table('student_certificates')->where('id', $c->id)->delete()));
            $this->fail('the runtime role deleted a certificate');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function void_needs_a_closed_reason_and_a_code_keeps_the_file_and_is_final(): void
    {
        $s = $this->leaver();
        $this->issue($s);
        [$c] = $this->certificates();
        $this->as($this->admin);

        $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'typo', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('reason');
        $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'replaced_by_corrected_certificate', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('reason');
        $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'data_error', 'confirm' => '1'])->assertSessionHasErrors('mfa_code');
        $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'data_error', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasNoErrors();

        $voided = $this->certificates()[0];
        $this->assertSame(['voided', 'data_error', $this->admin->id], [$voided->status, $voided->void_reason?->value, $voided->voided_by_user_id]);
        $document = app(TenantContext::class)->withSchool($this->school, fn () => Document::query()->findOrFail($c->document_id));
        $this->assertSame('archived', $document->status);
        Storage::disk($this->disk)->assertExists($document->storage_path);
        $record = app(TenantContext::class)->withSchool($this->school, fn () => StudentDocument::query()->where('document_id', $c->document_id)->firstOrFail());
        $this->assertSame('certificate_voided', $record->archive_reason?->value);
        $this->assertContains('student_certificate.voided', app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->pluck('event_type')->all()));

        $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'data_error', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
        // The generated document is never archived or edited from the document list.
        $this->withMfaAssurance($this->admin);
        $this->post("/app/students/{$s->id}/documents/{$record->id}/archive", ['reason' => 'superseded', 'version' => $record->version, 'mfa_code' => $this->freshMfaCode($this->admin)])->assertSessionHasErrors('refusal');
    }

    #[Test]
    public function reissue_voids_the_original_and_issues_a_linked_replacement_with_a_new_number(): void
    {
        $s = $this->leaver();
        $this->issue($s);
        [$original] = $this->certificates();
        // The correction: the Student's name is fixed, then the certificate is reissued.
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('students')->where('id', $s->id)->update(['last_name' => 'Rao-Iyer']));

        $props = $this->page($s);
        $this->post($this->url($s, "/{$original->id}/reissue"), [
            'preview_fingerprint' => $props['preview']['reissueFingerprints'][$original->id], 'form_key' => $props['formKey'], 'confirm' => '1',
            'mfa_code' => $this->freshMfaCode($this->admin),
        ])->assertSessionHasNoErrors();

        [$old, $new] = $this->certificates();
        $this->assertSame(['voided', 'replaced_by_corrected_certificate'], [$old->status, $old->void_reason?->value]);
        $this->assertSame(['issued', 'LC-000002', $old->id, 'Asha Rao-Iyer'], [$new->status, $new->display_number, $new->supersedes_certificate_id, $new->student_name]);
        $this->assertNotSame($old->document_id, $new->document_id);
        $this->assertCount(2, Storage::disk($this->disk)->allFiles(), 'the original output is retained');
        $history = collect($this->page($s)['history'])->keyBy('number');
        $this->assertSame(['LC-000002', 'LC-000001'], [$history['LC-000001']['replacedByNumber'], $history['LC-000002']['supersedesNumber']]);

        // A replacement cannot name a certificate that is not voided as replaced.
        try {
            app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction(fn () => DB::table('student_certificates')->insert([
                ...collect($new->getAttributes())->except(['id', 'serial', 'display_number', 'form_key', 'document_id', 'supersedes_certificate_id', 'status', 'voided_at', 'voided_by_user_id', 'void_reason'])->all(),
                'id' => (string) Str::uuid7(), 'serial' => 77, 'display_number' => 'LC-000077', 'form_key' => (string) Str::uuid7(), 'status' => 'issued',
                'document_id' => $this->createDocumentForStudent($s)->id, 'supersedes_certificate_id' => $new->id,
            ])));
            $this->fail('a replacement of an issued certificate was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('voided as replaced', $e->getMessage());
        }
    }

    #[Test]
    public function fees_never_block_issuance_and_finance_context_reaches_only_finance_holders(): void
    {
        $s = $this->createStudent($this->school, ['first_name' => 'Dev', 'last_name' => 'Owes', 'student_number' => 'F-1']);
        $this->placements()->enroll($s, $this->section, '7', '2026-06-01', $this->admin);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset', 'code' => '1100', 'name' => 'Fees Receivable', 'status' => 'active']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income', 'code' => '4000', 'name' => 'Tuition', 'status' => 'active']);
        $this->assessCharge($this->school, $s, $this->year, $receivable, $revenue, '5000.00');
        $this->placements()->withdraw($this->current($s), '2026-09-15', 'left_school', $this->admin, $this->freshProof($this->admin));

        $props = $this->page($s);
        $this->assertSame(['outstanding' => '5000.00', 'currency' => 'INR'], $props['finance'], 'issuer context for a Finance holder');
        $this->issue($s)->assertSessionHasNoErrors();
        $this->assertCount(1, $this->certificates(), 'an outstanding balance never blocks a certificate');

        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $this->school), 'principal');
        $this->assertNull($this->page($s, $principal)['finance'], 'a principal without Finance receives no Finance value');
    }

    #[Test]
    public function only_whole_school_certificate_issuers_issue_and_everyone_else_is_refused(): void
    {
        $s = $this->leaver();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $this->school), 'principal');
        $this->issue($s, as: $principal)->assertSessionHasNoErrors();
        [$c] = $this->certificates();

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $campusIssuer = $this->member(['students.view', 'students.documents.view', 'students.certificates.issue'], $this->campus->id);
        foreach (['teacher' => $teacher, 'Campus-scoped issuer' => $campusIssuer, 'students.view and .manage only' => $this->member(['students.view', 'students.manage'])] as $label => $user) {
            $this->as($user);
            $this->assertSame(403, $this->get($this->url($s))->status(), "{$label}: page");
            $this->assertSame(403, $this->post($this->url($s), ['type' => 'leaving', 'preview_fingerprint' => str_repeat('a', 64), 'form_key' => (string) new UuidV7, 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($user)])->status(), "{$label}: issue");
            $this->assertSame(403, $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'data_error', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($user)])->status(), "{$label}: void");
        }

        // A document viewer sees the history (and the download link) but cannot issue.
        $viewer = $this->member(['students.view', 'students.documents.view']);
        $props = $this->page($s, $viewer);
        $this->assertFalse($props['canIssue']);
        $this->assertNull($props['preview']);
        $this->assertNotNull($props['history'][0]['downloadUrl']);
        // Issuing authority WITHOUT whole-School Student visibility: not found.
        $blind = $this->member(['students.certificates.issue', 'students.documents.view']);
        $this->as($blind);
        $this->get($this->url($s))->assertNotFound();

        // Guardian of this Student, platform root, Network Manager: nothing.
        $guardianUser = $this->createUser();
        $membership = $this->createMembership($guardianUser, $this->school);
        $guardian = $this->createGuardian($this->school);
        $this->createStudentGuardianRelationship($s, $guardian, ['is_legal_guardian' => true]);
        app(AccountLinkService::class)->linkGuardian($this->school, $guardian, $membership, $this->admin);
        $this->grantGuardianPortal($this->school, $membership, $guardianUser);
        $root = $this->createPlatformRoot();
        $group = $this->createGroup([], 'Northwind Network');
        app(SchoolGroupGovernanceService::class)->addSchool($root, $group, $this->school->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        $manager = $this->createUser();
        app(NetworkAccessService::class)->grant($root, $group, $manager->email, 'network_manager', null, null, NetworkRoleChangeReason::APPOINTMENT, $this->freshProof($root));
        foreach (['guardian' => $guardianUser, 'platform root' => $this->createPlatformRoot(), 'network manager' => $manager] as $label => $user) {
            $this->actingAs($user);
            if ($label === 'guardian') {
                $this->post("/app/schools/{$this->school->id}/activate");
            }
            $this->assertNotSame(200, $this->get($this->url($s))->status(), "{$label}: page");
            $this->post($this->url($s), ['type' => 'leaving', 'preview_fingerprint' => str_repeat('a', 64), 'form_key' => (string) new UuidV7, 'confirm' => '1']);
        }
        $this->assertCount(1, $this->certificates(), 'nothing else was issued');

        // Another School: the Student is not found.
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->as($otherAdmin, $other);
        $this->get($this->url($s))->assertNotFound();
        $this->post($this->url($s, "/{$c->id}/void"), ['reason' => 'data_error', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($otherAdmin)])->assertNotFound();
    }

    #[Test]
    public function the_legal_production_gate_refuses_issue_void_and_reissue_outside_development(): void
    {
        $s = $this->leaver();
        $this->issue($s);
        [$c] = $this->certificates();
        $service = app(StudentCertificateService::class);
        $this->app['env'] = 'production';
        try {
            foreach ([
                fn () => $service->issue($this->school, $this->admin, $s->id, StudentCertificateType::LEAVING, str_repeat('a', 64), (string) new UuidV7, $this->freshProof($this->admin)),
                fn () => $service->void($this->school, $this->admin, $s->id, $c->id, StudentCertificateVoidReason::DATA_ERROR, $this->freshProof($this->admin)),
                fn () => $service->reissue($this->school, $this->admin, $s->id, $c->id, str_repeat('a', 64), (string) new UuidV7, $this->freshProof($this->admin)),
            ] as $call) {
                try {
                    $call();
                    $this->fail('a certificate write ran in production');
                } catch (StudentCertificateUnavailableException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame('issued', $this->certificates()[0]->status);
    }

    #[Test]
    public function a_non_latin_name_is_refused_before_a_number_or_file_is_used(): void
    {
        $s = $this->leaver('left_school', 'आशा');
        $props = $this->page($s);
        $this->assertFalse($props['preview']['printable']);
        $this->issue($s)->assertSessionHasErrors('refusal');
        $this->assertSame([], $this->certificates());
        $this->assertSame([], Storage::disk($this->disk)->allFiles());
        $this->assertSame(0, (int) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_certificate_sequences')->value('last_serial')));
    }

    #[Test]
    public function a_failure_after_the_file_is_written_leaves_no_row_and_no_file(): void
    {
        $s = $this->leaver();
        $real = app(AuditRecorder::class);
        $this->app->instance(AuditRecorder::class, new class(app(TenantContext::class), $real) extends AuditRecorder
        {
            public function __construct(TenantContext $context, private readonly AuditRecorder $real)
            {
                parent::__construct($context);
            }

            public function school(School $school, string $eventType, ?User $actor = null, ?Model $subject = null, array $metadata = []): SchoolAuditEvent
            {
                if ($eventType === 'student_certificate.issued') {
                    throw new \RuntimeException('injected failure');
                }

                return $this->real->school($school, $eventType, $actor, $subject, $metadata);
            }
        });
        try {
            $this->issue($s);
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $this->certificates());
        $this->assertSame([], Storage::disk($this->disk)->allFiles(), 'Documents compensation removed the generated file');
        $this->assertSame(0, app(TenantContext::class)->withSchool($this->school, fn () => DB::table('documents')->count()));
    }

    #[Test]
    public function student_360_shows_the_certificate_history_with_one_query_whatever_the_count(): void
    {
        $s = $this->leaver('transferred_out');
        $count = function () use ($s): int {
            $this->as($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/app/students/{$s->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $empty = $count();
        $this->issue($s, 'leaving');
        $one = $count();
        $this->issue($s, 'transfer');
        [$lc] = $this->certificates();
        $this->as($this->admin);
        $this->post($this->url($s, "/{$lc->id}/void"), ['reason' => 'issued_in_error', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)]);
        $this->assertSame($one, $count(), 'three history rows cost what one does');
        $this->assertLessThanOrEqual($empty + 1, $one);

        $this->as($this->admin);
        $items = $this->get("/app/students/{$s->id}")->viewData('page')['props']['sections']['certificates']['items'];
        $this->assertSame(['TC-000001', 'LC-000001'], array_column($items, 'number'));
        $this->assertSame(['issued', 'voided'], array_column($items, 'status'));
    }

    #[Test]
    public function a_certificate_of_an_earlier_exit_is_never_replaced_by_one_of_a_later_exit(): void
    {
        $s = $this->leaver();
        $this->issue($s);
        [$first] = $this->certificates();
        // The Student returns (a new placement) and leaves again.
        $this->placements()->enroll($s, $this->section, '77', '2026-09-20', $this->admin);
        $this->placements()->withdraw($this->current($s), '2026-10-01', 'left_school', $this->admin, $this->freshProof($this->admin));

        $props = $this->page($s);
        $this->assertNull($props['preview']['reissueFingerprints'][$first->id], 'no reissue is offered across exits');
        $this->assertFalse($props['preview']['types'][0]['alreadyIssued'], 'the new exit has no certificate yet');
        $fingerprint = StudentCertificateEligibility::fingerprint(
            app(StudentCertificateService::class)->preview($this->school, $this->admin, $s)['snapshot'], StudentCertificateType::LEAVING,
            (string) app(StudentCertificateService::class)->preview($this->school, $this->admin, $s)['sourceEnrollmentId'], $first->id);
        $this->post($this->url($s, "/{$first->id}/reissue"), ['preview_fingerprint' => $fingerprint, 'form_key' => $props['formKey'], 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertSessionHasErrors('refusal');
        $this->assertSame(['issued'], array_map(fn ($c) => $c->status, $this->certificates()), 'the earlier exit keeps its certificate');

        // A new exit gets its own certificate.
        $this->issue($s)->assertSessionHasNoErrors();
        $this->assertSame(['LC-000001', 'LC-000002'], array_map(fn ($c) => $c->display_number, $this->certificates()));
    }
}
