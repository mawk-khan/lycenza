<?php

namespace Tests\Feature\StudentReports;

use App\Domain\AcademicStructure\Application\SectionAdministrationService;
use App\Domain\AcademicStructure\Application\SectionVersion;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\RolloverCapacityAdvisory;
use App\Domain\Students\Application\SectionOccupancy;
use App\Domain\Students\Application\SectionOccupancyReader;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\DataHealth\DataHealthRegistry;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.3 (OD-SIS-6): advisory Section capacity against operational
 * strength. Capacity is Academic Structure's `sections.capacity` (>= 1 or
 * NULL, frozen in a closed year); strength is the ONE placement predicate
 * on the School-local today clamped into the year; nothing refuses a
 * placement into a full Section; the report is Campus-aware, isolated and
 * bounded; the rollover warning never blocks.
 */
class SectionCapacityTest extends TestCase
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

    private int $roll = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-15 09:00:00');
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'M']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->sections['A'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A', 'capacity' => 3]);
        $this->sections['B'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'B', 'code' => 'B', 'capacity' => 2]);
        $this->sections['E'] = $this->createSection($this->year, $this->early, $this->g5, ['name' => 'E', 'code' => 'E', 'capacity' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function placements(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function place(string $name, string $section, string $startsOn = '2026-06-01'): StudentEnrollment
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Pupil', 'student_number' => 'S-'.$name]);

        return $this->placements()->enroll($student, $this->sections[$section], (string) ++$this->roll, $startsOn, $this->admin);
    }

    /** @return array<string, SectionOccupancy> */
    private function occupancy(): array
    {
        $sections = app(TenantContext::class)->withSchool($this->school, fn () => Section::query()->get());

        return collect(app(SectionOccupancyReader::class)->forSections($this->school, $sections))
            ->mapWithKeys(fn (SectionOccupancy $o, string $id) => [$sections->firstWhere('id', $id)->name => $o])->all();
    }

    private function strength(string $section): ?int
    {
        return $this->occupancy()[$section]->strength;
    }

    /** SIS 11.H1: the current SectionVersion a fresh edit form would carry. */
    private function sectionVersion(string $sectionId): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => SectionVersion::of(Section::query()->findOrFail($sectionId)));
    }

    #[Test]
    public function capacity_is_null_or_at_least_one_in_the_service_and_the_database(): void
    {
        $service = app(SectionAdministrationService::class);
        foreach ([0, -3] as $bad) {
            try {
                $service->update($this->school, $this->sections['A']->id, ['capacity' => $bad, 'version' => $this->sectionVersion($this->sections['A']->id)], $this->admin);
                $this->fail("capacity {$bad} must be refused");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($service->update($this->school, $this->sections['A']->id, ['capacity' => null, 'version' => $this->sectionVersion($this->sections['A']->id)], $this->admin)->capacity, 'NULL = not configured');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sections_capacity_positive_check');
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('sections')->where('id', $this->sections['B']->id)->update(['capacity' => 0]));
    }

    #[Test]
    public function capacity_may_drop_below_strength_is_audited_and_never_moves_a_student(): void
    {
        foreach (['Asha', 'Bilal', 'Chen'] as $n) {
            $this->place($n, 'A');
        }
        app(SectionAdministrationService::class)->update($this->school, $this->sections['A']->id, ['capacity' => 2, 'version' => app(TenantContext::class)->withSchool($this->school, fn () => SectionVersion::of(Section::query()->findOrFail($this->sections['A']->id)))], $this->admin);

        $a = $this->occupancy()['A'];
        $this->assertSame([3, 2, -1, SectionOccupancy::OVER_CAPACITY, '3 / 2 · Over advisory capacity by 1'], [$a->strength, $a->capacity, $a->available(), $a->state(), $a->label()]);
        $audit = app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'section.updated')->where('subject_id', $this->sections['A']->id)->latest('occurred_at')->firstOrFail());
        $this->assertSame([['capacity' => 3], ['capacity' => 2], $this->admin->id], [$audit->metadata['before'], $audit->metadata['after'], $audit->actor_user_id]);
    }

    #[Test]
    public function a_closed_year_s_capacity_is_history(): void
    {
        $old = $this->createAcademicYear($this->school, ['name' => '2025-26', 'code' => 'AY25', 'status' => 'closed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-04-30']);
        $section = $this->createSection($old, $this->main, $this->g5, ['name' => 'Old', 'code' => 'OLD', 'capacity' => 30]);
        $service = app(SectionAdministrationService::class);

        try {
            $service->update($this->school, $section->id, ['capacity' => 25, 'version' => $this->sectionVersion($section->id)], $this->admin);
            $this->fail('closed-year capacity must be refused');
        } catch (ValidationException $e) {
            $this->assertSame([SectionAdministrationService::CLOSED_YEAR_CAPACITY], $e->errors()['capacity']);
        }
        $this->assertSame('Old room', $service->update($this->school, $section->id, ['name' => 'Old room', 'capacity' => 30, 'version' => $this->sectionVersion($section->id)], $this->admin)->name, 'an unchanged capacity is not a change');
        $this->assertSame(SectionOccupancy::CLOSED_YEAR, $this->occupancy()['Old room']->state());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sections_capacity_closed_year_guard');
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('sections')->where('id', $section->id)->update(['capacity' => 10]));
    }

    #[Test]
    public function strength_follows_the_placement_interval_on_the_school_local_today(): void
    {
        $this->place('Current', 'A');
        $this->place('Future', 'A', '2026-11-01');                                   // not started yet
        $ended = $this->place('Ended', 'A');
        $this->placements()->withdraw($ended, '2026-09-30', 'left_school', $this->admin, $this->freshProof($this->admin));
        $cancelled = $this->place('NeverCame', 'A', '2026-10-20');
        $this->placements()->cancelBeforeStart($cancelled, 'did_not_join', $this->admin, $this->freshProof($this->admin));
        $this->assertSame(1, $this->strength('A'), 'only the current placement counts today');

        // A Section change effective today: the Student counts in the destination only.
        $mover = $this->place('Mover', 'A');
        $this->placements()->transferPlacement($mover, $this->sections['B'], (string) ++$this->roll, '2026-10-15', $this->admin);
        $this->assertSame([1, 1], [$this->strength('A'), $this->strength('B')], 'never counted twice');

        // A Student who left and re-joined the same Section counts once.
        $back = $this->place('Back', 'B');
        $this->placements()->withdraw($back, '2026-07-31', 'left_school', $this->admin, $this->freshProof($this->admin));
        $this->placements()->enroll(app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->findOrFail($back->student_id)), $this->sections['B'], (string) ++$this->roll, '2026-09-01', $this->admin);
        $this->assertSame(2, $this->strength('B'));

        CarbonImmutable::setTestNow('2026-11-02 09:00:00');
        $this->assertSame(2, $this->strength('A'), 'the future placement counts from its start date');
    }

    #[Test]
    public function a_draft_year_counts_its_first_day_and_states_read_plainly(): void
    {
        $next = $this->createAcademicYear($this->school, ['name' => '2027-28', 'code' => 'AY27', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $this->sections['N'] = $this->createSection($next, $this->main, $this->g5, ['name' => 'N', 'code' => 'N', 'capacity' => 1]);
        $this->place('Planned', 'N', '2027-06-01');

        $n = $this->occupancy()['N'];
        $this->assertSame(['2027-06-01', 1, SectionOccupancy::AT_CAPACITY, '1 / 1 · At advisory capacity'], [$n->asOf, $n->strength, $n->state(), $n->label()]);

        $this->place('One', 'B');
        $occupancy = $this->occupancy();
        $this->assertSame('1 / 2 · 1 seat available', $occupancy['B']->label());
        $this->assertSame('0 Students · Capacity not configured', $occupancy['E']->label());
        $this->assertSame('2026-10-15', $occupancy['B']->asOf);
    }

    #[Test]
    public function a_full_section_still_accepts_a_valid_placement(): void
    {
        $this->place('One', 'B');
        $this->place('Two', 'B');
        $this->assertSame(SectionOccupancy::AT_CAPACITY, $this->occupancy()['B']->state());

        $this->place('Three', 'B');
        $this->assertSame('3 / 2 · Over advisory capacity by 1', $this->occupancy()['B']->label());
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null): array
    {
        app('auth')->forgetGuards();
        $this->actingAs($as ?? $this->admin)->post("/app/schools/{$this->school->id}/activate");

        return $this->get('/app/students/reports/students.section_capacity?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> "section|occupancy|seats" */
    private function rows(array $query = [], ?User $as = null): array
    {
        return array_map(fn (array $r) => "{$r['section']['name']}|{$r['occupancy']}|{$r['seats']}", $this->report($query, $as)['rows']['data']);
    }

    #[Test]
    public function the_report_lists_operational_occupancy_with_closed_filters_and_sorts(): void
    {
        $this->place('One', 'B');
        $this->place('Two', 'B');
        $this->place('Three', 'B');
        $this->place('Four', 'A');

        $this->assertSame(['Grade 5 · A|1 / 3|2 seats available', 'Grade 5 · B|3 / 2|Over advisory capacity by 1', 'Grade 5 · E|0 Students|Capacity not configured'], $this->rows());
        $this->assertSame(['Grade 5 · B|3 / 2|Over advisory capacity by 1'], $this->rows(['status' => 'over_capacity']));
        $this->assertSame(['Grade 5 · E|0 Students|Capacity not configured'], $this->rows(['status' => 'unconfigured']));
        $this->assertSame(['Grade 5 · E|0 Students|Capacity not configured'], $this->rows(['campus_id' => $this->early->id]));
        $this->assertSame('Grade 5 · B|3 / 2|Over advisory capacity by 1', $this->rows(['sort' => 'available'])[0]);
        $this->assertSame('Grade 5 · B|3 / 2|Over advisory capacity by 1', $this->rows(['sort' => 'strength'])[0]);

        // The SQL state equals SectionOccupancy::state() for every row.
        $occupancy = $this->occupancy();
        foreach ($this->report()['rows']['data'] as $row) {
            $this->assertSame($occupancy[substr($row['section']['name'], strlen('Grade 5 · '))]->state(), $row['state']);
        }

        // Operational only: no percentage, average, trend or Student name.
        $json = (string) json_encode($this->report()['rows']['data']);
        foreach (['%', 'percent', 'average', 'trend', 'S-One', 'Pupil'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->report()['rows']['data'][0]['section']['url'] === null && $this->fail('row links to its placements');

        foreach ([['status' => 'closed_year'], ['sort' => 'capacity / strength'], ['campus_id' => 'x']] as $bad) {
            $this->get('/app/students/reports/students.section_capacity?'.http_build_query($bad))->assertStatus(302)->assertSessionHasErrors();
        }
    }

    #[Test]
    public function a_campus_reader_sees_only_their_campus_s_active_year_sections(): void
    {
        $this->place('One', 'E');
        $next = $this->createAcademicYear($this->school, ['name' => '2027-28', 'code' => 'AY27', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $this->createSection($next, $this->early, $this->g5, ['name' => 'Next', 'code' => 'NX', 'capacity' => 5]);

        $reader = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($reader, $this->school), $this->createFixtureRole(['enrollments.view']), $this->early->id);

        $this->assertSame(['Grade 5 · E|1 Student|Capacity not configured'], $this->rows([], $reader), 'no Main Section, no draft year');
        $this->assertSame([], $this->rows(['campus_id' => $this->main->id], $reader), 'a forged Campus never broadens');
        $this->assertSame([], $this->rows(['academic_year_id' => $next->id], $reader));
        $this->assertSame(['Early Years'], array_column(collect($this->report([], $reader)['filters'])->firstWhere('key', 'campus_id')['options'], 'label'));
    }

    #[Test]
    public function only_enrollments_view_opens_it_and_another_school_never_counts(): void
    {
        foreach ([['students.view'], ['academics.structure.view']] as $caps) {
            $user = $this->createUser();
            LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($caps));
            app('auth')->forgetGuards();
            $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
            $this->get('/app/students/reports/students.section_capacity')->assertForbidden();
        }

        // A same-shaped School: same Section names, its own placements.
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $year = $this->createAcademicYear($other, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $section = $this->createSection($year, $this->createCampus($other, ['name' => 'Main', 'code' => 'M']), $this->createGradeLevel($other, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A', 'capacity' => 1]);
        foreach (['X', 'Y'] as $n) {
            $this->placements()->enroll($this->createStudent($other, ['first_name' => $n, 'student_number' => 'O-'.$n]), $section, $n, '2026-06-01', $otherAdmin);
        }
        $this->place('Mine', 'A');

        $this->assertSame('Grade 5 · A|1 / 3|2 seats available', $this->rows()[0]);
        $this->assertSame(3, $this->report()['rows']['total']);
    }

    #[Test]
    public function the_query_count_does_not_grow_with_sections_or_placements(): void
    {
        $count = function (): int {
            $this->report();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/students/reports/students.section_capacity')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $count();
        $small = $count();
        for ($i = 0; $i < 12; $i++) {
            $this->sections["X{$i}"] = $this->createSection($this->year, $i % 2 ? $this->main : $this->early, $this->g5, ['name' => "X{$i}", 'code' => "X{$i}", 'capacity' => 4]);
            $this->place("P{$i}a", "X{$i}");
            $this->place("P{$i}b", "X{$i}");
        }
        $this->assertSame($small, $count());
    }

    #[Test]
    public function placement_choices_show_advisory_occupancy(): void
    {
        $this->place('One', 'B');
        $this->place('Two', 'B');
        app('auth')->forgetGuards();
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $sections = collect($this->get('/app/students/'.$this->createStudent($this->school, ['first_name' => 'New', 'student_number' => 'S-NEW'])->id.'/enrollments/create')->assertOk()->viewData('page')['props']['sections'])->keyBy('id');
        $this->assertSame('2 / 2 · At advisory capacity', $sections[$this->sections['B']->id]['occupancy']);

        $pupil = $this->place('Mover', 'A');
        $destinations = collect($this->get("/app/students/{$pupil->student_id}/transfer")->assertOk()->viewData('page')['props']['destinations'])->keyBy('id');
        $this->assertSame('0 Students · Capacity not configured', $destinations[$this->sections['E']->id]['occupancy']);

        $sectionsPage = collect($this->get('/app/school-setup/sections?academic_year_id='.$this->year->id)->assertOk()->viewData('page')['props']['sections']['data'])->keyBy('name');
        $this->assertSame('2 / 2 · At advisory capacity', $sectionsPage['B']['occupancy']['label']);
    }

    #[Test]
    public function the_rollover_warning_counts_planned_targets_and_never_blocks(): void
    {
        $next = $this->createAcademicYear($this->school, ['name' => '2027-28', 'code' => 'AY27', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $g6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $t1 = $this->createSection($next, $this->main, $g6, ['name' => 'T1', 'code' => 'T1', 'capacity' => 1]);
        $t2 = $this->createSection($next, $this->main, $g6, ['name' => 'T2', 'code' => 'T2', 'capacity' => 1]);
        $plan = $this->createEnrollmentRolloverPlan($this->year, $next);
        $this->createEnrollmentRolloverMapping($plan, $this->g5, $g6, ['target_section_id' => $t1->id]);

        $items = [];
        foreach (['P1', 'P2', 'P3'] as $n) {
            $source = $this->place($n, 'A');
            $student = app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->findOrFail($source->student_id));
            $items[$n] = $this->createEnrollmentRolloverItem($plan, $student, $source, ['decision' => 'promote']);
        }
        // An item-level override takes one Student to T2 (the execution precedence).
        app(TenantContext::class)->withSchool($this->school, fn () => $items['P3']->update(['target_section_id' => $t2->id]));

        $warnings = app(RolloverCapacityAdvisory::class)->warnings($this->school, $plan);
        $this->assertSame([[$t1->id, 0, 2, 1]], array_map(fn (array $w) => [$w['sectionId'], $w['placed'], $w['planned'], $w['over']], $warnings), 'T1 takes the two mapped items; T2 its one override fits');
        $this->assertStringContainsString('has an advisory capacity of 1; 0 already placed and 2 planned would make 2, over by 1.', $warnings[0]['message']);

        app('auth')->forgetGuards();
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");
        $this->assertCount(1, $this->get("/app/enrollment-rollovers/{$plan->id}")->assertOk()->viewData('page')['props']['capacityWarnings']);
    }

    #[Test]
    public function capacity_stays_operational_one_field_and_out_of_data_health(): void
    {
        // Operational, never analytics (OD-SIS-6, ADR 0040): no ratio, average,
        // time bucket or demographic attribute in the capacity code.
        foreach (['Domain/Students/Application/SectionOccupancy.php', 'Domain/Students/Application/SectionOccupancyReader.php',
            'Domain/Students/Application/Reports/SectionCapacityReport.php', 'Domain/Students/Application/RolloverCapacityAdvisory.php'] as $file) {
            // Code only: the docblocks say what is never computed.
            $source = strtolower(implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(app_path($file))))));
            foreach (['percent', '* 100', 'avg(', 'date_trunc', 'generate_series', 'gender', 'religion', 'caste', 'date_of_birth'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file} must not compute {$forbidden}");
            }
        }

        // One capacity, on the Section: no other table gained a Section capacity.
        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'like', '%capacity%')->pluck('table_name')->unique()->sort()->values()->all();
        $this->assertNotContains('student_enrollments', $columns);
        $this->assertNotContains('admission_applications', $columns);
        $this->assertNotContains('enrollment_rollover_mappings', $columns);
        $this->assertContains('sections', $columns);

        // Over capacity is an operational warning, never a Data Health finding.
        foreach (array_keys(app(DataHealthRegistry::class)->all()) as $code) {
            $this->assertStringNotContainsString('capacity', $code);
        }
    }
}
