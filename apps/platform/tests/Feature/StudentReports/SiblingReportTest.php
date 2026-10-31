<?php

namespace Tests\Feature\StudentReports;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.2 (SR-25, owner decision 2026-10-31): the School-wide sibling list.
 * A family is an ACTIVE Guardian linked to two or more Students (the
 * StudentSiblingReader rule). Whole School only, students.view AND
 * guardians.view; the current class and class filters also need whole-School
 * enrollments.view. Names only: no contact, flag, date of birth or dues.
 */
class SiblingReportTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    /** @var array<string, Section> */
    private array $sections = [];

    /** @var array<string, Student> */
    private array $students = [];

    /** @var array<string, Guardian> */
    private array $guardians = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'M']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $ukg = $this->createGradeLevel($this->school, ['name' => 'UKG', 'code' => 'UKG', 'sequence' => 0]);
        $this->sections['5A'] = $this->createSection($year, $this->main, $g5, ['name' => 'A', 'code' => 'A']);
        $this->sections['UA'] = $this->createSection($year, $this->early, $ukg, ['name' => 'A', 'code' => 'UA']);

        // The Menon family: Lakshmi (active) and Ravi (active) both linked to Aarav and Diya.
        $this->student('Aarav', 'Menon', 'S-1', '5A');
        $this->student('Diya', 'Menon', 'S-2', 'UA');
        $this->guardian('Lakshmi', 'Menon', ['Aarav', 'Diya']);
        $this->guardian('Ravi', 'Menon', ['Aarav', 'Diya']);
        // One child only: never a family.
        $this->student('Kabir', 'Rao', 'S-3', '5A');
        $this->guardian('Lata', 'Rao', ['Kabir']);
        // An INACTIVE Guardian shared by two Students: not a family link.
        $this->student('Neel', 'Shah', 'S-4', '5A');
        $this->student('Tara', 'Shah', 'S-5', 'UA');
        $this->guardian('Old', 'Shah', ['Neel', 'Tara'], 'inactive');
    }

    private function student(string $first, string $last, string $number, ?string $section): void
    {
        $this->students[$first] = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => $last, 'student_number' => $number]);
        if ($section !== null) {
            $this->createStudentEnrollment($this->students[$first], $this->sections[$section], ['status' => 'active', 'roll_number' => $number, 'starts_on' => '2026-06-01', 'ends_on' => null]);
        }
    }

    /** @param  list<string>  $children */
    private function guardian(string $first, string $last, array $children, string $status = 'active'): void
    {
        $this->guardians[$first] = $this->createGuardian($this->school, ['first_name' => $first, 'last_name' => $last, 'status' => $status]);
        foreach ($children as $child) {
            $this->createStudentGuardianRelationship($this->students[$child], $this->guardians[$first]);
        }
    }

    /** @param  list<string>  $capabilities */
    private function holder(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    private function as(User $user): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this;
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null): array
    {
        return $this->as($as ?? $this->admin)->get('/app/students/reports/students.siblings?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> "Guardian: S-n,S-n" per row */
    private function rows(array $query = [], ?User $as = null): array
    {
        return array_map(fn (array $r) => $r['guardian']['name'].': '.implode(',', array_column($r['students'], 'studentNumber')), $this->report($query, $as)['rows']['data']);
    }

    #[Test]
    public function a_family_is_an_active_guardian_with_two_or_more_students(): void
    {
        $this->assertSame(['Lakshmi Menon: S-1,S-2', 'Ravi Menon: S-1,S-2'], $this->rows());
        $this->assertSame(['Ravi Menon: S-1,S-2', 'Lakshmi Menon: S-1,S-2'], $this->rows(['sort' => 'guardian_desc']));

        $row = $this->report()['rows']['data'][0];
        $this->assertSame('/app/guardians/'.$this->guardians['Lakshmi']->id, $row['guardian']['url']);
        $this->assertSame(['Grade 5', 'A', 'Main'], [$row['students'][0]['placement']['gradeLevel'], $row['students'][0]['placement']['section'], $row['students'][0]['placement']['campus']]);
        $this->assertSame(['key', 'guardian', 'students'], array_keys($row));
        $this->assertSame(['name', 'studentNumber', 'url', 'inactive', 'placement'], array_keys($row['students'][0]));
    }

    #[Test]
    public function names_only_never_a_contact(): void
    {
        $this->createGuardianContact($this->guardians['Lakshmi'], ContactType::Mobile, '+919876543210');
        $this->createGuardianContact($this->guardians['Lakshmi'], ContactType::Email, 'lakshmi@example.com');

        $json = json_encode($this->report()['rows']['data']);
        foreach (['9876543210', 'lakshmi@example.com', 'date_of_birth', 'is_primary', 'relationship'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    #[Test]
    public function search_and_current_class_filters_only_narrow(): void
    {
        $this->assertSame(['Lakshmi Menon: S-1,S-2'], $this->rows(['q' => 'menon lakshmi']));
        $this->assertCount(2, $this->rows(['q' => 'S-2']), 'a linked Student\'s number finds the family');
        $this->assertSame([], $this->rows(['q' => 'Shah']), 'an inactive Guardian\'s children are no family');
        $this->assertCount(2, $this->rows(['campus_id' => $this->early->id]));
        $this->assertCount(2, $this->rows(['section_id' => $this->sections['5A']->id]));
        $this->assertSame([], $this->rows(['campus_id' => '00000000-0000-4000-8000-000000000000']));

        $sections = collect($this->report()['filters'])->firstWhere('key', 'section_id')['options'];
        $this->assertSame([$this->early->id, $this->main->id], array_column($sections, 'campus_id'), 'each Section option names its Campus, so the page offers no impossible pair');
    }

    #[Test]
    public function whole_school_students_and_guardians_view_are_both_required(): void
    {
        $this->as($this->holder(['students.view']))->get('/app/students/reports/students.siblings')->assertForbidden();
        $this->as($this->holder(['guardians.view']))->get('/app/students/reports/students.siblings')->assertForbidden();
        $campus = $this->holder(['students.view', 'guardians.view', 'enrollments.view'], $this->main->id);
        $this->as($campus)->get('/app/students/reports/students.siblings')->assertForbidden();
        $this->assertNotContains('students.siblings', collect($this->as($campus)->get('/app/students/reports')->viewData('page')['props']['categories'])->flatMap(fn ($c) => array_column($c['reports'], 'key'))->all());

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $this->as($teacher)->get('/app/students/reports/students.siblings')->assertForbidden();
    }

    #[Test]
    public function without_whole_school_enrollments_view_there_is_no_class_and_no_class_filter(): void
    {
        $reader = $this->holder(['students.view', 'guardians.view']);
        $props = $this->report([], $reader);
        $this->assertNull($props['rows']['data'][0]['students'][0]['placement']);
        $this->assertSame([], collect($props['filters'])->firstWhere('key', 'campus_id')['options']);
        $this->assertSame([], $this->rows(['campus_id' => $this->main->id], $reader), 'a guessed class filter reveals nothing');
    }

    #[Test]
    public function another_school_s_families_never_appear(): void
    {
        [, $other] = $this->createSchoolAdmin();
        $guardian = $this->createGuardian($other, ['first_name' => 'Lakshmi', 'last_name' => 'Menon']);
        foreach (['S-1', 'S-2'] as $n) {
            $this->createStudentGuardianRelationship($this->createStudent($other, ['first_name' => 'Twin', 'last_name' => 'Menon', 'student_number' => $n]), $guardian);
        }

        $this->assertSame(2, $this->report()['rows']['total']);
        $this->assertSame(['Lakshmi Menon: S-1,S-2'], $this->rows(['q' => 'Lakshmi']));
        $this->assertStringNotContainsString('Twin', (string) json_encode($this->report()['rows']['data']));
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_families(): void
    {
        $count = function (): int {
            $this->as($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/students/reports/students.siblings')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $count();
        $small = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->student("Kid{$i}a", "Fam{$i}", "F-{$i}a", '5A');
            $this->student("Kid{$i}b", "Fam{$i}", "F-{$i}b", 'UA');
            $this->guardian("Parent{$i}", "Fam{$i}", ["Kid{$i}a", "Kid{$i}b"]);
        }
        $this->assertSame($small, $count());
        $this->assertSame(10, $this->report()['rows']['total']);
    }
}
