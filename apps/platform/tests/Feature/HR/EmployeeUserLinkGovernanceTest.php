<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAlreadyLinkedException;
use App\Domain\HR\Application\Exceptions\EmployeeNotActiveException;
use App\Domain\HR\Application\Exceptions\EmployeeNotLinkedException;
use App\Domain\HR\Application\Exceptions\EmployeeUserLinkNotEditableException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Application\Exceptions\UserAlreadyLinkedException;
use App\Domain\HR\Events\EmployeeUpdated;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 section 6, D-09): the Employee<->User link is an
 * authorization input with its own explicit lifecycle -- linkUser() /
 * unlinkUser() (and create()'s optional user_id through the same
 * primitive), under hr.employees.manage, requiring an enabled User with an
 * ACTIVE membership at the Employee's School, audited as
 * employee.user_linked / employee.user_unlinked. A generic update can no
 * longer touch it.
 */
class EmployeeUserLinkGovernanceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    private function member(School $school, string $status = 'active'): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school, $status);

        return $user;
    }

    /** @return list<SchoolAuditEvent> */
    private function linkAudits(School $school, Employee $employee): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->whereIn('event_type', ['employee.user_linked', 'employee.user_unlinked'])
            ->where('subject_id', $employee->id)
            ->orderBy('occurred_at')->orderBy('id')
            ->get()->all());
    }

    private function assertLinkRefused(School $school, User $target, string $reason): void
    {
        $employee = $this->createEmployee($school, ['user_id' => null]);

        try {
            $this->service()->linkUser($employee, $target->id, $this->fullHrActor($school));
            $this->fail('The link was accepted.');
        } catch (UnrelatedUserLinkageException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(422, $e->getStatusCode());
        }

        app(TenantContext::class)->set($school);
        $this->assertNull($employee->fresh()->user_id);
        $this->assertSame([], $this->linkAudits($school, $employee));
    }

    #[Test]
    public function an_active_member_is_linked_and_the_link_is_audited_with_ids_only(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $target = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);
        Event::fake([EmployeeUpdated::class]);

        $linked = $this->service()->linkUser($employee, $target->id, $actor);

        $this->assertSame($target->id, $linked->user_id);
        [$audit] = $this->linkAudits($school, $employee);
        $this->assertSame('employee.user_linked', $audit->event_type);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($school->id, $audit->school_id);
        $this->assertEquals(['employeeId' => $employee->id, 'previousUserId' => null, 'newUserId' => $target->id], $audit->metadata);
        $this->assertStringNotContainsString($target->email, json_encode($audit->metadata));
        Event::assertDispatched(EmployeeUpdated::class, fn (EmployeeUpdated $e) => $e->employeeId === $employee->id);
    }

    #[Test]
    public function an_invited_member_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->assertLinkRefused($school, $this->member($school, 'invited'), UnrelatedUserLinkageException::MEMBERSHIP_NOT_ACTIVE);
    }

    #[Test]
    public function a_suspended_member_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->assertLinkRefused($school, $this->member($school, 'suspended'), UnrelatedUserLinkageException::MEMBERSHIP_NOT_ACTIVE);
    }

    #[Test]
    public function a_disabled_user_is_rejected_even_with_an_active_membership(): void
    {
        $school = $this->createSchool();
        $target = $this->member($school);
        DB::table('users')->where('id', $target->id)->update(['is_disabled' => true, 'disabled_at' => now()]);

        $this->assertLinkRefused($school, $target, UnrelatedUserLinkageException::USER_UNAVAILABLE);
    }

    #[Test]
    public function a_user_with_no_membership_or_only_another_schools_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->assertLinkRefused($school, $this->createUser(), UnrelatedUserLinkageException::NO_MEMBERSHIP);

        $elsewhere = $this->member($this->createSchool());
        $this->assertLinkRefused($school, $elsewhere, UnrelatedUserLinkageException::NO_MEMBERSHIP);
    }

    #[Test]
    public function every_refusal_reason_answers_with_the_same_non_enumerating_message(): void
    {
        $messages = array_unique(array_map(
            fn (string $reason) => (new UnrelatedUserLinkageException('u', 's', $reason))->getMessage(),
            [UnrelatedUserLinkageException::NO_MEMBERSHIP, UnrelatedUserLinkageException::MEMBERSHIP_NOT_ACTIVE, UnrelatedUserLinkageException::USER_UNAVAILABLE],
        ));

        $this->assertCount(1, $messages);
    }

    #[Test]
    public function create_with_a_user_id_links_through_the_same_rules_and_audit(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $target = $this->member($school);

        $employee = $this->service()->create($school, ['full_name' => 'Asha Verma', 'user_id' => $target->id], $actor);

        $this->assertSame($target->id, $employee->user_id);
        $this->assertSame(['employee.user_linked'], array_map(fn ($a) => $a->event_type, $this->linkAudits($school, $employee)));

        $this->expectException(UnrelatedUserLinkageException::class);
        $this->service()->create($school, ['full_name' => 'Invited Person', 'user_id' => $this->member($school, 'invited')->id], $actor);
    }

    #[Test]
    public function a_refused_link_at_creation_creates_no_employee_and_consumes_no_employee_number(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $first = $this->service()->create($school, ['full_name' => 'First'], $actor);

        try {
            $this->service()->create($school, ['full_name' => 'Refused', 'user_id' => $this->member($school, 'suspended')->id], $actor);
            $this->fail('Created an Employee linked to a suspended member.');
        } catch (UnrelatedUserLinkageException) {
        }

        $second = $this->service()->create($school, ['full_name' => 'Second'], $actor);
        app(TenantContext::class)->set($school);
        $this->assertSame(2, Employee::query()->count());
        $this->assertSame((int) substr($first->employee_number, -6) + 1, (int) substr($second->employee_number, -6));
    }

    #[Test]
    public function the_same_user_may_be_an_employee_in_two_schools_but_not_twice_in_one(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->member($schoolA);
        $this->createMembership($user, $schoolB);

        $this->service()->create($schoolA, ['full_name' => 'Asha', 'user_id' => $user->id], $this->fullHrActor($schoolA));
        $this->service()->create($schoolB, ['full_name' => 'Asha', 'user_id' => $user->id], $this->fullHrActor($schoolB));

        $second = $this->createEmployee($schoolA, ['user_id' => null]);
        try {
            $this->service()->linkUser($second, $user->id, $this->fullHrActor($schoolA));
            $this->fail('One User was linked to two Employees of one School.');
        } catch (UserAlreadyLinkedException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        app(TenantContext::class)->set($schoolA);
        $this->assertNull($second->fresh()->user_id);
        $this->assertSame([], $this->linkAudits($schoolA, $second));
    }

    #[Test]
    public function an_already_linked_employee_is_never_relinked_directly(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $a = $this->member($school);
        $b = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => $a->id]);

        $this->expectException(EmployeeAlreadyLinkedException::class);
        $this->service()->linkUser($employee, $b->id, $actor);
    }

    #[Test]
    public function changing_the_linked_user_is_unlink_then_link_with_both_steps_audited(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $a = $this->member($school);
        $b = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->service()->linkUser($employee, $a->id, $actor);
        $this->service()->unlinkUser($employee, $actor);
        $relinked = $this->service()->linkUser($employee, $b->id, $actor);

        $this->assertSame($b->id, $relinked->user_id);
        $audits = $this->linkAudits($school, $employee);
        $this->assertSame(['employee.user_linked', 'employee.user_unlinked', 'employee.user_linked'], array_map(fn ($x) => $x->event_type, $audits));
        $this->assertEquals(['employeeId' => $employee->id, 'previousUserId' => $a->id, 'newUserId' => null], $audits[1]->metadata);
        $this->assertEquals(['employeeId' => $employee->id, 'previousUserId' => null, 'newUserId' => $b->id], $audits[2]->metadata);
    }

    #[Test]
    public function unlinking_deletes_nothing_and_an_unlinked_employee_cannot_be_unlinked_again(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $user = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'status' => 'active']);

        $this->service()->unlinkUser($employee, $actor);

        app(TenantContext::class)->set($school);
        $this->assertNull($employee->fresh()->user_id);
        $this->assertNotNull($record->fresh(), 'HR history stays.');
        $this->assertSame('active', DB::table('school_memberships')->where('user_id', $user->id)->where('school_id', $school->id)->value('status'));
        $this->assertNotNull(User::query()->find($user->id));

        $this->expectException(EmployeeNotLinkedException::class);
        $this->service()->unlinkUser($employee, $actor);
    }

    #[Test]
    public function an_archived_employee_cannot_be_linked_but_can_be_unlinked(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $archivedUnlinked = $this->createEmployee($school, ['user_id' => null, 'record_status' => 'archived']);

        try {
            $this->service()->linkUser($archivedUnlinked, $this->member($school)->id, $actor);
            $this->fail('An archived Employee was linked.');
        } catch (EmployeeNotActiveException) {
        }

        $archivedLinked = $this->createEmployee($school, ['user_id' => $this->member($school)->id, 'record_status' => 'archived']);
        $this->assertNull($this->service()->unlinkUser($archivedLinked, $actor)->user_id);
    }

    #[Test]
    public function the_generic_update_refuses_user_id_even_when_it_would_change_nothing(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $user = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);

        foreach ([['user_id' => $this->member($school)->id], ['user_id' => null], ['user_id' => $user->id, 'full_name' => 'Renamed']] as $attributes) {
            try {
                $this->service()->update($employee, $attributes, $actor);
                $this->fail('update() accepted user_id.');
            } catch (EmployeeUserLinkNotEditableException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        app(TenantContext::class)->set($school);
        $fresh = $employee->fresh();
        $this->assertSame($user->id, $fresh->user_id);
        $this->assertNotSame('Renamed', $fresh->full_name);
    }

    #[Test]
    public function linking_and_unlinking_require_hr_employees_manage(): void
    {
        $school = $this->createSchool();
        $target = $this->member($school);
        $unlinked = $this->createEmployee($school, ['user_id' => null]);
        $linked = $this->createEmployee($school, ['user_id' => $this->member($school)->id]);

        foreach ([[], ['hr.employees.view', 'hr.employees.personal.manage', 'hr.employees.assignments.manage']] as $capabilities) {
            $actor = $this->createUserWithCapabilities($school, $capabilities);

            foreach ([fn () => $this->service()->linkUser($unlinked, $target->id, $actor), fn () => $this->service()->unlinkUser($linked, $actor)] as $attempt) {
                try {
                    $attempt();
                    $this->fail('An actor without hr.employees.manage changed a link.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        app(TenantContext::class)->set($school);
        $this->assertNull($unlinked->fresh()->user_id);
        $this->assertNotNull($linked->fresh()->user_id);
    }

    #[Test]
    public function a_manager_of_another_school_cannot_link_this_schools_employee(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->expectException(AuthorizationException::class);
        $this->service()->linkUser($employee, $this->member($school)->id, $this->fullHrActor($other));
    }

    #[Test]
    public function a_rolled_back_link_leaves_neither_the_link_nor_its_audit(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $target = $this->member($school);
        $employee = $this->createEmployee($school, ['user_id' => null]);

        try {
            DB::transaction(function () use ($employee, $target, $actor): void {
                $this->service()->linkUser($employee, $target->id, $actor);

                throw new RuntimeException('caller aborts after the link');
            });
        } catch (RuntimeException) {
        }

        app(TenantContext::class)->set($school);
        $this->assertNull($employee->fresh()->user_id);
        $this->assertSame([], $this->linkAudits($school, $employee));
    }
}
