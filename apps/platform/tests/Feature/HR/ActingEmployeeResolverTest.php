<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 sections 4-5, D-08): the verified ActingEmployee
 * identity chain -- User -> active membership -> linked active Employee
 * -> exactly one eligible current EmploymentRecord -- resolves only when
 * every link holds, fails closed on each missing link, never infers an
 * identity from anything but (school_id, user_id), and grants nothing.
 */
class ActingEmployeeResolverTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const string AS_OF = '2026-09-15';

    private function resolver(): ActingEmployeeResolver
    {
        return app(ActingEmployeeResolver::class);
    }

    /**
     * An enabled member linked to an active Employee with one employment.
     *
     * @param  array<string, mixed>  $employment
     * @return array{0: User, 1: Employee, 2: EmploymentRecord}
     */
    private function staff(School $school, array $employment = []): array
    {
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);
        $record = $this->createEmploymentRecord($employee, array_merge([
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'status' => 'active',
        ], $employment));

        return [$user, $employee, $record];
    }

    private function assertDenied(string $reason, User $user, School $school, ?string $asOf = self::AS_OF): void
    {
        try {
            $this->resolver()->resolve($user, $school, $asOf);
        } catch (ActingEmployeeUnavailableException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('HR_ACTING_EMPLOYEE_UNAVAILABLE', $e->errorCode());

            return;
        }

        $this->fail("ActingEmployee resolved although it must fail closed ({$reason}).");
    }

    #[Test]
    public function an_enabled_active_member_linked_to_an_active_employee_with_current_employment_resolves(): void
    {
        $school = $this->createSchool();
        [$user, $employee, $record] = $this->staff($school);

        $acting = $this->resolver()->resolve($user, $school, self::AS_OF);

        $this->assertEquals(new ActingEmployee($school->id, $user->id, $employee->id, $record->id, self::AS_OF), $acting);
    }

    #[Test]
    public function a_disabled_user_is_denied(): void
    {
        $school = $this->createSchool();
        [$user] = $this->staff($school);
        DB::table('users')->where('id', $user->id)->update(['is_disabled' => true, 'disabled_at' => now()]);

        // The passed-in model still says "enabled": the resolver re-reads.
        $this->assertFalse($user->isDisabled());
        $this->assertDenied(ActingEmployeeUnavailableException::USER_UNAVAILABLE, $user, $school);
    }

    #[Test]
    public function no_membership_is_denied(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        // Linked without a membership (raw fixture): the resolver still refuses.
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'status' => 'active']);

        $this->assertDenied(ActingEmployeeUnavailableException::MEMBERSHIP_NOT_ACTIVE, $user, $school);
    }

    #[Test]
    public function an_invited_or_suspended_membership_is_denied(): void
    {
        foreach (['invited', 'suspended'] as $status) {
            $school = $this->createSchool();
            [$user] = $this->staff($school);
            DB::table('school_memberships')->where('user_id', $user->id)->where('school_id', $school->id)->update(['status' => $status]);

            $this->assertDenied(ActingEmployeeUnavailableException::MEMBERSHIP_NOT_ACTIVE, $user, $school);
        }
    }

    #[Test]
    public function a_membership_only_at_another_school_is_denied(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        [$user] = $this->staff($schoolA);

        $this->assertDenied(ActingEmployeeUnavailableException::MEMBERSHIP_NOT_ACTIVE, $user, $schoolB);
    }

    #[Test]
    public function a_school_that_is_not_operational_is_denied(): void
    {
        $school = $this->createSchool();
        [$user] = $this->staff($school);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);

        $this->assertDenied(ActingEmployeeUnavailableException::SCHOOL_NOT_OPERATIONAL, $user, $school);
    }

    #[Test]
    public function an_active_member_with_no_linked_employee_is_denied(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->createEmployee($school, ['user_id' => null]);

        $this->assertDenied(ActingEmployeeUnavailableException::NOT_LINKED, $user, $school);
    }

    #[Test]
    public function an_employee_linked_to_another_user_is_never_this_users_identity(): void
    {
        $school = $this->createSchool();
        [$owner] = $this->staff($school);
        $other = $this->createUser();
        $this->createMembership($other, $school);

        $this->assertDenied(ActingEmployeeUnavailableException::NOT_LINKED, $other, $school);
        $this->assertSame($owner->id, $this->resolver()->resolve($owner, $school, self::AS_OF)->userId);
    }

    #[Test]
    public function an_archived_employee_is_denied(): void
    {
        $school = $this->createSchool();
        [$user, $employee] = $this->staff($school);
        app(TenantContext::class)->withSchool($school, fn () => $employee->update(['record_status' => 'archived']));

        $this->assertDenied(ActingEmployeeUnavailableException::EMPLOYEE_NOT_ACTIVE, $user, $school);
    }

    #[Test]
    public function no_employment_record_is_denied(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->createEmployee($school, ['user_id' => $user->id]);

        $this->assertDenied(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT, $user, $school);
    }

    /** @return array<string, array{0: string}> */
    public static function ineligibleStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'pre_joining' => ['pre_joining'],
            'separated' => ['separated'],
            'terminated' => ['terminated'],
            'retired' => ['retired'],
            'deceased' => ['deceased'],
        ];
    }

    #[Test]
    #[DataProvider('ineligibleStatuses')]
    public function an_ineligible_employment_status_is_denied_even_on_current_dates(string $status): void
    {
        $school = $this->createSchool();
        [$user] = $this->staff($school, ['status' => $status]);

        $this->assertDenied(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT, $user, $school);
    }

    #[Test]
    public function active_and_notice_period_employment_resolve(): void
    {
        foreach (EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES as $status) {
            $school = $this->createSchool();
            [$user, , $record] = $this->staff($school, ['status' => $status]);

            $this->assertSame($record->id, $this->resolver()->resolve($user, $school, self::AS_OF)->employmentRecordId);
        }

        $this->assertSame(['active', 'notice_period'], EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES);
    }

    #[Test]
    public function the_employment_date_range_is_inclusive_at_both_ends(): void
    {
        $school = $this->createSchool();

        [$startsToday] = $this->staff($school, ['starts_on' => self::AS_OF]);
        $this->resolver()->resolve($startsToday, $school, self::AS_OF);

        [$endsToday] = $this->staff($school, ['starts_on' => '2026-01-01', 'ends_on' => self::AS_OF, 'status' => 'notice_period']);
        $this->resolver()->resolve($endsToday, $school, self::AS_OF);

        [$startsTomorrow] = $this->staff($school, ['starts_on' => '2026-09-16']);
        $this->assertDenied(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT, $startsTomorrow, $school);

        [$endedYesterday] = $this->staff($school, ['starts_on' => '2026-01-01', 'ends_on' => '2026-09-14', 'status' => 'active']);
        $this->assertDenied(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT, $endedYesterday, $school);
    }

    #[Test]
    public function a_past_employment_with_a_current_rehire_resolves_to_the_current_record(): void
    {
        $school = $this->createSchool();
        [$user, $employee] = $this->staff($school, ['starts_on' => '2024-01-01', 'ends_on' => '2025-06-30', 'status' => 'separated']);
        $current = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'status' => 'active']);

        $this->assertSame($current->id, $this->resolver()->resolve($user, $school, self::AS_OF)->employmentRecordId);
    }

    #[Test]
    public function two_simultaneously_eligible_employment_records_fail_closed_instead_of_picking_one(): void
    {
        $school = $this->createSchool();
        [$user, $employee] = $this->staff($school);
        // Corrupt/legacy data only: EmploymentService refuses this overlap.
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-06-01', 'status' => 'notice_period']);

        $this->assertDenied(ActingEmployeeUnavailableException::AMBIGUOUS_EMPLOYMENT, $user, $school);
    }

    #[Test]
    public function the_default_as_of_date_is_today_in_the_schools_timezone(): void
    {
        // 20:00 UTC on 30 September is already 1 October in Asia/Kolkata.
        Carbon::setTestNow('2026-09-30 20:00:00 UTC');

        try {
            $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
            [$user] = $this->staff($school, ['starts_on' => '2026-10-01']);

            $this->assertSame('2026-10-01', $this->resolver()->resolve($user, $school)->asOf);

            $utcSchool = $this->createSchool(['timezone' => 'UTC']);
            [$utcUser] = $this->staff($utcSchool, ['starts_on' => '2026-10-01']);
            $this->assertDenied(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT, $utcUser, $utcSchool, null);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_malformed_as_of_date_is_a_programming_error(): void
    {
        $school = $this->createSchool();
        [$user] = $this->staff($school);

        foreach (['2026-9-15', '2026-02-30', '15/09/2026', 'today'] as $bad) {
            try {
                $this->resolver()->resolve($user, $school, $bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_same_user_resolves_to_the_right_employee_at_each_school_and_never_across(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        [$user, $employeeA] = $this->staff($schoolA);
        $this->createMembership($user, $schoolB);
        $employeeB = $this->createEmployee($schoolB, ['user_id' => $user->id]);
        $this->createEmploymentRecord($employeeB, ['starts_on' => '2026-01-01', 'status' => 'active']);

        $this->assertSame($employeeA->id, $this->resolver()->resolve($user, $schoolA, self::AS_OF)->employeeId);
        $this->assertSame($employeeB->id, $this->resolver()->resolve($user, $schoolB, self::AS_OF)->employeeId);

        // Even with School B's tenant context active, resolving for A
        // answers A only (the School is the trusted argument).
        $acting = app(TenantContext::class)->withSchool($schoolB, fn () => $this->resolver()->resolve($user, $schoolA, self::AS_OF));
        $this->assertSame($employeeA->id, $acting->employeeId);
        $this->assertSame($schoolA->id, $acting->schoolId);
    }

    #[Test]
    public function an_employee_of_another_school_is_never_resolved_even_with_a_membership_here(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        [$user] = $this->staff($schoolA);
        $this->createMembership($user, $schoolB);

        $this->assertDenied(ActingEmployeeUnavailableException::NOT_LINKED, $user, $schoolB);
    }

    #[Test]
    public function identity_is_never_inferred_from_email_employee_number_or_name(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser(['name' => 'Asha Verma', 'email' => 'asha.verma.'.uniqid().'@example.test']);
        $this->createMembership($user, $school);
        // An unlinked Employee sharing every convenience identifier.
        $employee = $this->createEmployee($school, [
            'user_id' => null,
            'full_name' => 'Asha Verma',
            'work_email' => $user->email,
        ]);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'status' => 'active']);

        $this->assertDenied(ActingEmployeeUnavailableException::NOT_LINKED, $user, $school);
    }

    #[Test]
    public function acting_employee_grants_no_capability_and_capabilities_do_not_need_an_employee(): void
    {
        $school = $this->createSchool();
        [$user] = $this->staff($school);
        $capabilities = app(CapabilityResolver::class);

        $this->resolver()->resolve($user, $school, self::AS_OF);
        $this->assertSame([], $capabilities->schoolCapabilities($user, $school), 'A verified identity carries no capability of its own.');

        [$admin, $adminSchool] = $this->createSchoolAdmin();
        $this->assertTrue($capabilities->canInSchool($admin, 'hr.employees.manage', $adminSchool), 'A School Admin needs no Employee record for its capabilities.');
        $this->assertDenied(ActingEmployeeUnavailableException::NOT_LINKED, $admin, $adminSchool);
    }

    #[Test]
    public function hold_resolves_the_same_identity_inside_a_transaction(): void
    {
        $school = $this->createSchool();
        [$user, $employee] = $this->staff($school);

        // (hold() outside any transaction is refused -- proven in the
        // non-transactional ActingEmployeeConcurrencyTest, since this
        // class always runs inside DatabaseTransactions' own transaction.)
        $held = DB::transaction(fn () => $this->resolver()->hold($user, $school, self::AS_OF));
        $this->assertEquals($this->resolver()->resolve($user, $school, self::AS_OF), $held);
        $this->assertSame($employee->id, $held->employeeId);
    }

    #[Test]
    public function hold_fails_closed_exactly_like_resolve(): void
    {
        $school = $this->createSchool();
        [$user] = $this->staff($school, ['status' => 'separated']);

        try {
            DB::transaction(fn () => $this->resolver()->hold($user, $school, self::AS_OF));
            $this->fail('hold() resolved an ineligible employment.');
        } catch (ActingEmployeeUnavailableException $e) {
            $this->assertSame(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT, $e->reason);
        }
    }
}
