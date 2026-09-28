<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 section 23) -- THE central ADR 0058 E24 proof: a
 * fresh installation with no ordinary User and no School reaches an operating
 * School with staff, using only production paths:
 *
 * root bootstrap (console) + MFA -> the bootstrap account (the REAL operator
 * console, stdin/stdout) -> its activation (HTTP) -> School creation naming
 * it, refused activation while it could not sign in, activation (HTTP, fresh
 * MFA) -> the School Admin signs in, selects the School, enrolls MFA -> staff
 * invitation (email) -> staff activation and sign-in -> role management ->
 * off-boarding (access gone) -> explicit reactivation (access back with the
 * NEW role only). No demo data, no fixture User or membership is used for
 * any step; no Employee is ever created.
 *
 * Non-transactional: the console steps commit. tearDown removes everything.
 */
class FreshInstallProvisioningTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail;

    private const ROOT_PASSWORD = 'Fresh-Install-Root-Passw0rd';

    private const ADMIN_PASSWORD = 'fresh-install-admin-password';

    private const STAFF_PASSWORD = 'fresh-install-staff-password';

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private string $suffix;

    private ?string $schoolId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = bin2hex(random_bytes(4));
        $this->fakeEmail();
    }

    protected function tearDown(): void
    {
        if ($this->schoolId !== null) {
            $this->deleteSchoolAsAdmin($this->schoolId);
        }

        $admin = DB::connection('pgsql_admin');
        $ids = $admin->table('users')->where('email', 'like', "%.{$this->suffix}@example.test")->pluck('id')->all();
        $grants = $admin->table('platform_role_assignments')->whereIn('user_id', $ids)->pluck('id')->all();
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $ids)->orWhereIn('subject_id', array_merge($ids, $grants))->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $ids)->delete();
        $admin->table('personal_access_tokens')->whereIn('tokenable_id', $ids)->delete();
        $admin->table('user_mfa_recovery_codes')->whereIn('user_id', $ids)->delete();
        $admin->table('user_mfa_factors')->whereIn('user_id', $ids)->delete();
        $admin->table('users')->whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    private function email(string $who): string
    {
        return "{$who}.{$this->suffix}@example.test";
    }

    private function signOut(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    #[Test]
    public function a_fresh_installation_reaches_an_operating_school_with_staff_through_production_paths_only(): void
    {
        // 0. Fresh: no root exists (bootstrap-root refuses otherwise), and
        //    every account and School this scenario uses is created by it --
        //    nothing pre-existing is referenced at any step.
        $this->assertSame(0, DB::table('platform_role_assignments')->where('role_id', app(PlatformRootProvisioningService::class)->rootRole()->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, User::query()->where('email', 'like', '%.'.$this->suffix.'@example.test')->count());
        $this->assertSame(0, School::query()->where('slug', 'fresh-'.$this->suffix)->count());

        // 1. Root bootstrap on the operator console, then MFA.
        $this->artisan('platform:bootstrap-root')
            ->expectsQuestion('Full name', 'First Operator')
            ->expectsQuestion('Email address (the sign-in identity)', $this->email('root'))
            ->expectsQuestion('Password (hidden)', self::ROOT_PASSWORD)
            ->expectsQuestion('Confirm password (hidden)', self::ROOT_PASSWORD)
            ->expectsConfirmation('Create this account and grant it the root platform role?', 'yes')
            ->assertSuccessful();
        $root = User::query()->where('email', $this->email('root'))->sole();
        $this->enrollActiveMfaFactor($root);
        $rootCodes = $this->issueRecoveryCodes($root, 6);

        // 2. The bootstrap account -- the REAL console: typed confirmation on
        //    stdin, the one-time link read from stdout.
        $console = new Process(
            ['php', 'artisan', 'platform:provision-school-admin-account', $this->email('admin'), '--name=First School Admin'],
            base_path(),
        );
        $console->setInput($this->email('admin')."\n");
        $console->mustRun();
        $this->assertSame(1, preg_match('#http://localhost:8000/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $console->getOutput(), $link), $console->getOutput());
        $admin = User::query()->where('email', $this->email('admin'))->sole();
        $this->assertFalse($admin->hasLocalCredential());
        $this->assertSame(0, SchoolMembership::query()->where('user_id', $admin->id)->count());

        // 3. Root creates the School naming that account; activation is
        //    refused while the account cannot sign in.
        $this->actingAs($root);
        $this->post('http://localhost/app/platform/schools', [
            'name' => 'Fresh Install School', 'slug' => 'fresh-'.$this->suffix, 'admin' => $this->email('admin'),
            'confirmed' => '1', 'mfa_code' => array_shift($rootCodes),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $school = School::query()->where('slug', 'fresh-'.$this->suffix)->sole();
        $this->schoolId = $school->id;
        $this->assertSame('provisioning', $school->status);

        $this->post("http://localhost/app/platform/schools/{$school->id}/activate", ['confirmed' => '1', 'mfa_code' => array_shift($rootCodes)])
            ->assertSessionHasErrors('school');
        $this->assertSame('provisioning', $school->fresh()->status);

        // 4. The administrator activates their account (platform host).
        $this->signOut();
        $this->post("http://localhost/account-activation/{$link[1]}", ['secret' => $link[2], 'password' => self::ADMIN_PASSWORD, 'password_confirmation' => self::ADMIN_PASSWORD])
            ->assertRedirect('http://localhost:8000/login');
        $this->assertGuest();

        // 5. Root activates the School.
        $this->actingAs($root);
        $this->post("http://localhost/app/platform/schools/{$school->id}/activate", ['confirmed' => '1', 'mfa_code' => array_shift($rootCodes)])
            ->assertSessionHasNoErrors();
        $this->assertSame('active', $school->fresh()->status);

        // 6. The School Admin signs in with their own password, selects the
        //    School and enrolls MFA.
        $this->signOut();
        $this->post('http://localhost/login', ['email' => $this->email('admin'), 'password' => self::ADMIN_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->post("http://localhost/app/schools/{$school->id}/activate")->assertRedirect();
        $this->assertSame($school->id, session(RequireSchoolContext::SESSION_KEY));
        $this->enrollActiveMfaFactor($admin);
        $adminCodes = $this->issueRecoveryCodes($admin, 8);

        // 7. Staff invitation (email), then activation by the new person.
        $this->postJson('http://localhost/app/settings/staff/invitations', [
            'email' => $this->email('staff'), 'roles' => ['principal'], 'mfa_code' => array_shift($adminCodes),
        ])->assertCreated();
        $this->assertSame(1, preg_match('#/invitations/'.$school->id.'/staff/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $this->lastAcceptedEmail()->text, $staffLink));

        $this->signOut();
        $this->post("http://localhost/invitations/{$school->id}/staff/{$staffLink[1]}", [
            'secret' => $staffLink[2], 'mode' => 'new', 'name' => 'First Staff',
            'password' => self::STAFF_PASSWORD, 'password_confirmation' => self::STAFF_PASSWORD,
        ])->assertRedirect();
        $staff = User::query()->where('email', $this->email('staff'))->sole();
        $membership = SchoolMembership::query()->where('user_id', $staff->id)->sole();

        // 8. The staff member signs in and works in the School.
        $this->post('http://localhost/login', ['email' => $this->email('staff'), 'password' => self::STAFF_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($staff);
        $this->post("http://localhost/app/schools/{$school->id}/activate")->assertRedirect();
        $this->get('http://localhost/app/settings')->assertOk();
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page->where('canInvite', false));

        // 9. Role management, then off-boarding.
        $this->signOut();
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
        $this->postJson("http://localhost/app/settings/staff/members/{$membership->id}/roles", ['role' => 'school_admin', 'mfa_code' => array_shift($adminCodes)])->assertOk();
        $this->postJson("http://localhost/app/settings/staff/members/{$membership->id}/roles/school_admin/revoke", ['mfa_code' => array_shift($adminCodes)])->assertOk();
        $this->postJson("http://localhost/app/settings/staff/members/{$membership->id}/suspend", ['mfa_code' => array_shift($adminCodes)])->assertOk();

        $this->signOut();
        $this->post('http://localhost/login', ['email' => $this->email('staff'), 'password' => self::STAFF_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($staff); // the account still exists and signs in
        $this->post("http://localhost/app/schools/{$school->id}/activate")->assertSessionHasErrors('school');
        $this->withSession([RequireSchoolContext::SESSION_KEY => $school->id])->get('http://localhost/app/settings')->assertRedirect(route('app.dashboard'));

        // 10. Explicit reactivation with a newly chosen role only.
        $this->signOut();
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
        $this->postJson("http://localhost/app/settings/staff/members/{$membership->id}/reactivate", ['roles' => ['principal'], 'mfa_code' => array_shift($adminCodes)])->assertOk();

        $this->signOut();
        $this->post('http://localhost/login', ['email' => $this->email('staff'), 'password' => self::STAFF_PASSWORD])->assertRedirect();
        $this->post("http://localhost/app/schools/{$school->id}/activate")->assertRedirect();
        $this->get('http://localhost/app/settings')->assertOk();
        $this->assertSame(['principal'], app(TenantContext::class)->withSchool($school, fn () => DB::table('membership_role_assignments')
            ->join('roles', 'roles.id', '=', 'membership_role_assignments.role_id')
            ->where('school_membership_id', $membership->id)->whereNull('revoked_at')->pluck('roles.key')->all()));

        // User is not Employee: nothing in HR was created along the way.
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => Employee::query()->count()));
    }
}
