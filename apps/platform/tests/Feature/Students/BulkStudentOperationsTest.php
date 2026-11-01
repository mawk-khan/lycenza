<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Bulk\BulkPlacementOperation;
use App\Domain\Students\Application\Bulk\BulkPlacementRefusedException;
use App\Domain\Students\Application\Bulk\BulkPlacementRequest;
use App\Domain\Students\Application\Bulk\BulkStudentPlacementService;
use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\StudentCommunityService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Application\StudentTransferBlocker;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Support\BulkProbeBlocker;
use Tests\TestCase;

/**
 * SIS 11.13: bulk Section change and Campus transfer -- Select → Preview →
 * fresh MFA → revalidate → atomic execute, over the existing single-Student
 * primitives (docs/sis/SIS-11-13-BULK-STUDENT-OPERATIONS.md).
 */
class BulkStudentOperationsTest extends TestCase
{
    use CreatesTenancyFixtures;
    use ProvidesSensitiveActionMfa;

    private School $school;

    private Campus $north;

    private Campus $south;

    private Campus $east;

    private AcademicYear $year;

    private AcademicYear $next;

    private GradeLevel $grade;

    private Section $a1;

    private Section $a2;

    private Section $b1;

    private Section $c1;

    private User $admin;

    /** @var list<Student> */
    private array $students = [];

    /** @var array<string, StudentEnrollment> by Student id */
    private array $placements = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->mfaEnrolled($this->admin);
        $this->north = $this->createCampus($this->school, ['name' => 'North', 'code' => 'N']);
        $this->south = $this->createCampus($this->school, ['name' => 'South', 'code' => 'S']);
        $this->east = $this->createCampus($this->school, ['name' => 'East', 'code' => 'E']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5']);
        $this->year = $this->createAcademicYear($this->school, ['name' => 'Y1', 'code' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
        $this->next = $this->createAcademicYear($this->school, ['name' => 'Y2', 'code' => 'Y2', 'status' => 'draft', 'starts_on' => now()->addDays(101)->toDateString(), 'ends_on' => now()->addDays(400)->toDateString()]);
        $this->a1 = $this->createSection($this->year, $this->north, $this->grade, ['code' => 'A1', 'name' => 'A1', 'status' => 'active']);
        $this->a2 = $this->createSection($this->year, $this->north, $this->grade, ['code' => 'A2', 'name' => 'A2', 'status' => 'active']);
        $this->b1 = $this->createSection($this->year, $this->south, $this->grade, ['code' => 'B1', 'name' => 'B1', 'status' => 'active']);
        $this->c1 = $this->createSection($this->year, $this->east, $this->grade, ['code' => 'C1', 'name' => 'C1', 'status' => 'active']);
        foreach (['Asha', 'Bilal', 'Chitra', 'Dev'] as $i => $name) {
            $this->enrol($name, $this->a1, (string) ($i + 1));
        }
    }

    private function enrol(string $name, Section $section, string $roll): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Example', 'status' => 'active']);
        $this->students[] = $student;
        $this->placements[$student->id] = $this->createStudentEnrollment($student, $section, ['status' => 'active', 'roll_number' => $roll, 'starts_on' => now()->subDays(100)->toDateString()]);

        return $student;
    }

    private function inSchool(callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $callback);
    }

