<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.EXP-2: the School-wide Student movement and exit register -- a
 * read-only projection of the canonical history (placements, executed
 * rollovers, lifecycle events), one row per canonical fact, whole-School
 * only, with closed reason codes and no free text. Every fixture history is
 * written through the real services (the database refuses invented states).
 */
class StudentMovementRegisterTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    private AcademicYear $year;

    private GradeLevel $g5;

    /** @var array<string, Section> */
    private array $sections = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->sections['5A'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A']);
        $this->sections['5B'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'B', 'code' => 'B']);
        $this->sections['5E'] = $this->createSection($this->year, $this->early, $this->g5, ['name' => 'E', 'code' => 'E']);
    }

    private function placements(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function student(string $first, string $number): Student
    {
        return $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Test', 'student_number' => $number]);
    }

    private function current(Student $student): StudentEnrollment
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->firstOrFail());
    }

    /** @return array<string, mixed> */
    private function props(array $query = [], ?User $as = null): array
    {
        $this->actingAs($as ?? $this->admin)->post("/app/schools/{$this->school->id}/activate");

        return $this->get('/app/students/movements?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> "type|student|from section|to section" per row, in page order */
    private function rows(array $query = [], ?User $as = null): array
    {
        return array_map(
            fn (array $r) => "{$r['type']}|{$r['student']['studentNumber']}|".($r['from']['section'] ?? '-').'|'.($r['to']['section'] ?? '-'),
            $this->props($query, $as)['movements']['data'],
        );
    }

    /** A Student moved twice in one year: A -> B (Section), B -> Early Years E (Campus). */
    private function mover(): Student
    {
        $s = $this->student('Mover', 'M-1');
        $this->placements()->enroll($s, $this->sections['5A'], '1', '2026-06-01', $this->admin);
        $this->placements()->transferPlacement($this->current($s), $this->sections['5B'], '1', '2026-08-01', $this->admin);
        $this->placements()->transferPlacement($this->current($s), $this->sections['5E'], '1', '2026-09-01', $this->admin);

        return $s;
    }

    /** Enrolled, withdrawn (left the School) and marked inactive. */
    private function leaver(): Student
    {
        $s = $this->student('Leaver', 'L-1');
        $this->placements()->enroll($s, $this->sections['5A'], '2', '2026-06-01', $this->admin);
        $this->placements()->withdraw($this->current($s), '2026-09-15', 'left_school', $this->admin, $this->freshProof($this->admin));
        app(StudentService::class)->changeStatus($s, 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));

        return $s;
    }

    #[Test]
    public function entry_internal_movement_and_exit_are_projected_once_each(): void
    {
        $this->mover();
        $this->leaver();

        $rows = $this->rows(['sort' => 'date_asc']);
        $this->assertSame([
            'initial_enrollment|M-1|-|A',
            'initial_enrollment|L-1|-|A',
            'section_change|M-1|A|B',
            'campus_transfer|M-1|B|E',
            'withdrawal|L-1|A|-',
            'student_deactivated|L-1|-|-',
        ], $rows, 'a transfer pair is ONE row and its destination is never also an entry; the withdrawal\'s own lifecycle event is its reason, not a row');

        $exit = collect($this->props(['type' => 'withdrawal'])['movements']['data'])->first();
        $this->assertSame(['exit', '2026-09-15', 'left_school', 'Main', 'withdrawn'], [$exit['category'], $exit['effectiveDate'], $exit['reasonCode'], $exit['from']['campus'], $exit['from']['status']]);
        $transfer = collect($this->props(['type' => 'campus_transfer'])['movements']['data'])->first();
        $this->assertSame(['2026-09-01', 'Main', 'Early Years', 'transferred', 'active'], [$transfer['effectiveDate'], $transfer['from']['campus'], $transfer['to']['campus'], $transfer['from']['status'], $transfer['to']['status']]);
    }

    #[Test]
    public function a_return_is_a_reactivation_and_a_re_enrollment(): void
    {
        $s = $this->leaver();
        app(StudentService::class)->changeStatus($s, 'active', 'returned_to_school', $this->admin, $this->freshProof($this->admin));
        $this->placements()->enroll($s, $this->sections['5B'], '9', '2026-10-01', $this->admin);

        $rows = $this->rows(['category' => 'entry', 'sort' => 'date_asc']);
        $this->assertSame(['initial_enrollment|L-1|-|A', 're_enrollment|L-1|-|B', 'student_reactivated|L-1|-|-'], $rows);
        $this->assertSame('returned_to_school', collect($this->props(['type' => 'student_reactivated'])['movements']['data'])->first()['reasonCode']);
    }

    #[Test]
    public function promotion_and_retention_come_from_executed_rollovers_and_fold_the_completed_source(): void
    {
        // A past year (completion happens on its last day, once that day has come) rolled into a draft next year.
        $past = $this->createAcademicYear($this->school, ['name' => '2025-26', 'code' => 'AY25', 'starts_on' => '2025-06-01', 'ends_on' => '2026-04-30']);
        $next = $this->createAcademicYear($this->school, ['name' => '2027-28', 'code' => 'AY27', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $g6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $pastA = $this->createSection($past, $this->main, $this->g5, ['name' => 'PA', 'code' => 'PA']);
        $pastB = $this->createSection($past, $this->main, $this->g5, ['name' => 'PB', 'code' => 'PB']);
        $sixA = $this->createSection($next, $this->main, $g6, ['name' => '6A', 'code' => '6A']);
        $fiveR = $this->createSection($next, $this->main, $this->g5, ['name' => '5R', 'code' => '5R']);

        $promoted = $this->student('Promoted', 'P-1');
        $this->placements()->enroll($promoted, $pastA, '3', '2025-06-01', $this->admin);
        $repeater = $this->student('Repeater', 'R-1');
        $this->placements()->enroll($repeater, $pastB, '4', '2025-06-01', $this->admin);
        $done = $this->student('Done', 'D-1');
        $this->placements()->enroll($done, $pastA, '5', '2025-06-01', $this->admin);

        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $this->createEnrollmentRolloverPlan($past, $next);
        $fresh = fn () => app(TenantContext::class)->withSchool($this->school, fn () => $plan->fresh());
        $plans->upsertMapping($fresh(), $this->g5, null, $g6, $sixA);
        // The repeater's Section maps to the same Grade next year (a repeat keeps the Grade).
        $plans->upsertMapping($fresh(), $this->g5, $pastB, $this->g5, $fiveR);
        app(EnrollmentRolloverDryRunService::class)->run($fresh());
        $item = fn (Student $s) => app(TenantContext::class)->withSchool($this->school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $s->id)->firstOrFail());
        $plans->setItemDecision($fresh(), $item($promoted), 'promote', null, 'explicit', '11');
        $plans->setItemDecision($fresh(), $item($repeater), 'repeat', null, 'explicit', '12');
        $plans->setItemDecision($fresh(), $item($done), 'exclude');
        $this->assertTrue(app(EnrollmentRolloverDryRunService::class)->run($fresh())['validated']);
        foreach ([$promoted, $repeater] as $s) {
            app(EnrollmentRolloverItemExecutionService::class)->execute($item($s));
        }
        // Year end: every 2025-26 placement completes on its last day.
        foreach ([$promoted, $repeater, $done] as $s) {
            $source = app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $s->id)->where('academic_year_id', $past->id)->firstOrFail());
            $this->placements()->complete($source, '2026-04-30', $this->admin);
        }

        $this->assertSame(['promotion|P-1|PA|6A', 'retention|R-1|PB|5R'], $this->rows(['category' => 'movement', 'sort' => 'type']));
        $this->assertSame(['placement_completed|D-1|PA|-'], $this->rows(['category' => 'exit']), 'a completion the rollover continued is folded into its promotion / retention row');
        $this->assertSame(['initial_enrollment|D-1|-|PA', 'initial_enrollment|P-1|-|PA', 'initial_enrollment|R-1|-|PB'], $this->rows(['category' => 'entry', 'sort' => 'student']), 'a rollover target is never also an entry');
        $promotion = collect($this->props(['type' => 'promotion'])['movements']['data'])->first();
        $this->assertSame(['2025-26', '2027-28', '2027-06-01', 'completed'], [$promotion['from']['academicYear'], $promotion['to']['academicYear'], $promotion['effectiveDate'], $promotion['from']['status']]);
        $this->assertSame(['promotion|P-1|PA|6A', 'retention|R-1|PB|5R'], $this->rows(['academic_year_id' => $next->id, 'sort' => 'type']), 'a rollover row belongs to the target year');
    }

    #[Test]
    public function filters_narrow_by_date_year_campus_grade_section_type_category_and_search(): void
    {
        $this->mover();
        $this->leaver();

        $this->assertSame(['campus_transfer|M-1|B|E'], $this->rows(['campus_id' => $this->early->id]), 'either end of the movement matches a Campus');
        $this->assertSame(['section_change|M-1|A|B', 'campus_transfer|M-1|B|E'], $this->rows(['section_id' => $this->sections['5B']->id, 'sort' => 'date_asc']));
        $this->assertCount(5, $this->rows(['grade_level_id' => $this->g5->id]), 'every placement row is Grade 5; the Student-status row has no placement');
        $this->assertSame(['withdrawal|L-1|A|-', 'campus_transfer|M-1|B|E'], $this->rows(['from' => '2026-09-01', 'to' => '2026-09-30']));
        $this->assertSame(['withdrawal|L-1|A|-', 'student_deactivated|L-1|-|-'], $this->rows(['category' => 'exit', 'sort' => 'date_asc']));
        $this->assertSame(['campus_transfer|M-1|B|E', 'section_change|M-1|A|B', 'initial_enrollment|M-1|-|A'], $this->rows(['q' => 'M-1']));
        $this->assertCount(6, $this->rows(['academic_year_id' => $this->year->id]), 'the Student-status row falls in the year by its date');
        // Combined.
        $this->assertSame(['campus_transfer|M-1|B|E'], $this->rows(['from' => '2026-06-01', 'to' => '2026-12-31', 'campus_id' => $this->early->id, 'section_id' => $this->sections['5E']->id, 'type' => 'campus_transfer']));
    }

    #[Test]
    public function forged_and_foreign_ids_match_nothing_and_another_schools_history_never_appears(): void
    {
        $this->mover();
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $otherCampus = $this->createCampus($other);
        $otherSection = $this->createSection($this->createAcademicYear($other, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']), $otherCampus, $this->createGradeLevel($other));
        $stranger = $this->createStudent($other, ['student_number' => 'X-1']);
        app(StudentEnrollmentService::class)->enroll($stranger, $otherSection, '1', '2026-06-01', $otherAdmin);

        $this->assertNotContains('X-1', array_column(array_column($this->props()['movements']['data'], 'student'), 'studentNumber'));
        $this->assertSame([], $this->rows(['campus_id' => $otherCampus->id]));
        $this->assertSame([], $this->rows(['section_id' => $otherSection->id]));
        $this->assertSame([], $this->rows(['academic_year_id' => $otherSection->academic_year_id]));
        $this->get('/app/students/movements?campus_id=not-a-uuid')->assertSessionHasErrors('campus_id');
        $this->get('/app/students/movements?sort=st.first_name')->assertSessionHasErrors('sort');
    }

    #[Test]
    public function it_is_whole_school_only_and_refuses_campus_teacher_guardian_platform_and_network_actors(): void
    {
        $this->mover();
        $user = function (array $capabilities, ?string $campusId = null): User {
            $u = $this->createUser();
            LocalCatalogueFixtures::grantRole($this->createMembership($u, $this->school), $this->createFixtureRole($capabilities), $campusId);

            return $u;
        };
        $refused = function (User $u): void {
            $this->actingAs($u)->post("/app/schools/{$this->school->id}/activate");
            $this->assertNotSame(200, $this->get('/app/students/movements')->status());
        };

        // A Campus-only holder: history is whole-School (rule 101), refused by the route floor.
        $refused($user(['students.view', 'enrollments.view'], $this->early->id));
        // A teacher holds attendance.teacher only.
        $refused($user(['attendance.teacher']));
        // students.view alone is not placement history.
        $refused($user(['students.view']));
        // A platform root with no School membership, and a Guardian persona with portal capabilities only.
        $this->actingAs($this->createPlatformRoot());
        $this->assertNotSame(200, $this->get('/app/students/movements')->status());

        // enrollments.view WITHOUT students.view: placement rows only, no Student-status rows, no profile link.
        $this->leaver();
        $props = $this->props([], $user(['enrollments.view']));
        $this->assertFalse($props['canOpenStudent']);
        $this->assertNotContains('student_deactivated', array_column($props['movements']['data'], 'type'));
        $this->assertNotContains('student_deactivated', array_column($props['options']['types'], 'value'));
        $this->actingAs($user(['enrollments.view']))->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/students/movements?type=student_deactivated')->assertSessionHasErrors('type');
    }

    #[Test]
    public function the_payload_carries_no_free_text_contact_identifier_or_actor(): void
    {
        $this->leaver();
        $props = $this->props();
        $json = json_encode($props['movements']['data']);
        foreach (['dateOfBirth', 'date_of_birth', 'guardian', 'contact', 'actor', 'acted_by', 'email', 'note'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, (string) $json);
        }
        $this->assertSame(['id', 'effectiveDate', 'category', 'type', 'typeLabel', 'student', 'from', 'to', 'reasonCode'], array_keys($props['movements']['data'][0]));
        $this->assertSame(['id', 'name', 'studentNumber'], array_keys($props['movements']['data'][0]['student']));
    }

    #[Test]
    public function pagination_and_sorting_are_server_side_and_the_query_count_does_not_grow(): void
    {
        $count = function (array $query = []): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/students/movements?'.http_build_query($query))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->mover();
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");
        $count();
        $small = $count();
        $smallFiltered = $count(['from' => '2026-06-01', 'to' => '2026-12-31', 'campus_id' => $this->main->id, 'section_id' => $this->sections['5B']->id, 'type' => 'section_change']);

        for ($i = 0; $i < 40; $i++) {
            $s = $this->student("Bulk{$i}", sprintf('B-%03d', $i));
            $this->placements()->enroll($s, $this->sections[$i % 2 === 0 ? '5A' : '5B'], (string) (100 + $i), '2026-06-01', $this->admin);
            $this->placements()->transferPlacement($this->current($s), $this->sections[$i % 2 === 0 ? '5B' : '5A'], (string) (100 + $i), '2026-07-01', $this->admin);
        }

        $this->assertSame($small, $count(), 'page 1');
        $this->assertSame($small, $count(['page' => 3]), 'a later page');
        $this->assertSame($smallFiltered, $count(['from' => '2026-06-01', 'to' => '2026-12-31', 'campus_id' => $this->main->id, 'section_id' => $this->sections['5B']->id, 'type' => 'section_change']));

        $props = $this->props(['page' => 2]);
        $this->assertSame(83, $props['movements']['total'], '3 for the mover + 2 for each of 40 Students');
        $this->assertCount(25, $props['movements']['data']);
        $byName = array_column(array_column($this->props(['sort' => 'student'])['movements']['data'], 'student'), 'name');
        $this->assertSame('Bulk0 Test', $byName[0]);
        $byDate = array_column($this->props(['sort' => 'date_desc'])['movements']['data'], 'effectiveDate');
        $sorted = $byDate;
        rsort($sorted);
        $this->assertSame($sorted, $byDate, 'newest first by default');
    }

    #[Test]
    public function a_student_link_leads_to_the_profile_which_re_checks_its_own_authority(): void
    {
        $mover = $this->mover();
        $props = $this->props();
        $this->assertTrue($props['canOpenStudent']);
        $this->get("/app/students/{$mover->id}")->assertOk();

        // A placement-only reader sees no link, and the profile refuses them.
        $u = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($u, $this->school), $this->createFixtureRole(['enrollments.view']));
        $this->assertFalse($this->props([], $u)['canOpenStudent']);
        $this->get("/app/students/{$mover->id}")->assertForbidden();
    }

    #[Test]
    public function a_transfer_whose_destination_was_cancelled_keeps_its_type_and_lists_no_entry(): void
    {
        // A Campus transfer dated in the future, whose destination is then
        // cancelled before it starts (did not join) -- SIS 4.2's empty interval.
        $s = $this->student('Planned', 'C-1');
        $this->placements()->enroll($s, $this->sections['5A'], '7', '2026-06-01', $this->admin);
        $future = now()->addDays(10)->toDateString();
        $destination = $this->placements()->transferPlacement($this->current($s), $this->sections['5E'], '7', $future, $this->admin);
        $this->placements()->cancelBeforeStart($destination, 'did_not_join', $this->admin, $this->freshProof($this->admin));

        $transfer = collect($this->props(['type' => 'campus_transfer'])['movements']['data'])->first();
        $this->assertNotNull($transfer, 'still a Campus transfer, not a Section change');
        $this->assertSame(['Main', 'Early Years', 'cancelled'], [$transfer['from']['campus'], $transfer['to']['campus'], $transfer['to']['status']]);
        $this->assertSame(['initial_enrollment|C-1|-|A'], $this->rows(['category' => 'entry']), 'the cancelled destination is never an entry');
    }
}
