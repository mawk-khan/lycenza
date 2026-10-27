<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\PlacementDependentsBlockException;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * SIS 4.2 (placement safety):
 * - a whole-School transfer that changes the Student's Campus asks the same
 *   StudentTransferBlockers as the Campus path (Hostel residency, Transport
 *   rider assignment, elective on the old Campus) and names them;
 * - a placement that has not started can be cancelled before it starts (the
 *   empty interval, history kept), unless records already depend on it;
 * - an active placement's roll number can be corrected (unique per Section
 *   and year, any status);
 * - ENR-01: pickers list the active year first, and Transfer offers only the
 *   placement's own year and Grade.
 */
class PlacementSafetyTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $year;

    private AcademicYear $next;

    private GradeLevel $grade;

    private Section $sectionA;

    private Section $sectionA2;

    private Section $sectionB;

    private Section $nextA;

    private Student $student;

    private StudentEnrollment $placement;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'sequence' => 5]);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => 'Current', 'status' => 'active', 'starts_on' => $today->subDays(200)->toDateString(), 'ends_on' => $today->addDays(100)->toDateString()]);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'name' => 'Next', 'status' => 'draft', 'starts_on' => $today->addDays(101)->toDateString(), 'ends_on' => $today->addDays(400)->toDateString()]);
        $this->sectionA = $this->createSection($this->year, $this->a, $this->grade, ['code' => 'A', 'name' => 'A', 'status' => 'active']);
        $this->sectionA2 = $this->createSection($this->year, $this->a, $this->grade, ['code' => 'A2', 'name' => 'A2', 'status' => 'active']);
        $this->sectionB = $this->createSection($this->year, $this->b, $this->grade, ['code' => 'B', 'name' => 'B', 'status' => 'active']);
        $this->nextA = $this->createSection($this->next, $this->a, $this->grade, ['code' => 'NA', 'name' => 'NA', 'status' => 'active']);
        $this->student = $this->createStudent($this->school, ['first_name' => 'Asha', 'student_number' => 'S-1']);
        $this->placement = $this->service()->enroll($this->student, $this->sectionA, '7', $today->subDays(100)->toDateString());
        [$this->admin] = $this->createSchoolAdmin('school_admin');
        $this->assignSchoolRole($this->createMembership($this->admin, $this->school), 'school_admin');
    }

    private function service(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function fresh(StudentEnrollment $e): StudentEnrollment
    {
        return $this->inSchool(fn () => StudentEnrollment::query()->findOrFail($e->id));
    }

    private function today(): string
    {
        return SchoolTimezone::today($this->school);
    }

    private function proof(): FreshMfaProof
    {
        return new FreshMfaProof($this->admin->id, CarbonImmutable::now());
    }

    private function residency(): void
    {
        $bed = $this->createHostelBed($this->createHostelRoom($this->createHostel($this->school, $this->a, ['status' => 'active']), ['status' => 'active']), ['status' => 'active']);
        $this->createHostelResidencyAssignment($this->student, $bed, ['status' => 'active']);
    }

    private function rider(): void
    {
        $this->createTransportStudentAssignment($this->student, $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'status' => 'active']), ['status' => 'active']);
    }

    private function transfer(Section $to, string $roll = '9'): StudentEnrollment
    {
        return $this->service()->transferPlacement($this->fresh($this->placement), $to, $roll, $this->today(), $this->admin);
    }

    private function blocked(string $code, callable $operation): PlacementDependentsBlockException
    {
        try {
            $operation();
            $this->fail("expected {$code}");
        } catch (PlacementDependentsBlockException $e) {
            $this->assertSame([$code, 422], [$e->errorCode(), $e->getStatusCode()]);

            return $e;
        }
    }

    /** @return list<array{0: string, 1: string}> [campus, status] of the Student's placements */
    private function placements(): array
    {
        return $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $this->student->id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($e) => [$e->campus_id, $e->status])->all());
    }

    // --- Transfer blockers on the whole-School path ------------------------------

    #[Test]
    public function a_whole_school_campus_change_is_refused_while_a_hostel_residency_or_rider_remains_and_names_both(): void
    {
        $this->residency();
        $this->rider();

        $e = $this->blocked('STUDENT_TRANSFER_BLOCKED', fn () => $this->transfer($this->sectionB));

        $this->assertSame('This Student can\'t change Campus yet. First end or move: the active Hostel residency on the current Campus (Hostel → Residency); the active Transport rider assignment on the current Campus (Transport → Student assignments).', $e->getMessage());
        $this->assertSame([[$this->a->id, 'active']], $this->placements(), 'nothing moved');
        $this->assertSame(['active', 'active'], $this->inSchool(fn () => [
            DB::table('hostel_residency_assignments')->where('student_id', $this->student->id)->value('status'),
            DB::table('transport_student_assignments')->where('student_id', $this->student->id)->value('status'),
        ]), 'never ended by the transfer');
    }

    #[Test]
    public function an_elective_on_the_old_campus_blocks_too_and_resolving_every_blocker_lets_the_transfer_through(): void
    {
        $offering = $this->createSubjectOffering($this->year, $this->a, $this->grade, $this->createSubject($this->school), ['is_required' => false, 'status' => 'active']);
        $this->createStudentSubjectEnrollment($this->student, $offering, ['student_enrollment_id' => $this->placement->id, 'status' => 'active', 'starts_on' => $this->today()]);
        $this->residency();

        $e = $this->blocked('STUDENT_TRANSFER_BLOCKED', fn () => $this->transfer($this->sectionB));
        $this->assertStringContainsString('the active elective(s) on the current Campus', $e->getMessage());

        $this->inSchool(function (): void {
            DB::table('student_subject_enrollments')->where('student_id', $this->student->id)->update(['status' => 'withdrawn', 'ends_on' => $this->today()]);
            DB::table('hostel_residency_assignments')->where('student_id', $this->student->id)->update(['status' => 'ended', 'ends_on' => DB::raw('starts_on')]);
        });
        $this->assertSame($this->b->id, $this->transfer($this->sectionB)->campus_id);
    }

    #[Test]
    public function a_same_campus_section_change_is_never_blocked(): void
    {
        $this->residency();
        $this->rider();

        $this->assertSame($this->sectionA2->id, $this->transfer($this->sectionA2)->section_id);
    }

    #[Test]
    public function the_browser_and_the_api_refuse_the_same_way(): void
    {
        $this->residency();
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $this->from("/app/enrollments/{$this->placement->id}/transfer")
            ->post("/app/enrollments/{$this->placement->id}/transfer", ['target_section_id' => $this->sectionB->id, 'roll_number' => '9', 'effective_date' => $this->today()])
            ->assertSessionHasErrors(['refusal' => 'This Student can\'t change Campus yet. First end or move: the active Hostel residency on the current Campus (Hostel → Residency).']);

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->admin->createToken('t')->plainTextToken)->withHeader('Idempotency-Key', 'sis42-'.Str::uuid())
            ->postJson("/api/v1/schools/{$this->school->id}/enrollments/{$this->placement->id}/transfer", ['target_section_id' => $this->sectionB->id, 'roll_number' => '9', 'effective_date' => $this->today()])
            ->assertUnprocessable()->assertJsonPath('error.code', 'STUDENT_TRANSFER_BLOCKED');
        $this->assertSame([[$this->a->id, 'active']], $this->placements());
    }

    // --- Cancel before start -------------------------------------------------------

    private function future(string $roll = '1'): StudentEnrollment
    {
        return $this->service()->enroll($this->student, $this->nextA, $roll, $this->next->starts_on->toDateString(), $this->admin);
    }

    #[Test]
    public function a_placement_that_has_not_started_is_cancelled_as_never_effective_and_kept_in_history(): void
    {
        $future = $this->future();

        $cancelled = $this->service()->cancelBeforeStart($this->fresh($future), 'entered_in_error', $this->admin, $this->proof());

        $start = $this->next->starts_on->toDateString();
        $this->assertSame(['cancelled', CarbonImmutable::parse($start)->subDay()->toDateString(), $start], [$cancelled->status, $cancelled->ends_on->toDateString(), $cancelled->starts_on->toDateString()]);
        $this->assertSame(2, $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $this->student->id)->count()), 'the row is kept');
        $this->assertSame([], $this->inSchool(fn () => app(StudentEnrollmentRosterReadService::class)->membersAsOf($this->school->id, $this->next->id, $this->a->id, $this->grade->id, $this->nextA->id, $start)->all()), 'never on a roster');
        $event = $this->inSchool(fn () => StudentLifecycleEvent::query()->where('student_enrollment_id', $future->id)->sole());
        $this->assertSame(['enrollment_cancelled', 'entered_in_error', $this->admin->id], [$event->action, $event->reason_code, $event->acted_by_user_id]);
        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student_enrollment.cancelled')->sole());
        $this->assertTrue($audit->metadata['beforeStart']);
        $this->assertNotNull($this->inSchool(fn () => DB::table('student_enrollments')->where('id', $future->id)->value('ended_recorded_at')), 'the database records when it really ended');
    }

    #[Test]
    public function a_started_placement_is_never_cancelled_as_never_effective_and_ordinary_cancel_points_to_the_right_action(): void
    {
        $this->assertThrows(fn () => $this->service()->cancelBeforeStart($this->fresh($this->placement), 'entered_in_error', $this->admin, $this->proof()), InvalidEnrollmentDateRangeException::class, 'This placement has already started. Cancel it with an end date instead.');

        $future = $this->future();
        $this->assertThrows(fn () => $this->service()->cancel($this->fresh($future), $this->today(), 'entered_in_error', $this->admin, $this->proof()), InvalidEnrollmentDateRangeException::class, 'has not started yet. Use "Cancel before it starts" instead.');
        $this->assertSame('active', $this->fresh($future)->status);

        // An ordinary cancellation of the started placement is unchanged.
        $this->assertSame('cancelled', $this->service()->cancel($this->fresh($this->placement), $this->today(), 'duplicate_placement', $this->admin, $this->proof())->status);
    }

    #[Test]
    public function an_elective_anchored_to_the_future_placement_blocks_its_cancellation(): void
    {
        $future = $this->future();
        $offering = $this->createSubjectOffering($this->next, $this->a, $this->grade, $this->createSubject($this->school), ['is_required' => false, 'status' => 'active']);
        $this->createStudentSubjectEnrollment($this->student, $offering, ['student_enrollment_id' => $future->id, 'status' => 'active', 'starts_on' => $this->next->starts_on->toDateString()]);

        $e = $this->blocked('PLACEMENT_CANCELLATION_BLOCKED', fn () => $this->service()->cancelBeforeStart($this->fresh($future), 'did_not_join', $this->admin, $this->proof()));

        $this->assertSame('This placement can\'t be cancelled yet because other records depend on it. First resolve: the active elective(s) on this placement (withdraw or cancel them).', $e->getMessage());
        $this->assertSame('active', $this->fresh($future)->status);
        $this->assertSame(0, $this->inSchool(fn () => StudentLifecycleEvent::query()->count()), 'nothing recorded');
    }

    #[Test]
    public function the_student_page_offers_it_and_the_route_needs_mfa_and_management(): void
    {
        $future = $this->future();
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $this->get("/app/students/{$this->student->id}")->assertInertia(fn ($p) => $p
            ->where('enrollmentHistory', fn ($rows) => collect($rows)->firstWhere('id', $future->id)['startsInFuture'] === true));
        $this->post("/app/enrollments/{$future->id}/cancel-before-start", ['reason_code' => 'did_not_join', 'confirm' => '1'])->assertSessionHasErrors('mfa_code');
        $this->assertSame('active', $this->fresh($future)->status);

        $this->post("/app/enrollments/{$future->id}/cancel-before-start", ['reason_code' => 'did_not_join', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->admin)])
            ->assertRedirect("/app/students/{$this->student->id}")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $this->fresh($future)->status);
        $this->get("/app/students/{$this->student->id}")->assertInertia(fn ($p) => $p
            ->where('enrollmentHistory', fn ($rows) => collect($rows)->firstWhere('id', $future->id)['cancelledBeforeStart'] === true));

        $viewer = $this->createUserWithCapabilities($this->school, ['students.view', 'enrollments.view']);
        $this->actingAs($viewer)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/enrollments/{$this->placement->id}/cancel-before-start", ['reason_code' => 'did_not_join', 'confirm' => '1'])->assertForbidden();
    }

    // --- Roll number -------------------------------------------------------------------

    #[Test]
    public function an_active_placements_roll_number_is_corrected_and_audited(): void
    {
        $corrected = $this->service()->correctRollNumber($this->fresh($this->placement), ' 12 ', $this->admin);

        $this->assertSame('12', $corrected->roll_number);
        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student_enrollment.roll_number_corrected')->sole());
        $this->assertSame(['7', '12'], [$audit->metadata['before'], $audit->metadata['after']]);
    }

    #[Test]
    public function a_roll_number_in_use_in_the_section_even_on_an_ended_placement_is_refused(): void
    {
        $other = $this->createStudent($this->school, ['student_number' => 'S-2']);
        $otherPlacement = $this->service()->enroll($other, $this->sectionA, '8', $this->today());
        $this->assertThrows(fn () => $this->service()->correctRollNumber($this->fresh($this->placement), '8', $this->admin), DuplicateEnrollmentRollNumberException::class);

        $this->service()->cancel($this->fresh($otherPlacement), $this->today(), 'duplicate_placement', $this->admin, $this->proof());
        $this->assertThrows(fn () => $this->service()->correctRollNumber($this->fresh($this->placement), '8', $this->admin), DuplicateEnrollmentRollNumberException::class);

        // The same number in another Section is fine.
        $this->assertSame('8', $this->transfer($this->sectionA2, '8')->roll_number);
    }

    #[Test]
    public function an_ended_placements_roll_number_is_history(): void
    {
        $this->service()->cancel($this->fresh($this->placement), $this->today(), 'entered_in_error', $this->admin, $this->proof());

        $this->assertThrows(fn () => $this->service()->correctRollNumber($this->fresh($this->placement), '99', $this->admin), InvalidEnrollmentTransitionException::class);
        $this->assertSame('7', $this->fresh($this->placement)->roll_number);
    }

    #[Test]
    public function the_roll_number_route_shows_field_errors_and_needs_management(): void
    {
        $other = $this->createStudent($this->school, ['student_number' => 'S-2']);
        $this->service()->enroll($other, $this->sectionA, '8', $this->today());
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");
        $page = "/app/students/{$this->student->id}";

        $this->from($page)->post("/app/enrollments/{$this->placement->id}/roll-number", ['roll_number' => '8'])
            ->assertRedirect($page)->assertSessionHasErrors(['roll_number' => 'Roll number "8" is already in use in this Section for this Academic Year.']);
        $this->from($page)->post("/app/enrollments/{$this->placement->id}/roll-number", ['roll_number' => '15'])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertSame('15', $this->fresh($this->placement)->roll_number);

        $s = $this->createSchool();
        $foreign = $this->createStudentEnrollment($this->createStudent($s), $this->createSection($this->createAcademicYear($s, ['status' => 'active']), $this->createCampus($s), $this->createGradeLevel($s)));
        $this->post("/app/enrollments/{$foreign->id}/roll-number", ['roll_number' => '1'])->assertNotFound();
        $this->post('/app/enrollments/not-a-uuid/roll-number', ['roll_number' => '1'])->assertNotFound();

        $viewer = $this->createUserWithCapabilities($this->school, ['students.view', 'enrollments.view']);
        $this->actingAs($viewer)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/enrollments/{$this->placement->id}/roll-number", ['roll_number' => '16'])->assertForbidden();
    }

    // --- ENR-01 --------------------------------------------------------------------------

    #[Test]
    public function pickers_list_the_active_year_first_and_transfer_offers_only_its_own_year_and_grade(): void
    {
        $grade6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'sequence' => 6]);
        $this->createSection($this->year, $this->a, $grade6, ['code' => 'G6', 'name' => 'G6', 'status' => 'active']);
        $unplaced = $this->createStudent($this->school, ['student_number' => 'S-3']);
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $this->get("/app/students/{$unplaced->id}/enrollments/create")->assertInertia(fn ($p) => $p
            ->where('sections', fn ($sections) => collect($sections)->pluck('academicYearId')->unique()->values()->all() === [$this->year->id, $this->next->id]));

        $this->get("/app/enrollments/{$this->placement->id}/transfer")->assertInertia(fn ($p) => $p
            ->where('sections', fn ($sections) => collect($sections)->pluck('id')->sort()->values()->all() === collect([$this->sectionA2->id, $this->sectionB->id])->sort()->values()->all()));
    }
}
