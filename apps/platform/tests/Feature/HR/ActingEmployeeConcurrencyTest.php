<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 section 20): the identity races, between real OS
 * processes against real PostgreSQL, overlap forced and verified
 * (ForcesConcurrentOverlap: the holder's change is uncommitted and the
 * contender is observed blocked on a lock before the holder commits).
 *
 * In each pair, both orders serialize, and the one outcome never accepted
 * is a protected decision (ActingEmployeeResolver::hold(), standing in for
 * a future consumer's authoritative transaction) taken on an identity that
 * had already become ineligible -- or a link established to a membership
 * that had already been suspended.
 */
class ActingEmployeeConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $userIds = [];

    private const string AS_OF = '2026-09-15';

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin = DB::connection('pgsql_admin');
        // Committed fixture actors' `test.capability_grant.*` roles outlive
        // the School (the FeeConcessionConcurrencyTest precedent).
        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $this->userIds)->orWhereIn('subject_id', $this->userIds)->delete();
        $admin->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/acting-employee-op.php', ...$args];
    }

    private function staffScript(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/staff-account-op.php', ...$args];
    }

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;

        return $school;
    }

    private function user(): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;

        return $user;
    }

    /** @return array{0: User, 1: SchoolMembership} */
    private function member(School $school, ?string $role = null): array
    {
        $user = $this->user();
        $membership = $this->createMembership($user, $school);
        if ($role !== null) {
            $this->assignSchoolRole($membership, $role);
        }

        return [$user, $membership];
    }

    private function hrActor(School $school): User
    {
        $actor = $this->fullHrActor($school);
        $this->userIds[] = $actor->id;

        return $actor;
    }

    /** @return array{0: User, 1: Employee, 2: EmploymentRecord} */
    private function staff(School $school): array
    {
        [$user] = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active']);

        return [$user, $employee, $record];
    }

    private function linkedUserId(School $school, string $employeeId): ?string
    {
        return app(TenantContext::class)->withSchool($school, fn () => Employee::query()->whereKey($employeeId)->value('user_id'));
    }

    private function denialReason(User $user, School $school): ?string
    {
        try {
            app(ActingEmployeeResolver::class)->resolve($user, $school, self::AS_OF);
        } catch (ActingEmployeeUnavailableException $e) {
            return $e->reason;
        }

        return null;
    }

    #[Test]
    public function hold_refuses_to_run_outside_a_transaction(): void
    {
        $school = $this->school();
        [$user] = $this->staff($school);
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        app(ActingEmployeeResolver::class)->hold($user, $school, self::AS_OF);
    }

    #[Test]
    public function a_link_racing_a_membership_suspension_never_links_an_already_suspended_member(): void
    {
        $school = $this->school();
        [$admin] = $this->member($school, 'school_admin');
        $this->member($school, 'school_admin');
        $hr = $this->hrActor($school);

        // Link first: the link commits on the still-active membership, then
        // the suspension (blocked on the membership row) proceeds.
        [$target, $membership] = $this->member($school, 'principal');
        $employee = $this->createEmployee($school, ['user_id' => null]);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('link', $school->id, $hr->id, $employee->id, $target->id),
            $this->staffScript('suspend', $school->id, $admin->id, $membership->id),
        );
        $this->assertSame(['linked', 'suspended'], [$holder, $contender]);
        $this->assertSame($target->id, $this->linkedUserId($school, $employee->id), 'Serialized: link, then suspend.');
        $this->assertSame(ActingEmployeeUnavailableException::MEMBERSHIP_NOT_ACTIVE, $this->denialReason($target, $school), 'The stored link never outlives the suspension as an identity.');

        // Suspension first: the link waits for it and then sees a suspended membership.
        [$target2, $membership2] = $this->member($school, 'principal');
        $employee2 = $this->createEmployee($school, ['user_id' => null]);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->staffScript('suspend', $school->id, $admin->id, $membership2->id),
            $this->script('link', $school->id, $hr->id, $employee2->id, $target2->id),
        );
        $this->assertSame(['suspended', 'rejected:HR_UNRELATED_USER_LINKAGE'], [$holder, $contender]);
        $this->assertNull($this->linkedUserId($school, $employee2->id));
    }

    #[Test]
    public function an_unlink_racing_a_protected_decision_serializes_both_ways(): void
    {
        $school = $this->school();
        $hr = $this->hrActor($school);

        [$user, $employee] = $this->staff($school);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('hold', $school->id, $user->id, self::AS_OF),
            $this->script('unlink', $school->id, $hr->id, $employee->id),
        );
        $this->assertSame(['acting:'.$employee->id, 'unlinked'], [$holder, $contender], 'The decision was taken on a live link; the unlink waited for it.');
        $this->assertNull($this->linkedUserId($school, $employee->id));

        [$user2, $employee2] = $this->staff($school);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('unlink', $school->id, $hr->id, $employee2->id),
            $this->script('hold', $school->id, $user2->id, self::AS_OF),
        );
        $this->assertSame(['unlinked', 'denied:'.ActingEmployeeUnavailableException::NOT_LINKED], [$holder, $contender], 'A decision after the unlink never sees the removed link.');
    }

    #[Test]
    public function an_employment_end_racing_a_protected_decision_serializes_both_ways(): void
    {
        $school = $this->school();
        $hr = $this->hrActor($school);

        [$user, $employee, $record] = $this->staff($school);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('hold', $school->id, $user->id, self::AS_OF),
            $this->script('end-employment', $school->id, $hr->id, $record->id, self::AS_OF),
        );
        $this->assertSame(['acting:'.$employee->id, 'ended'], [$holder, $contender]);

        [$user2, , $record2] = $this->staff($school);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-employment', $school->id, $hr->id, $record2->id, self::AS_OF),
            $this->script('hold', $school->id, $user2->id, self::AS_OF),
        );
        $this->assertSame(['ended', 'denied:'.ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT], [$holder, $contender], 'Separated on the as-of date: no longer eligible.');
    }

    #[Test]
    public function an_employee_archive_racing_a_protected_decision_serializes_both_ways(): void
    {
        $school = $this->school();
        $hr = $this->hrActor($school);

        [$user, $employee] = $this->staff($school);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('hold', $school->id, $user->id, self::AS_OF),
            $this->script('archive', $school->id, $hr->id, $employee->id),
        );
        $this->assertSame(['acting:'.$employee->id, 'archived'], [$holder, $contender]);

        [$user2, $employee2] = $this->staff($school);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('archive', $school->id, $hr->id, $employee2->id),
            $this->script('hold', $school->id, $user2->id, self::AS_OF),
        );
        $this->assertSame(['archived', 'denied:'.ActingEmployeeUnavailableException::EMPLOYEE_NOT_ACTIVE], [$holder, $contender]);
    }

    #[Test]
    public function a_membership_suspension_racing_a_protected_decision_serializes_both_ways(): void
    {
        $school = $this->school();
        [$admin] = $this->member($school, 'school_admin');
        $this->member($school, 'school_admin');

        [$user, $employee] = $this->staff($school);
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $user->id)->firstOrFail();
        $this->assignSchoolRole($membership, 'principal');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('hold', $school->id, $user->id, self::AS_OF),
            $this->staffScript('suspend', $school->id, $admin->id, $membership->id),
        );
        $this->assertSame(['acting:'.$employee->id, 'suspended'], [$holder, $contender]);

        [$user2] = $this->staff($school);
        $membership2 = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $user2->id)->firstOrFail();
        $this->assignSchoolRole($membership2, 'principal');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->staffScript('suspend', $school->id, $admin->id, $membership2->id),
            $this->script('hold', $school->id, $user2->id, self::AS_OF),
        );
        $this->assertSame(['suspended', 'denied:'.ActingEmployeeUnavailableException::MEMBERSHIP_NOT_ACTIVE], [$holder, $contender]);
    }

    #[Test]
    public function one_user_linked_concurrently_to_two_employees_of_one_school_links_once(): void
    {
        $school = $this->school();
        $hr = $this->hrActor($school);
        [$target] = $this->member($school);
        $first = $this->createEmployee($school, ['user_id' => null]);
        $second = $this->createEmployee($school, ['user_id' => null]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('link', $school->id, $hr->id, $first->id, $target->id),
            $this->script('link', $school->id, $hr->id, $second->id, $target->id),
        );

        $this->assertSame(['linked', 'rejected:HR_USER_ALREADY_LINKED'], [$holder, $contender]);
        $this->assertSame([$target->id, null], [$this->linkedUserId($school, $first->id), $this->linkedUserId($school, $second->id)]);
    }

    #[Test]
    public function two_users_linked_concurrently_to_one_employee_link_once(): void
    {
        $school = $this->school();
        $hr = $this->hrActor($school);
        [$a] = $this->member($school);
        [$b] = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('link', $school->id, $hr->id, $employee->id, $a->id),
            $this->script('link', $school->id, $hr->id, $employee->id, $b->id),
        );

        $this->assertSame(['linked', 'rejected:HR_EMPLOYEE_ALREADY_LINKED'], [$holder, $contender]);
        $this->assertSame($a->id, $this->linkedUserId($school, $employee->id));
        $audits = app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('event_type', 'employee.user_linked')->where('subject_id', $employee->id)->count());
        $this->assertSame(1, $audits, 'Exactly one link audited.');
    }
}
