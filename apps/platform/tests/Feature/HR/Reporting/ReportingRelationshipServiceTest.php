<?php

namespace Tests\Feature\HR\Reporting;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\InvalidReportingChangeException;
use App\Domain\HR\Application\Exceptions\ReportingHierarchyCycleException;
use App\Domain\HR\Application\Reporting\ReportingAsOfReader;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Application\ReportingLine;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.3A: dated, employment-to-employment reporting lines through
 * ReportingRelationshipService, and the historical answers of ReportingLine
 * and ReportingAsOfReader. Fixed dates throughout (employments from
 * 2024-01-01), the School-local today only where the operation itself is
 * "from today".
 */
class ReportingRelationshipServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->actor = $this->fullHrActor($this->school);
    }

    private function staff(string $startsOn = '2024-01-01', ?string $endsOn = null): EmploymentRecord
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => 'active']);
        $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'is_primary' => true]);

        return $employment;
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function service(): ReportingRelationshipService
    {
        return app(ReportingRelationshipService::class);
    }

    private function assign(EmploymentRecord $sub, EmploymentRecord $manager, string $on, ReportingChangeReason $reason = ReportingChangeReason::ORGANISATIONAL_CHANGE): ReportingRelationship
    {
        return $this->service()->assign($sub, $manager, $on, $reason, $this->actor);
    }

    private function managerOf(EmploymentRecord $sub, string $on): ?string
    {
        return app(ReportingLine::class)->managerOf($this->school, $sub->id, $on);
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (InvalidReportingChangeException $e) {
            return $e->reason;
        } catch (ReportingHierarchyCycleException) {
            return 'cycle';
        }

        return 'allowed';
    }

    // --- Assign, change, remove, future-dated ------------------------------

    #[Test]
    public function an_assignment_is_a_dated_line_answered_from_its_date_only(): void
    {
        [$sub, $manager] = [$this->staff(), $this->staff()];

        $line = $this->assign($sub, $manager, '2025-03-01');

        $this->assertSame(['assigned', 'organisational_change', $this->actor->id], [$line->started_via, $line->start_reason, $line->started_by_user_id]);
        $this->assertNull($this->managerOf($sub, '2025-02-28'));
        $this->assertSame($manager->employee_id, $this->managerOf($sub, '2025-03-01'));
        $this->assertSame([$sub->id], app(ReportingLine::class)->reportsOf($this->school, $manager->employee_id, '2025-03-01'));
        $this->assertSame([], app(ReportingLine::class)->reportsOf($this->school, $manager->employee_id, '2025-02-28'));
    }

    #[Test]
    public function a_change_mid_period_keeps_both_answers_for_ever_and_is_audited(): void
    {
        [$sub, $first, $second] = [$this->staff(), $this->staff(), $this->staff()];

        $old = $this->assign($sub, $first, '2025-01-01');
        $new = $this->assign($sub, $second, '2025-06-01', ReportingChangeReason::RESTRUCTURING);

        $this->assertSame($first->employee_id, $this->managerOf($sub, '2025-05-31'));
        $this->assertSame($second->employee_id, $this->managerOf($sub, '2025-06-01'));
        $old = $this->inSchool($this->school, fn () => $old->fresh());
        $this->assertSame(['2025-05-31', 'changed', 'restructuring'], [$old->ends_on->toDateString(), $old->ended_via, $old->end_reason]);
        $this->assertSame('changed', $new->started_via);

        $event = $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'hr.reporting.changed')->sole());
        $this->assertEquals([
            'employeeId' => $sub->employee_id,
            'subordinateEmploymentId' => $sub->id,
            'reportingRelationshipId' => $new->id,
            'effectiveOn' => '2025-06-01',
            'previousManagerEmploymentId' => $first->id,
            'managerEmploymentId' => $second->id,
            'reason' => 'restructuring',
            'previousRelationshipId' => $old->id,
        ], $event->metadata, 'ids and closed codes only');
        $this->assertSame(1, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'hr.reporting.assigned')->count()));
    }

    #[Test]
    public function a_future_dated_change_leaves_today_alone(): void
    {
        [$sub, $current, $next] = [$this->staff(), $this->staff(), $this->staff()];
        $today = SchoolTimezone::today($this->school);
        $future = CarbonImmutable::parse($today)->addDays(30)->toDateString();

        $this->assign($sub, $current, '2025-01-01');
        $this->assign($sub, $next, $future);

        $this->assertSame($current->employee_id, $this->managerOf($sub, $today));
        $this->assertSame($current->employee_id, $this->managerOf($sub, CarbonImmutable::parse($future)->subDay()->toDateString()));
        $this->assertSame($next->employee_id, $this->managerOf($sub, $future));
    }

    #[Test]
    public function a_removal_ends_the_line_from_its_date(): void
    {
        [$sub, $manager] = [$this->staff(), $this->staff()];
        $this->assign($sub, $manager, '2025-01-01');

        $ended = $this->service()->remove($sub, '2025-09-01', ReportingChangeReason::MANAGER_DEPARTURE, $this->actor);

        $this->assertSame(['2025-08-31', 'removed', 'manager_departure'], [$ended->ends_on->toDateString(), $ended->ended_via, $ended->end_reason]);
        $this->assertSame($manager->employee_id, $this->managerOf($sub, '2025-08-31'));
        $this->assertNull($this->managerOf($sub, '2025-09-01'));
        $this->assertSame('no_line_on_date', $this->refusal(fn () => $this->service()->remove($sub, '2025-10-01', ReportingChangeReason::MANAGER_DEPARTURE, $this->actor)));
    }

    #[Test]
    public function a_change_never_rewrites_a_later_line_and_an_unchanged_manager_is_refused(): void
    {
        [$sub, $first, $second, $third] = [$this->staff(), $this->staff(), $this->staff(), $this->staff()];
        $this->assign($sub, $first, '2025-01-01');
        $this->assign($sub, $second, '2025-06-01');

        $this->assertSame('later_line_exists', $this->refusal(fn () => $this->assign($sub, $third, '2025-03-01')));
        $this->assertSame('later_line_exists', $this->refusal(fn () => $this->service()->remove($sub, '2025-03-01', ReportingChangeReason::RESTRUCTURING, $this->actor)));
        $this->assertSame('unchanged', $this->refusal(fn () => $this->assign($sub, $second, '2025-07-01')));
        $this->assertSame($first->employee_id, $this->managerOf($sub, '2025-03-01'), 'nothing was written');
    }

    #[Test]
    public function both_people_must_be_employed_on_the_effective_date_and_the_line_ends_with_a_fixed_term(): void
    {
        $sub = $this->staff('2024-01-01');
        $lateManager = $this->staff('2025-05-01');
        $fixedTerm = $this->staff('2024-01-01', '2026-12-31');

        $this->assertSame('outside_employment', $this->refusal(fn () => $this->assign($sub, $lateManager, '2025-04-30')));
        $line = $this->assign($sub, $fixedTerm, '2025-01-01');
        $this->assertSame('2026-12-31', $line->ends_on->toDateString(), 'a line never outlives either employment');
        $this->assertNull($line->ended_via);
    }

    // --- Integrity: School, self, loops ------------------------------------

    #[Test]
    public function a_manager_of_another_school_or_the_same_employee_is_refused(): void
    {
        $sub = $this->staff();
        $other = $this->createSchool();
        $foreign = $this->createEmploymentRecord($this->createEmployee($other), ['starts_on' => '2024-01-01']);

        $this->assertSame('cross_school', $this->refusal(fn () => $this->assign($sub, $foreign, '2025-01-01')));
        $this->assertSame('same_employee', $this->refusal(fn () => $this->assign($sub, $sub, '2025-01-01')));
        $assignments = $this->inSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $sub->id)->get());
        $second = $this->createEmployeeAssignment($sub, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'is_primary' => false]);
        $this->assertSame('same_employee', $this->refusal(fn () => app(ReportingHierarchyService::class)->setManager($assignments->first(), $second, $this->actor, effectiveOn: '2025-01-01')), 'another assignment of the same employment');
    }

    #[Test]
    public function direct_and_indirect_loops_are_refused_but_a_reversal_over_other_dates_is_not_a_loop(): void
    {
        [$a, $b, $c, $d, $e] = [$this->staff(), $this->staff(), $this->staff(), $this->staff(), $this->staff()];

        $this->assign($a, $b, '2025-01-01');
        $this->assertSame('cycle', $this->refusal(fn () => $this->assign($b, $a, '2025-01-01')), 'A -> B -> A');

        $this->assign($b, $c, '2025-01-01');
        $this->assertSame('cycle', $this->refusal(fn () => $this->assign($c, $a, '2025-01-01')), 'A -> B -> C -> A');

        $this->assign($c, $d, '2025-01-01');
        $this->assign($d, $e, '2025-01-01');
        $this->assertSame('cycle', $this->refusal(fn () => $this->assign($e, $a, '2025-01-01')), 'a five-node loop');
        $this->assertSame('cycle', $this->refusal(fn () => $this->assign($e, $a, '2026-06-01')), 'a loop that only starts later');

        // A reports to B until 2025-12-31; from 2026-01-01 B may report to A.
        $this->service()->remove($a, '2026-01-01', ReportingChangeReason::RESTRUCTURING, $this->actor);
        $this->assertSame('allowed', $this->refusal(fn () => $this->assign($b, $a, '2026-01-01')));
        $this->assertSame($b->employee_id, $this->managerOf($a, '2025-12-31'));
        $this->assertSame($a->employee_id, $this->managerOf($b, '2026-01-01'));
    }

    // --- Arbitrary depth, chains, trees -------------------------------------

    #[Test]
    public function chains_and_trees_have_arbitrary_depth_and_answer_any_date(): void
    {
        $people = [];
        for ($i = 0; $i < 13; $i++) {
            $people[] = $this->staff();
        }
        // people[0] is the top; each reports to the one before (12 levels).
        for ($i = 1; $i < 13; $i++) {
            $this->assign($people[$i], $people[$i - 1], '2025-01-01');
        }
        $reader = app(ReportingAsOfReader::class);

        $chain = $reader->chainOn($this->school, $people[12]->id, '2025-06-01');
        $this->assertSame(array_map(fn (EmploymentRecord $p) => $p->id, array_reverse(array_slice($people, 0, 12))), $chain);
        $this->assertSame(12, $reader->depthOn($this->school, $people[12]->id, '2025-06-01'));
        $this->assertSame(0, $reader->depthOn($this->school, $people[0]->id, '2025-06-01'));
        $this->assertSame(0, $reader->depthOn($this->school, $people[12]->id, '2024-12-31'), 'before the lines existed');

        $tree = $reader->treeOn($this->school, $people[0]->id, '2025-06-01');
        $this->assertCount(12, $tree);
        $this->assertSame(['manager' => $people[4]->id, 'depth' => 5], $tree[$people[5]->id]);
        $this->assertCount(2, $reader->treeOn($this->school, $people[0]->id, '2025-06-01', maxDepth: 2));
        $this->assertSame([$people[6]->id], $reader->directReportsOn($this->school, $people[5]->id, '2025-06-01'));
    }

    #[Test]
    public function batch_manager_lookups_take_a_constant_number_of_queries(): void
    {
        $manager = $this->staff();
        $small = [$this->staff(), $this->staff()];
        $large = [];
        for ($i = 0; $i < 25; $i++) {
            $large[] = $this->staff();
        }
        foreach ([...$small, ...$large] as $sub) {
            $this->assign($sub, $manager, '2025-01-01');
        }
        $reader = app(ReportingAsOfReader::class);
        $campusId = $this->createCampus($this->school)->id;

        $count = function (array $subs) use ($reader, $campusId): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $answer = $reader->managerEmployeesOn($this->school, array_map(fn (EmploymentRecord $s) => $s->id, $subs), '2025-06-01', [$campusId]);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertIsArray($answer);

            return $queries;
        };

        $this->assertSame($count($small), $count($large));
    }

    // --- Placement changes and employment end -------------------------------

    #[Test]
    public function a_transfer_or_assignment_end_never_touches_reporting_lines(): void
    {
        [$sub, $manager] = [$this->staff(), $this->staff()];
        $report = $this->staff();
        $this->assign($sub, $manager, '2025-01-01');
        $this->assign($report, $sub, '2025-01-01');
        $source = $this->inSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $sub->id)->sole());
        $today = SchoolTimezone::today($this->school);
        $future = CarbonImmutable::parse($today)->addDays(15)->toDateString();

        // HR.1A refused a future change of someone with reports; HR.3A records it.
        app(PlacementChangeService::class)->change($source, PlacementChangeKind::TRANSFER, $future, PlacementChangeReason::STAFFING_REQUIREMENT, $this->createPosition($this->school), $this->actor, $this->createCampus($this->school));

        $this->assertSame($manager->employee_id, $this->managerOf($sub, $future));
        $this->assertSame($sub->employee_id, $this->managerOf($report, $future));
        $managerAssignment = $this->inSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $manager->id)->sole());
        app(EmployeeAssignmentService::class)->end($managerAssignment, $today, $this->actor);
        $this->assertSame($manager->employee_id, $this->managerOf($sub, $today), 'the line joins employments, not assignments');
    }

    #[Test]
    public function an_employment_end_closes_its_lines_on_both_sides_and_cancels_later_ones(): void
    {
        [$sub, $manager, $next, $report] = [$this->staff(), $this->staff(), $this->staff(), $this->staff()];
        $this->assign($sub, $manager, '2025-01-01');
        $this->assign($sub, $next, '2026-09-01');
        $this->assign($report, $sub, '2025-01-01');

        app(EmploymentService::class)->end($sub, '2026-03-31', $this->actor);

        $lines = $this->inSchool($this->school, fn () => ReportingRelationship::query()->whereIn('subordinate_employment_id', [$sub->id, $report->id])->get());
        $expected = [
            "{$sub->id}|2025-01-01|2026-03-31|subordinate_employment_ended",
            "{$report->id}|2025-01-01|2026-03-31|manager_employment_ended",
            "{$sub->id}|2026-09-01|2026-08-31|subordinate_employment_ended", // cancelled: the empty interval
        ];
        sort($expected);
        $this->assertSame($expected, $lines->map(fn (ReportingRelationship $r) => "{$r->subordinate_employment_id}|{$r->starts_on->toDateString()}|{$r->ends_on?->toDateString()}|{$r->ended_via}")->sort()->values()->all());
        $this->assertNull($this->managerOf($report, '2026-04-01'), 'the reports are left with no manager, never re-parented');
        $this->assertSame($sub->employee_id, $this->managerOf($report, '2026-03-31'), 'history stays');
        $this->assertSame(3, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'hr.reporting.ended')->count()));
    }

    // --- Correction -----------------------------------------------------------

    #[Test]
    public function a_correction_voids_the_wrong_line_and_records_the_right_one_over_the_same_dates(): void
    {
        [$sub, $wrong, $right] = [$this->staff(), $this->staff(), $this->staff()];
        $line = $this->assign($sub, $wrong, '2025-01-01');
        $this->service()->remove($sub, '2025-12-01', ReportingChangeReason::RESTRUCTURING, $this->actor);

        $replacement = $this->service()->correct($this->inSchool($this->school, fn () => $line->fresh()), $right, ReportingChangeReason::ADMINISTRATIVE_CORRECTION, $this->actor);

        $voided = $this->inSchool($this->school, fn () => $line->fresh());
        $this->assertNotNull($voided->voided_at);
        $this->assertSame(['administrative_correction', $this->actor->id], [$voided->void_reason, $voided->voided_by_user_id]);
        $this->assertSame(['corrected', '2025-01-01', '2025-11-30', 'removed'], [$replacement->started_via, $replacement->starts_on->toDateString(), $replacement->ends_on->toDateString(), $replacement->ended_via]);
        $this->assertSame($right->employee_id, $this->managerOf($sub, '2025-06-01'));
        $this->assertCount(2, app(ReportingAsOfReader::class)->historyOf($this->school, $sub->id), 'the voided line stays as evidence');

        $this->assertSame('already_voided', $this->refusal(fn () => $this->service()->correct($voided, null, ReportingChangeReason::ADMINISTRATIVE_CORRECTION, $this->actor)));
        // Void only: the line never existed.
        $this->service()->correct($replacement, null, ReportingChangeReason::ADMINISTRATIVE_CORRECTION, $this->actor);
        $this->assertNull($this->managerOf($sub, '2025-06-01'));
        $this->assertSame(1, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'hr.reporting.corrected')->whereNull('metadata->replacementRelationshipId')->count()));
    }

    // --- Authorization and boundaries -----------------------------------------

    #[Test]
    public function an_actor_without_the_capability_changes_nothing(): void
    {
        [$sub, $manager] = [$this->staff(), $this->staff()];
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.assignments.view']);
        $line = $this->assign($sub, $manager, '2025-01-01');

        foreach ([
            fn () => $this->service()->assign($sub, $this->staff(), '2025-06-01', ReportingChangeReason::RESTRUCTURING, $viewer),
            fn () => $this->service()->remove($sub, '2025-06-01', ReportingChangeReason::RESTRUCTURING, $viewer),
            fn () => $this->service()->correct($line, null, ReportingChangeReason::ADMINISTRATIVE_CORRECTION, $viewer),
        ] as $call) {
            try {
                $call();
                $this->fail('An actor without hr.employees.assignments.manage changed a reporting line.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(1, $this->inSchool($this->school, fn () => ReportingRelationship::query()->count()));
    }

    #[Test]
    public function a_reporting_line_never_touches_an_authorization_table(): void
    {
        [$sub, $manager] = [$this->staff(), $this->staff()];
        $counts = fn () => $this->inSchool($this->school, fn () => [Role::query()->count(), MembershipRoleAssignment::query()->count(), PlatformRoleAssignment::query()->count()]);
        $before = $counts();

        $this->assign($sub, $manager, '2025-01-01');

        $this->assertSame($before, $counts());
    }
}