    private function web(User $user): static
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    private function role(string $key, ?string $campusId = null): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), $key, $campusId);

        return $user;
    }

    /** @param  list<Student>|null  $students  @param  array<string, string>  $rolls */
    private function payload(BulkPlacementOperation $operation, Section $target, ?array $students = null, array $rolls = [], ?string $date = null, ?Section $source = null): array
    {
        return [
            'operation' => $operation->value,
            'source_section_id' => ($source ?? $this->a1)->id,
            'target_section_id' => $target->id,
            'effective_date' => $date ?? SchoolTimezone::today($this->school),
            'rows' => array_map(fn (Student $s) => [
                'student_id' => $s->id,
                'enrollment_id' => $this->placements[$s->id]->id,
                'roll_number' => $rolls[$s->id] ?? '1'.$this->placements[$s->id]->roll_number,
            ], $students ?? $this->students),
        ];
    }

    /** @return array<string, mixed> the review page's preview */
    private function preview(array $payload, ?User $as = null): array
    {
        $this->web($as ?? $this->admin)->post('/app/students/bulk-operations/preview', $payload)->assertRedirect('/app/students/bulk-operations/review');

        return $this->get('/app/students/bulk-operations/review')->assertOk()->viewData('page')['props']['preview'];
    }

    /** @param  array<string, mixed>  $preview */
    private function execute(array $payload, array $preview, ?User $as = null, array $overrides = []): TestResponse
    {
        $as ??= $this->admin;

        return $this->post('/app/students/bulk-operations/execute', array_merge($payload, [
            'issued_at' => $preview['issuedAt'], 'signature' => $preview['signature'], 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($as),
        ], $overrides));
    }

    private function sectionOf(Student $student): ?string
    {
        return $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $this->year->id)->where('status', 'active')->value('section_id'));
    }

    private function counts(): array
    {
        return $this->inSchool(fn () => [
            'enrollments' => StudentEnrollment::query()->count(),
            'transferred' => StudentEnrollment::query()->where('status', 'transferred')->count(),
            'audit' => SchoolAuditEvent::query()->where('school_id', $this->school->id)->count(),
        ]);
    }

    // --- Bulk Section change --------------------------------------------------

    #[Test]
    public function a_bulk_section_change_moves_every_student_with_ordinary_history_and_one_batch_audit(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2);
        $preview = $this->preview($payload);
        $this->assertTrue($preview['executable']);
        $this->assertSame(4, $preview['summary']['ready']);
        $this->assertSame('Asha Example', collect($preview['rows'])->firstWhere('studentId', $this->students[0]->id)['name']);

        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/result');
        $result = $this->get('/app/students/bulk-operations/result')->assertOk()->viewData('page')['props']['result'];
        $this->assertCount(4, $result['moved']);

        $today = SchoolTimezone::today($this->school);
        foreach ($this->students as $student) {
            $this->assertSame($this->a2->id, $this->sectionOf($student));
            $old = $this->inSchool(fn () => StudentEnrollment::query()->findOrFail($this->placements[$student->id]->id));
            $this->assertSame('transferred', $old->status);
            $this->assertSame(now()->parse($today)->subDay()->toDateString(), $old->ends_on->toDateString());
            $this->assertSame(1, $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->count()));
        }

        $batch = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', BulkStudentPlacementService::AUDIT_EVENT)->sole());
        $this->assertSame(4, $batch->metadata['students']);
        $this->assertSame('section_change', $batch->metadata['operation']);
        $this->assertSame($this->a2->id, $batch->metadata['toSectionId']);
        $this->assertStringNotContainsString('Asha', json_encode($batch->metadata));
        $perStudent = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student_enrollment.transferred')->get());
        $this->assertCount(4, $perStudent);
        $this->assertSame([$batch->metadata['batchId']], $perStudent->pluck('metadata.batchId')->unique()->values()->all());

        // The ordinary movement register shows each move once.
        $rows = collect(iterator_to_array(app(StudentMovementRegister::class)->rows($this->school, ['academic_year_id' => $this->year->id], false), false));
        foreach ($this->students as $student) {
            $mine = $rows->filter(fn (array $r) => $r['student']['id'] === $student->id && $r['type'] === 'section_change');
            $this->assertCount(1, $mine, 'one section-change row per Student');
            $this->assertSame('A2', $mine->first()['to']['section']);
        }
    }

    #[Test]
    public function the_preview_and_its_review_commit_nothing(): void
    {
        $before = $this->counts();
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2);
        $this->preview($payload);
        $this->get('/app/students/bulk-operations/review')->assertOk();
        $this->get('/app/students/bulk-operations/review')->assertOk();

        $this->assertSame($before, $this->counts(), 'no placement, transfer or audit row survives a preview');
        $this->assertSame($this->a1->id, $this->sectionOf($this->students[0]));
    }

    #[Test]
    public function the_preview_classifies_blocked_already_in_target_and_stale_students(): void
    {
        $occupant = $this->enrol('Esha', $this->a2, '7');
        // Bilal's new roll number is taken in the target; Chitra and Dev share one.
        $rolls = [$this->students[1]->id => '7', $this->students[2]->id => '30', $this->students[3]->id => '30'];
        $preview = $this->preview($this->payload(BulkPlacementOperation::SectionChange, $this->a2, [...array_slice($this->students, 0, 4), $occupant], $rolls));
        $rows = collect($preview['rows'])->keyBy('studentId');

        $this->assertSame('ready', $rows[$this->students[0]->id]['state']);
        $this->assertSame('blocked', $rows[$this->students[1]->id]['state']);
        $this->assertStringContainsString('Roll number "7" is already in use', $rows[$this->students[1]->id]['message']);
        $this->assertSame('ready', $rows[$this->students[2]->id]['state']);
        $this->assertSame('blocked', $rows[$this->students[3]->id]['state'], 'two selected Students cannot share a roll number');
        $this->assertSame('already_in_target', $rows[$occupant->id]['state']);
        $this->assertFalse($preview['executable']);
        $this->assertNull($preview['signature']);

        // Stale: Asha moves individually after the selection.
        app(StudentEnrollmentService::class)->transferPlacement($this->placements[$this->students[0]->id], $this->b1, '99', SchoolTimezone::today($this->school), $this->admin);
        $rows = collect($this->get('/app/students/bulk-operations/review')->viewData('page')['props']['preview']['rows'])->keyBy('studentId');
        $this->assertSame('stale', $rows[$this->students[0]->id]['state']);
        $this->assertStringContainsString('now in Grade 5 · Section B1 · South', $rows[$this->students[0]->id]['message']);
    }

    #[Test]
    public function an_unready_preview_cannot_be_executed(): void
    {
        $occupant = $this->enrol('Esha', $this->a2, '7');
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, [$this->students[0], $occupant]);
        $preview = $this->preview($payload);
        $this->assertFalse($preview['executable']);
        $before = $this->counts();

        $this->execute($payload, ['issuedAt' => now()->toIso8601String(), 'signature' => str_repeat('a', 64)])->assertRedirect('/app/students/bulk-operations/review');
        $this->assertSame($before, $this->counts());
        $this->assertStringContainsString('does not match its preview', session('bulkPlacementRefusal'));
    }

    #[Test]
    public function capacity_over_the_advisory_limit_is_a_warning_and_the_move_still_runs(): void
    {
        $this->inSchool(fn () => Section::query()->whereKey($this->a2->id)->update(['capacity' => 3]));
        $selection = $this->students;
        $this->enrol('Esha', $this->a2, '50');
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, $selection);
        $preview = $this->preview($payload);

        $this->assertSame('1 / 3 · 2 seats available', $preview['capacity']['now']);
        $this->assertSame('5 / 3 · Over advisory capacity by 2', $preview['capacity']['after']);
        $this->assertStringContainsString('over its advisory capacity by 2', $preview['capacity']['warning']);
        $this->assertTrue($preview['executable']);
        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/result');
        $this->assertSame($this->a2->id, $this->sectionOf($this->students[3]));
    }

    #[Test]
    public function a_student_changed_after_the_preview_refuses_the_whole_batch(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2);
        $preview = $this->preview($payload);
        $this->assertTrue($preview['executable']);
        $before = $this->counts();

        // Dev (the last in id order or not) moves individually in between.
        $dev = $this->students[3];
        app(StudentEnrollmentService::class)->transferPlacement($this->placements[$dev->id], $this->b1, '77', SchoolTimezone::today($this->school), $this->admin);

        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/review');
        $this->assertStringStartsWith('No Students were moved. Dev Example', session('bulkPlacementRefusal'));
        foreach (array_slice($this->students, 0, 3) as $student) {
            $this->assertSame($this->a1->id, $this->sectionOf($student), 'nobody else moved');
        }
        $after = $this->counts();
        $this->assertSame($before['transferred'] + 1, $after['transferred'], 'only the individual transfer');
        $this->assertSame(0, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', BulkStudentPlacementService::AUDIT_EVENT)->count()));
    }

    #[Test]
    public function a_repeated_confirmation_never_moves_anyone_twice(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, array_slice($this->students, 0, 2));
        $preview = $this->preview($payload);
        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/result');
        $after = $this->counts();

        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/review');
        $this->assertStringContainsString('already in the target class', session('bulkPlacementRefusal'));
        $this->assertSame($after, $this->counts());
        $this->assertSame(1, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', BulkStudentPlacementService::AUDIT_EVENT)->count()));
    }

    #[Test]
    public function execution_needs_confirmation_and_a_fresh_authentication_code(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2);
        $preview = $this->preview($payload);
        $before = $this->counts();

        $this->execute($payload, $preview, overrides: ['mfa_code' => ''])->assertSessionHasErrors('mfa_code');
        $this->execute($payload, $preview, overrides: ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->execute($payload, $preview, overrides: ['confirm' => '0'])->assertSessionHasErrors('confirm');
        $this->assertSame($before, $this->counts());

        // No enrolled factor: refused with the enrolment hint.
        $plain = $this->role('school_admin');
        $preview = $this->preview($payload, $plain);
        $this->post('/app/students/bulk-operations/execute', $payload + ['issued_at' => $preview['issuedAt'], 'signature' => $preview['signature'], 'confirm' => '1', 'mfa_code' => '123456'])
            ->assertSessionHasErrors(['mfa_code' => 'This action requires multi-factor authentication. Enroll a factor under Account security first.']);
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function the_preview_signature_binds_every_row_and_expires(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, array_slice($this->students, 0, 2));
        $preview = $this->preview($payload);
        $before = $this->counts();

        // A third Student added to a signed two-Student batch.
        $forged = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, array_slice($this->students, 0, 3));
        $this->execute($forged, $preview)->assertRedirect('/app/students/bulk-operations/review');
        $this->assertStringContainsString('does not match its preview', session('bulkPlacementRefusal'));

        $this->travel(BulkStudentPlacementService::PREVIEW_TTL_MINUTES + 1)->minutes();
        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/review');
        $this->assertStringContainsString('expired', session('bulkPlacementRefusal'));
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function malformed_duplicate_empty_and_oversized_selections_are_validation_errors(): void
    {
        $this->web($this->admin);
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, array_slice($this->students, 0, 2));

        $dup = $payload;
        $dup['rows'][1]['student_id'] = $dup['rows'][0]['student_id'];
        $this->post('/app/students/bulk-operations/preview', $dup)->assertSessionHasErrors('rows.1.student_id');
        $this->post('/app/students/bulk-operations/preview', ['rows' => []] + $payload)->assertSessionHasErrors('rows');
        $this->post('/app/students/bulk-operations/preview', ['target_section_id' => 'not-a-uuid'] + $payload)->assertSessionHasErrors('target_section_id');
        $bad = $payload;
        $bad['rows'][0]['enrollment_id'] = '1 OR 1=1';
        $this->post('/app/students/bulk-operations/preview', $bad)->assertSessionHasErrors('rows.0.enrollment_id');
        $many = $payload;
        $many['rows'] = array_map(fn () => ['student_id' => (string) str()->uuid(), 'enrollment_id' => (string) str()->uuid(), 'roll_number' => '1'], range(1, BulkStudentPlacementService::MAX_STUDENTS + 1));
        $this->post('/app/students/bulk-operations/preview', $many)->assertSessionHasErrors('rows');
        $this->assertNull(session('bulkPlacementRequest'));
    }

    #[Test]
    public function another_year_or_grade_is_refused_and_points_to_rollover(): void
    {
        $nextSection = $this->createSection($this->next, $this->north, $this->grade, ['code' => 'N1', 'name' => 'N1', 'status' => 'active']);
        $this->web($this->admin)->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::SectionChange, $nextSection))
            ->assertRedirect()->assertSessionHasErrors(['refusal' => 'A bulk move stays within one Academic Year. To move Students into another Academic Year, use Academic-Year Rollover.']);
        $this->assertTrue(session('bulkPlacementRollover'));

        $grade6 = $this->createSection($this->year, $this->north, $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6']), ['code' => 'G6A', 'name' => 'G6A', 'status' => 'active']);
        $this->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::SectionChange, $grade6))
            ->assertSessionHasErrors(['refusal' => 'A bulk move keeps each Student\'s Grade. Moving to another Grade is promotion, done through Academic-Year Rollover.']);

        // The operation decides the Campus rule both ways.
        $this->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::SectionChange, $this->b1))->assertSessionHasErrors('refusal');
        $this->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::CampusTransfer, $this->a2))->assertSessionHasErrors('refusal');
    }

    #[Test]
    public function an_inactive_student_is_refused_exactly_as_a_single_move_refuses_them(): void
    {
        $dev = $this->students[3];
        app(StudentService::class)->changeStatus($this->inSchool(fn () => Student::query()->findOrFail($dev->id)), 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));
        $preview = $this->preview($this->payload(BulkPlacementOperation::SectionChange, $this->a2));
        $row = collect($preview['rows'])->firstWhere('studentId', $dev->id);

        $this->assertSame('blocked', $row['state']);
        $this->assertSame('Only an active Student can be placed. Reactivate the Student first.', $row['message']);
    }

    // --- Bulk Campus transfer -------------------------------------------------

    #[Test]
    public function a_whole_school_campus_transfer_reports_the_same_blockers_and_preserves_communities(): void
    {
        [$asha, $bilal] = $this->students;
        $route = $this->createTransportRoute($this->school, ['campus_id' => $this->north->id, 'status' => 'active']);
        $this->createTransportStudentAssignment($bilal, $route, ['status' => 'active']);

        $house = app(HouseAdministrationService::class)->create($this->school, $this->admin, ['name' => 'Red', 'code' => 'RED']);
        app(StudentHouseService::class)->assign($this->school, $this->admin, $asha->id, $house->id, null, app(StudentHouseService::class)->history($this->school, $this->admin, $asha->id)['version']);
        $club = app(CommunityAdministrationService::class)->create($this->school, $this->admin, StudentGroupType::CLUB, ['name' => 'Chess', 'code' => 'CHESS']);
        $activity = app(CommunityAdministrationService::class)->create($this->school, $this->admin, StudentGroupType::ACTIVITY, ['name' => 'Choir', 'code' => 'CHOIR']);
        app(StudentCommunityService::class)->join($this->school, $this->admin, $asha->id, $club->id);
        app(StudentCommunityService::class)->join($this->school, $this->admin, $asha->id, $activity->id);
        $memberships = $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $asha->id)->orderBy('id')->get(['id', 'version', 'ends_on'])->toArray());

        $preview = $this->preview($this->payload(BulkPlacementOperation::CampusTransfer, $this->b1, [$asha, $bilal]));
        $rows = collect($preview['rows'])->keyBy('studentId');
        $this->assertSame('ready', $rows[$asha->id]['state']);
        $this->assertSame('blocked', $rows[$bilal->id]['state']);
        $this->assertStringStartsWith('This Student can\'t change Campus yet. First end or move:', $rows[$bilal->id]['message']);

        // Remove the blocked Student, preview again and run.
        $payload = $this->payload(BulkPlacementOperation::CampusTransfer, $this->b1, [$asha]);
        $preview = $this->preview($payload);
        $this->execute($payload, $preview)->assertRedirect('/app/students/bulk-operations/result');

        $this->assertSame($this->b1->id, $this->sectionOf($asha));
        $this->assertSame($this->a1->id, $this->sectionOf($bilal));
        $this->assertSame($memberships, $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $asha->id)->orderBy('id')->get(['id', 'version', 'ends_on'])->toArray()), 'House, Club and Activity memberships are untouched');
        $this->assertSame(1, $this->inSchool(fn () => DB::table('transport_student_assignments')->where('student_id', $bilal->id)->where('status', 'active')->count()), 'Transport is never mutated');
    }

    #[Test]
    public function bulk_asks_the_very_same_registered_blockers_as_a_single_transfer(): void
    {
        // A blocker registered only for this test: if bulk had its own list, it would not see it.
        $this->app->bind(BulkProbeBlocker::class, fn () => new BulkProbeBlocker($this->students[0]->id));
        $this->app->tag([BulkProbeBlocker::class], StudentTransferBlocker::TAG);

        $preview = $this->preview($this->payload(BulkPlacementOperation::CampusTransfer, $this->b1, array_slice($this->students, 0, 2)));
        $rows = collect($preview['rows'])->keyBy('studentId');
        $this->assertSame('blocked', $rows[$this->students[0]->id]['state']);
        $this->assertStringContainsString('the bulk probe record', $rows[$this->students[0]->id]['message']);
        $this->assertSame('ready', $rows[$this->students[1]->id]['state']);

        // ...and a Section change on the same Campus asks no blocker, exactly like one Student's.
        $preview = $this->preview($this->payload(BulkPlacementOperation::SectionChange, $this->a2, array_slice($this->students, 0, 1)));
        $this->assertTrue($preview['executable']);
    }

    #[Test]
    public function a_campus_scoped_principal_transfers_only_between_their_own_campuses_today(): void
    {
        $principal = $this->role('principal', $this->north->id);
        $this->assignSchoolRole($principal->schoolMemberships()->firstOrFail(), 'principal', $this->south->id);
        $this->mfaEnrolled($principal);

        $index = $this->web($principal)->get('/app/students/bulk-operations?section_id='.$this->a1->id)->assertOk()->viewData('page')['props'];
        $this->assertSame(['campus_transfer'], array_column($index['operations'], 'value'));
        $this->assertSame([$this->b1->id], array_column($index['targets'], 'id'), 'only in-scope Campuses');
        $this->assertCount(4, $index['students']);

        // A Section change is whole-School, in bulk as for one Student.
        $this->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::SectionChange, $this->a2))->assertForbidden();
        // Another date than today.
        $this->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::CampusTransfer, $this->b1, date: CarbonImmutable::parse(SchoolTimezone::today($this->school))->addDay()->toDateString()))
            ->assertSessionHasErrors(['refusal' => 'A Campus transfer takes effect today.']);
        // A Campus outside the scope looks like any unknown class.
        $this->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::CampusTransfer, $this->c1))
            ->assertSessionHasErrors(['refusal' => 'Choose the source and target classes again.']);

        $payload = $this->payload(BulkPlacementOperation::CampusTransfer, $this->b1, array_slice($this->students, 0, 2));
        $preview = $this->preview($payload, $principal);
        $this->assertSame('campus', $preview['scope']);
        $this->execute($payload, $preview, $principal)->assertRedirect('/app/students/bulk-operations/result');
        $this->assertSame($this->b1->id, $this->sectionOf($this->students[0]));
        $this->assertSame('campus', $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', BulkStudentPlacementService::AUDIT_EVENT)->sole())->metadata['scope']);
    }

    #[Test]
    public function a_student_outside_the_scope_reveals_nothing_and_cannot_be_moved(): void
    {
        $principal = $this->role('principal', $this->north->id);
        $this->assignSchoolRole($principal->schoolMemberships()->firstOrFail(), 'principal', $this->south->id);
        $outsider = $this->enrol('Farah', $this->c1, '1');

        $payload = $this->payload(BulkPlacementOperation::CampusTransfer, $this->b1, [$this->students[0], $outsider]);
        $preview = $this->preview($payload, $principal);
        $row = collect($preview['rows'])->firstWhere('studentId', $outsider->id);
        $this->assertSame('blocked', $row['state']);
        $this->assertNull($row['name']);
        $this->assertNull($row['studentNumber']);
        $this->assertNull($row['from']);
        $this->assertFalse($preview['executable']);
        $this->assertSame($this->c1->id, $this->sectionOf($outsider));
    }

    #[Test]
    public function losing_authority_after_the_preview_refuses_the_execution(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2);
        $preview = $this->preview($payload);
        $before = $this->counts();
        // The role is revoked after the preview; the 60 s grant cache still opens the page,
        // but execution re-reads authority fresh inside its transaction.
        $this->inSchool(fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $this->admin->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));

        $this->execute($payload, $preview)->assertForbidden();
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function teachers_admissions_officers_and_other_roles_get_no_bulk_movement(): void
    {
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2);
        foreach (['teacher', 'admissions_officer', 'front_office', 'librarian'] as $key) {
            $user = $this->role($key);
            $this->web($user)->get('/app/students/bulk-operations')->assertForbidden();
            $this->post('/app/students/bulk-operations/preview', $payload)->assertForbidden();
            $this->post('/app/students/bulk-operations/execute', $payload + ['issued_at' => now()->toIso8601String(), 'signature' => str_repeat('a', 64), 'confirm' => '1'])->assertForbidden();
            $this->get('/app/students/bulk-operations/review')->assertForbidden();
        }
        $this->assertSame($this->a1->id, $this->sectionOf($this->students[0]));
    }

    #[Test]
    public function another_school_can_neither_be_selected_nor_influence_a_batch(): void
    {
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $otherCampus = $this->createCampus($other, ['name' => 'North', 'code' => 'N']);
        $otherYear = $this->createAcademicYear($other, ['name' => 'Y1', 'code' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
        $otherGrade = $this->createGradeLevel($other, ['name' => 'Grade 5', 'code' => 'G5']);
        $otherA1 = $this->createSection($otherYear, $otherCampus, $otherGrade, ['code' => 'A1', 'name' => 'A1', 'status' => 'active']);
        $otherA2 = $this->createSection($otherYear, $otherCampus, $otherGrade, ['code' => 'A2', 'name' => 'A2', 'status' => 'active']);
        $foreign = $this->createStudent($other, ['first_name' => 'Zara', 'status' => 'active']);
        $foreignPlacement = $this->createStudentEnrollment($foreign, $otherA1, ['status' => 'active', 'roll_number' => '1', 'starts_on' => now()->subDays(100)->toDateString()]);

        // A foreign target or source class: the generic refusal.
        $this->web($this->admin)->post('/app/students/bulk-operations/preview', $this->payload(BulkPlacementOperation::SectionChange, $otherA2))->assertSessionHasErrors(['refusal' => 'Choose the source and target classes again.']);
        $this->get('/app/students/bulk-operations?section_id='.$otherA1->id)->assertOk()->assertInertia(fn ($page) => $page->where('source', null)->where('students', []));

        // A foreign Student row: unavailable, no name, never executable.
        $payload = $this->payload(BulkPlacementOperation::SectionChange, $this->a2, [$this->students[0]]);
        $payload['rows'][] = ['student_id' => $foreign->id, 'enrollment_id' => $foreignPlacement->id, 'roll_number' => '9'];
        $preview = $this->preview($payload);
        $row = collect($preview['rows'])->firstWhere('studentId', $foreign->id);
        $this->assertSame('blocked', $row['state']);
        $this->assertNull($row['name']);
        $this->assertFalse($preview['executable']);
        $this->assertSame($otherA1->id, app(TenantContext::class)->withSchool($other, fn () => StudentEnrollment::query()->whereKey($foreignPlacement->id)->value('section_id')));
        $this->assertNotNull($otherAdmin);
    }

    #[Test]
    public function the_selection_lists_only_the_source_class_and_searches_by_name_or_number(): void
    {
        $this->enrol('Esha', $this->a2, '1');
        $props = $this->web($this->admin)->get('/app/students/bulk-operations?section_id='.$this->a1->id)->assertOk()->viewData('page')['props'];
        $this->assertCount(4, $props['students']);
        $this->assertSame(['section_change', 'campus_transfer'], array_column($props['operations'], 'value'));
        $this->assertSame([$this->a2->id], array_column($props['targets'], 'id'), 'same year, Campus and Grade, not the source');

        $props = $this->get('/app/students/bulk-operations?section_id='.$this->a1->id.'&q=bil')->viewData('page')['props'];
        $this->assertSame(['Bilal Example'], array_column($props['students'], 'name'));
        $props = $this->get('/app/students/bulk-operations?section_id='.$this->a1->id.'&q='.urlencode('%'))->viewData('page')['props'];
        $this->assertSame([], $props['students'], 'a wildcard is literal');

        $transfer = $this->get('/app/students/bulk-operations?operation=campus_transfer&section_id='.$this->a1->id)->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([$this->b1->id, $this->c1->id], array_column($transfer['targets'], 'id'));
    }

    #[Test]
    public function the_service_refuses_a_batch_over_the_maximum_or_with_duplicates(): void
    {
        $service = app(BulkStudentPlacementService::class);
        $rows = fn (int $n) => array_map(fn () => ['studentId' => (string) str()->uuid(), 'enrollmentId' => (string) str()->uuid(), 'rollNumber' => '1'], range(1, $n));
        $request = fn (array $r) => new BulkPlacementRequest(BulkPlacementOperation::SectionChange, $this->a1->id, $this->a2->id, SchoolTimezone::today($this->school), $r);

        foreach ([[], $rows(BulkStudentPlacementService::MAX_STUDENTS + 1)] as $r) {
            try {
                $service->preview($this->school, $this->admin, CampusScope::wholeSchool(), $request($r));
                $this->fail('refused');
            } catch (BulkPlacementRefusedException) {
                $this->addToAssertionCount(1);
            }
        }
        $dup = $rows(1);
        $this->expectException(BulkPlacementRefusedException::class);
        $service->preview($this->school, $this->admin, CampusScope::wholeSchool(), $request([$dup[0], $dup[0]]));
    }
}
