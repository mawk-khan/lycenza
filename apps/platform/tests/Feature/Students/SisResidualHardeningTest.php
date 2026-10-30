<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Guardians\Application\StudentSiblingReader;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\StudentProfile\StudentProfileSections;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.HARDEN-1 (SIS.EXP-R1 findings R-3, R-5, R-6): Enrollment ownership and
 * lifecycle are not mass assignable; the directory offers only Sections a
 * current placement can match; siblings are authorized before the display
 * limit, so no slot, count or "more" hint comes from a hidden Student.
 */
class SisResidualHardeningTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private AcademicYear $year;

    private Campus $main;

    private Campus $early;

    private Section $mainA;

    private Section $earlyA;

    private GradeLevel $g5;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'AY26', 'status' => 'active']);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->mainA = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A']);
        $this->earlyA = $this->createSection($this->year, $this->early, $this->g5, ['name' => 'E', 'code' => 'E']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    // --- R-3 ------------------------------------------------------------

    #[Test]
    public function mass_assignment_never_sets_enrollment_ownership_or_lifecycle(): void
    {
        $student = $this->createStudent($this->school);
        $other = $this->createSchool();

        $placement = $this->inSchool(fn () => StudentEnrollment::query()->create([
            'school_id' => $other->id, 'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'campus_id' => $this->main->id, 'grade_level_id' => $this->mainA->grade_level_id, 'section_id' => $this->mainA->id,
            'roll_number' => '1', 'starts_on' => $this->year->starts_on->toDateString(),
            'status' => 'completed', 'ends_on' => $this->year->ends_on->toDateString(),
        ]));
        $fresh = $this->inSchool(fn () => StudentEnrollment::query()->findOrFail($placement->id));
        $this->assertSame([$this->school->id, 'active', null], [$fresh->school_id, $fresh->status, $fresh->ends_on], 'the School comes from the trusted context; status and end date stay the database defaults');

        $fresh->fill(['school_id' => $other->id, 'status' => 'withdrawn', 'ends_on' => '2026-07-01', 'roll_number' => '2']);
        $this->assertSame(['roll_number'], array_keys($fresh->getDirty()), 'fill() reaches only ordinary fields');
        foreach (StudentEnrollment::PROTECTED_FIELDS as $field) {
            $this->assertFalse($fresh->isFillable($field), $field);
        }
    }

    #[Test]
    public function the_canonical_services_still_write_every_transition(): void
    {
        $student = $this->createStudent($this->school);
        $service = app(StudentEnrollmentService::class);
        $first = $service->enroll($student, $this->mainA, '1', $this->year->starts_on->toDateString(), $this->admin);
        $this->assertSame([$this->school->id, 'active'], [$first->school_id, $first->status]);

        $moved = $service->transferPlacement($first, $this->earlyA, '1', $this->year->starts_on->copy()->addDays(10)->toDateString(), $this->admin);
        $source = $this->inSchool(fn () => StudentEnrollment::query()->findOrFail($first->id));
        $this->assertSame(['transferred', 'active'], [$source->status, $moved->status]);

        $withdrawn = $service->withdraw($moved, $this->year->starts_on->copy()->addDays(20)->toDateString(), 'left_school', $this->admin, $this->freshProof($this->admin));
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertNotNull($withdrawn->ends_on);
    }

    // --- R-5 ------------------------------------------------------------

    /** @return list<string> the directory's Section option labels */
    private function sectionOptions(): array
    {
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        return array_column($this->get('/app/students')->assertOk()->viewData('page')['props']['placementFilters']['sections'], 'label');
    }

    #[Test]
    public function the_directory_offers_only_sections_a_current_placement_can_match(): void
    {
        $retired = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'Z', 'code' => 'Z', 'status' => 'inactive']);
        $stillPlaced = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'Y', 'code' => 'Y']);
        $this->createStudentEnrollment($this->createStudent($this->school), $stillPlaced, ['roll_number' => '1', 'status' => 'active']);
        $this->inSchool(fn () => $stillPlaced->forceFill(['status' => 'inactive'])->save());

        $labels = $this->sectionOptions();
        $this->assertContains('Grade 5 · A · Main', $labels);
        $this->assertContains('Grade 5 · Y · Main (inactive)', $labels, 'deactivating a Section never ends its placements, so it still matches -- labelled');
        $this->assertNotContains('Grade 5 · Z · Main', $labels);
        $this->assertNotContains('Grade 5 · Z · Main (inactive)', $labels, 'an inactive Section nobody is placed in matches nobody');

        // A forged inactive Section id still only narrows (it matches nobody).
        $this->get("/app/students?section_id={$retired->id}")->assertOk()->assertInertia(fn ($page) => $page->where('students.total', 0));
    }

    // --- R-6 ------------------------------------------------------------

    /** @return array<string, mixed> the Family section the assembler returns for $actor */
    private function family(User $actor, Student $student, Guardian $guardian): array
    {
        return $this->inSchool(fn () => app(StudentProfileSections::class)->for($this->school, $actor, $student, null, [$guardian->id => 'Lakshmi Menon'])['family']);
    }

    private function siblingOf(Guardian $guardian, Section $section, string $first): Student
    {
        $s = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Menon']);
        $this->createStudentEnrollment($s, $section, ['roll_number' => $first, 'status' => 'active']);
        $this->createStudentGuardianRelationship($s, $guardian);

        return $s;
    }

    #[Test]
    public function hidden_siblings_take_no_slot_and_leave_no_count_or_more_hint(): void
    {
        $mother = $this->createGuardian($this->school, ['first_name' => 'Lakshmi', 'last_name' => 'Menon']);
        $subject = $this->siblingOf($mother, $this->earlyA, 'Zara');
        // More hidden (Main) siblings than the limit, created FIRST and sorting FIRST by name.
        for ($i = 1; $i <= StudentSiblingReader::LIMIT + 5; $i++) {
            $this->siblingOf($mother, $this->mainA, sprintf('A%02d', $i));
        }
        $visible = [$this->siblingOf($mother, $this->earlyA, 'Yash'), $this->siblingOf($mother, $this->earlyA, 'Xavi')];

        $earlyReader = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($earlyReader, $this->school), $this->createFixtureRole(['students.view']), $this->early->id);

        $family = $this->family($earlyReader, $subject, $mother);
        $this->assertSame(['Xavi', 'Yash'], array_map(fn (array $s) => explode(' ', $s['name'])[0], $family['siblings']), 'the in-scope siblings fill the slots');
        $this->assertFalse($family['truncated'], 'never "more" because of Students the viewer may not see');
        $this->assertSame(['siblings', 'truncated'], array_keys($family), 'no total, no hidden count');
        $this->assertSame(array_map(fn (Student $s) => (string) $s->id, array_reverse($visible)), array_column($family['siblings'], 'id'));
    }

    #[Test]
    public function the_limit_still_applies_to_authorized_siblings(): void
    {
        $mother = $this->createGuardian($this->school, ['first_name' => 'Lakshmi', 'last_name' => 'Menon']);
        $subject = $this->siblingOf($mother, $this->mainA, 'Subject');
        for ($i = 1; $i <= StudentSiblingReader::LIMIT + 1; $i++) {
            $this->siblingOf($mother, $this->earlyA, sprintf('B%02d', $i));
        }

        $family = $this->family($this->admin, $subject, $mother);
        $this->assertCount(StudentSiblingReader::LIMIT, $family['siblings']);
        $this->assertTrue($family['truncated']);
        $this->assertSame('B01 Menon', $family['siblings'][0]['name']);
        $this->assertSame(['Lakshmi Menon'], $family['siblings'][0]['sharedGuardians']);
    }
}
