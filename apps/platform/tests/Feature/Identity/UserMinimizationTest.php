<?php

namespace Tests\Feature\Identity;

use App\Domain\Communications\Application\Channels\EmailAddressResolver;
use App\Domain\Identity\Application\Minimization\UserMinimizationService;
use App\Domain\Identity\Application\Staff\StaffAccountDirectory;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Retention\Erasure\ErasureCaseException;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.4 (E21-L1 project-adopted, India-aligned development position,
 * pending qualified ratification): an approved platform erasure case
 * minimizes a User into a non-login tombstone only when no current purpose
 * and no hold remains anywhere. Retained history keeps referencing the
 * same row, unchanged; nothing is deleted or nulled.
 */
class UserMinimizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.hold_school_ids' => [], 'retention.hold_platform' => false]);
    }

    private function cases(): ErasureCaseService
    {
        return app(ErasureCaseService::class);
    }

    private function approvedCase(User $user): string
    {
        $case = $this->cases()->open(null, 'user', $user->id, 'written');
        $this->cases()->decide($case->id, 'approve', 'request_valid');

        return $case->id;
    }

    /** @param  list<ErasureCategory>  $outcome  @return list<string> "outcome:reason" of the identity */
    private function identity(array $outcome): array
    {
        return array_values(array_map(fn (ErasureCategory $c) => "{$c->outcome}:{$c->reason}", array_filter($outcome, fn (ErasureCategory $c) => $c->category === 'user_identity')));
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** A former member of $school with history there, and nothing current. */
    private function formerMember(School $school): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school, 'suspended');

        return $user;
    }

    #[Test]
    public function an_unapproved_case_cannot_minimize_and_a_dry_run_changes_nothing(): void
    {
        $user = $this->formerMember($this->createSchool());
        $case = $this->cases()->open(null, 'user', $user->id, 'written');

        try {
            $this->cases()->execute($case->id, false);
            $this->fail('An unapproved case executed.');
        } catch (ErasureCaseException) {
        }
        $this->cases()->decide($case->id, 'approve', 'request_valid');
        $this->assertSame(['eligible:no_active_purpose'], $this->identity($this->cases()->execute($case->id, true)));

        $fresh = $user->fresh();
        $this->assertNull($fresh->minimized_at);
        $this->assertSame($user->email, $fresh->email);
        $this->assertFalse($fresh->isDisabled());
    }

    #[Test]
    public function every_current_purpose_and_every_hold_blocks_minimization(): void
    {
        $school = $this->createSchool();
        $cases = [];

        $member = $this->createUser();
        $this->createMembership($member, $school);
        $cases['blocked:active_membership'] = $member;

        $invited = $this->createUser();
        $this->createMembership($invited, $school, 'invited');
        $cases['blocked:active_membership '] = $invited;

        $employee = $this->formerMember($school);
        $this->inSchool($school, function () use ($school, $employee): void {
            $record = $this->createEmployee($school);
            $this->createEmploymentRecord($record, ['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => null]);
            DB::table('employees')->where('id', $record->id)->update(['user_id' => $employee->id]);
        });
        $cases['blocked:active_employee_link'] = $employee;

        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor');
        $cases['blocked:active_platform_role'] = $auditor;

        $owner = $this->formerMember($school);
        $this->inSchool($school, fn () => DB::table('automation_rule_instances')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'rule_type' => 'probe', 'status' => 'enabled', 'owner_user_id' => $owner->id,
            'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $cases['blocked:active_automation_owner'] = $owner;

        // A Guardian/Student account link on a membership: the person behind it is a separate
        // domain subject, but the login principal is current while the link is active.
        $portal = $this->createUser();
        $portalMembership = $this->createMembership($portal, $school, 'suspended');
        $this->inSchool($school, function () use ($school, $portalMembership): void {
            DB::table('student_guardian_account_links')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'student_id' => null, 'guardian_id' => $this->createGuardian($school)->id,
                'school_membership_id' => $portalMembership->id, 'status' => 'active', 'linked_by_user_id' => $this->createUser()->id,
                'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $cases['blocked:active_account_link'] = $portal;

        foreach ($cases as $expected => $user) {
            $outcome = $this->cases()->execute($this->approvedCase($user), false);
            $this->assertContains('dependency_blocked:'.trim(substr($expected, 8)), $this->identity($outcome), $expected);
            $this->assertNull($user->fresh()->minimized_at, $expected);
        }

        // Holds: the platform hold, and a hold on any School the User belonged to.
        $held = $this->formerMember($school);
        config(['retention.hold_school_ids' => [$school->id]]);
        $this->assertSame(['legal_hold:school_hold'], $this->identity($this->cases()->execute($this->approvedCase($held), false)));
        config(['retention.hold_school_ids' => [], 'retention.hold_platform' => true]);
        $this->assertSame(['legal_hold:platform_hold'], $this->identity($this->cases()->execute($this->approvedCase($held), false)));
        $this->assertNull($held->fresh()->minimized_at);
    }

    #[Test]
    public function an_eligible_user_becomes_a_non_login_tombstone_and_retained_history_still_points_at_it(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser(['name' => 'Asha Verma']);
        $membership = $this->createMembership($user, $school);
        $grant = $this->assignSchoolRole($membership, 'school_admin');
        $originalEmail = $user->email;

        // History made while current: an audit event, a grant they issued, a Finance-style actor reference.
        $this->inSchool($school, fn () => app(AuditRecorder::class)->school($school, 'probe.actor_event', actor: $user));
        $other = $this->createMembership($this->createUser(), $school);
        $issued = $this->inSchool($school, fn () => DB::table('membership_role_assignments')->insertGetId([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $other->id,
            'role_id' => DB::table('roles')->where('key', 'school_admin')->value('id'), 'assigned_by_user_id' => $user->id,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], 'id'));

        // Credentials of every kind.
        $user->createToken('device');
        DB::table('user_mfa_factors')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'type' => 'totp', 'secret_encrypted' => encrypt('s'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        $this->app['auth']->forgetGuards();

        // The User leaves: membership suspended, role revoked (the ordinary off-boarding).
        $this->inSchool($school, fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->createUser()->id, 'revocation_reason' => 'membership_suspended']));
        DB::table('school_memberships')->where('id', $membership->id)->update(['status' => 'suspended']);

        $outcome = $this->cases()->execute($this->approvedCase($user), false);
        $this->assertSame(['completed:minimized'], $this->identity($outcome));

        $tomb = $user->fresh();
        $this->assertTrue($tomb->isMinimized());
        $this->assertTrue($tomb->isDisabled());
        $this->assertSame(UserMinimizationService::FORMER_USER_NAME, $tomb->name);
        $this->assertSame("minimized-{$user->id}@users.invalid", $tomb->email);
        $this->assertNull($tomb->email_verified_at);
        $this->assertNull($tomb->remember_token);
        $this->assertNotSame($user->getAuthPassword(), $tomb->getAuthPassword(), 'the old password hash is gone');
        $this->assertNull($tomb->publicEmail());
        foreach (['personal_access_tokens' => 'tokenable_id', 'user_mfa_factors' => 'user_id', 'sessions' => 'user_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, $user->id)->count(), "{$table} removed");
        }
        // Nothing personal was copied anywhere: not into the audit trail of the minimization.
        $event = DB::table('platform_audit_events')->where('event_type', 'platform.user.minimized')->where('subject_id', $user->id)->sole();
        $this->assertStringNotContainsString('Asha', (string) $event->metadata);
        $this->assertStringNotContainsString($originalEmail, (string) $event->metadata);

        // Retained history: same rows, same actor id, nothing nulled or deleted.
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('school_audit_events')->where('event_type', 'probe.actor_event')->where('actor_user_id', $user->id)->count()));
        $this->assertSame($user->id, $this->inSchool($school, fn () => DB::table('membership_role_assignments')->where('id', $issued)->value('assigned_by_user_id')));
        $this->assertSame('suspended', DB::table('school_memberships')->where('id', $membership->id)->value('status'), 'the membership is history and stays');
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->count()));

        // Presentation: "Former user", no address; never mailed.
        $row = collect(app(StaffAccountDirectory::class)->for($school)['staff'])->firstWhere('userId', $user->id);
        $this->assertSame('Former user', $row['name']);
        $this->assertNull($row['email']);
        $this->assertNull(app(EmailAddressResolver::class)->resolve($tomb));

        // Cannot sign in, with the old address or the placeholder.
        $this->post('/login', ['email' => $originalEmail, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $tomb->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // A rerun is safe and restores nothing.
        $this->assertSame(['completed:minimized'], $this->identity($this->cases()->execute($this->approvedCase($user), false)));
        $this->assertSame($tomb->email, $user->fresh()->email);
    }

    #[Test]
    public function finance_and_payroll_operator_references_stay_valid_and_read_as_a_former_user(): void
    {
        $school = $this->createSchool();
        $preparer = $this->createUser(['name' => 'Ravi Kumar']);
        $membership = $this->createMembership($preparer, $school);
        $run = $this->inSchool($school, function () use ($school, $preparer) {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, now()->startOfMonth(), null, $preparer), $preparer);

            return app(PayrollRunService::class)->createRun($period, $preparer);
        });
        DB::table('school_memberships')->where('id', $membership->id)->update(['status' => 'suspended']);

        $this->cases()->execute($this->approvedCase($preparer), false);

        $this->assertSame($preparer->id, $this->inSchool($school, fn () => DB::table('payroll_runs')->where('id', $run->id)->value('prepared_by_user_id')), 'the operator reference is unchanged');
        $viewer = $this->createUserWithCapabilities($school, ['payroll.runs.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");
        $page = $this->actingAs($viewer)->get("/app/payroll/runs/{$run->id}")->assertOk();
        $page->assertInertia(fn ($p) => $p->where('run.preparedByName', 'Former user'));
        $this->assertStringNotContainsString('Ravi Kumar', $page->getContent());
    }

    #[Test]
    public function a_session_issued_before_minimization_ends_and_a_new_login_fails(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);

        $email = $user->email;
        $this->post('/login', ['email' => $email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);

        DB::table('school_memberships')->where('id', $membership->id)->update(['status' => 'suspended']);
        $this->cases()->execute($this->approvedCase($user), false);
        $this->app['auth']->forgetGuards();

        $this->get('/app')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function a_tombstone_is_immutable_and_never_becomes_a_current_principal_again(): void
    {
        $school = $this->createSchool();
        $user = $this->formerMember($school);
        $this->cases()->execute($this->approvedCase($user), false);
        $refused = function (callable $write): string {
            try {
                DB::transaction($write);

                return '';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        };

        foreach ([
            fn () => DB::table('users')->where('id', $user->id)->update(['is_disabled' => false]),
            fn () => DB::table('users')->where('id', $user->id)->update(['email' => 'back@example.test']),
            fn () => DB::table('users')->where('id', $user->id)->update(['minimized_at' => null]),
            fn () => DB::table('school_memberships')->where('user_id', $user->id)->update(['status' => 'active']),
            fn () => DB::table('school_memberships')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'school_id' => $this->createSchool()->id, 'status' => 'invited', 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'x', 'token' => Str::random(64), 'abilities' => '["read"]', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('platform_role_assignments')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'role_id' => DB::table('roles')->where('key', 'platform_auditor')->value('id'), 'granted_by_user_id' => $this->createUser()->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
            fn () => $this->inSchool($school, fn () => DB::table('employees')->where('id', $this->createEmployee($school)->id)->update(['user_id' => $user->id])),
        ] as $i => $write) {
            $this->assertStringContainsString('users_minimized', $refused($write), "write {$i} must be refused");
        }
        $this->assertTrue($user->fresh()->isMinimized());
    }

    #[Test]
    public function a_school_request_never_minimizes_a_user_another_school_still_uses_and_later_eligibility_is_re_evaluated(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $c = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $a, 'suspended');
        $inB = $this->createMembership($user, $b);
        $this->assignSchoolRole($inB, 'school_admin');
        $this->createMembership($user, $c, 'suspended');
        $bBefore = DB::table('school_memberships')->where('school_id', $b->id)->get()->toArray();

        // School A's request (its ended membership) cannot reach the global User while B uses it.
        $this->assertSame(['dependency_blocked:active_membership'], $this->identity($this->cases()->execute($this->approvedCase($user), false)));
        $this->assertNull($user->fresh()->minimized_at);
        $this->assertFalse($user->fresh()->isDisabled());
        $this->assertEquals($bBefore, DB::table('school_memberships')->where('school_id', $b->id)->get()->toArray(), 'School B is untouched');
        $this->assertSame(1, $this->inSchool($b, fn () => DB::table('membership_role_assignments')->where('school_membership_id', $inB->id)->whereNull('revoked_at')->count()));

        // B's purpose ends (off-boarding): the same User is re-evaluated and becomes eligible.
        DB::table('school_memberships')->where('id', $inB->id)->update(['status' => 'suspended']);
        $this->assertSame(['completed:minimized'], $this->identity($this->cases()->execute($this->approvedCase($user), false)));
        $this->assertSame(3, DB::table('school_memberships')->where('user_id', $user->id)->count(), 'all three Schools keep their membership history');
    }
}
