<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-1 (ADR 0076 §45): the Campus Student reads are bounded SQL -- the
 * scope is one EXISTS predicate and a page's placements one query -- so the
 * number of queries never grows with the rows (no per-Student resolver call,
 * no N+1 placement lookup).
 */
class StudentCampusReadPerformanceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Section $sectionA;

    private Section $sectionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $b = $this->createCampus($this->school);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $this->sectionA = $this->createSection($year, $this->a, $grade, ['code' => 'PA', 'status' => 'active']);
        $this->sectionB = $this->createSection($year, $b, $grade, ['code' => 'PB', 'status' => 'active']);
    }

    private function seedPlacements(int $count): string
    {
        $last = '';
        for ($i = 0; $i < $count; $i++) {
            foreach ([$this->sectionA, $this->sectionB] as $section) {
                $student = $this->createStudent($this->school);
                $this->createStudentEnrollment($student, $section, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => null]);
                $last = $section->is($this->sectionA) ? $student->id : $last;
            }
        }

        return $last;
    }

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function principalA(): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), 'principal', $this->a->id);

        return $user;
    }

    #[Test]
    public function the_campus_directory_query_count_does_not_grow_with_its_rows(): void
    {
        $user = $this->principalA();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->seedPlacements(2);
        $this->actingAs($user)->get('/app/students')->assertOk(); // warm the per-request authorization caches
        $small = $this->queries(fn () => $this->actingAs($user)->get('/app/students')->assertOk());
        $this->seedPlacements(8);
        $large = $this->queries(fn () => $this->actingAs($user)->get('/app/students')->assertOk()->assertInertia(fn ($page) => $page->has('students.data', 10)));

        $this->assertSame($small, $large, 'the Campus Directory issues a fixed number of queries');
    }

    #[Test]
    public function the_campus_api_lists_and_overview_are_bounded(): void
    {
        $user = $this->principalA();
        $token = $user->createToken('perf')->plainTextToken;
        $first = $this->seedPlacements(2);
        $api = fn (string $path) => $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$this->school->id}{$path}");
        $api('/students')->assertOk(); // warm the token and authorization caches
        $students = $this->queries(fn () => $api('/students')->assertOk());
        $enrollments = $this->queries(fn () => $api('/enrollments')->assertOk());
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->actingAs($user)->get("/app/students/{$first}/overview")->assertOk(); // warm
        $overview = $this->queries(fn () => $this->actingAs($user)->get("/app/students/{$first}/overview")->assertOk());

        $last = $this->seedPlacements(8);

        $this->assertSame($students, $this->queries(fn () => $api('/students')->assertOk()->assertJsonPath('meta.total', 10)));
        $this->assertSame($enrollments, $this->queries(fn () => $api('/enrollments')->assertOk()->assertJsonPath('meta.total', 10)));
        $this->assertSame($overview, $this->queries(fn () => $this->actingAs($user)->get("/app/students/{$last}/overview")->assertOk()));
    }
}
