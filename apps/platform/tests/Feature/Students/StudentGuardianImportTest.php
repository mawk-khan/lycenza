<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Guardians\Application\Exceptions\DuplicateRelationshipException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Students\Application\Import\StudentGuardianImport;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * SIS.EXP-5 (OD-SIS-3): the add-only bulk Student & Guardian import --
 * preview read-only and signed, atomic commit through the canonical services
 * behind `students.import` + the single paths' keys, fresh MFA, idempotent,
 * never a match, update, merge, portal account or message.
 */
class StudentGuardianImportTest extends TestCase
{
    use CreatesGuardianPortalFixtures;
    use CreatesTenancyFixtures;
    use GroupTestHelpers;
    use ProvidesSensitiveActionMfa;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $ukg = $this->createGradeLevel($this->school, ['name' => 'UKG', 'code' => 'UKG', 'sequence' => 1]);
        $this->createSection($this->year, $this->main, $g5, ['name' => 'A', 'code' => 'A']);
        $this->createSection($this->year, $this->early, $ukg, ['name' => 'E', 'code' => 'E']);
    }

    /** @param  array<string, string>  $cells */
    private function row(array $cells = []): array
    {
        return array_merge(array_fill_keys(StudentGuardianImport::columns(), ''), [
            'first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '2016-04-12',
            'academic_year_code' => 'AY26', 'campus_code' => 'MAIN', 'grade_code' => 'G5', 'section_code' => 'A', 'roll_number' => '1', 'start_date' => '2026-06-01',
            'guardian1_first_name' => 'Ravi', 'guardian1_last_name' => 'Rao', 'guardian1_relationship' => 'father', 'guardian1_email' => 'ravi.rao@example.test', 'guardian1_legal_guardian' => 'yes',
        ], $cells);
    }

    /** @param  list<array<string, string>>  $rows */
    private function csv(array $rows, ?array $header = null): string
    {
        $header ??= StudentGuardianImport::columns();
        $escape = fn (string $v) => preg_match('/[",\n]/', $v) ? '"'.str_replace('"', '""', $v).'"' : $v;

        return implode(',', $header)."\n".implode("\n", array_map(fn (array $r) => implode(',', array_map(fn ($c) => $escape((string) ($r[$c] ?? '')), $header)), $rows))."\n";
    }

    /** The valid family: siblings on both Campuses sharing one NEW Guardian; the elder with a second Guardian. */
    private function family(): string
    {
        return $this->csv([
            $this->row(['guardian1_key' => 'FAM-1', 'guardian2_first_name' => 'Meena', 'guardian2_last_name' => 'Rao', 'guardian2_relationship' => 'mother', 'guardian2_mobile' => '+919810000001', 'guardian2_emergency_contact' => 'yes']),
            $this->row(['first_name' => 'Arun', 'date_of_birth' => '2021-09-30', 'campus_code' => 'EY', 'grade_code' => 'UKG', 'section_code' => 'E', 'roll_number' => '1', 'guardian1_key' => 'FAM-1']),
            $this->row(['first_name' => 'Kabir', 'last_name' => 'Shah', 'date_of_birth' => '2016-01-05', 'roll_number' => '2', 'guardian1_first_name' => 'Sana', 'guardian1_last_name' => 'Shah', 'guardian1_relationship' => 'mother', 'guardian1_email' => 'sana.shah@example.test']),
        ]);
    }

    private function as(User $user, ?School $school = null): static
    {
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    private function file(string $contents, string $name = 'students.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** @return array<string, mixed> */
    private function preview(string $csv, ?User $as = null, ?School $school = null): array
    {
        $this->as($as ?? $this->admin, $school);

        return $this->post('/app/students/import/preview', ['file' => $this->file($csv)])->assertOk()->viewData('page')['props']['preview'];
    }

    /** @param  array<string, mixed>  $preview */
    private function commit(array $preview, ?User $as = null, array $overrides = []): TestResponse
    {
        $as ??= $this->admin;

        return $this->post('/app/students/import/commit', array_merge([
            'payload' => $preview['payload'], 'issued_at' => $preview['issuedAt'], 'result_digest' => $preview['resultDigest'], 'signature' => $preview['signature'],
            'acknowledge_warnings' => '0', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($as),
        ], $overrides));
    }

    private function tableCount(string $table): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => DB::table($table)->where('school_id', $this->school->id)->count());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return array_combine($t = ['students', 'guardians', 'student_guardian_relationships', 'student_enrollments', 'guardian_contacts', 'student_import_batches'], array_map(fn ($x) => $this->tableCount($x), $t));
    }

    /** @param  list<string>  $capabilities */
    private function member(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    #[Test]
    public function the_preview_is_read_only_and_counts_students_guardians_relationships_and_campuses(): void
    {
        $before = $this->counts();
        $preview = $this->preview($this->family());

        $this->assertSame($before, $this->counts(), 'nothing is written during the preview');
        $this->assertTrue($preview['committable']);
        $this->assertSame(['rows' => 3, 'ready' => 3, 'warning' => 0, 'error' => 0, 'students' => 3, 'guardians' => 3, 'relationships' => 4, 'placements' => 3, 'campuses' => 2], $preview['summary']);
        $this->assertSame(['Ravi Rao (father) [FAM-1]', 'Meena Rao (mother)'], $preview['rows'][0]['guardians']);
        $this->assertNotNull($preview['signature']);
        $this->assertStringNotContainsString('student_number', (string) json_encode($preview['rows']), 'no number is reserved');
    }

    #[Test]
    public function commit_creates_everything_through_the_canonical_services_and_sends_nothing(): void
    {
        Mail::fake();
        $users = User::query()->count();
        $before = $this->counts();
        $this->commit($this->preview($this->family()))->assertRedirect('/app/students/import')->assertSessionHasNoErrors();

        $after = $this->counts();
        $this->assertSame([3, 3, 4, 3, 3, 1], [
            $after['students'] - $before['students'], $after['guardians'] - $before['guardians'], $after['student_guardian_relationships'] - $before['student_guardian_relationships'],
            $after['student_enrollments'] - $before['student_enrollments'], $after['guardian_contacts'] - $before['guardian_contacts'], $after['student_import_batches'],
        ]);
        $ctx = app(TenantContext::class);
        $students = $ctx->withSchool($this->school, fn () => DB::table('students')->where('school_id', $this->school->id)->orderBy('student_number')->get(['id', 'first_name', 'student_number', 'status']));
        $this->assertSame(['active'], $students->pluck('status')->unique()->values()->all());
        $this->assertSame($students->pluck('student_number')->all(), $students->pluck('student_number')->unique()->values()->all(), 'numbers come from the School counter');

        // The siblings share ONE new Guardian; Asha has two, Ravi is her primary.
        $asha = $students->firstWhere('first_name', 'Asha');
        $arun = $students->firstWhere('first_name', 'Arun');
        $links = $ctx->withSchool($this->school, fn () => DB::table('student_guardian_relationships')->whereIn('student_id', [$asha->id, $arun->id])->get());
        $ravi = $links->where('student_id', $arun->id)->first()->guardian_id;
        $this->assertTrue($links->where('student_id', $asha->id)->contains(fn ($l) => $l->guardian_id === $ravi && $l->is_primary && $l->is_legal_guardian));
        $this->assertCount(2, $links->where('student_id', $asha->id));
        // Placements on both Campuses.
        $this->assertSame([$this->main->id, $this->early->id], $ctx->withSchool($this->school, fn () => DB::table('student_enrollments')->whereIn('student_id', [$asha->id, $arun->id])->orderBy('roll_number')->orderBy('campus_id', $this->main->id < $this->early->id ? 'asc' : 'desc')->pluck('campus_id')->unique()->values()->all()) ?: []);

        // No account, invitation, link or message.
        Mail::assertNothingSent();
        $this->assertSame($users, User::query()->count());
        foreach (['student_guardian_account_links', 'identity_account_invitations', 'email_messages'] as $table) {
            $this->assertSame(0, $this->tableCount($table), "{$table}: no side effect");
        }

        // One batch audit with counts and the MFA time only.
        $audit = $ctx->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'student_import.committed')->firstOrFail());
        $this->assertEqualsCanonicalizing(['batchId', 'formatVersion', 'rows', 'students', 'guardians', 'relationships', 'placements', 'campuses', 'warningsAcknowledged', 'mfaVerifiedAt'], array_keys($audit->metadata));
        foreach (['Asha', 'Ravi', 'example.test', '+9198'] as $absent) {
            $this->assertStringNotContainsString($absent, (string) json_encode($audit->metadata));
        }
    }

    #[Test]
    public function every_invalid_row_is_an_error_and_blocks_the_whole_batch(): void
    {
        $existing = $this->createStudent($this->school, ['student_number' => 'X-1']);
        $this->placementService()->enroll($existing, $this->section('A'), '9', '2026-06-01', $this->admin);
        $cases = [
            'campus_code' => ['campus_code' => 'NOPE'],
            'grade_code' => ['grade_code' => 'G9'],
            'section_code' => ['section_code' => 'Z'],
            'academic_year_code' => ['academic_year_code' => 'AY99'],
            'date_of_birth' => ['date_of_birth' => '12/04/2016'],
            'start_date' => ['start_date' => '2025-01-01'],
            'guardian1_relationship' => ['guardian1_relationship' => 'uncle'],
            'guardian1_legal_guardian' => ['guardian1_legal_guardian' => 'maybe'],
            'guardian1_email' => ['guardian1_email' => 'not-an-email'],
            'roll_number' => ['roll_number' => '9'],
            'last_name' => ['last_name' => "Rao\u{202E}gnp"],
        ];
        foreach ($cases as $field => $cells) {
            $preview = $this->preview($this->csv([$this->row($cells)]));
            $this->assertFalse($preview['committable'], $field);
            $this->assertNull($preview['signature'], "{$field}: an uncommittable preview is never signed");
            $this->assertContains($field, array_column($preview['rows'][0]['issues'], 'field'), $field);
        }
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('grade_levels')->where('code', 'UKG')->update(['status' => 'inactive']));
        $preview = $this->preview($this->csv([$this->row(['campus_code' => 'EY', 'grade_code' => 'UKG', 'section_code' => 'E'])]));
        $this->assertContains('grade_code', array_column($preview['rows'][0]['issues'], 'field'), 'an inactive Grade');
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('grade_levels')->where('code', 'UKG')->update(['status' => 'active']));
        // Inside the file: the same Student twice, a repeated roll, a key with different details.
        $preview = $this->preview($this->csv([
            $this->row(['guardian1_key' => 'K']),
            $this->row(['roll_number' => '2', 'guardian1_key' => 'K']),
            $this->row(['first_name' => 'Other', 'roll_number' => '2', 'guardian1_key' => 'K', 'guardian1_first_name' => 'Different']),
        ]));
        $this->assertSame(['ready', 'error', 'error'], array_column($preview['rows'], 'result'));
        $this->assertSame(1, $this->tableCount('students'), 'nothing was created');
    }

    #[Test]
    public function the_file_itself_is_bounded_strict_utf8_and_exactly_the_v1_columns(): void
    {
        $refused = [
            'invalid UTF-8' => "\xFF\xFE".$this->family(),
            'oversized' => $this->csv([$this->row()]).str_repeat('x', StudentGuardianImport::MAX_BYTES),
            'too many rows' => $this->csv(array_map(fn ($i) => $this->row(['roll_number' => (string) $i, 'first_name' => "S{$i}"]), range(1, StudentGuardianImport::MAX_ROWS + 1))),
            'school_id column' => $this->csv([$this->row()], [...StudentGuardianImport::columns(), 'school_id']),
            'status column' => $this->csv([$this->row()], [...StudentGuardianImport::columns(), 'status']),
            'missing column' => $this->csv([$this->row()], array_slice(StudentGuardianImport::columns(), 1)),
            'no rows' => implode(',', StudentGuardianImport::columns())."\n",
        ];
        foreach ($refused as $label => $csv) {
            $this->as($this->admin);
            $this->post('/app/students/import/preview', ['file' => $this->file($csv)])->assertSessionHasErrors('file');
            $this->assertSame(0, $this->tableCount('students'), $label);
        }
    }

    #[Test]
    public function names_in_any_script_and_formula_like_values_are_kept_as_plain_text(): void
    {
        $this->commit($this->preview($this->csv([$this->row(['first_name' => 'आशा', 'last_name' => 'राव', 'guardian1_first_name' => '=SUM(A1)'])])))->assertSessionHasNoErrors();
        $ctx = app(TenantContext::class);
        $this->assertSame('आशा', $ctx->withSchool($this->school, fn () => DB::table('students')->value('first_name')));
        $this->assertSame('=SUM(A1)', $ctx->withSchool($this->school, fn () => DB::table('guardians')->value('first_name')));
    }

    #[Test]
    public function a_possible_existing_student_or_guardian_is_a_warning_never_a_link(): void
    {
        $existing = $this->createStudent($this->school, ['first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '2016-04-12', 'student_number' => 'OLD-1']);
        $guardian = $this->createGuardian($this->school);
        $this->createGuardianContact($guardian, ContactType::Email, 'ravi.rao@example.test');

        $preview = $this->preview($this->csv([$this->row()]));
        $this->assertSame('warning', $preview['rows'][0]['result']);
        $messages = implode(' ', array_column($preview['rows'][0]['issues'], 'message'));
        $this->assertStringContainsString('OLD-1', $messages);
        $this->assertStringContainsString('existing Guardian', $messages);

        $this->assertStringContainsString('acknowledge the warnings', (string) $this->commit($preview)->viewData('page')['props']['refusal']);
        $this->assertSame(1, $this->tableCount('students'), 'warnings must be acknowledged');
        $this->commit($preview, overrides: ['acknowledge_warnings' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->tableCount('students'), 'a NEW Student, never a merge');
        $this->assertSame(2, $this->tableCount('guardians'), 'a NEW Guardian, never a link to the existing one');
        $this->assertSame(0, app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_guardian_relationships')->where('guardian_id', $guardian->id)->count()));
        $this->assertSame('OLD-1', app(TenantContext::class)->withSchool($this->school, fn () => DB::table('students')->where('id', $existing->id)->value('student_number')), 'the existing Student is untouched');
    }

    #[Test]
    public function a_tampered_expired_or_stale_preview_is_refused_and_writes_nothing(): void
    {
        $preview = $this->preview($this->family());
        $tampered = $preview;
        $tampered['payload'][0]['cells']['first_name'] = 'Mallory';
        $this->commit($tampered)->assertSessionHasErrors('file');
        $this->commit($preview, overrides: ['signature' => str_repeat('a', 64)])->assertSessionHasErrors('file');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(StudentGuardianImport::PREVIEW_TTL_MINUTES + 1));
        $this->commit($preview)->assertSessionHasErrors('file');
        CarbonImmutable::setTestNow();

        // Stale: the Section was deactivated after the preview.
        $preview = $this->preview($this->family());
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('sections')->where('code', 'E')->update(['status' => 'inactive']));
        $response = $this->commit($preview);
        $this->assertStringContainsString('can no longer be imported', (string) $response->viewData('page')['props']['refusal']);
        $this->assertSame(0, $this->tableCount('students'));
    }

    #[Test]
    public function the_preview_is_checked_before_a_code_is_consumed_and_a_code_is_required(): void
    {
        $preview = $this->preview($this->family());
        $codes = $this->tableCount('students');
        $this->commit($preview, overrides: ['mfa_code' => ''])->assertSessionHasErrors('mfa_code');
        $this->commit($preview, overrides: ['confirm' => ''])->assertSessionHasErrors('confirm');
        $this->assertSame($codes, $this->tableCount('students'));

        // A forged preview is refused before any code is read.
        $this->mfaEnrolled($this->admin);
        $this->post('/app/students/import/commit', ['payload' => $preview['payload'], 'issued_at' => $preview['issuedAt'], 'result_digest' => $preview['resultDigest'], 'signature' => str_repeat('b', 64), 'confirm' => '1'])
            ->assertSessionHasErrors('file')->assertSessionDoesntHaveErrors('mfa_code');
    }

    #[Test]
    public function a_failure_anywhere_rolls_the_whole_batch_back(): void
    {
        $preview = $this->preview($this->family());
        $real = app(AuditRecorder::class);
        $this->app->instance(AuditRecorder::class, new class(app(TenantContext::class), $real) extends AuditRecorder
        {
            public function __construct(TenantContext $context, private readonly AuditRecorder $real)
            {
                parent::__construct($context);
            }

            public function school(School $school, string $eventType, ?User $actor = null, ?Model $subject = null, array $metadata = []): SchoolAuditEvent
            {
                if ($eventType === 'student_import.committed') {
                    throw new \RuntimeException('injected failure after every row was created');
                }

                return $this->real->school($school, $eventType, $actor, $subject, $metadata);
            }
        });
        try {
            $this->commit($preview);
        } catch (\RuntimeException) {
        }
        $this->assertSame(['students' => 0, 'guardians' => 0, 'student_guardian_relationships' => 0, 'student_enrollments' => 0, 'guardian_contacts' => 0, 'student_import_batches' => 0], $this->counts());
    }

    #[Test]
    public function a_canonical_refusal_on_any_row_names_the_row_and_rolls_everything_back(): void
    {
        $preview = $this->preview($this->family());
        $this->partialMock(StudentGuardianRelationshipService::class, function ($mock): void {
            $mock->shouldReceive('link')->andThrow(new DuplicateRelationshipException);
        });
        $response = $this->commit($preview);
        $response->assertSessionHasErrors('refusal');
        $this->assertStringContainsString('Row 2 could not be imported', (string) session('errors')->first('refusal'));
        $this->assertSame(['students' => 0, 'guardians' => 0, 'student_guardian_relationships' => 0, 'student_enrollments' => 0, 'guardian_contacts' => 0, 'student_import_batches' => 0], $this->counts());
    }

    #[Test]
    public function a_retry_replays_and_the_same_file_is_never_imported_twice(): void
    {
        $preview = $this->preview($this->family());
        $this->commit($preview)->assertSessionHasNoErrors();
        $this->commit($preview)->assertSessionHasNoErrors();
        $this->assertSame([3, 1], [$this->tableCount('students'), $this->tableCount('student_import_batches')], 'the retry replays the first batch');

        $again = $this->preview($this->family());
        $this->assertFalse($again['committable']);
        $this->assertNotNull($again['alreadyImported']);
    }

    #[Test]
    public function only_a_whole_school_importer_with_the_single_paths_keys_may_import(): void
    {
        $preview = $this->preview($this->family());

        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $this->school), 'principal');
        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $refused = [
            'principal (no students.import)' => $principal,
            'teacher' => $teacher,
            'Campus-scoped importer' => $this->member(StudentGuardianImport::AUTHORITY, $this->main->id),
        ];
        foreach ($refused as $label => $user) {
            $this->as($user);
            $this->assertSame(403, $this->get('/app/students/import')->status(), "{$label}: page");
            $this->assertSame(403, $this->post('/app/students/import/preview', ['file' => $this->file($this->family())])->status(), "{$label}: preview");
            $this->assertSame(403, $this->commit($preview, $user)->status(), "{$label}: commit");
        }

        // students.import alone does not exceed the single paths -- not even a preview
        // (it reveals possible existing Students and Guardian contacts).
        $importOnly = $this->member(['students.import']);
        $this->as($importOnly);
        $this->assertSame(403, $this->get('/app/students/import')->status());
        $this->assertSame(403, $this->post('/app/students/import/preview', ['file' => $this->file($this->family())])->status());
        $this->assertSame(403, $this->commit($preview, $importOnly)->status());

        // Authority revoked after the preview: refused under the fresh re-read.
        $role = $this->createFixtureRole(StudentGuardianImport::AUTHORITY);
        $importer = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($importer, $this->school), $role);
        $mine = $this->preview($this->family(), $importer);
        LocalCatalogueFixtures::removeRoleCapability($role, 'enrollments.manage');
        $this->assertSame(403, $this->commit($mine, $importer)->status());
        $this->assertSame(0, $this->tableCount('students'));

        // Guardian, platform root, Network Manager: nothing.
        $guardianUser = $this->portalGuardian($this->school)['user'];
        $baseline = $this->tableCount('students'); // the portal fixture's own Student
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
            $this->assertNotSame(200, $this->get('/app/students/import')->status(), $label);
        }
        $this->assertSame($baseline, $this->tableCount('students'));
    }

    #[Test]
    public function another_schools_codes_never_resolve_and_the_school_comes_only_from_context(): void
    {
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->createCampus($other, ['name' => 'Other', 'code' => 'OTHERCAMPUS']);

        $preview = $this->preview($this->csv([$this->row(['campus_code' => 'OTHERCAMPUS'])]));
        $this->assertSame('error', $preview['rows'][0]['result']);

        // Another School's admin importing the same codes resolves nothing of this School.
        $theirs = $this->preview($this->family(), $otherAdmin, $other);
        $this->assertFalse($theirs['committable']);
        $this->assertSame(0, $this->tableCount('students'));
    }

    #[Test]
    public function the_preview_reads_references_in_bounded_queries(): void
    {
        $measure = function (int $n): int {
            $csv = $this->csv(array_map(fn ($i) => $this->row(['first_name' => "Student{$i}", 'roll_number' => (string) $i, 'guardian1_email' => "g{$i}@example.test"]), range(1, $n)));
            $this->as($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->post('/app/students/import/preview', ['file' => $this->file($csv)])->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $measure(3); // warm-up (session, caches)
        $this->assertSame($measure(10), $measure(60), 'no query per row');
    }

    private function section(string $code): Section
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Section::query()->where('code', $code)->firstOrFail());
    }

    private function placementService(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }
}
