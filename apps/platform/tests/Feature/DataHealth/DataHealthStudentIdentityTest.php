<?php

namespace Tests\Feature\DataHealth;

use App\Domain\Students\Application\DataHealth\PossibleDuplicateStudentsCheck;
use App\Domain\Students\Application\StudentDuplicateCandidates;
use App\Domain\Students\Application\StudentService;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\DataHealth\DataHealthRegistry;
use App\Support\DataHealth\DataHealthService;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Students\StudentDuplicateCandidatesTest;
use Tests\TestCase;

/**
 * SIS 8.1: Student identity checks.
 *
 * - `student.possible_duplicate` is the SIS 2.3 rule itself
 *   (StudentDuplicateCandidates), grouped: one finding per connected group,
 *   inactive Students included, never another School, never a birthday shown.
 * - `student.number_case_collision` flags numbers equal apart from letter
 *   case (searches fold case, uniqueness and exact matches do not).
 * - Both are live: a corrected record stops being reported.
 */
class DataHealthStudentIdentityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function viewer(School $school, array $extra = []): User
    {
        return $this->createUserWithCapabilities($school, ['students.view', 'enrollments.view', ...$extra]);
    }

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    private function healthOf(School $school, string $code, ?User $viewer = null, int $page = 1): array
    {
        $findings = app(DataHealthService::class)->findings($school, $code, $viewer ?? $this->viewer($school), $page)['findings'];

        return ['total' => $findings->total(), 'rows' => $findings->items()];
    }

    private function student(School $school, string $number, string $first, ?string $last, string $birthday = '2016-03-04', array $extra = []): mixed
    {
        return $this->createStudent($school, ['student_number' => $number, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'date_of_birth' => $birthday, ...$extra]);
    }

    #[Test]
    public function possible_duplicates_are_one_finding_per_connected_group_inactive_included_and_never_show_a_birthday(): void
    {
        $school = $this->createSchool();
        // a~b (same first + last), a~c (same full name); b and c do not match each other directly.
        $a = $this->student($school, 'A-1', 'Riya', 'Kapoor', extra: ['middle_name' => 'Devi']);
        $b = $this->student($school, 'B-2', 'RIYA', 'kapoor', extra: ['status' => 'inactive']);
        $c = $this->student($school, 'C-3', 'Riya Devi', 'Kapoor');
        $d = $this->student($school, 'D-4', 'Anne-Marie', "D'Souza", '2015-01-02');
        $e = $this->student($school, 'E-5', 'Anne Marie', 'DSouza', '2015-01-02');
        // Not duplicates: another birthday, twins with different first names, another School.
        $this->student($school, 'F-6', 'Riya', 'Kapoor', '2016-03-05');
        $this->student($school, 'G-7', 'Aarav', 'Shah', '2015-01-02');
        $this->student($school, 'H-8', 'Vihaan', 'Shah', '2015-01-02');
        $this->student($this->createSchool(), 'A-1', 'Riya', 'Kapoor');

        $result = $this->healthOf($school, 'student.possible_duplicate');

        $this->assertSame(2, $result['total'], 'groups, not pairs and not Students');
        $this->assertSame([$a->id, $d->id], array_column($result['rows'], 'entityId'), 'ordered by first Student number');
        $group = $result['rows'][0];
        $this->assertSame('Riya Devi Kapoor (A-1) · RIYA kapoor (B-2) · Riya Devi Kapoor (C-3)', $group['label']);
        $this->assertStringContainsString('3 records with the same date of birth', $group['detail']);
        $this->assertStringContainsString('B-2: Inactive', $group['detail']);
        $this->assertStringNotContainsString('2016', $group['detail'].$group['label'], 'the date of birth is never shown (ADR 0075 §10.4)');
        $this->assertSame(["/app/students/{$a->id}", "/app/students/{$b->id}", "/app/students/{$c->id}"], array_column($group['links'], 'url'));
        $this->assertSame([$d->id, $e->id], array_map(fn ($s) => $s->id, app(TenantContext::class)->withSchool($school, fn () => app(StudentDuplicateCandidates::class)->groupsIn($school))[1]));
    }

    /**
     * Data Health and creation can never disagree: every name case of the
     * SIS 2.3 rule's own test produces a group exactly when matches() does.
     *
     * @param  array<string, string|null>  $first
     * @param  array<string, string|null>  $second
     */
    #[Test]
    #[DataProviderExternal(StudentDuplicateCandidatesTest::class, 'names')]
    public function the_check_applies_exactly_the_sis_2_3_rule(array $first, array $second, bool $expected): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, [...$first, 'student_number' => 'X-1', 'date_of_birth' => '2016-03-04']);
        $this->createStudent($school, [...$second, 'student_number' => 'X-2', 'date_of_birth' => '2016-03-04']);

        $this->assertSame($expected ? 1 : 0, $this->healthOf($school, 'student.possible_duplicate')['total']);
    }

    #[Test]
    public function the_check_reuses_the_one_evaluator_and_has_no_name_rule_of_its_own(): void
    {
        $check = (string) file_get_contents((new \ReflectionClass(PossibleDuplicateStudentsCheck::class))->getFileName());
        $this->assertStringContainsString('->groupsIn($school)', $check);
        foreach (['preg_replace', 'mb_strtolower', 'strtolower', 'lower(', 'similar_text', 'levenshtein', 'soundex', 'metaphone'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $check, "no second matching rule ({$forbidden})");
        }

        $method = new ReflectionMethod(StudentDuplicateCandidates::class, 'link');
        $body = implode('', array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $this->assertStringContainsString('self::matches(', $body, 'grouping uses the creation rule');
    }

    #[Test]
    public function correcting_a_record_in_its_own_workflow_clears_the_finding_live(): void
    {
        $school = $this->createSchool();
        $this->student($school, 'A-1', 'Riya', 'Kapoor');
        $typo = $this->student($school, 'A-2', 'Riya', 'Kapoor');
        $this->assertSame(1, $this->healthOf($school, 'student.possible_duplicate')['total']);

        // SIS.EXP-1: the update re-reads the actor's current students.manage under the lock.
        app(StudentService::class)->update($typo, ['first_name' => 'Rhea'], $this->createUserWithCapabilities($school, ['students.manage']));

        $this->assertSame(0, $this->healthOf($school, 'student.possible_duplicate')['total']);
    }

    #[Test]
    public function student_numbers_equal_apart_from_letter_case_are_flagged_per_number(): void
    {
        $school = $this->createSchool();
        $lower = $this->student($school, 'ab-1', 'Ira', 'Mehta', '2014-01-01');
        $upper = $this->student($school, 'AB-1', 'Dev', 'Rao', '2014-02-02');
        $this->student($school, 'AB-2', 'Kai', 'Sen', '2014-03-03');
        $this->student($school, '0041', 'Om', 'Das', '2014-04-04');
        $other = $this->createSchool();
        $this->student($other, 'xy-9', 'A', 'B');
        $this->student($other, 'XY-9', 'C', 'D');
        $this->student($other, 'ab-2', 'E', 'F');

        $manager = $this->viewer($school, ['students.manage']);
        $result = $this->healthOf($school, 'student.number_case_collision', $manager);

        $this->assertSame(1, $result['total'], 'another School never adds to or joins a group');
        // Order within a group follows the database collation; the content does not.
        $this->assertEqualsCanonicalizing(['Dev Rao (AB-1)', 'Ira Mehta (ab-1)'], explode(' · ', $result['rows'][0]['label']));
        $this->assertStringContainsString('2 Student numbers that differ only in letter case', $result['rows'][0]['detail']);
        $this->assertEqualsCanonicalizing(
            ["/app/students/{$upper->id}", "/app/students/{$upper->id}/edit", "/app/students/{$lower->id}", "/app/students/{$lower->id}/edit"],
            array_column($result['rows'][0]['links'], 'url'),
        );
        $this->assertEqualsCanonicalizing(
            ["/app/students/{$upper->id}", "/app/students/{$lower->id}"],
            array_column($this->healthOf($school, 'student.number_case_collision')['rows'][0]['links'], 'url'),
            'the Edit link only for students.manage',
        );

        app(StudentService::class)->update($lower, ['student_number' => 'AB-1-OLD'], $manager);
        $this->assertSame(0, $this->healthOf($school, 'student.number_case_collision')['total']);
    }

    #[Test]
    public function every_check_only_reads(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'status' => 'active']);
        $this->student($school, 'A-1', 'Riya', 'Kapoor');
        $this->student($school, 'a-1', 'Riya', 'Kapoor');
        $viewer = $this->createUserWithCapabilities($school, [
            'students.view', 'enrollments.view', 'academics.years.view', 'academics.structure.view', 'timetable.schedule.view',
            'teaching.assignments.view', 'attendance.view', 'finance.charges.view',
        ]);
        $health = app(DataHealthService::class);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $health->overview($school, $viewer);
        foreach (array_keys(app(DataHealthRegistry::class)->all()) as $code) {
            $health->findings($school, $code, $viewer, 1);
        }

        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(select|with)\b|^RESET app\.current_school_id$/i', $sql) !== 1);
        $this->assertNotEmpty($statements);
        $this->assertSame([], array_values($writes), 'Data Health never writes, locks or stores a finding');
        foreach ($statements as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\bfor (update|share|no key update)\b/i', $sql);
        }
    }

    #[Test]
    public function the_duplicate_finding_page_costs_the_same_queries_for_two_or_six_groups(): void
    {
        $count = function (int $groups): int {
            $school = $this->createSchool();
            $year = $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'status' => 'active']);
            $section = $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school));
            for ($i = 0; $i < $groups; $i++) {
                foreach ([1, 2] as $n) {
                    $s = $this->student($school, "G{$i}-{$n}", "Name{$i}", 'Same', sprintf('2016-01-%02d', $i + 1));
                    $this->createStudentEnrollment($s, $section, ['status' => 'active']);
                }
            }
            $viewer = $this->viewer($school);
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(DataHealthService::class)->findings($school, 'student.possible_duplicate', $viewer, 1);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(6));
    }

    #[Test]
    public function a_campus_grant_of_every_check_key_never_opens_data_health(): void
    {
        // Rule 101: Data Health is a whole-School report. Its checks read
        // grants through canInSchool(), so a Campus grant -- even of every key
        // the new checks need -- reveals no check, finding or count.
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $this->student($school, 'A-1', 'Riya', 'Kapoor');
        $this->student($school, 'A-2', 'Riya', 'Kapoor');
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_role_'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['students.view', 'enrollments.view', 'attendance.view', 'finance.charges.view']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id, 'campus_id' => $campus->id,
        ])));

        $this->assertSame([], app(DataHealthService::class)->visibleChecks($school, $user));
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/data-health')->assertForbidden();
        $this->get('/app/data-health/student.possible_duplicate')->assertForbidden();
    }
}
