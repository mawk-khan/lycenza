<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Analytics\Application\AnalyticsReadGate;
use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;
use App\Domain\CurriculumDelivery\Application\Coverage\CurriculumCoverageCounts;
use App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Models\GroupRoleAssignment;
use App\Models\PlatformAuditEvent;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0N.11 (ADR 0048): the Group Curriculum Coverage report -- Group
 * authority only, current MFA, active member Schools only, one School
 * context at a time, source-defined aggregation, fail-closed failures,
 * identifiers-only platform audit, nothing persisted.
 */
class GroupCurriculumCoverageReportTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures, GroupTestHelpers;

    private const URL = '/app/groups/%s/reports/curriculum-coverage';

    /**
     * An active School whose active academic year has $units syllabus units
     * across ONE Section, $completed of them completed.
     */
    private function coverageSchool(int $units, int $completed, string $name): School
    {
        $w = $this->deliveryWorld();
        $w['school']->update(['name' => $name]);
        $unitRows = [$w['unit']];
        for ($i = 2; $i <= $units; $i++) {
            $unitRows[] = $this->createSyllabusUnitFor($w['offering'], ['code' => 'U'.$i, 'sequence' => $i]);
        }
        foreach (array_slice($unitRows, 0, $completed) as $unit) {
            $this->createDelivery($w['offering'], $w['section'], $unit, ['status' => CurriculumDelivery::STATUS_COMPLETED, 'completed_on' => $this->today()->subDay()->toDateString()]);
        }

        return $w['school']->fresh();
    }

    private function report(User $actor, SchoolGroup $group): TestResponse
    {
        return $this->actingAs($actor)->withSession(['mfa_verified_at' => now()->toIso8601String()])->get(sprintf(self::URL, $group->id));
    }

    /** @return array<string, mixed> */
    private function payload(TestResponse $response): array
    {
        return $response->assertOk()->viewData('page')['props']['report'];
    }

    private function events(string $type): array
    {
        return PlatformAuditEvent::query()->where('event_type', $type)->orderBy('occurred_at')->orderBy('id')->get()->all();
    }

    private function bindSource(callable $onCoverage): void
    {
        $this->app->instance(CurriculumCoverageReadService::class, new class(app(TenantContext::class), $onCoverage) extends CurriculumCoverageReadService
        {
            /** @var callable */
            private $onCoverage;

            public function __construct(TenantContext $context, callable $onCoverage)
            {
                parent::__construct($context);
                $this->onCoverage = $onCoverage;
            }

            public function coverageForAcademicYear(School $school, string $academicYearId): CurriculumCoverageCounts
            {
                ($this->onCoverage)($school);

                return parent::coverageForAcademicYear($school, $academicYearId);
            }
        });
    }

    #[Test]
    public function the_group_admin_sees_each_school_and_a_total_recomputed_from_counts_not_averaged(): void
    {
        // A: 1 of 1 unit (100.0%); B: 2 of 10 (20.0%). Averaging would say
        // 60.0%; the unit-weighted total is 3 of 11 = 27.3%.
        $a = $this->coverageSchool(1, 1, 'Alpha School');
        $b = $this->coverageSchool(10, 2, 'Beta School');
        $group = $this->createGroup([$a, $b]);
        $admin = $this->groupAdmin($group);

        $response = $this->report($admin, $group);
        $response->assertInertia(fn (AssertableInertia $p) => $p->component('App/Groups/CurriculumCoverageReport'));
        $report = $this->payload($response);

        $this->assertSame(['planned' => 11, 'completed' => 3, 'inProgress' => 0, 'notStarted' => 8, 'offerings' => 2, 'offeringsWithoutSyllabus' => 0, 'coveragePercent' => '27.3', 'contributingSchools' => 2, 'unavailableSchools' => 0, 'noActiveYearSchools' => 0], $report['totals']);
        $this->assertNotSame('60.0', $report['totals']['coveragePercent']);

        $rows = collect($report['schools'])->keyBy('schoolId');
        $this->assertSame(['Alpha School', 'Beta School'], array_column($report['schools'], 'name'));
        $this->assertSame('100.0', $rows[$a->id]['coverage']['coveragePercent']);
        $this->assertSame('20.0', $rows[$b->id]['coverage']['coveragePercent']);
        $this->assertSame(['hasActiveAcademicYear', 'academicYearName', 'academicYearCode', 'planned', 'completed', 'inProgress', 'notStarted', 'coveragePercent', 'offerings', 'offeringsWithoutSyllabus'], array_keys($rows[$a->id]['coverage']));
        $this->assertSame(['schoolId', 'name', 'state', 'observedAt', 'coverage'], array_keys($rows[$a->id]));
        $this->assertSame(['group', 'reportKey', 'generatedAt', 'schools', 'totals'], array_keys($report));

        // No ids, grade/subject/Section names or person data leave the server.
        $json = json_encode($report);
        foreach (['subjectOfferingId', 'sectionId', 'gradeLevelName', 'subjectName', 'campusName', 'academicYearId', 'student', 'employee', 'teacher'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $json);
        }

        // One identifiers-only success event; nothing on the School ledgers.
        $viewed = $this->events(GroupCurriculumCoverageReportService::VIEWED);
        $this->assertCount(1, $viewed);
        $this->assertSame($group->id, $viewed[0]->subject_id);
        $this->assertSame($admin->id, $viewed[0]->actor_user_id);
        $grantId = GroupRoleAssignment::query()->where('user_id', $admin->id)->value('id');
        $this->assertEquals(['group_role_assignment_id' => $grantId, 'report_key' => 'curriculum.coverage', 'contributing_school_ids' => [min($a->id, $b->id), max($a->id, $b->id)], 'unavailable_count' => 0, 'no_active_year_count' => 0], $viewed[0]->metadata);
        foreach ([$a, $b] as $school) {
            $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->count()));
        }

        $this->assertNull(app(TenantContext::class)->schoolId());
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v);
    }

    #[Test]
    public function only_active_schools_with_an_active_year_contribute_and_the_others_are_marked(): void
    {
        $active = $this->coverageSchool(4, 2, 'Active School');
        $noYear = $this->coverageSchool(5, 5, 'No Year School');
        $this->inDeliverySchool($noYear, fn () => DB::table('academic_years')->where('school_id', $noYear->id)->update(['status' => 'closed']));
        $suspended = $this->coverageSchool(6, 6, 'Suspended School');
        $suspended->update(['status' => 'suspended']);
        $provisioning = $this->createSchool(['status' => 'provisioning', 'name' => 'Provisioning School']);
        $archived = $this->createSchool(['status' => 'archived', 'name' => 'Archived School']);
        $group = $this->createGroup([$active, $noYear, $suspended, $provisioning, $archived]);
        $admin = $this->groupAdmin($group);

        $read = [];
        $this->bindSource(function (School $school) use (&$read): void {
            $read[] = $school->id;
        });

        $report = $this->payload($this->report($admin, $group));
        $states = collect($report['schools'])->pluck('state', 'schoolId');

        $this->assertSame('included', $states[$active->id]);
        $this->assertSame('no_active_academic_year', $states[$noYear->id]);
        foreach ([$suspended, $provisioning, $archived] as $school) {
            $this->assertSame('unavailable', $states[$school->id]);
            $this->assertNull(collect($report['schools'])->firstWhere('schoolId', $school->id)['coverage']);
        }
        $this->assertSame([$active->id], $read, 'Only the active School with an active year had coverage read -- no fallback year, nothing from non-active Schools.');
        $this->assertSame(['planned' => 4, 'completed' => 2, 'coveragePercent' => '50.0', 'contributingSchools' => 1, 'unavailableSchools' => 3, 'noActiveYearSchools' => 1], array_intersect_key($report['totals'], array_flip(['planned', 'completed', 'coveragePercent', 'contributingSchools', 'unavailableSchools', 'noActiveYearSchools'])));

        // The ordinary School report still falls back to a listed year:
        // the Group contract is separate and does not change it.
        $viewer = $this->createUserWithCapabilities($noYear, ['analytics.view']);
        $this->app->forgetInstance(CurriculumCoverageReadService::class);
        $ordinary = app(AnalyticsReadGate::class)->read(app(CurriculumCoverageReadModel::class), $noYear, $viewer);
        $this->assertNotNull($ordinary['academicYearId']);
        $this->assertSame(5, $ordinary['totals']['completed']);
    }

    #[Test]
    public function the_group_summary_equals_the_ordinary_reports_totals_for_the_active_year(): void
    {
        $school = $this->coverageSchool(7, 3, 'Parity School');
        $viewer = $this->createUserWithCapabilities($school, ['analytics.view']);
        $ordinary = app(AnalyticsReadGate::class)->read(app(CurriculumCoverageReadModel::class), $school, $viewer);
        $summary = app(TenantContext::class)->withSchool($school, fn () => app(CurriculumCoverageReadModel::class)->groupSafeSummary($school));

        $this->assertSame(
            array_intersect_key($ordinary['totals'], array_flip(['planned', 'completed', 'inProgress', 'notStarted', 'coveragePercent', 'offerings', 'offeringsWithoutSyllabus'])),
            array_intersect_key($summary->toArray(), array_flip(['planned', 'completed', 'inProgress', 'notStarted', 'coveragePercent', 'offerings', 'offeringsWithoutSyllabus'])),
        );
    }

    #[Test]
    public function nothing_but_the_group_reporting_grant_opens_the_report(): void
    {
        $a = $this->coverageSchool(2, 1, 'A');
        $b = $this->coverageSchool(2, 2, 'B');
        $group = $this->createGroup([$a, $b]);
        $otherGroup = $this->createGroup([$this->createSchool()], 'Other Trust');

        $root = $this->platformAdmin();
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor');
        $this->enrollActiveMfaFactor($auditor);
        $schoolAdmin = $this->createUserWithCapabilities($a, ['analytics.view', 'school.members.manage']);
        $this->assignSchoolRole($this->createMembership($schoolAdmin, $b), 'school_admin');
        $this->enrollActiveMfaFactor($schoolAdmin);
        $otherAdmin = $this->groupAdmin($otherGroup);

        // A Group role WITHOUT group.reporting.view.
        $viewOnly = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test.group_viewer.'.Str::random(6), 'name' => 'Viewer', 'scope' => 'group', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $viewOnly->capabilities()->sync(['group.schools.view']));
        $viewer = $this->createUser();
        $this->enrollActiveMfaFactor($viewer);
        $this->grantGroupRole($viewer, $group, $viewOnly->key);

        foreach ([$root, $auditor, $schoolAdmin, $otherAdmin, $viewer] as $actor) {
            $this->report($actor, $group)->assertNotFound();
        }

        // An elevated Platform Super Admin still has no Group report.
        $this->elevate($root, $a);
        $this->withSession(['mfa_verified_at' => now()->toIso8601String()])->get(sprintf(self::URL, $group->id))->assertNotFound();

        // The Group view shows the link only with the capability.
        $this->actingAs($viewer)->get("/app/groups/{$group->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canViewReports', false));
        $admin = $this->groupAdmin($group);
        $this->actingAs($admin)->get("/app/groups/{$group->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canViewReports', true));

        // A root WITH a real Group grant is authorized by that grant only.
        $grantedRoot = $this->platformAdmin();
        $this->grantGroupRole($grantedRoot, $group);
        $this->payload($this->report($grantedRoot, $group));

        $this->assertCount(1, $this->events(GroupCurriculumCoverageReportService::VIEWED));
        $this->assertSame([], $this->events(GroupCurriculumCoverageReportService::FAILED), 'Refusals before any School is read are not audited.');
    }

    #[Test]
    public function current_mfa_assurance_is_required_but_no_fresh_code(): void
    {
        $group = $this->createGroup([$this->coverageSchool(1, 1, 'M')]);
        $noFactor = $this->groupAdmin($group, withMfa: false);
        $this->actingAs($noFactor)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->get(sprintf(self::URL, $group->id))->assertForbidden()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/MfaRequired')->where('code', 'mfa_required_not_enrolled'));

        $admin = $this->groupAdmin($group);
        $this->actingAs($admin)->withSession(['mfa_verified_at' => null])->get(sprintf(self::URL, $group->id))->assertStatus(401);
        $this->actingAs($admin)->withSession(['mfa_verified_at' => now()->subMinutes((int) config('mfa.assurance_window_minutes') + 1)->toIso8601String()])
            ->get(sprintf(self::URL, $group->id))->assertStatus(401);

        // Two refreshes with the same assurance: no new code needed.
        $this->payload($this->report($admin, $group));
        $this->payload($this->report($admin, $group));
        $this->assertCount(2, $this->events(GroupCurriculumCoverageReportService::VIEWED));
    }

    #[Test]
    public function an_archived_group_or_a_revoked_grant_is_refused_before_any_read(): void
    {
        $school = $this->coverageSchool(1, 1, 'R');
        $group = $this->createGroup([$school]);
        $admin = $this->groupAdmin($group);
        $grant = GroupRoleAssignment::query()->where('user_id', $admin->id)->firstOrFail();

        DB::table('group_role_assignments')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->createUser()->id]);
        $this->report($admin, $group)->assertNotFound();

        $admin2 = $this->groupAdmin($group);
        DB::table('school_groups')->where('id', $group->id)->update(['status' => SchoolGroup::STATUS_ARCHIVED]);
        $this->report($admin2, $group)->assertNotFound();

        $this->assertSame([], $this->events(GroupCurriculumCoverageReportService::VIEWED));
    }

    #[Test]
    public function a_school_in_two_groups_is_reported_only_under_the_named_group_authority(): void
    {
        $shared = $this->coverageSchool(2, 1, 'Shared');
        $groupA = $this->createGroup([$shared], 'Trust A');
        $groupB = $this->createGroup([$shared, $this->coverageSchool(3, 3, 'Only B')], 'Trust B');
        $admin = $this->groupAdmin($groupA);

        $report = $this->payload($this->report($admin, $groupA));
        $this->assertSame([$shared->id], array_column($report['schools'], 'schoolId'));
        $this->report($admin, $groupB)->assertNotFound();
    }

    #[Test]
    public function losing_authority_during_the_report_fails_it_whole_and_is_audited(): void
    {
        $a = $this->coverageSchool(1, 1, 'First');
        $b = $this->coverageSchool(1, 0, 'Second');
        $group = $this->createGroup([$a, $b]);
        $admin = $this->groupAdmin($group);
        $grant = GroupRoleAssignment::query()->where('user_id', $admin->id)->firstOrFail();
        $revoker = $this->createUser();
        $first = min($a->id, $b->id);

        // Revoked while the first School is being read (committed with it).
        $this->bindSource(function (School $school) use ($grant, $revoker, $first): void {
            if ($school->id === $first) {
                DB::table('group_role_assignments')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_by_user_id' => $revoker->id]);
            }
        });

        $this->report($admin, $group)->assertNotFound();

        $failed = $this->events(GroupCurriculumCoverageReportService::FAILED);
        $this->assertCount(1, $failed);
        $this->assertEquals(['group_role_assignment_id' => $grant->id, 'report_key' => 'curriculum.coverage', 'outcome_code' => 'authority_lost', 'read_school_ids' => [$first]], $failed[0]->metadata);
        $this->assertSame([], $this->events(GroupCurriculumCoverageReportService::VIEWED));
        $this->assertContextClearedAndIsolated($a, $b);
    }

    #[Test]
    public function a_group_archived_during_the_report_fails_it_whole(): void
    {
        $a = $this->coverageSchool(1, 1, 'One');
        $b = $this->coverageSchool(1, 1, 'Two');
        $group = $this->createGroup([$a, $b]);
        $admin = $this->groupAdmin($group);
        $first = min($a->id, $b->id);

        $this->bindSource(function (School $school) use ($group, $first): void {
            if ($school->id === $first) {
                DB::table('school_groups')->where('id', $group->id)->update(['status' => SchoolGroup::STATUS_ARCHIVED]);
            }
        });

        $this->report($admin, $group)->assertNotFound();
        $this->assertSame('authority_lost', $this->events(GroupCurriculumCoverageReportService::FAILED)[0]->metadata['outcome_code']);
    }

    #[Test]
    public function a_school_removed_before_its_turn_is_omitted_not_read(): void
    {
        $a = $this->coverageSchool(2, 2, 'Stays');
        $b = $this->coverageSchool(2, 0, 'Leaves');
        [$first, $second] = [min($a->id, $b->id), max($a->id, $b->id)];
        $group = $this->createGroup([$a, $b]);
        $admin = $this->groupAdmin($group);

        $read = [];
        $this->bindSource(function (School $school) use ($group, $first, $second, &$read): void {
            $read[] = $school->id;
            if ($school->id === $first) {
                DB::table('school_group_members')->where('school_group_id', $group->id)->where('school_id', $second)->delete();
            }
        });

        $report = $this->payload($this->report($admin, $group));
        $this->assertSame([$first], array_column($report['schools'], 'schoolId'));
        $this->assertSame([$first], $read);
        $this->assertSame([$first], $this->events(GroupCurriculumCoverageReportService::VIEWED)[0]->metadata['contributing_school_ids']);
    }

    #[Test]
    public function a_source_failure_fails_the_whole_report_with_no_partial_figures(): void
    {
        $a = $this->coverageSchool(1, 1, 'Fine');
        $b = $this->coverageSchool(1, 1, 'Broken');
        [$first, $second] = [min($a->id, $b->id), max($a->id, $b->id)];
        $group = $this->createGroup([$a, $b]);
        $admin = $this->groupAdmin($group);

        $this->bindSource(function (School $school) use ($second): void {
            if ($school->id === $second) {
                throw new RuntimeException('source exploded');
            }
        });

        $response = $this->report($admin, $group)->assertStatus(503);
        $response->assertInertia(fn (AssertableInertia $p) => $p->component('App/Groups/CurriculumCoverageReport')->where('report', null));
        $this->assertStringNotContainsString('source exploded', $response->getContent());

        $failed = $this->events(GroupCurriculumCoverageReportService::FAILED);
        $this->assertEquals(['group_role_assignment_id' => GroupRoleAssignment::query()->where('user_id', $admin->id)->value('id'), 'report_key' => 'curriculum.coverage', 'outcome_code' => 'source_error', 'read_school_ids' => [$first]], $failed[0]->metadata);
        $this->assertSame([], $this->events(GroupCurriculumCoverageReportService::VIEWED));
        $this->assertContextClearedAndIsolated($a, $b);
    }

    #[Test]
    public function a_report_never_starts_inside_a_school_context(): void
    {
        $school = $this->coverageSchool(1, 1, 'Ctx');
        $group = $this->createGroup([$school]);
        $admin = $this->groupAdmin($group);

        $this->expectException(LogicException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(GroupCurriculumCoverageReportService::class)->generate(request(), $admin, $group));
    }

    private function assertContextClearedAndIsolated(School $a, School $b): void
    {
        $this->assertNull(app(TenantContext::class)->schoolId());
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v);

        // The next School operation sees exactly that School and no leftover.
        foreach ([$a, $b] as $school) {
            $seen = app(TenantContext::class)->withSchool($school, fn () => DB::table('syllabus_units')->distinct()->pluck('school_id')->all());
            $this->assertSame([$school->id], $seen);
        }
        $this->assertSame([], DB::table('syllabus_units')->pluck('school_id')->all(), 'No School context: RLS shows nothing.');
    }
}
