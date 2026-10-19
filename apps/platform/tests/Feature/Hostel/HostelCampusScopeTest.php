<?php

namespace Tests\Feature\Hostel;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-3 D3-B (ADR 0076 §39): Hostel residency is Campus-adopted. A
 * Campus-scoped warden works only with Hostels of their Campuses, picks only
 * current operational Students of those Campuses (never students.view), and
 * every write re-checks the Student's CURRENT Campus. Refusals never say
 * where; fee intent pages, the directory and Student profiles stay
 * whole-School; whole-School behaviour is unchanged.
 */
class HostelCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $year;

    private GradeLevel $grade;

    /** @var array<string, array{hostel: Hostel, bed: HostelBed, section: Section}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'H2627', 'name' => '2026-27', 'status' => 'active']);
        $this->grade = $this->createGradeLevel($this->school);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $hostel = $this->createHostel($this->school, $campus, ['name' => 'Hostel '.strtoupper($key), 'status' => 'active']);
            $room = $this->createHostelRoom($hostel, ['code' => 'R'.strtoupper($key), 'status' => 'active']);
            $bed = $this->createHostelBed($room, ['code' => 'BED'.strtoupper($key), 'status' => 'active']);
            $section = $this->createSection($this->year, $campus, $this->grade, ['code' => 'S'.strtoupper($key), 'status' => 'active']);
            $this->at[$key] = compact('hostel', 'bed', 'section');
        }
    }

    private function pupil(string $name, ?string $campus): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Pupil', 'status' => 'active']);
        if ($campus !== null) {
            $this->createStudentEnrollment($student, $this->at[$campus]['section'], ['status' => 'active']);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds */
    private function warden(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'hostel_warden', $campusId);
        }

        return $user;
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function assign(User $user, Student $student, string $bed, string $key = 'hostel-assign-0001')
    {
        return $this->api($user)->withHeader('Idempotency-Key', $key)->postJson($this->url('/hostel-residency-assignments'), ['student_id' => $student->id, 'hostel_bed_id' => $this->at[$bed]['bed']->id]);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    private function residencies(): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => HostelResidencyAssignment::query()->where('status', 'active')->count());
    }

    #[Test]
    public function a_whole_school_warden_is_unchanged(): void
    {
        $user = $this->warden([null]);
        $this->pupil('Unplaced', null);
        $this->signIn($user);
        // The whole-School picker still offers an active Student with no placement.
        $this->getJson('/app/hostel-residency/search/students?q=Unplaced')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/app/hostel-residency/search/beds?q=BED')->assertOk()->assertJsonCount(2, 'data');
        $this->assign($user, $this->pupil('Cross', 'a'), 'b')->assertCreated();
    }

    #[Test]
    public function a_campus_a_warden_works_only_campus_a_hostels_and_students(): void
    {
        $user = $this->warden([$this->a->id]);
        $here = $this->pupil('Here', 'a');
        $there = $this->pupil('There', 'b');
        $this->pupil('Unplaced', null);
        $bResident = $this->createHostelResidencyAssignment($this->pupil('Boarder', 'b'), $this->at['b']['bed'], ['status' => 'active']);

        // Pickers: Campus A Students (current operational only) and Campus A beds; minimal fields.
        $this->signIn($user);
        $students = $this->getJson('/app/hostel-residency/search/students?q=Pupil')->assertOk()->json('data');
        $this->assertSame([$here->id], array_column($students, 'id'));
        $this->assertSame(['id', 'studentNumber', 'name'], array_keys($students[0]));
        $this->assertSame(['BEDA'], array_column($this->getJson('/app/hostel-residency/search/beds?q=BED')->assertOk()->json('data'), 'code'));

        // Lists and direct access: Campus B's residency is NOT FOUND.
        $this->assertSame([], $this->api($user)->getJson($this->url('/hostel-residency-assignments'))->assertOk()->json('data'));
        $this->api($user)->getJson($this->url("/hostel-residency-assignments/{$bResident->id}"))->assertNotFound();
        $this->api($user)->postJson($this->url("/hostel-residency-assignments/{$bResident->id}/end"))->assertNotFound();
        $this->signIn($user);
        $this->post("/app/hostel-residency/{$bResident->id}/end")->assertNotFound();

        // Writes: a Campus B bed is NOT FOUND; a Campus B (or unknown) Student is one generic refusal.
        $before = $this->residencies();
        $this->assign($user, $here, 'b', 'hostel-assign-b-bed')->assertNotFound();
        $refusal = fn ($r) => array_diff_key((array) $r->assertStatus(422)->json('error'), ['requestId' => true]);
        $other = $refusal($this->assign($user, $there, 'a', 'hostel-assign-b-student'));
        $this->assertSame('HOSTEL_RESIDENCY_UNAVAILABLE', $other['code']);
        $ghost = new Student(['id' => (string) Str::uuid()]);
        $ghost->id = (string) Str::uuid();
        $this->assertSame($other, $refusal($this->assign($user, $ghost, 'a', 'hostel-assign-unknown')), 'an unknown Student answers exactly like another Campus\'s');
        foreach (['South', $this->b->id, 'inactive'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, json_encode($other));
        }
        $this->assertSame($before, $this->residencies());

        // Allowed: a current Campus A Student into a Campus A bed.
        $this->assign($user, $here, 'a', 'hostel-assign-ok')->assertCreated();
    }

    #[Test]
    public function the_write_re_checks_the_students_current_campus_and_state(): void
    {
        $user = $this->warden([$this->a->id]);
        $mover = $this->pupil('Mover', 'a');
        $leaver = $this->pupil('Leaver', 'a');
        $this->signIn($user);
        $this->assertCount(2, $this->getJson('/app/hostel-residency/search/students?q=Pupil')->json('data'), 'both are eligible when picked');

        // Before submit: Mover's active-year placement moves to Campus B; Leaver leaves.
        app(TenantContext::class)->withSchool($this->school, function () use ($mover, $leaver): void {
            StudentEnrollment::query()->where('student_id', $mover->id)->update(['status' => 'transferred', 'ends_on' => now()->toDateString()]);
            $leaver->forceFill(['status' => 'inactive'])->save();
        });
        $this->createStudentEnrollment($mover, $this->at['b']['section'], ['status' => 'active']);

        $this->assign($user, $mover, 'a', 'hostel-mover')->assertStatus(422)->assertJsonPath('error.code', 'HOSTEL_RESIDENCY_UNAVAILABLE');
        $this->assign($user, $leaver, 'a', 'hostel-leaver')->assertStatus(422)->assertJsonPath('error.code', 'HOSTEL_RESIDENCY_UNAVAILABLE');
        $this->assertSame(0, $this->residencies(), 'no residency is written from a stale picker answer');
    }

    #[Test]
    public function an_existing_residency_elsewhere_is_never_disclosed_to_a_campus_actor(): void
    {
        $student = $this->pupil('Twice', 'a');
        $this->createHostelResidencyAssignment($student, $this->at['b']['bed'], ['status' => 'active']);
        $spare = $this->createHostelBed(app(TenantContext::class)->withSchool($this->school, fn () => $this->at['a']['bed']->room()->firstOrFail()), ['code' => 'SPARE', 'status' => 'active']);

        $campus = $this->api($this->warden([$this->a->id]))->withHeader('Idempotency-Key', 'hostel-twice-a')
            ->postJson($this->url('/hostel-residency-assignments'), ['student_id' => $student->id, 'hostel_bed_id' => $spare->id]);
        $campus->assertStatus(422)->assertJsonPath('error.code', 'HOSTEL_RESIDENCY_UNAVAILABLE');
        $this->api($this->warden([null]))->withHeader('Idempotency-Key', 'hostel-twice-w')
            ->postJson($this->url('/hostel-residency-assignments'), ['student_id' => $student->id, 'hostel_bed_id' => $spare->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'HOSTEL_STUDENT_ALREADY_RESIDENT');
    }

    #[Test]
    public function fees_the_directory_and_student_profiles_stay_whole_school(): void
    {
        $user = $this->warden([$this->a->id]);
        $student = $this->pupil('Here', 'a');
        $this->signIn($user);

        $this->get('/app/hostel-residency/fees')->assertForbidden();
        $this->post('/app/hostel-residency/fees/carry-forward', ['academic_year_id' => $this->year->id])->assertForbidden();
        $this->get('/app/hostels')->assertForbidden();
        $this->get("/app/students/{$student->id}")->assertForbidden();

        $keys = collect(app(NavigationBuilder::class)->visibleEntries($user, $this->school, false))->pluck('key')->all();
        $this->assertContains('hostel-residency', $keys);
        $this->assertNotContains('hostel-fees', $keys, 'a declared whole-School page is never shown from a Campus grant');
    }

    #[Test]
    public function a_hostels_campus_cannot_change_once_it_has_rooms(): void
    {
        $admin = $this->createUserWithCapabilities($this->school, ['hostel.directory.manage', 'hostel.directory.view']);
        $this->api($admin)->patchJson($this->url('/hostels/'.$this->at['a']['hostel']->id), ['campus_id' => $this->b->id])
            ->assertStatus(422)->assertJsonPath('error.errors.campus_id.0', 'A Hostel\'s Campus cannot change once it has Rooms.');
        $this->assertSame($this->a->id, app(TenantContext::class)->withSchool($this->school, fn () => Hostel::query()->findOrFail($this->at['a']['hostel']->id)->campus_id));
    }

    #[Test]
    public function module_and_campus_state_gate_campus_authority(): void
    {
        $user = $this->warden([$this->a->id]);
        SchoolFactory::setOptionalModules($this->school, false, ['hostel']);
        $this->api($user)->getJson($this->url('/hostel-residency-assignments'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['hostel']);
        $this->api($user)->getJson($this->url('/hostel-residency-assignments'))->assertOk();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/hostel-residency-assignments'))->assertForbidden();
    }
}
