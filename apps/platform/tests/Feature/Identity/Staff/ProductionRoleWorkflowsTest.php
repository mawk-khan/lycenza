<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SR.4 (ADR 0071 §26.3-§26.6): each SR.3 production role over the real
 * browser (and, where it is the only surface, JSON) routes -- the work it
 * was designed for opens, the excluded work refuses -- with the role
 * granted through the real SR.2 rule by the School's own administrator,
 * never a test-only capability set. Ordinary operational pages need no
 * MFA step-up (ADR 0071 §26.7, "avoid over-MFA").
 */
class ProductionRoleWorkflowsTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, ProvidesSensitiveActionMfa, StaffAccountTestHelpers;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->staffAdmin();
    }

    /** A staff member holding exactly the given production roles (plus staff_self_service). */
    private function holder(string ...$roles): User
    {
        [$user, $membership] = $this->staffMember($this->school, 'staff_self_service');
        foreach ($roles as $role) {
            app(StaffAccessService::class)->grantRole($this->school, $this->admin, $membership->id, $role);
        }

        return $user;
    }

    /**
     * @param  list<string>  $allowed  GET paths that must open
     * @param  list<string>  $refused  GET paths that must refuse
     */
    private function pages(User $user, array $allowed, array $refused): void
    {
        $this->enterSchool($user, $this->school);
        foreach ($allowed as $path) {
            $this->get($path)->assertOk();
        }
        foreach ($refused as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    private function inSchool(callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $callback);
    }

    #[Test]
    public function the_payroll_officer_prepares_payroll_and_holds_no_checker_accounting_or_hr_authority(): void
    {
        $officer = $this->holder('payroll_officer');
        $this->pages($officer,
            ['/app/payroll', '/app/payroll/periods', '/app/payroll/structures', '/app/payroll/components', '/app/payroll/compensation'],
            ['/app/payroll/accounting', '/app/finance/journal-entries', '/app/hr/employees', '/app/settings/staff'],
        );

        $token = $this->mfaToken($officer);
        $api = "/api/v1/schools/{$this->school->id}";
        foreach (['approve', 'post', 'reverse'] as $action) {
            $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('Idempotency-Key', "sr4-po-{$action}")
                ->postJson("{$api}/payroll-runs/".fake()->uuid()."/{$action}", $this->mfaBody($token))->assertForbidden();
        }
        $this->postJson("{$api}/payroll-accounting-configuration", [])->assertForbidden();
    }

    #[Test]
    public function the_accountant_posts_and_records_but_never_reverses_closes_or_approves(): void
    {
        $accountant = $this->holder('accountant');
        $this->pages($accountant,
            ['/app/finance/journal-entries', '/app/finance/journal-entries/create', '/app/finance/payments', '/app/finance/payments/record', '/app/finance/concessions'],
            ['/app/payroll/periods', '/app/hr/employees', '/app/settings/staff'],
        );

        [$cash, $income] = $this->inSchool(fn () => [
            LedgerAccount::factory()->for($this->school, 'school')->type('asset')->create(),
            LedgerAccount::factory()->for($this->school, 'school')->type('income')->create(),
        ]);
        $this->post('/app/finance/journal-entries', ['currency' => 'INR', 'description' => 'Fees', 'mfa_code' => $this->freshMfaCode($accountant), 'lines' => [
            ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '5.00'],
            ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '5.00'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $entry = $this->inSchool(fn () => JournalEntry::query()->sole());

        $this->post("/app/finance/journal-entries/{$entry->id}/reverse", ['mfa_code' => $this->freshMfaCode($accountant)])->assertForbidden();
        $this->post('/app/finance/periods/'.fake()->uuid().'/close', ['confirmation' => 'x', 'mfa_code' => $this->freshMfaCode($accountant)])->assertForbidden();
        $this->post('/app/finance/concessions/'.fake()->uuid().'/approve', ['mfa_code' => $this->freshMfaCode($accountant)])->assertForbidden();
    }

    #[Test]
    public function the_cashier_finds_students_through_the_payment_seam_without_any_student_capability(): void
    {
        $cashier = $this->holder('cashier');
        $this->createStudent($this->school, ['first_name' => 'Meera', 'student_number' => 'S-1']);
        $this->pages($cashier,
            ['/app/finance/payments', '/app/finance/payments/record'],
            ['/app/students', '/app/finance/journal-entries/create', '/app/finance/concessions'],
        );

        $this->getJson('/app/finance/payments/record/students/search?q=Meera')->assertOk()->assertJsonPath('data.0.studentNumber', 'S-1');
        $this->post('/app/finance/journal-entries', ['mfa_code' => $this->freshMfaCode($cashier)])->assertForbidden();
    }

    #[Test]
    public function the_librarian_runs_the_library_and_never_sets_or_voids_fines(): void
    {
        $librarian = $this->holder('librarian');
        $this->pages($librarian, ['/app/library/titles', '/app/library/circulation'], ['/app/students', '/app/finance/payments']);

        $token = $this->mfaToken($librarian);
        $api = "/api/v1/schools/{$this->school->id}";
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson("{$api}/library-fine-policy")->assertOk();
        $this->withHeader('Idempotency-Key', 'sr4-lib-policy')->postJson("{$api}/library-fine-policy/versions", [])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'sr4-lib-void')->postJson("{$api}/library-fines/".fake()->uuid().'/void', ['reason' => 'x'])->assertForbidden();
    }

    #[Test]
    public function the_operational_desk_roles_open_their_own_modules_only(): void
    {
        $this->pages($this->holder('transport_coordinator'),
            ['/app/transport/routes', '/app/transport/vehicles', '/app/transport/assignments', '/app/transport/operations'],
            ['/app/finance/payments', '/app/students', '/app/hostels', '/app/canteen-orders'],
        );
        $this->pages($this->holder('hostel_warden'),
            ['/app/hostels', '/app/hostel-residency'],
            ['/app/finance/payments', '/app/students', '/app/transport/routes'],
        );
        $this->pages($this->holder('front_office'),
            ['/app/visitor/directory', '/app/visitor/visits'],
            ['/app/students', '/app/hr/employees', '/app/finance/payments', '/app/settings/staff'],
        );
        $this->pages($this->holder('stores_officer'),
            ['/app/inventory-items', '/app/inventory-locations', '/app/inventory-stock'],
            ['/app/canteen-orders', '/app/canteen-settings', '/app/finance/payments'],
        );
        $this->pages($this->holder('canteen_operator'),
            ['/app/canteen-items', '/app/canteen-outlets', '/app/canteen-orders'],
            ['/app/canteen-settings', '/app/inventory-stock', '/app/finance/payments'],
        );

        // Canteen + stores: the union of the two modules, and nothing new (settings stay admin-only).
        $this->pages($this->holder('canteen_operator', 'stores_officer'),
            ['/app/canteen-orders', '/app/inventory-stock'],
            ['/app/canteen-settings', '/app/finance/payments', '/app/finance/journal-entries'],
        );
    }

    #[Test]
    public function lookup_seams_return_active_records_only(): void
    {
        $this->createStudent($this->school, ['first_name' => 'Zara', 'last_name' => 'Active', 'student_number' => 'S-ZA', 'status' => 'active']);
        $this->createStudent($this->school, ['first_name' => 'Zara', 'last_name' => 'Gone', 'student_number' => 'S-ZG', 'status' => 'inactive']);
        $this->createVisitor($this->school, ['full_name' => 'Kiran Active', 'status' => 'active']);
        $this->createVisitor($this->school, ['full_name' => 'Kiran Gone', 'status' => 'inactive']);

        $this->enterSchool($this->holder('transport_coordinator', 'hostel_warden', 'librarian'), $this->school);
        foreach (['/app/transport/assignments/search/students', '/app/hostel-residency/search/students', '/app/library/circulation/search/students'] as $search) {
            $this->assertSame(['S-ZA'], array_column($this->getJson("{$search}?q=Zara")->assertOk()->json('data'), 'studentNumber'), $search);
        }
        $this->enterSchool($this->holder('front_office'), $this->school);
        $this->assertSame(['Kiran Active'], array_column($this->getJson('/app/visitor/visits/search/visitors?q=Kiran')->assertOk()->json('data'), 'fullName'));
    }

    #[Test]
    public function the_admissions_officer_runs_admissions_without_student_or_guardian_administration(): void
    {
        $officer = $this->holder('admissions_officer');
        $student = $this->createStudent($this->school);
        $guardian = $this->createGuardian($this->school);
        $this->pages($officer, ['/app/admissions', '/app/admissions/applicants'], ['/app/students', "/app/students/{$student->id}/edit", '/app/guardians', "/app/guardians/{$guardian->id}/edit"]);

        $this->put("/app/students/{$student->id}", ['first_name' => 'Changed'])->assertForbidden();
        $this->post("/app/guardians/{$guardian->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->post("/app/students/{$student->id}/guardians/link", ['guardian_id' => $guardian->id])->assertForbidden();
    }

    #[Test]
    public function the_communications_coordinator_sends_but_never_approves_declares_an_emergency_or_reaches_students(): void
    {
        $coordinator = $this->holder('communications_coordinator');
        $this->pages($coordinator,
            ['/app/communications/announcements', '/app/communications/templates', '/app/communications/conversations'],
            ['/app/communications/approvals', '/app/communications/participants/search/students?q=a'],
        );

        $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide', 'requirement' => 'required',
            'dispatch_mode' => 'emergency', 'emergency_justification' => 'Fire.', 'emergency_acknowledged' => true,
        ])->assertSessionHasErrors('dispatch_mode');
    }

    #[Test]
    public function one_person_with_different_roles_in_two_schools_works_in_each_and_only_there(): void
    {
        [$adminB, $schoolB] = $this->staffAdmin();
        [$person, $membershipA] = $this->staffMember($this->school, 'staff_self_service');
        $membershipB = SchoolMembership::query()->create(['user_id' => $person->id, 'school_id' => $schoolB->id, 'status' => SchoolMembership::STATUS_ACTIVE]);
        $this->assignSchoolRole($membershipB, 'staff_self_service');
        app(StaffAccessService::class)->grantRole($this->school, $this->admin, $membershipA->id, 'librarian');
        app(StaffAccessService::class)->grantRole($schoolB, $adminB, $membershipB->id, 'accountant');
        [$cash, $income] = app(TenantContext::class)->withSchool($schoolB, fn () => [
            LedgerAccount::factory()->for($schoolB, 'school')->type('asset')->create(),
            LedgerAccount::factory()->for($schoolB, 'school')->type('income')->create(),
        ]);
        $journal = ['currency' => 'INR', 'description' => 'Fees', 'lines' => [
            ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '5.00'],
            ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '5.00'],
        ]];

        // School A: the library works; Finance refuses, even with a code and School B's accounts.
        $this->enterSchool($person, $this->school);
        $this->post('/app/library/titles', ['title' => 'Gitanjali'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/finance/journal-entries/create')->assertForbidden();
        $this->post('/app/finance/journal-entries', $journal + ['mfa_code' => $this->freshMfaCode($person)])->assertForbidden();

        // School B: Finance works (fresh code); the library refuses.
        $this->enterSchool($person, $schoolB);
        $this->post('/app/finance/journal-entries', $journal + ['mfa_code' => $this->freshMfaCode($person)])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/library/titles')->assertForbidden();
        $this->assertSame(1, app(TenantContext::class)->withSchool($schoolB, fn () => JournalEntry::query()->count()));
        $this->assertSame(0, $this->inSchool(fn () => JournalEntry::query()->count()));
    }
}
