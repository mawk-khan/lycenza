<?php

namespace Tests\Feature\Students\Communities;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Groups\ActivityCategory;
use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\StudentCommunityService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Application\Reports\ActivityRosterReport;
use App\Domain\Students\Application\Reports\ClubRosterReport;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\StudentReports\StudentReportRegistry;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.8: `students.clubs` and `students.activities` -- the community
 * rosters on the one shared roster query. Current members only,
 * Campus-aware rows, closed filters that only narrow, no export or print, a
 * bounded number of queries, another School never shows.
 */
class CommunityRosterReportTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    private Section $mainA;

    private Section $earlyA;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $year = $this->createAcademicYear($this->school, ['name' => 'Current', 'code' => 'AYC', 'status' => 'active', 'starts_on' => $today->subMonths(3)->toDateString(), 'ends_on' => $today->addMonths(6)->toDateString()]);
        $grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->mainA = $this->createSection($year, $this->main, $grade, ['name' => 'A', 'code' => 'A']);
        $this->earlyA = $this->createSection($year, $this->early, $grade, ['name' => 'E', 'code' => 'E']);
    }

    private function group(StudentGroupType $type, string $name, ?ActivityCategory $category = null): StudentGroup
    {
        return app(CommunityAdministrationService::class)->create($this->school, $this->admin, $type, ['name' => $name, 'code' => strtoupper(substr($name, 0, 6)), 'activity_category' => $category?->value]);
    }

    /** @param  list<StudentGroup>  $groups */
    private function member(string $first, Section $section, array $groups): Student
    {
        $number = 'S-'.(++$this->n);
        $s = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Rao', 'student_number' => $number]);
        app(StudentEnrollmentService::class)->enroll($s, $section, (string) $this->n, CarbonImmutable::parse(SchoolTimezone::today($this->school))->subMonth()->toDateString(), $this->admin);
        foreach ($groups as $g) {
            app(StudentCommunityService::class)->join($this->school, $this->admin, $s->id, $g->id);
        }

        return $s;
    }

    private function as(User $user, ?School $school = null): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    /** @return list<string> "number|group|extra" */
    private function rows(string $report, array $query = [], ?User $as = null): array
    {
        $props = $this->as($as ?? $this->admin)->get("/app/students/reports/{$report}?".http_build_query($query))->assertOk()->viewData('page')['props'];
        $extra = $report === 'students.clubs' ? 'responsibility' : 'category';
        $col = $report === 'students.clubs' ? 'club' : 'activity';

        return array_map(fn (array $r) => "{$r['student']['studentNumber']}|{$r[$col]}|{$r[$extra]}", $props['rows']['data']);
    }

    #[Test]
    public function the_rosters_are_governed_rendered_reports_without_export(): void
    {
        $this->assertContains(ClubRosterReport::class, StudentReportRegistry::RENDERED);
        $this->assertContains(ActivityRosterReport::class, StudentReportRegistry::RENDERED);
        foreach (['students.clubs', 'students.activities'] as $key) {
            $this->assertArrayNotHasKey($key, StudentReportRegistry::EXPORTERS, 'browser-only (SIS 11.8 §55 review)');
            $this->as($this->admin)->post("/app/students/reports/{$key}/export", ['format' => 'csv', 'columns' => ['student']])->assertNotFound();
            $this->as($this->admin)->get("/app/students/reports/{$key}/print?columns[]=student")->assertNotFound();
        }
        $this->assertSame(['students.communities.view'], app(ClubRosterReport::class)->definition()->authority);
        $this->assertSame(['students.communities.view'], app(ActivityRosterReport::class)->definition()->authority);
    }

    #[Test]
    public function a_student_in_several_groups_appears_once_per_group_and_filters_only_narrow(): void
    {
        $chess = $this->group(StudentGroupType::CLUB, 'Chess');
        $debate = $this->group(StudentGroupType::CLUB, 'Debate');
        $football = $this->group(StudentGroupType::ACTIVITY, 'Football', ActivityCategory::SPORTS);
        $music = $this->group(StudentGroupType::ACTIVITY, 'Music', ActivityCategory::PERFORMING_ARTS);
        $asha = $this->member('Asha', $this->mainA, [$chess, $debate, $football, $music]);
        $this->member('Ravi', $this->earlyA, [$chess, $football]);
        $membership = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('student_group_memberships')->where('student_id', $asha->id)->where('student_group_id', $chess->id)->value('id'));
        app(StudentCommunityService::class)->assignResponsibility($this->school, $this->admin, $asha->id, $membership, ClubResponsibility::PRESIDENT);

        $this->assertSame(['S-1|Chess|President', 'S-2|Chess|—', 'S-1|Debate|—'], $this->rows('students.clubs'));
        $this->assertSame(['S-1|Football|Sports', 'S-2|Football|Sports', 'S-1|Music|Performing arts'], $this->rows('students.activities'));
        $this->assertSame(['S-1|Debate|—'], $this->rows('students.clubs', ['club_id' => $debate->id]));
        $this->assertSame(['S-1|Chess|President'], $this->rows('students.clubs', ['responsibility' => 'club_president']));
        $this->assertSame(['S-1|Music|Performing arts'], $this->rows('students.activities', ['activity_category' => 'performing_arts']));
        $this->assertSame(['S-2|Chess|—'], $this->rows('students.clubs', ['campus_id' => $this->early->id]));
        $this->assertSame(['S-2|Football|Sports'], $this->rows('students.activities', ['q' => 'Ravi']));
        $this->assertSame([], $this->rows('students.clubs', ['club_id' => $football->id]), 'an Activity id is no Club');
        $this->as($this->admin)->get('/app/students/reports/students.clubs?responsibility=house_captain')->assertSessionHasErrors('responsibility');
    }

    #[Test]
    public function a_campus_reader_sees_only_in_scope_rows_and_teachers_see_nothing(): void
    {
        $chess = $this->group(StudentGroupType::CLUB, 'Chess');
        $this->member('Asha', $this->mainA, [$chess]);
        $this->member('Ravi', $this->earlyA, [$chess]);
        $campus = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campus, $this->school), 'principal', $this->main->id);

        $this->assertSame(['S-1|Chess|—'], $this->rows('students.clubs', [], $campus));
        $this->assertSame([], $this->rows('students.clubs', ['campus_id' => $this->early->id], $campus), 'a filter never widens the scope');
        $props = $this->as($campus)->get('/app/students/reports/students.clubs')->viewData('page')['props'];
        $this->assertSame(1, $props['rows']['total'], 'the page total is the in-scope total, never the School-wide one');

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $this->as($teacher)->get('/app/students/reports/students.clubs')->assertForbidden();
        $this->as($teacher)->get('/app/students/reports/students.activities')->assertForbidden();
    }

    #[Test]
    public function the_roster_and_community_pages_run_a_bounded_number_of_queries(): void
    {
        $clubs = [$this->group(StudentGroupType::CLUB, 'Chess'), $this->group(StudentGroupType::CLUB, 'Debate'), $this->group(StudentGroupType::CLUB, 'Robots')];
        $activities = [$this->group(StudentGroupType::ACTIVITY, 'Football', ActivityCategory::SPORTS), $this->group(StudentGroupType::ACTIVITY, 'Music')];
        $count = function (string $url): int {
            $this->as($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $first = $this->member('Asha', $this->mainA, [...$clubs, ...$activities]);
        $small = [$count('/app/students/reports/students.clubs'), $count('/app/students/clubs'), $count("/app/students/{$first->id}/communities"), $count("/app/students/{$first->id}")];
        foreach (range(1, 20) as $i) {
            $this->member("Member{$i}", $i % 2 === 0 ? $this->mainA : $this->earlyA, [...$clubs, ...$activities]);
        }
        $large = [$count('/app/students/reports/students.clubs'), $count('/app/students/clubs'), $count("/app/students/{$first->id}/communities"), $count("/app/students/{$first->id}")];

        foreach ($large as $i => $queries) {
            $this->assertLessThanOrEqual($small[$i], $queries, "no N+1 (page {$i}): 21 Students in 5 groups never run more queries than 1");
        }
    }

    #[Test]
    public function another_school_never_appears_on_the_rosters(): void
    {
        $chess = $this->group(StudentGroupType::CLUB, 'Chess');
        $this->member('Asha', $this->mainA, [$chess]);
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        app(CommunityAdministrationService::class)->create($other, $otherAdmin, StudentGroupType::CLUB, ['name' => 'Chess', 'code' => 'CHESS']);

        $this->as($otherAdmin, $other);
        $props = $this->get('/app/students/reports/students.clubs?club_id='.$chess->id)->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['rows']['data']);
        $this->assertSame(['Chess'], array_column(collect($props['filters'])->firstWhere('key', 'club_id')['options'], 'label'), 'only the School\'s own Clubs are offered');
    }
}
