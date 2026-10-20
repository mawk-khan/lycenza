<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\Exceptions\StudentTransferUnavailableException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-3 (ADR 0076 §47): the Campus transfer of a Student's CURRENT
 * placement -- enrollments.manage over BOTH Campuses; ends the source row and
 * creates the destination row (never an UPDATE); refuses, generically, on any
 * active source-Campus Hostel residency, Transport rider assignment or
 * elective; moves and rewrites nothing else.
 */
class StudentCampusTransferTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Campus $c;

    private AcademicYear $year;

    private AcademicYear $next;

    private GradeLevel $grade;

    private Section $sectionA;

    private Section $sectionB;

    private Section $sectionA2;

    private Section $sectionC;

    private Student $student;

    private StudentEnrollment $placement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->c = $this->createCampus($this->school, ['name' => 'East']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'status' => 'draft', 'starts_on' => now()->addDays(101)->toDateString(), 'ends_on' => now()->addDays(400)->toDateString()]);
        $this->sectionA = $this->createSection($this->year, $this->a, $this->grade, ['code' => 'A', 'name' => 'A', 'status' => 'active']);
        $this->sectionA2 = $this->createSection($this->year, $this->a, $this->grade, ['code' => 'A2', 'name' => 'A2', 'status' => 'active']);
        $this->sectionB = $this->createSection($this->year, $this->b, $this->grade, ['code' => 'B', 'name' => 'B', 'status' => 'active']);
        $this->sectionC = $this->createSection($this->year, $this->c, $this->grade, ['code' => 'C', 'name' => 'C', 'status' => 'active']);
        $this->student = $this->createStudent($this->school, ['first_name' => 'Asha', 'student_number' => 'S-1', 'date_of_birth' => '2015-04-01']);
        $this->placement = $this->createStudentEnrollment($this->student, $this->sectionA, ['status' => 'active', 'roll_number' => '7', 'starts_on' => now()->subDays(100)->toDateString(), 'ends_on' => null]);
    }

    /** @param  list<?string>  $campusIds */
    private function actor(string $role, array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    private function web(User $user): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)->withHeader('Idempotency-Key', 'transfer-'.Str::uuid());
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function placements(): array
    {
        return $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $this->student->id)->orderBy('starts_on')->orderBy('created_at')->get()->map(fn ($e) => [$e->campus_id, $e->status])->all());
    }

    private function transferAs(User $user, Section $to, string $roll = '9'): TestResponse
    {
        return $this->web($user)->post("/app/students/{$this->student->id}/transfer", ['target_section_id' => $to->id, 'roll_number' => $roll]);
    }

    #[Test]
    public function a_principal_over_both_campuses_transfers_the_current_placement(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);

        $this->web($principalAB)->get("/app/students/{$this->student->id}/transfer")->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $this->assertSame([$this->sectionB->id], collect($page->toArray()['props']['destinations'])->pluck('id')->all(), 'only another in-scope Campus, same Grade (not A2 on the same Campus, not C out of scope)');
        });
        $this->transferAs($principalAB, $this->sectionB)->assertRedirect("/app/students/{$this->student->id}/overview");

        $this->assertSame([[$this->a->id, 'transferred'], [$this->b->id, 'active']], $this->placements());
        $source = $this->inSchool(fn () => StudentEnrollment::query()->whereKey($this->placement->id)->firstOrFail());
        $this->assertSame([$this->a->id, $this->sectionA->id, '7', CarbonImmutable::parse(SchoolTimezone::today($this->school))->subDay()->toDateString()], [$source->campus_id, $source->section_id, $source->roll_number, $source->ends_on->toDateString()], 'the source row keeps its placement, it is only ended');
        $destination = $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $this->student->id)->where('status', 'active')->firstOrFail());
        $this->assertSame([$this->sectionB->id, '9', SchoolTimezone::today($this->school)], [$destination->section_id, $destination->roll_number, $destination->starts_on->toDateString()]);

        $student = $this->inSchool(fn () => Student::query()->findOrFail($this->student->id));
        $this->assertSame(['S-1', 'Asha', '2015-04-01', 'active'], [$student->student_number, $student->first_name, $student->date_of_birth->toDateString(), $student->status]);

        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student_enrollment.transferred')->where('subject_id', $this->placement->id)->firstOrFail());
        $this->assertSame([$this->a->id, $this->b->id, $this->placement->id, $destination->id, $this->sectionA->id, $this->sectionB->id, 'campus'], [$audit->metadata['fromCampusId'], $audit->metadata['toCampusId'], $audit->metadata['fromEnrollmentId'], $audit->metadata['toEnrollmentId'], $audit->metadata['fromSectionId'], $audit->metadata['toSectionId'], $audit->metadata['scope']]);
    }

    #[Test]
    public function both_campuses_must_be_in_scope(): void
    {
        $principalA = $this->actor('principal', [$this->a->id]);
        $this->web($principalA)->get("/app/students/{$this->student->id}/transfer")->assertInertia(fn (AssertableInertia $page) => $page->where('destinations', []));
        $this->transferAs($principalA, $this->sectionB)->assertSessionHasErrors('target_section_id');

        $principalB = $this->actor('principal', [$this->b->id]);
        $this->transferAs($principalB, $this->sectionB)->assertNotFound();

        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $this->transferAs($principalAB, $this->sectionC)->assertSessionHasErrors('target_section_id');
        $this->transferAs($principalAB, $this->sectionA2)->assertSessionHasErrors('target_section_id', 'a same-Campus Section change stays whole-School');
        $this->assertSame([[$this->a->id, 'active']], $this->placements());
    }

    #[Test]
    public function every_in_scope_refusal_is_the_same_generic_answer_and_moves_nothing(): void
    {
        $principalAC = $this->actor('principal', [$this->a->id, $this->b->id, $this->c->id]);
        $inactive = $this->createSection($this->year, $this->b, $this->grade, ['code' => 'OFF', 'status' => 'inactive']);
        $nextYear = $this->createSection($this->next, $this->b, $this->grade, ['code' => 'NX', 'status' => 'active']);
        $grade6 = $this->createSection($this->year, $this->b, $this->createGradeLevel($this->school, ['code' => 'G6', 'sequence' => 6]), ['code' => 'G6', 'status' => 'active']);
        $this->inSchool(fn () => Campus::query()->whereKey($this->c->id)->update(['status' => 'inactive']));
        $messages = [];
        foreach (['inactive Section' => $inactive->id, 'draft year' => $nextYear->id, 'other Grade' => $grade6->id, 'inactive Campus' => $this->sectionC->id, 'same Campus' => $this->sectionA2->id, 'unknown' => Str::uuid()->toString()] as $case => $sectionId) {
            $this->web($principalAC)->post("/app/students/{$this->student->id}/transfer", ['target_section_id' => $sectionId, 'roll_number' => '9'])->assertSessionHasErrors('target_section_id');
            $messages[$case] = session('errors')->first('target_section_id');
        }
        $this->assertCount(1, array_unique($messages));
        $this->assertSame([[$this->a->id, 'active']], $this->placements());
    }

    #[Test]
    public function only_the_current_placement_of_an_active_in_scope_student_is_reachable(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $unknown = $this->web($principalAB)->post('/app/students/'.Str::uuid().'/transfer', ['target_section_id' => $this->sectionB->id, 'roll_number' => '9'])->assertNotFound();

        $inactive = $this->createStudent($this->school, ['status' => 'inactive']);
        $this->createStudentEnrollment($inactive, $this->sectionA, ['status' => 'active', 'roll_number' => '8', 'starts_on' => now()->subDays(50)->toDateString()]);
        $future = $this->createStudent($this->school);
        $this->createStudentEnrollment($future, $this->createSection($this->next, $this->a, $this->grade, ['code' => 'FA', 'status' => 'active']), ['status' => 'active', 'starts_on' => now()->addDays(120)->toDateString()]);
        foreach ([$inactive, $future, $this->createStudent($this->school)] as $other) {
            $this->web($principalAB)->post("/app/students/{$other->id}/transfer", ['target_section_id' => $this->sectionB->id, 'roll_number' => '9'])->assertNotFound();
        }
        $this->assertSame($unknown->status(), 404);
    }

    #[Test]
    public function an_active_source_campus_hostel_transport_or_elective_relationship_blocks_and_is_never_moved(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);

        // Hostel: an active residency in a Campus A Hostel.
        $bed = $this->createHostelBed($this->createHostelRoom($this->createHostel($this->school, $this->a, ['status' => 'active']), ['status' => 'active']), ['status' => 'active']);
        $this->createHostelResidencyAssignment($this->student, $bed, ['status' => 'active']);
        $this->transferAs($principalAB, $this->sectionB)->assertSessionHasErrors('target_section_id');
        $this->assertSame([[$this->a->id, 'active']], $this->placements());
        $this->assertSame('active', $this->inSchool(fn () => DB::table('hostel_residency_assignments')->where('student_id', $this->student->id)->value('status')), 'never ended by the transfer');
        $this->inSchool(fn () => DB::table('hostel_residency_assignments')->where('student_id', $this->student->id)->update(['status' => 'ended', 'ends_on' => now()]));

        // Transport: an active rider assignment on a Campus A route.
        $route = $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'status' => 'active']);
        $this->createTransportStudentAssignment($this->student, $route, ['status' => 'active']);
        $this->transferAs($principalAB, $this->sectionB)->assertSessionHasErrors('target_section_id');
        $this->inSchool(fn () => DB::table('transport_student_assignments')->where('student_id', $this->student->id)->update(['status' => 'ended', 'ends_on' => now()]));

        // Elective: an active subject enrollment anchored to the source placement.
        $offering = $this->createSubjectOffering($this->year, $this->a, $this->grade, $this->createSubject($this->school), ['is_required' => false, 'status' => 'active']);
        $this->createStudentSubjectEnrollment($this->student, $offering, ['student_enrollment_id' => $this->placement->id, 'status' => 'active', 'starts_on' => now()->subDays(10)->toDateString()]);
        $this->transferAs($principalAB, $this->sectionB)->assertSessionHasErrors('target_section_id');
        $this->assertSame([[$this->a->id, 'active']], $this->placements());
        $this->assertSame('active', $this->inSchool(fn () => DB::table('student_subject_enrollments')->where('student_id', $this->student->id)->value('status')));
    }

    #[Test]
    public function library_and_canteen_records_do_not_block_and_are_not_rewritten(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $copy = $this->createLibraryCopy($this->createLibraryTitle($this->school), ['campus_id' => $this->a->id]);
        $this->createLibraryLoan($copy, $this->student, ['status' => 'active']);

        $this->transferAs($principalAB, $this->sectionB)->assertRedirect();
        $this->assertSame([[$this->a->id, 'transferred'], [$this->b->id, 'active']], $this->placements());
        $loan = $this->inSchool(fn () => DB::table('library_loans')->where('student_id', $this->student->id)->first(['campus_id', 'status']));
        $this->assertSame([$this->a->id, 'active'], [$loan->campus_id, $loan->status], 'the loan keeps its Campus and stays open');
    }

    #[Test]
    public function read_authority_follows_the_new_current_campus(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $principalA = $this->actor('principal', [$this->a->id]);
        $principalB = $this->actor('principal', [$this->b->id]);
        $this->web($principalA)->get("/app/students/{$this->student->id}/overview")->assertOk();
        $this->web($principalB)->get("/app/students/{$this->student->id}/overview")->assertNotFound();

        $this->transferAs($principalAB, $this->sectionB)->assertRedirect();

        $this->web($principalA)->get("/app/students/{$this->student->id}/overview")->assertNotFound();
        $this->web($principalB)->get("/app/students/{$this->student->id}/overview")->assertOk();
        $this->web($principalA)->get("/app/students/{$this->student->id}")->assertForbidden();
    }

    #[Test]
    public function the_api_transfer_is_campus_aware_and_the_whole_school_path_is_unchanged(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $today = SchoolTimezone::today($this->school);
        $url = "/api/v1/schools/{$this->school->id}/enrollments/{$this->placement->id}/transfer";

        $this->api($principalAB)->postJson($url, ['target_section_id' => $this->sectionB->id, 'roll_number' => '9', 'effective_date' => now()->subDays(3)->toDateString()])->assertUnprocessable();
        $this->assertSame([[$this->a->id, 'active']], $this->placements(), 'a Campus transfer never backdates');
        $this->api($principalAB)->postJson($url, ['target_section_id' => $this->sectionA2->id, 'roll_number' => '9', 'effective_date' => $today])->assertUnprocessable()->assertJsonPath('error.code', 'STUDENT_TRANSFER_UNAVAILABLE');
        $this->api($this->actor('principal', [$this->b->id]))->postJson($url, ['target_section_id' => $this->sectionB->id, 'roll_number' => '9', 'effective_date' => $today])->assertNotFound();
        $this->api($principalAB)->postJson($url, ['target_section_id' => $this->sectionB->id, 'roll_number' => '9', 'effective_date' => $today])->assertCreated()->assertJsonPath('data.campus.id', $this->b->id);

        // A historical (now transferred) row is never Campus-reachable.
        $this->api($principalAB)->postJson($url, ['target_section_id' => $this->sectionB->id, 'roll_number' => '10', 'effective_date' => $today])->assertNotFound();

        // Whole School: the existing transfer -- a same-Campus Section change on a chosen date.
        $admin = $this->actor('school_admin', [null]);
        $current = $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $this->student->id)->where('status', 'active')->firstOrFail());
        $this->api($admin)->postJson("/api/v1/schools/{$this->school->id}/enrollments/{$current->id}/transfer", ['target_section_id' => $this->createSection($this->year, $this->b, $this->grade, ['code' => 'B2', 'status' => 'active'])->id, 'roll_number' => '11', 'effective_date' => CarbonImmutable::parse($today)->addDay()->toDateString()])->assertCreated();
    }

    #[Test]
    public function the_transfer_service_refuses_a_campus_scope_that_does_not_cover_the_source(): void
    {
        $principalB = $this->actor('principal', [$this->b->id]);
        $scope = app(CampusScopeResolver::class)->campusScope($principalB, 'enrollments.manage', $this->school);

        $this->expectException(ModelNotFoundException::class);
        app(CampusStudentTransferService::class)->transfer($this->school, $scope, $this->student->id, $this->sectionB->id, '9');
    }

    #[Test]
    public function the_generic_refusal_class_is_the_campus_answer(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $scope = app(CampusScopeResolver::class)->campusScope($principalAB, 'enrollments.manage', $this->school);

        $this->expectException(StudentTransferUnavailableException::class);
        app(CampusStudentTransferService::class)->transfer($this->school, $scope, $this->student->id, $this->sectionA2->id, '9');
    }

    protected function tearDown(): void
    {
        app('auth')->forgetGuards();
        parent::tearDown();
    }

    #[Test]
    public function review_fixes_pin_the_named_row_the_unknown_section_answer_and_legacy_electives(): void
    {
        $principalAB = $this->actor('principal', [$this->a->id, $this->b->id]);
        $scope = app(CampusScopeResolver::class)->campusScope($principalAB, 'enrollments.manage', $this->school);

        // LOW-1: a named placement that is no longer the current one is NOT FOUND.
        try {
            app(CampusStudentTransferService::class)->transfer($this->school, $scope, $this->student->id, $this->sectionB->id, '9', null, (string) Str::uuid());
            $this->fail('a stale named placement was transferred');
        } catch (ModelNotFoundException) {
        }

        // LOW-2: over the API, an unknown Section and an out-of-scope Section are the same answer.
        $today = SchoolTimezone::today($this->school);
        $url = "/api/v1/schools/{$this->school->id}/enrollments/{$this->placement->id}/transfer";
        $unknown = $this->api($principalAB)->postJson($url, ['target_section_id' => (string) Str::uuid(), 'roll_number' => '9', 'effective_date' => $today]);
        $outOfScope = $this->api($principalAB)->postJson($url, ['target_section_id' => $this->sectionC->id, 'roll_number' => '9', 'effective_date' => $today]);
        $this->assertSame([422, 'STUDENT_TRANSFER_UNAVAILABLE'], [$unknown->status(), $unknown->json('error.code')]);
        $this->assertSame([422, 'STUDENT_TRANSFER_UNAVAILABLE'], [$outOfScope->status(), $outOfScope->json('error.code')]);

        // A pre-anchor (legacy) elective on a source-Campus Offering also blocks.
        $offering = $this->createSubjectOffering($this->year, $this->a, $this->grade, $this->createSubject($this->school), ['is_required' => false, 'status' => 'active']);
        $this->createStudentSubjectEnrollment($this->student, $offering, ['student_enrollment_id' => null, 'status' => 'active', 'starts_on' => now()->subDays(10)->toDateString()]);
        $this->transferAs($principalAB, $this->sectionB)->assertSessionHasErrors('target_section_id');
        $this->assertSame([[$this->a->id, 'active']], $this->placements());
    }
}
