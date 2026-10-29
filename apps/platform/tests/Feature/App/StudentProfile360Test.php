<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.EXP-1: the Student 360 sections of the whole-School staff profile.
 * Every cross-domain section is filled on the server only when the viewer
 * holds that domain's OWN capability in a scope reaching the Student --
 * `students.view` alone opens nothing else, and an unauthorized section is
 * absent from the Inertia payload (never sent and hidden in Vue).
 */
class StudentProfile360Test extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private School $school;

    private Campus $main;

    private Campus $other;

    private AcademicYear $year;

    private Section $mainSection;

    private Section $otherSection;

    private Student $aarav;

    private Student $diya;

    private Guardian $mother;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->createSchool();
        $this->main = $this->createCampus($this->school, ['name' => 'Main Campus', 'code' => 'MAIN']);
        $this->other = $this->createCampus($this->school, ['name' => 'Early Years Campus', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'AY26', 'status' => 'active']);
        $g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 7]);
        $ukg = $this->createGradeLevel($this->school, ['name' => 'UKG', 'code' => 'UKG', 'sequence' => 2]);
        $this->mainSection = $this->createSection($this->year, $this->main, $g5, ['name' => 'A', 'code' => 'A']);
        $this->otherSection = $this->createSection($this->year, $this->other, $ukg, ['name' => 'A', 'code' => 'A']);
        $english = $this->createSubject($this->school, ['name' => 'English', 'code' => 'ENG']);
        $this->createSubjectOffering($this->year, $this->main, $g5, $english, ['is_required' => true, 'status' => 'active']);

        $this->aarav = $this->createStudent($this->school, ['first_name' => 'Aarav', 'last_name' => 'Menon', 'student_number' => 'GCB-1']);
        $this->diya = $this->createStudent($this->school, ['first_name' => 'Diya', 'last_name' => 'Menon', 'student_number' => 'GCB-2']);
        $this->createStudentEnrollment($this->aarav, $this->mainSection, ['roll_number' => '1', 'status' => 'active']);
        $this->createStudentEnrollment($this->diya, $this->otherSection, ['roll_number' => '1', 'status' => 'active']);
        $this->mother = $this->createGuardian($this->school, ['first_name' => 'Lakshmi', 'last_name' => 'Menon']);
        $this->createStudentGuardianRelationship($this->aarav, $this->mother, ['is_primary' => true]);
        $this->createStudentGuardianRelationship($this->diya, $this->mother, ['is_primary' => true]);
    }

    /** @param list<string> $capabilities */
    private function viewer(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    /** @return array<string, mixed> the Inertia props of the profile */
    private function profile(User $user, Student $student): array
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->get("/app/students/{$student->id}")->assertOk()->viewData('page')['props'];
    }

    private function charge(Student $student, string $amount): void
    {
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset', 'code' => 'AR'.substr(md5($student->id.$amount), 0, 6)]);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income', 'code' => 'RV'.substr(md5($student->id.$amount), 0, 6)]);
        $this->assessCharge($this->school, $student, $this->year, $receivable, $revenue, $amount);
    }

    #[Test]
    public function students_view_alone_opens_the_family_block_and_no_other_domain(): void
    {
        $props = $this->profile($this->viewer(['students.view']), $this->aarav);

        $this->assertSame(['family'], array_keys($props['sections']), 'no Subjects, Attendance, Fees, Transport, Hostel or Library without their own capability');
        $this->assertSame([(string) $this->diya->id], array_column($props['sections']['family']['siblings'], 'id'));
        $this->assertSame(['Lakshmi Menon'], $props['sections']['family']['siblings'][0]['sharedGuardians']);
        $this->assertNull($props['sections']['family']['siblings'][0]['currentPlacement'], 'no placement without enrollments.view');
    }

    #[Test]
    public function each_domain_section_appears_only_with_that_domains_capability(): void
    {
        $this->charge($this->aarav, '30000.00');
        $route = $this->createTransportRoute($this->school, ['campus_id' => $this->main->id, 'name' => 'Kondapur Route']);
        $stop = $this->createTransportStop($route, ['name' => 'Kondapur Junction']);
        $this->createTransportStudentAssignment($this->aarav, $route, ['pickup_stop_id' => $stop->id, 'status' => 'active']);
        $copy = $this->createLibraryCopy($this->createLibraryTitle($this->school), ['campus_id' => $this->main->id]);
        $this->createLibraryLoan($copy, $this->aarav, ['status' => 'active', 'due_at' => now()->addDays(5)]);

        $all = $this->profile($this->viewer([
            'students.view', 'enrollments.view', 'academics.subjects.view', 'attendance.view', 'finance.charges.view',
            'finance.payments.view', 'transport.assignments.view', 'hostel.residency.view', 'library.circulation.view',
        ]), $this->aarav);
        $this->assertSame(['attendance', 'family', 'fees', 'hostel', 'library', 'subjects', 'transport'], collect($all['sections'])->keys()->sort()->values()->all());
        $this->assertSame([['subjectName' => 'English', 'subjectCode' => 'ENG', 'required' => true]], $all['sections']['subjects']['items']);
        $this->assertSame([], $all['sections']['attendance']['recent'], 'authorized, no records yet: present and empty');
        $this->assertSame(['30000.00', '0.00', '30000.00', 'school'], [$all['sections']['fees']['billed'], $all['sections']['fees']['paid'], $all['sections']['fees']['outstanding'], $all['sections']['fees']['scope']]);
        $this->assertSame("/app/finance/fee-statements/{$this->aarav->id}", $all['sections']['fees']['statementLink']);
        $this->assertSame('Kondapur Route', $all['sections']['transport']['assignments'][0]['routeName']);
        $this->assertSame([], $all['sections']['hostel']['residencies']);
        $this->assertSame([1, 0], [$all['sections']['library']['activeLoans'], $all['sections']['library']['overdueLoans']]);

        // Fees needs BOTH Finance read capabilities (the Payments rule).
        $chargesOnly = $this->profile($this->viewer(['students.view', 'finance.charges.view']), $this->aarav);
        $this->assertArrayNotHasKey('fees', $chargesOnly['sections']);
        // Subjects needs whole-School placement read as well.
        $subjectsOnly = $this->profile($this->viewer(['students.view', 'academics.subjects.view']), $this->aarav);
        $this->assertArrayNotHasKey('subjects', $subjectsOnly['sections']);
    }

    #[Test]
    public function a_campus_scoped_domain_grant_shows_only_that_campuss_records(): void
    {
        $this->charge($this->aarav, '30000.00');
        // Diya's Charge carries the Early Years Campus (placement on assessment).
        $this->charge($this->diya, '18000.00');
        $mainRoute = $this->createTransportRoute($this->school, ['campus_id' => $this->main->id]);
        $this->createTransportStudentAssignment($this->aarav, $mainRoute, ['status' => 'active']);

        // A whole-School Student reader with Early Years Finance/Transport only.
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['students.view']));
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['finance.charges.view', 'finance.payments.view', 'transport.assignments.view']), $this->other->id);

        $props = $this->profile($user, $this->aarav);
        $this->assertSame('campus', $props['sections']['fees']['scope']);
        $this->assertFalse($props['sections']['fees']['hasCharges'], 'a Main Campus Charge is not reachable from an Early Years Finance grant');
        $this->assertNull($props['sections']['fees']['statementLink'], 'the whole-School statement is never offered to a Campus reader');
        $this->assertSame([], $props['sections']['transport']['assignments'], 'a Main Campus route is not reachable');

        $diya = $this->profile($user, $this->diya);
        $this->assertSame('18000.00', $diya['sections']['fees']['outstanding']);
    }

    #[Test]
    public function siblings_follow_an_active_shared_guardian_and_the_viewers_own_reach(): void
    {
        $admin = $this->viewer(['students.view', 'enrollments.view']);
        $props = $this->profile($admin, $this->aarav);
        $this->assertSame(['gradeLevel' => 'UKG', 'section' => 'A', 'campus' => 'Early Years Campus'], $props['sections']['family']['siblings'][0]['currentPlacement']);

        // An inactive Guardian is no longer a family link.
        app(TenantContext::class)->withSchool($this->school, fn () => $this->mother->forceFill(['status' => 'inactive'])->save());
        $this->assertSame([], $this->profile($admin, $this->aarav)['sections']['family']['siblings']);

        // A Student of ANOTHER School is never a sibling (relationships are School-bound).
        $elsewhere = $this->createSchool();
        $stranger = $this->createStudent($elsewhere);
        $this->assertNotContains((string) $stranger->id, array_column($this->profile($admin, $this->aarav)['sections']['family']['siblings'], 'id'));
    }

    #[Test]
    public function the_profile_stays_whole_school_and_refuses_everyone_else(): void
    {
        // Campus-scoped Student reader: the profile is a declared whole-School operation.
        $campusReader = $this->viewer(['students.view', 'enrollments.view'], $this->other->id);
        $this->actingAs($campusReader)->post("/app/schools/{$this->school->id}/activate");
        $this->get("/app/students/{$this->diya->id}")->assertForbidden();
        $this->get("/app/students/{$this->diya->id}/overview")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->missing('sections'));
        $this->get("/app/students/{$this->aarav->id}/overview")->assertNotFound();

        // A teacher (attendance.teacher only) never opens a Student profile.
        $teacher = $this->viewer(['attendance.teacher']);
        $this->actingAs($teacher)->post("/app/schools/{$this->school->id}/activate");
        $this->get("/app/students/{$this->aarav->id}")->assertForbidden();

        // Another School's Student is NOT FOUND, like an unknown id.
        $admin = $this->viewer(['students.view']);
        $foreign = $this->createStudent($this->createSchool());
        $this->actingAs($admin)->post("/app/schools/{$this->school->id}/activate");
        $this->get("/app/students/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function platform_and_network_roles_hold_no_student_capability(): void
    {
        $keys = DB::table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
            ->whereIn('r.scope', ['platform', 'group'])
            ->where(fn ($q) => $q->where('rc.capability_key', 'like', 'students.%')->orWhere('rc.capability_key', 'like', 'enrollments.%')->orWhere('rc.capability_key', 'like', 'guardians.%'))
            ->pluck('rc.capability_key')->all();
        $this->assertSame([], $keys);

        // A platform root with no School membership never reaches a Student page.
        $root = $this->createPlatformRoot();
        $this->actingAs($root);
        $this->assertNotSame(200, $this->get("/app/students/{$this->aarav->id}")->status());
    }

    #[Test]
    public function the_profile_query_count_is_bounded_and_does_not_grow_with_the_students_data(): void
    {
        $user = $this->viewer([
            'students.view', 'enrollments.view', 'academics.subjects.view', 'attendance.view', 'finance.charges.view',
            'finance.payments.view', 'transport.assignments.view', 'hostel.residency.view', 'library.circulation.view',
        ]);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/app/students/{$this->aarav->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        // One of everything first (an empty section skips its eager loads).
        $this->charge($this->aarav, '1000.00');
        $title = $this->createLibraryTitle($this->school);
        $route = $this->createTransportRoute($this->school, ['campus_id' => $this->main->id]);
        $this->createTransportStudentAssignment($this->aarav, $route, ['status' => 'active']);
        $this->createLibraryLoan($this->createLibraryCopy($title, ['campus_id' => $this->main->id]), $this->aarav, ['status' => 'active', 'due_at' => now()->addDays(3)]);
        $count(); // warm the per-request caches once
        $before = $count();

        // Then more of everything the sections read: Charges, loans, siblings.
        for ($i = 0; $i < 6; $i++) {
            $this->charge($this->aarav, (string) (2000 + $i).'.00');
            $this->createLibraryLoan($this->createLibraryCopy($title, ['campus_id' => $this->main->id]), $this->aarav, ['status' => 'active', 'due_at' => now()->addDays(3)]);
            $sibling = $this->createStudent($this->school, ['student_number' => "SIB-{$i}"]);
            $this->createStudentEnrollment($sibling, $this->mainSection, ['roll_number' => (string) (10 + $i), 'status' => 'active']);
            $this->createStudentGuardianRelationship($sibling, $this->mother, ['is_primary' => true]);
        }

        $after = $count();
        $this->assertSame($before, $after, 'every Student 360 section is a constant number of queries');
        // 33 for the page itself (SIS.EXP-0) plus a constant per section; Fees
        // is the canonical charge-state read (allocations, corrections,
        // adjustments, closed-period baseline).
        $this->assertLessThanOrEqual(85, $after, 'the SIS.EXP-1 profile budget');
    }
}
