<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\Workforce\EmploymentTermsChangeReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Application\Policy\LeaveEligibilityOutcome;
use App\Domain\Leave\Application\Policy\LeaveEligibilityResolver;
use App\Domain\Leave\Application\Policy\LeaveEligibilityVersion;
use App\Domain\Leave\Infrastructure\LeaveEligibilityRule;
use App\Domain\Leave\Infrastructure\LeaveEligibilityRun;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.9 (ADR 0065 §28.3-§28.6): leave policy eligibility -- rules on the
 * version (staff type, Employee Category, primary Department), never
 * ambiguous between active versions, resolved from the HR.1A facts ON A
 * DATE, and the additive, idempotent run with provenance. Every value is a
 * synthetic test input, never a product default.
 *
 * Today is Monday 2026-10-05 (School-local).
 */
class LeaveEligibilityTest extends TestCase
{
    use CreatesLeaveFixtures;

    private School $school;

    private User $admin;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
        $this->type = $this->leaveType($this->school, $this->admin);
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 200);
        } catch (ModelNotFoundException) {
            return 'not_found';
        } catch (AuthorizationException) {
            return 'forbidden';
        }
    }

    /** @param  list<LeaveEligibilityCriteria>  $rules */
    private function version(string $name, array $rules, ?LeavePolicy $supersedes = null, ?LeaveType $type = null, ?School $school = null, ?User $admin = null): LeavePolicy
    {
        return app(LeavePolicyService::class)->create($school ?? $this->school, ($type ?? $this->type)->id, [
            'name' => $name, 'annual_allocation_units' => 24, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
        ], $supersedes?->id, $admin ?? $this->admin, $rules);
    }

    private static function rule(?StaffType $staffType = null, ?string $categoryId = null, ?string $departmentId = null): LeaveEligibilityCriteria
    {
        return new LeaveEligibilityCriteria($staffType, $categoryId, $departmentId);
    }

    /** An active Employee with an employment of these terms and a primary assignment (optionally in a Department). */
    private function employment(?string $staffType, ?EmployeeCategory $category = null, ?Department $department = null, string $startsOn = '2024-01-01', ?string $endsOn = null, string $status = 'active', ?User $user = null): EmploymentRecord
    {
        $employee = $this->createEmployee($this->school, $user === null ? [] : ['user_id' => $user->id]);
        $employment = $this->createEmploymentRecord($employee, ['status' => $status, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'staff_type' => $staffType, 'employee_category_id' => $category?->id]);
        $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'is_primary' => true, 'department_id' => $department?->id]);

        return $employment;
    }

    /** @return array<string, array<string, mixed>> employment id => row */
    private function rows(string $date, ?User $actor = null): array
    {
        $result = app(LeaveEligibilityService::class)->applicability($this->school, $this->type->id, $date, $actor ?? $this->admin);

        return collect($result['rows'])->keyBy('employmentRecordId')->all();
    }

    #[Test]
    public function rules_are_part_of_the_version_and_read_back_with_it(): void
    {
        $category = $this->createEmployeeCategory($this->school);
        $policy = $this->version('Teaching', [self::rule(StaffType::TEACHING), self::rule(null, $category->id)]);

        $this->assertSame(2, $policy->eligibility_rule_count);
        $read = collect(app(LeaveReadService::class)->policies($this->school, $this->type->id, $this->admin))->firstWhere('id', $policy->id);
        $this->assertCount(2, $read['eligibilityRules']);
        $this->assertSame(['teaching', null], array_column($read['eligibilityRules'], 'staffType'));
    }

    #[Test]
    public function an_overlap_with_another_active_version_is_refused_never_ranked(): void
    {
        $dept = $this->createDepartment($this->school);
        $teaching = $this->version('Teaching', [self::rule(StaffType::TEACHING)]);

        $this->assertSame('LEAVE_ELIGIBILITY_OVERLAP', $this->code(fn () => $this->version('Dept', [self::rule(null, null, $dept->id)])), 'a teacher in the Department matches both');
        $this->assertSame('LEAVE_ELIGIBILITY_OVERLAP', $this->code(fn () => $this->version('All', [self::rule()])));
        $this->assertSame('ok', $this->code(fn () => $this->version('Non-teaching', [self::rule(StaffType::NON_TEACHING)])), 'disjoint staff types never overlap');
        $this->assertSame('ok', $this->code(fn () => $this->version('Teaching v2', [self::rule(StaffType::TEACHING)], $teaching)), 'a replacement version takes over its own rules');
        $this->assertSame('retired', $this->inSchool($this->school, fn () => $teaching->refresh()->status));
        // A retired version's rules are inert; a version WITHOUT rules never overlaps.
        $this->assertSame('ok', $this->code(fn () => $this->version('By hand', [])));
        // Another leave type is independent.
        $other = $this->leaveType($this->school, $this->admin, 'SL');
        $this->assertSame('ok', $this->code(fn () => $this->version('Other', [self::rule()], type: $other)));
    }

    #[Test]
    public function rule_sets_are_validated(): void
    {
        $inactive = $this->createEmployeeCategory($this->school, ['status' => 'inactive']);
        $foreign = $this->createEmployeeCategory($this->createSchool());
        $foreignDept = $this->createDepartment($this->createSchool());

        $this->assertSame('LEAVE_ELIGIBILITY_RULE_DUPLICATE', $this->code(fn () => $this->version('D', [self::rule(StaffType::TEACHING), self::rule(StaffType::TEACHING)])));
        $this->assertSame('LEAVE_ELIGIBILITY_RULES_TOO_MANY', $this->code(fn () => $this->version('M', array_map(fn () => self::rule(StaffType::TEACHING, (string) Str::uuid()), range(1, 21)))));
        $this->assertSame('LEAVE_ELIGIBILITY_REFERENCE_INVALID', $this->code(fn () => $this->version('I', [self::rule(null, $inactive->id)])));
        $this->assertSame('LEAVE_ELIGIBILITY_REFERENCE_INVALID', $this->code(fn () => $this->version('F', [self::rule(null, $foreign->id)])), 'another School\'s Category');
        $this->assertSame('LEAVE_ELIGIBILITY_REFERENCE_INVALID', $this->code(fn () => $this->version('F', [self::rule(null, null, $foreignDept->id)])), 'another School\'s Department');
    }

    #[Test]
    public function the_database_freezes_the_rule_set_and_refuses_overlap_on_raw_writes(): void
    {
        $teaching = $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $rule = $this->inSchool($this->school, fn () => LeaveEligibilityRule::query()->where('leave_policy_id', $teaching->id)->sole());
        $insert = fn (string $policyId, ?string $staffType) => $this->inSchool($this->school, fn () => DB::table('leave_eligibility_rules')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'leave_policy_id' => $policyId, 'leave_type_id' => $this->type->id,
            'staff_type' => $staffType, 'created_by_user_id' => $this->admin->id, 'created_at' => now(),
        ]));

        $this->assertStringContainsString('leave_eligibility_rule_count', $this->code(fn () => $insert($teaching->id, 'outsourced')), 'no rule is added to a saved version');
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_eligibility_rules')->where('id', $rule->id)->update(['staff_type' => 'outsourced']))));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($this->school, fn () => DB::table('leave_eligibility_rules')->where('id', $rule->id)->delete())));

        // A raw version declaring one rule, written raw with an overlapping rule.
        $rawPolicy = (string) Str::uuid();
        $this->assertStringContainsString('leave_eligibility_overlap', $this->code(function () use ($rawPolicy, $insert) {
            $this->inSchool($this->school, fn () => DB::table('leave_policies')->insert([
                'id' => $rawPolicy, 'school_id' => $this->school->id, 'leave_type_id' => $this->type->id, 'name' => 'Raw', 'annual_allocation_units' => 0,
                'carry_forward_allowed' => false, 'status' => 'active', 'created_by_user_id' => $this->admin->id, 'eligibility_rule_count' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $insert($rawPolicy, null);
        }));
        // A version must hold exactly the rules it declared, checked at commit (made immediate here).
        $this->assertStringContainsString('leave_eligibility_rule_count', $this->code(function () {
            $this->inSchool($this->school, fn () => DB::table('leave_policies')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'leave_type_id' => $this->type->id, 'name' => 'Empty', 'annual_allocation_units' => 0,
                'carry_forward_allowed' => false, 'status' => 'active', 'created_by_user_id' => $this->admin->id, 'eligibility_rule_count' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::statement('SET CONSTRAINTS trg_leave_policies_rules_complete IMMEDIATE');
        }));
    }

    #[Test]
    public function the_verifier_checks_the_leave_policy_backstops(): void
    {
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $checks['leave_policy_invariants']->status);
    }

    #[Test]
    public function each_employment_resolves_from_its_facts_on_the_date(): void
    {
        $category = $this->createEmployeeCategory($this->school);
        $dept = $this->createDepartment($this->school);
        $teaching = $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $support = $this->version('Support', [self::rule(StaffType::NON_TEACHING, $category->id), self::rule(StaffType::OUTSOURCED, null, $dept->id)]);

        $teacher = $this->employment('teaching');
        $clerk = $this->employment('non_teaching', $category);
        $clerkOther = $this->employment('non_teaching');
        $contractor = $this->employment('outsourced', null, $dept);
        $unclassified = $this->employment(null);
        $separated = $this->employment('teaching', null, null, '2024-01-01', '2026-12-31', 'separated');
        $planned = $this->employment('teaching', null, null, '2026-10-01', null, 'pre_joining');
        $mine = $this->employment('teaching', user: $this->admin);
        $draft = $this->employment('teaching', status: 'draft');

        $rows = $this->rows('2026-10-05');
        $this->assertSame(['eligible', $teaching->id], [$rows[$teacher->id]['outcome'], $rows[$teacher->id]['policyId']]);
        $this->assertSame(['eligible', $support->id], [$rows[$clerk->id]['outcome'], $rows[$clerk->id]['policyId']]);
        $this->assertSame('no_matching_rule', $rows[$clerkOther->id]['outcome'], 'a rule naming a Category never matches an employment without it');
        $this->assertSame(['eligible', $support->id], [$rows[$contractor->id]['outcome'], $rows[$contractor->id]['policyId']]);
        $this->assertSame('no_matching_rule', $rows[$unclassified->id]['outcome'], 'an unclassified staff type is never guessed');
        $this->assertNull($rows[$unclassified->id]['facts']['staffType']);
        $this->assertSame('employment_not_eligible', $rows[$separated->id]['outcome']);
        $this->assertSame('eligible', $rows[$planned->id]['outcome'], 'a planned employment spanning the date');
        $this->assertSame('own_employment', $rows[$mine->id]['outcome']);
        $this->assertArrayNotHasKey($draft->id, $rows, 'a draft employment is never a candidate');
        $this->assertSame($rows[$teacher->id]['ruleId'], $this->inSchool($this->school, fn () => LeaveEligibilityRule::query()->where('leave_policy_id', $teaching->id)->sole()->id));
    }

    #[Test]
    public function a_rule_naming_no_dimension_matches_every_employment_including_unclassified(): void
    {
        $all = $this->version('All staff', [self::rule()]);
        $unclassified = $this->employment(null);

        $this->assertSame(['eligible', $all->id], [$this->rows('2026-10-05')[$unclassified->id]['outcome'], $this->rows('2026-10-05')[$unclassified->id]['policyId']]);
    }

    #[Test]
    public function two_matching_versions_are_ambiguous_never_resolved_by_a_guess(): void
    {
        $teacher = $this->employment('teaching');
        $versions = [
            new LeaveEligibilityVersion((string) Str::uuid(), 'A', 0, [[null, self::rule(StaffType::TEACHING)]]),
            new LeaveEligibilityVersion((string) Str::uuid(), 'B', 0, [[null, self::rule()]]),
        ];
        $rows = $this->inSchool($this->school, fn () => app(LeaveEligibilityResolver::class)->resolve($this->school, $this->type->id, '2026-10-05', $versions, null));
        $row = collect($rows)->firstWhere('employmentRecordId', $teacher->id);

        $this->assertSame(LeaveEligibilityOutcome::AMBIGUOUS, $row->outcome);
        $this->assertNull($row->policyId);
        $this->assertCount(2, $row->matchingPolicyIds);
    }

    #[Test]
    public function an_effective_dated_category_change_moves_eligibility_from_its_date_only(): void
    {
        $x = $this->createEmployeeCategory($this->school);
        $y = $this->createEmployeeCategory($this->school);
        $px = $this->version('X', [self::rule(null, $x->id)]);
        $py = $this->version('Y', [self::rule(null, $y->id)]);
        $employment = $this->employment('non_teaching', $x);
        app(EmploymentTermsService::class)->change($employment, '2026-10-01', EmploymentType::PERMANENT, StaffType::NON_TEACHING, $y->id, EmploymentTermsChangeReason::RECLASSIFICATION, $this->fullHrActor($this->school));

        $this->assertSame($px->id, $this->rows('2026-09-15')[$employment->id]['policyId']);
        $this->assertSame($py->id, $this->rows('2026-10-05')[$employment->id]['policyId']);
    }

    #[Test]
    public function a_transfer_changes_the_resolution_but_never_moves_an_assignment_or_its_balance(): void
    {
        $a = $this->createDepartment($this->school);
        $b = $this->createDepartment($this->school);
        $pa = $this->version('A', [self::rule(null, null, $a->id)]);
        $pb = $this->version('B', [self::rule(null, null, $b->id)]);
        $employment = $this->employment('teaching', null, $a);
        $year = $this->leaveYear($this->school, $this->admin);
        app(LeaveEligibilityService::class)->executeRun($this->school, $this->type->id, '2026-08-01', $this->admin);
        $this->allocate(['school' => $this->school, 'type' => $this->type, 'year' => $year, 'employment' => $employment, 'admin' => $this->admin], 24);

        $source = $this->inSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $employment->id)->sole());
        $position = $this->inSchool($this->school, fn () => Position::query()->findOrFail($source->position_id));
        app(PlacementChangeService::class)->change($source, PlacementChangeKind::DEPARTMENT_CHANGE, '2026-09-01', PlacementChangeReason::RESTRUCTURING,
            $position, $this->fullHrActor($this->school), department: $b);

        $row = $this->rows('2026-10-05')[$employment->id];
        $this->assertSame('assigned', $row['outcome']);
        $this->assertSame($pa->id, $row['assignedPolicyId'], 'the transfer never repoints the assignment');
        $this->assertSame([$pb->id], $row['matchingPolicyIds']);
        $this->assertFalse($row['assignedMatchesRules'], 'drift is shown for an administrator to act on');
        $this->assertSame($pa->id, $this->rows('2026-08-15')[$employment->id]['matchingPolicyIds'][0], 'the past still resolves to the Department of its date');

        $run = app(LeaveEligibilityService::class)->executeRun($this->school, $this->type->id, '2026-10-05', $this->admin);
        $this->assertSame(0, $run->assigned_count, 'a run never adds a second assignment');
        $this->assertSame(24, (int) $this->inSchool($this->school, fn () => LeaveLedgerEntry::query()->where('employment_record_id', $employment->id)->sum('units')), 'the balance is untouched');
    }

    #[Test]
    public function the_run_assigns_only_the_eligible_with_provenance_and_is_idempotent(): void
    {
        $teaching = $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $rule = $this->inSchool($this->school, fn () => LeaveEligibilityRule::query()->where('leave_policy_id', $teaching->id)->sole());
        $open = $this->employment('teaching');
        // HR.R1-C2 (HRC-1): a notice period has no last day until the employment is ended, so its assignment is
        // open like any other; an employment already ENDED (here with a last day after the run date) gets nothing.
        $leaving = $this->employment('teaching', status: 'notice_period');
        $ended = $this->employment('teaching', endsOn: '2027-01-31', status: 'separated');
        $later = $this->employment('teaching');
        $manualPolicy = $this->version('Manual', []);
        app(LeavePolicyAssignmentService::class)->assign($this->school, $later->id, $manualPolicy->id, '2026-12-01', null, $this->admin);
        $unmatched = $this->employment('outsourced');
        $mine = $this->employment('teaching', user: $this->admin);

        $run = app(LeaveEligibilityService::class)->executeRun($this->school, $this->type->id, '2026-10-05', $this->admin);
        $this->assertSame(3, $run->assigned_count);
        $this->assertSame(3, $run->skipped_count, 'unmatched + own + the ended employment');

        $assignment = fn (EmploymentRecord $e) => $this->inSchool($this->school, fn () => LeavePolicyAssignment::query()->where('employment_record_id', $e->id)->orderBy('effective_from')->get());
        $first = $assignment($open)->sole();
        $this->assertSame(['2026-10-05', null, $teaching->id, $run->id, $rule->id], [$first->effective_from->toDateString(), $first->effective_to, $first->leave_policy_id, $first->eligibility_run_id, $first->eligibility_rule_id]);
        $this->assertNull($assignment($leaving)->sole()->effective_to, 'a notice period is still open');
        $this->assertCount(0, $assignment($ended), 'an ended employment is never assigned, whatever its last day');
        $this->assertSame(['2026-11-30', '2026-12-01'], [$assignment($later)[0]->effective_to->toDateString(), $assignment($later)[1]->effective_from->toDateString()], 'up to the day before a later assignment');
        $this->assertCount(0, $assignment($unmatched));
        $this->assertCount(0, $assignment($mine), 'never the actor\'s own employment');

        $again = app(LeaveEligibilityService::class)->executeRun($this->school, $this->type->id, '2026-10-05', $this->admin);
        $this->assertSame(0, $again->assigned_count, 'a re-run assigns nothing twice');
        $this->assertSame(3, $this->inSchool($this->school, fn () => LeavePolicyAssignment::query()->whereNotNull('eligibility_run_id')->count()));

        $events = $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('school_id', $this->school->id)->where('event_type', 'leave.eligibility_run.executed')->orderBy('id')->get());
        $this->assertCount(2, $events);
        $keys = array_keys($events[0]->metadata);
        sort($keys);
        $this->assertSame(['asOf', 'assigned', 'leaveTypeId', 'outcomes', 'skipped'], $keys, 'ids, a date, counts and closed outcome codes only');
        $this->assertSame(3, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'leave.policy.assigned')->where('metadata->source', 'eligibility_run')->count()));
    }

    #[Test]
    public function a_rehire_starts_a_fresh_employment_and_inherits_nothing(): void
    {
        $teaching = $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $year = $this->leaveYear($this->school, $this->admin);
        $employee = $this->createEmployee($this->school);
        $old = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        app(LeavePolicyAssignmentService::class)->assign($this->school, $old->id, $teaching->id, '2026-04-01', '2026-05-31', $this->admin);
        $this->allocate(['school' => $this->school, 'type' => $this->type, 'year' => $year, 'employment' => $old, 'admin' => $this->admin], 10);
        // The first employment ends (fixture: the separation path itself is HR's).
        $this->inSchool($this->school, fn () => DB::table('employment_records')->where('id', $old->id)->update(['status' => 'separated', 'ends_on' => '2026-05-31']));
        $new = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2026-09-01', 'ends_on' => null, 'staff_type' => 'teaching']);

        $run = app(LeaveEligibilityService::class)->executeRun($this->school, $this->type->id, '2026-10-05', $this->admin);
        $this->assertSame(1, $run->assigned_count);
        $this->assertSame(1, $this->inSchool($this->school, fn () => LeavePolicyAssignment::query()->where('employment_record_id', $new->id)->count()));
        $this->assertSame(0, $this->inSchool($this->school, fn () => LeaveLedgerEntry::query()->where('employment_record_id', $new->id)->count()), 'no balance follows into the new employment');
    }

    #[Test]
    public function a_draft_preview_writes_nothing_and_reports_an_overlap(): void
    {
        $teaching = $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $teacher = $this->employment('teaching');
        $counts = fn () => $this->inSchool($this->school, fn () => [LeavePolicy::query()->count(), LeaveEligibilityRule::query()->count(), LeavePolicyAssignment::query()->count(), LeaveEligibilityRun::query()->count()]);
        $before = $counts();
        $service = app(LeaveEligibilityService::class);

        $replacing = $service->previewDraft($this->school, $this->type->id, '2026-10-05', $teaching->id, 'Teaching v2', 30, [self::rule(StaffType::TEACHING)], $this->admin);
        $this->assertNull($replacing['overlappingPolicyId']);
        $this->assertSame(LeaveEligibilityService::DRAFT_POLICY_ID, collect($replacing['rows'])->firstWhere('employmentRecordId', $teacher->id)['policyId']);

        $adding = $service->previewDraft($this->school, $this->type->id, '2026-10-05', null, 'Everyone', 30, [self::rule()], $this->admin);
        $this->assertSame($teaching->id, $adding['overlappingPolicyId']);
        $this->assertSame('ambiguous', collect($adding['rows'])->firstWhere('employmentRecordId', $teacher->id)['outcome']);
        $this->assertSame($before, $counts());
    }

    #[Test]
    public function each_operation_needs_its_own_capability(): void
    {
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $configurer = $this->createUserWithCapabilities($this->school, ['hr.leave.configure']);
        $service = app(LeaveEligibilityService::class);

        $this->assertSame('ok', $this->code(fn () => $service->applicability($this->school, $this->type->id, '2026-10-05', $viewer)));
        $this->assertSame('forbidden', $this->code(fn () => $service->applicability($this->school, $this->type->id, '2026-10-05', $configurer)));
        $this->assertSame('ok', $this->code(fn () => $service->previewDraft($this->school, $this->type->id, '2026-10-05', null, 'D', 0, [], $configurer)));
        $this->assertSame('forbidden', $this->code(fn () => $service->previewDraft($this->school, $this->type->id, '2026-10-05', null, 'D', 0, [], $viewer)));
        $this->assertSame('forbidden', $this->code(fn () => $service->executeRun($this->school, $this->type->id, '2026-10-05', $viewer)));
        $this->assertSame('forbidden', $this->code(fn () => $service->executeRun($this->school, $this->type->id, '2026-10-05', $configurer)));
        $this->assertSame('forbidden', $this->code(fn () => $this->version('V', [], admin: $viewer)));
    }

    #[Test]
    public function schools_never_see_or_assign_each_other(): void
    {
        $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $mine = $this->employment('teaching');
        $other = $this->createSchool();
        $otherAdmin = $this->leaveAdmin($other);
        $otherType = $this->leaveType($other, $otherAdmin);
        $service = app(LeaveEligibilityService::class);

        $this->assertSame('not_found', $this->code(fn () => $service->applicability($other, $this->type->id, '2026-10-05', $otherAdmin)), 'a foreign leave type is unknown');
        $this->assertSame('not_found', $this->code(fn () => $service->executeRun($other, $this->type->id, '2026-10-05', $otherAdmin)));
        $this->assertSame('forbidden', $this->code(fn () => $service->applicability($other, $otherType->id, '2026-10-05', $this->admin)), 'no capability in the other School');
        $result = $service->applicability($other, $otherType->id, '2026-10-05', $otherAdmin);
        $this->assertNotContains($mine->id, array_column($result['rows'], 'employmentRecordId'));
        $run = $service->executeRun($other, $otherType->id, '2026-10-05', $otherAdmin);
        $this->assertSame(0, $run->assigned_count);
    }

    #[Test]
    public function an_id_has_one_spelling_for_locks_comparison_and_uniqueness(): void
    {
        $category = $this->createEmployeeCategory($this->school);
        $this->assertSame('LEAVE_ELIGIBILITY_RULE_DUPLICATE', $this->code(fn () => $this->version('D', [self::rule(null, $category->id), self::rule(null, strtoupper($category->id))])));
        $policy = app(LeavePolicyService::class)->create($this->school, strtoupper($this->type->id), [
            'name' => 'Upper', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
        ], null, $this->admin, [self::rule(null, strtoupper($category->id))]);
        $this->assertSame($category->id, $this->inSchool($this->school, fn () => LeaveEligibilityRule::query()->where('leave_policy_id', $policy->id)->sole()->employee_category_id));
        $member = $this->employment('non_teaching', $category);

        $run = app(LeaveEligibilityService::class)->executeRun($this->school, strtoupper($this->type->id), '2026-10-05', $this->admin);
        $this->assertSame(1, $run->assigned_count);
        $this->assertSame($policy->id, $this->inSchool($this->school, fn () => LeavePolicyAssignment::query()->where('employment_record_id', $member->id)->sole()->leave_policy_id));
        $preview = app(LeaveEligibilityService::class)->previewDraft($this->school, $this->type->id, '2026-10-05', null, 'X', 0, [self::rule(null, strtoupper($category->id))], $this->admin);
        $this->assertSame($policy->id, $preview['overlappingPolicyId'], 'an upper-case id previews exactly like the stored one');
    }

    #[Test]
    public function rollback_refuses_while_hr9_evidence_exists_and_changes_nothing(): void
    {
        $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $migration = require database_path('migrations/2027_01_28_090000_create_leave_policy_rules_and_eligibility.php');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql'); // the test transaction holds the evidence; the count reads it under the School's context
        try {
            $this->inSchool($this->school, function () use ($migration) {
                try {
                    $migration->down();
                    $this->fail('down() must refuse while HR.9 evidence exists');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('Refusing to roll back HR.9', $e->getMessage());
                }
            });
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertTrue(Schema::hasTable('leave_eligibility_rules'), 'nothing was dropped');
    }

    #[Test]
    public function a_run_needs_an_active_leave_type_and_a_valid_date(): void
    {
        $this->version('Teaching', [self::rule(StaffType::TEACHING)]);
        $service = app(LeaveEligibilityService::class);
        $this->assertSame('LEAVE_DATE_INVALID', $this->code(fn () => $service->executeRun($this->school, $this->type->id, '2026-02-30', $this->admin)));
        $this->inSchool($this->school, fn () => $this->type->forceFill(['status' => 'inactive'])->save());
        $this->assertSame('LEAVE_TYPE_INACTIVE', $this->code(fn () => $service->executeRun($this->school, $this->type->id, '2026-10-05', $this->admin)));
    }
}
