<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C6 (UAT-STF-05, ADR 0075 section 19): an invited staff account is
 * linked to its HR Employee in the browser (Employee page -> Sign-in
 * account), through the governed `EmployeeService::linkUser()` /
 * `unlinkUser()` -- no API client, no SQL. Candidates are names only, from
 * this School's active members who are not linked yet and are not the
 * actor; every refusal is a form message without identifiers.
 */
class EmployeeAccountLinkWebTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function hr(School $school): User
    {
        $actor = $this->fullHrActor($school);
        $this->actingAs($actor)->post("/app/schools/{$school->id}/activate");

        return $actor;
    }

    private function member(School $school, string $name, string $email, string $status = 'active'): User
    {
        $user = $this->createUser(['name' => $name, 'email' => $email]);
        $this->createMembership($user, $school, $status);

        return $user;
    }

    private function linked(School $school, Employee $employee): ?string
    {
        return app(TenantContext::class)->withSchool($school, fn () => $employee->fresh()->user_id);
    }

    private function audits(School $school, string $type, Employee $employee): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('event_type', $type)->where('subject_id', $employee->id)->count());
    }

    #[Test]
    public function an_hr_manager_finds_the_invited_account_by_name_and_links_it(): void
    {
        $school = $this->createSchool();
        $actor = $this->hr($school);
        $pooja = $this->member($school, 'Pooja Sharma', 'Pooja.Sharma@example.test');
        $this->member($school, 'Pooja Sharma', 'p.sharma@example.test'); // same name, other account
        $employee = $this->createEmployee($school, ['user_id' => null, 'full_name' => 'Pooja Sharma', 'work_email' => 'pooja.sharma@example.test']);

        $candidates = $this->getJson("/app/hr/employees/{$employee->id}/account-link/search?q=pooja")->assertOk()->json('candidates');
        $this->assertCount(2, $candidates);
        // The work-email match only ranks (server-side): never returned, so it cannot be probed.
        $this->assertSame($pooja->id, $candidates[0]['userId'], 'the account matching the work email is listed first');
        foreach ($candidates as $candidate) {
            $this->assertSame(['userId', 'name'], array_keys($candidate), 'names only -- no address, no match flag');
        }
        $this->assertNotContains($actor->id, collect($candidates)->pluck('userId'));

        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $pooja->id])
            ->assertRedirect("/app/hr/employees/{$employee->id}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash', 'Account linked.');
        $this->assertSame($pooja->id, $this->linked($school, $employee));
        $this->assertSame(1, $this->audits($school, 'employee.user_linked', $employee));

        $page = $this->get("/app/hr/employees/{$employee->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($page['workspace']['summary']['user_linked']);
        $this->assertSame('Pooja Sharma', $page['linkedAccountName']);

        // Linked accounts are no longer offered.
        $this->assertNotContains($pooja->id, collect($this->getJson("/app/hr/employees/{$employee->id}/account-link/search?q=pooja")->json('candidates'))->pluck('userId'));
    }

    #[Test]
    public function an_already_linked_employee_or_account_is_refused_with_a_message_and_never_reassigned(): void
    {
        $school = $this->createSchool();
        $this->hr($school);
        $first = $this->member($school, 'First Account', 'first@example.test');
        $second = $this->member($school, 'Second Account', 'second@example.test');
        $employee = $this->createEmployee($school, ['user_id' => $first->id]);
        $other = $this->createEmployee($school, ['user_id' => null]);

        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $second->id])
            ->assertSessionHasErrors(['account' => 'This Employee is already linked to an account. Remove that link first.']);
        $this->assertSame($first->id, $this->linked($school, $employee));

        $this->post("/app/hr/employees/{$other->id}/account-link", ['account' => $first->id])
            ->assertSessionHasErrors(['account' => 'That account is already linked to another Employee of this School.']);
        $this->assertNull($this->linked($school, $other));

        // Removing is explicit, audited, and then a different account can be linked.
        $this->post("/app/hr/employees/{$employee->id}/account-link/remove")->assertSessionHasNoErrors()->assertSessionHas('flash', 'Account link removed.');
        $this->assertNull($this->linked($school, $employee));
        $this->assertSame(1, $this->audits($school, 'employee.user_unlinked', $employee));
        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $second->id])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $this->linked($school, $employee));
    }

    #[Test]
    public function another_schools_account_or_employee_is_never_linked_and_never_offered(): void
    {
        $school = $this->createSchool();
        $foreignSchool = $this->createSchool();
        $this->hr($school);
        $outsider = $this->member($foreignSchool, 'Outside Teacher', 'outside@example.test');
        $suspended = $this->member($school, 'Suspended Teacher', 'suspended@example.test', 'suspended');
        $employee = $this->createEmployee($school, ['user_id' => null]);
        $foreignEmployee = $this->createEmployee($foreignSchool, ['user_id' => null]);

        $names = collect($this->getJson("/app/hr/employees/{$employee->id}/account-link/search?q=teacher")->json('candidates'))->pluck('name');
        $this->assertNotContains('Outside Teacher', $names);
        $this->assertNotContains('Suspended Teacher', $names);

        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $outsider->id])
            ->assertSessionHasErrors(['account' => 'That account is not an active member of this School.']);
        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $suspended->id])
            ->assertSessionHasErrors(['account' => 'That account is not an active member of this School.']);
        $this->assertNull($this->linked($school, $employee));

        // The other School's Employee does not exist here.
        $this->post("/app/hr/employees/{$foreignEmployee->id}/account-link", ['account' => $outsider->id])->assertNotFound();
        $this->getJson("/app/hr/employees/{$foreignEmployee->id}/account-link/search?q=a")->assertNotFound();
        $this->assertNull($this->linked($foreignSchool, $foreignEmployee));
    }

    #[Test]
    public function linking_needs_hr_employees_manage_and_never_links_yourself(): void
    {
        $school = $this->createSchool();
        $target = $this->member($school, 'Target Account', 'target@example.test');
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $viewer = $this->createUserWithCapabilities($school, ['hr.employees.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");
        $this->getJson("/app/hr/employees/{$employee->id}/account-link/search?q=t")->assertForbidden();
        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $target->id])->assertForbidden();
        $this->assertNull($this->linked($school, $employee));

        $actor = $this->hr($school);
        // Never yourself (ADR 0071 §26.2): the existing refusal, shown on the page.
        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $actor->id])
            ->assertSessionHasErrors(['self_administration' => 'You cannot change your own employee identity, employment or attendance. Another HR administrator must do this.']);
        $this->assertNull($this->linked($school, $employee));
    }

    #[Test]
    public function an_archived_employee_cannot_be_linked_and_a_malformed_choice_is_a_form_error(): void
    {
        $school = $this->createSchool();
        $this->hr($school);
        $target = $this->member($school, 'Target Account', 'target@example.test');
        $archived = $this->createEmployee($school, ['user_id' => null, 'record_status' => 'archived']);

        $this->post("/app/hr/employees/{$archived->id}/account-link", ['account' => $target->id])
            ->assertSessionHasErrors(['account' => 'An archived Employee cannot be linked to an account.']);
        $this->post("/app/hr/employees/{$archived->id}/account-link", ['account' => 'not-a-uuid'])
            ->assertSessionHasErrors(['account' => 'Choose a Lycenza account from the list.']);
        $this->assertNull($this->linked($school, $archived));
    }

    #[Test]
    public function a_disabled_account_is_never_offered_and_never_linked(): void
    {
        $school = $this->createSchool();
        $this->hr($school);
        $disabled = $this->member($school, 'Disabled Teacher', 'disabled@example.test');
        $disabled->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $this->assertNotContains($disabled->id, collect($this->getJson("/app/hr/employees/{$employee->id}/account-link/search?q=disabled")->json('candidates'))->pluck('userId'));
        $this->post("/app/hr/employees/{$employee->id}/account-link", ['account' => $disabled->id])
            ->assertSessionHasErrors(['account' => 'That account is not an active member of this School.']);
        $this->assertNull($this->linked($school, $employee));
    }
}
