<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Domain\Students\Application\StudentOperationalCampusResolver;
use App\Domain\Students\Application\StudentPurposeLookup;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-2 (ADR 0076 §38, D2-1 to D2-9): one canonical CURRENT OPERATIONAL
 * Campus -- the active Student's active Enrollment in the School's active
 * AcademicYear, on an active Campus -- and the purpose lookup built on it.
 * Student identity stays School-wide; history and finance attribution are
 * untouched; the lookup is never a Student authority.
 */
class StudentOperationalCampusTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $prior;

    private AcademicYear $current;

    private AcademicYear $next;

    private GradeLevel $grade;

    /** @var array<string, Section> "{year}:{campus}" => Section */
    private array $sections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->grade = $this->createGradeLevel($this->school);
        $this->prior = $this->createAcademicYear($this->school, ['code' => 'Y2526', 'name' => '2025-26', 'status' => 'closed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-03-31']);
        $this->current = $this->createAcademicYear($this->school, ['code' => 'Y2627', 'name' => '2026-27', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2728', 'name' => '2027-28', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31']);
        foreach (['prior' => $this->prior, 'current' => $this->current, 'next' => $this->next] as $y => $year) {
            foreach (['a' => $this->a, 'b' => $this->b] as $c => $campus) {
                $this->sections["{$y}:{$c}"] = $this->createSection($year, $campus, $this->grade, ['code' => strtoupper("{$y}{$c}"), 'status' => 'active']);
            }
        }
    }

    /** @param  array<string, string>  $placements  "{year}" => "{campus}" (active enrollments) */
    private function student(string $name, array $placements, string $status = 'active'): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Pupil', 'status' => $status]);
        foreach ($placements as $year => $campus) {
            $this->createStudentEnrollment($student, $this->sections["{$year}:{$campus}"], ['status' => 'active']);
        }

        return $student;
    }

    private function resolver(): StudentOperationalCampusResolver
    {
        return app(StudentOperationalCampusResolver::class);
    }

    private function inSchool(callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $callback);
    }

    private function setStatus(object $model, string $status): void
    {
        $this->inSchool(fn () => $model->forceFill(['status' => $status])->save());
    }

    /** @return list<string> */
    private function lookup(CampusScope $scope, ?string $term = null): array
    {
        return array_map(fn (StudentLookupCandidate $c) => $c->displayName, app(StudentPurposeLookup::class)->search($this->school, $scope, $term));
    }

    #[Test]
    public function the_current_campus_is_the_active_years_active_placement_only(): void
    {
        $here = $this->student('Here', ['current' => 'a']);
        $moved = $this->student('Moved', ['prior' => 'a', 'current' => 'b']);
        $intake = $this->student('Intake', ['next' => 'a']);
        $promoted = $this->student('Promoted', ['current' => 'b', 'next' => 'a']);
        $unplaced = $this->student('Unplaced', []);

        $r = $this->resolver()->forMany($this->school, [$here->id, $moved->id, $intake->id, $promoted->id, $unplaced->id]);
        $this->assertSame($this->a->id, $r[$here->id]->operationalCampusId());
        $this->assertSame($this->b->id, $r[$moved->id]->operationalCampusId(), 'history (prior year) never decides the current Campus');
        $this->assertNull($r[$intake->id]->operationalCampusId(), 'a draft/future-year placement confers no current Campus');
        $this->assertFalse($r[$intake->id]->hasCurrentPlacement());
        $this->assertSame($this->b->id, $r[$promoted->id]->operationalCampusId(), 'the latest year is not "current"; the active year is');
        $this->assertNull($r[$unplaced->id]->operationalCampusId());
        $this->assertSame($this->a->id, $this->resolver()->for($this->school, $here->id)->operationalCampusId());
    }

    #[Test]
    public function switching_the_active_year_moves_the_current_campus_without_touching_any_enrollment(): void
    {
        $student = $this->student('Mover', ['current' => 'a', 'next' => 'b']);
        $before = $this->inSchool(fn () => DB::table('student_enrollments')->where('student_id', $student->id)->orderBy('id')->get()->toArray());
        $this->assertSame($this->a->id, $this->resolver()->for($this->school, $student->id)->operationalCampusId());

        $this->setStatus($this->current, 'closed');
        $this->setStatus($this->next, 'active');

        $this->assertSame($this->b->id, $this->resolver()->for($this->school, $student->id)->operationalCampusId());
        $this->assertEquals($before, $this->inSchool(fn () => DB::table('student_enrollments')->where('student_id', $student->id)->orderBy('id')->get()->toArray()), 'no enrollment is rewritten');
    }

    #[Test]
    public function no_active_year_means_no_current_campus_and_no_candidates(): void
    {
        $student = $this->student('Here', ['current' => 'a', 'next' => 'b']);
        $this->setStatus($this->current, 'closed');

        $this->assertNull($this->resolver()->for($this->school, $student->id)->operationalCampusId(), 'never the newest or last-active year');
        $this->assertSame([], $this->lookup(CampusScope::wholeSchool()));
        $this->assertNull(app(StudentEnrollmentReadService::class)->currentStudentIdsIn($this->school, $this->grade->id, null));
    }

    #[Test]
    public function a_leaver_has_no_current_campus_but_keeps_the_placement_as_context(): void
    {
        $leaver = $this->student('Leaver', ['current' => 'a'], status: 'inactive');

        $state = $this->resolver()->for($this->school, $leaver->id);
        $this->assertTrue($state->hasCurrentPlacement());
        $this->assertSame($this->a->id, $state->placementCampusId);
        $this->assertNull($state->operationalCampusId());
        $this->assertSame([], $this->lookup(CampusScope::wholeSchool()));
        $this->assertSame([], $this->lookup(CampusScope::campuses([$this->a->id])));
        // Finance's charge-year attribution is a different question and still answers.
        $this->assertSame(['k' => $this->a->id], $this->inSchool(fn () => app(StudentEnrollmentReadService::class)->campusAttribution(['k' => [$leaver->id, $this->current->id, null]])));
    }

    #[Test]
    public function an_inactive_campus_is_not_operational_until_it_is_reactivated(): void
    {
        $student = $this->student('Here', ['current' => 'a']);
        $this->setStatus($this->a, 'inactive');

        $state = $this->resolver()->for($this->school, $student->id);
        $this->assertTrue($state->hasCurrentPlacement(), 'the enrollment is kept');
        $this->assertNull($state->operationalCampusId());
        $this->assertSame([], $this->lookup(CampusScope::wholeSchool()));

        $this->setStatus($this->a, 'active');
        $this->assertSame($this->a->id, $this->resolver()->for($this->school, $student->id)->operationalCampusId());
        $this->assertSame(['Here Pupil'], $this->lookup(CampusScope::campuses([$this->a->id])));
    }

    #[Test]
    public function the_database_allows_one_current_placement_and_one_active_year(): void
    {
        $student = $this->student('Once', ['current' => 'a']);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->inSchool(fn () => DB::transaction(fn () => $this->createStudentEnrollment($student, $this->sections['current:b'], ['status' => 'active'])));
    }

    #[Test]
    public function only_one_academic_year_can_be_active(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        $this->setStatus($this->next, 'active');
    }

    #[Test]
    public function the_purpose_lookup_matrix(): void
    {
        $this->student('Anna', ['current' => 'a']);
        $this->student('Bela', ['current' => 'b']);
        $this->student('Hist', ['prior' => 'a', 'current' => 'b']);
        $this->student('Futu', ['current' => 'b', 'next' => 'a']);
        $this->student('Gone', ['current' => 'a'], status: 'inactive');
        $this->student('Nope', ['prior' => 'a']);

        $this->assertSame(['Anna Pupil', 'Bela Pupil', 'Futu Pupil', 'Hist Pupil'], $this->lookup(CampusScope::wholeSchool()));
        $this->assertSame(['Anna Pupil'], $this->lookup(CampusScope::campuses([$this->a->id])), 'history-A and future-A Students never appear for A');
        $this->assertSame(['Bela Pupil', 'Futu Pupil', 'Hist Pupil'], $this->lookup(CampusScope::campuses([$this->b->id])));
        $this->assertSame(['Anna Pupil', 'Bela Pupil', 'Futu Pupil', 'Hist Pupil'], $this->lookup(CampusScope::campuses([$this->a->id, $this->b->id])), 'A+B is the union, each Student once');
        $this->assertSame([], $this->lookup(CampusScope::none()));
        $this->assertSame(['Hist Pupil'], $this->lookup(CampusScope::wholeSchool(), 'his'));
        $this->assertSame([], $this->lookup(CampusScope::wholeSchool(), '%'), 'LIKE wildcards are literal');
    }

    #[Test]
    public function the_lookup_returns_only_the_minimal_identity_in_one_bounded_query(): void
    {
        $anna = $this->student('Anna', ['prior' => 'a', 'current' => 'a', 'next' => 'b']);
        foreach (range(1, 8) as $i) {
            $this->student("Many{$i}", ['prior' => 'b', 'current' => 'a']);
        }

        $first = app(StudentPurposeLookup::class)->search($this->school, CampusScope::campuses([$this->a->id]), 'anna')[0];
        $this->assertSame(['id' => $anna->id, 'displayName' => 'Anna Pupil', 'studentNumber' => $anna->student_number], $first->toArray());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $all = app(StudentPurposeLookup::class)->search($this->school, CampusScope::campuses([$this->a->id]), null, 5);
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'student_enrollments') || str_contains($q['query'], '"students"')));
        DB::disableQueryLog();
        $this->assertCount(5, $all, 'bounded');
        $this->assertCount(1, $queries, 'one candidate query, never one per Student');
        $this->assertStringNotContainsString('date_of_birth', $queries[0]['query']);
    }

    #[Test]
    public function the_operational_predicate_never_changes_what_a_student_row_is(): void
    {
        // Security review (ADR 0076 §38.8): an EXISTS predicate, not a join, so
        // even a `select *` caller hydrates genuine Student rows.
        $anna = $this->student('Anna', ['current' => 'a']);
        $rows = $this->inSchool(fn () => $this->resolver()
            ->restrictToOperationalCampus(Student::query(), $this->school, CampusScope::campuses([$this->a->id]))->get());

        $this->assertSame([$anna->id], $rows->pluck('id')->all());
        $this->assertSame([$this->school->id], $rows->pluck('school_id')->all());
        $this->assertSame(['active'], $rows->pluck('status')->all());
    }

    #[Test]
    public function another_schools_students_and_enrollments_never_take_part(): void
    {
        $mine = $this->student('Mine', ['current' => 'a']);
        $other = $this->createSchool();
        $otherCampus = $this->createCampus($other);
        $otherYear = $this->createAcademicYear($other, ['code' => 'OY2627', 'name' => 'Other 2026-27', 'status' => 'active']);
        $theirs = $this->createStudent($other, ['first_name' => 'Theirs']);
        $this->createStudentEnrollment($theirs, $this->createSection($otherYear, $otherCampus, $this->createGradeLevel($other)), ['status' => 'active']);

        $this->assertSame([$mine->id], array_keys($this->resolver()->forMany($this->school, [$mine->id, $theirs->id])));
        $this->assertSame(['Mine Pupil'], $this->lookup(CampusScope::wholeSchool()));
        $this->assertSame([], $this->lookup(CampusScope::campuses([$otherCampus->id])), 'another School\'s Campus id matches nothing here');
    }

    #[Test]
    public function the_foundation_grants_no_student_authority(): void
    {
        // A principal granted for Campus A gains no Student profile or Enrollment access.
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), 'principal', $this->a->id);
        foreach (['students.view', 'students.update', 'enrollments.view'] as $capability) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$capability, $this->school]), "{$capability} School-wide");
            $this->assertTrue(app(CampusScopeResolver::class)->campusScope($user, $capability, $this->school)->isNone(), "{$capability} in a Campus");
        }
        $this->student('Anna', ['current' => 'a']);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/students')->assertForbidden();
    }
}
