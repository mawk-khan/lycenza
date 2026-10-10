<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Identity\Application\Staff\RoleGrantRefusalAudit;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SR.2 (ADR 0071 §14): the application grant-authority decision is race-safe.
 * Real OS processes against real PostgreSQL, the contender OBSERVED waiting
 * on the holder (ForcesConcurrentOverlap), both orders where both orders are
 * possible:
 * - a grant racing the revocation of the issuer's own authority, and of the
 *   issuer's grant right;
 * - an invitation acceptance racing the revocation of the issuer's authority
 *   (stale authority never grants);
 * - a single-role revoke racing reactivation and off-boarding (serialized, no
 *   lost history).
 * Every path takes the School access lock first, so these serialize there;
 * the in-transaction decision then reads the committed state.
 */
class StaffGrantAuthorityConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, FakesEmail, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $roleIds = [];

    /** @var list<string> */
    private array $emails = [];

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin = DB::connection('pgsql_admin');
        // SR.1: grant history RESTRICTs role deletion -- the School deletion above removed the grants first.
        $admin->table('roles')->whereIn('id', $this->roleIds)->delete();
        $ids = array_merge($this->userIds, $admin->table('users')->whereIn('email', $this->emails)->pluck('id')->all());
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $ids)->orWhereIn('subject_id', $ids)->delete();
        $admin->table('users')->whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/staff-account-op.php', ...$args];
    }

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;

        return $school;
    }

    /** @return array{0: User, 1: SchoolMembership} */
    private function member(School $school, string|Role ...$roles): array
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $membership = $this->createMembership($user, $school);
        foreach ($roles as $role) {
            $this->assignSchoolRole($membership, $role instanceof Role ? $role->key : $role);
        }

        return [$user, $membership];
    }

    /** @param list<string> $capabilities */
    private function role(array $capabilities, bool $system = true): Role
    {
        $role = $this->createFixtureRole($capabilities, key: 'sr2.race.'.Str::uuid(), isSystem: $system);
        $this->roleIds[] = $role->id;

        return $role;
    }

    /** @return list<string> */
    private function activeRoles(SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => DB::table('membership_role_assignments as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.school_membership_id', $membership->id)->whereNull('a.revoked_at')
            ->orderBy('r.key')->pluck('r.key')->all());
    }

    /** @return list<array{0: string, 1: ?string}> [role key, revocation reason] in grant order */
    private function history(SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => DB::table('membership_role_assignments as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.school_membership_id', $membership->id)
            ->orderBy('r.key')->orderBy('a.id')
            ->get(['r.key', 'a.revocation_reason'])->map(fn ($row) => [$row->key, $row->revocation_reason])->all());
    }

    /** @return list<string> refusal codes recorded in $school */
    private function refusals(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $school->id)->where('event_type', RoleGrantRefusalAudit::REFUSED)->orderBy('occurred_at')->get()
            ->map(fn (SchoolAuditEvent $e) => $e->metadata['stage'].':'.$e->metadata['refusal'])->all());
    }

    #[Test]
    public function a_grant_racing_the_revocation_of_the_issuers_authority_serializes_in_both_orders(): void
    {
        $school = $this->school();
        [$a, $ma] = $this->member($school, 'school_admin');
        [$b] = $this->member($school, 'school_admin');
        [, $m] = $this->member($school, 'staff_self_service');

        // Revocation first: the waiting grant re-decides on the committed state and is refused.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $b->id, $ma->id, 'school_admin'),
            $this->script('grant', $school->id, $a->id, $m->id, 'teacher'),
        );
        $this->assertSame(['revoked', 'rejected:not_authorized'], [$holder, $contender]);
        $this->assertSame(['staff_self_service'], $this->activeRoles($m));
        $this->assertSame(['grant:not_role_manager'], $this->refusals($school));

        // Grant first: the revocation waits, then both apply.
        [$c, $mc] = $this->member($school, 'school_admin');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('grant', $school->id, $c->id, $m->id, 'teacher'),
            $this->script('revoke-role', $school->id, $b->id, $mc->id, 'school_admin'),
        );
        $this->assertSame(['granted', 'revoked'], [$holder, $contender]);
        $this->assertSame(['staff_self_service', 'teacher'], $this->activeRoles($m));
    }

    #[Test]
    public function a_grant_racing_the_revocation_of_the_issuers_grant_right_serializes_in_both_orders(): void
    {
        $school = $this->school();
        [$admin] = $this->member($school, 'school_admin');
        $staffAdministration = $this->role(['school.members.view', 'school.members.manage', 'school.roles.view', 'school.roles.manage', 'hr.employees.view'], system: false);
        $grantRight = $this->role(['school.roles.grant.hr']);
        $hr = $this->role(['hr.positions.view']);
        [$officer, $mo] = $this->member($school, $staffAdministration, $grantRight);
        [, $m] = $this->member($school, 'staff_self_service');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $admin->id, $mo->id, $grantRight->key),
            $this->script('grant', $school->id, $officer->id, $m->id, $hr->key),
        );
        $this->assertSame(['revoked', 'rejected:role_escalation'], [$holder, $contender]);
        $this->assertSame(['staff_self_service'], $this->activeRoles($m));
        $this->assertSame(['grant:not_covered'], $this->refusals($school));

        [$officer2, $mo2] = $this->member($school, $staffAdministration, $grantRight);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('grant', $school->id, $officer2->id, $m->id, $hr->key),
            $this->script('revoke-role', $school->id, $admin->id, $mo2->id, $grantRight->key),
        );
        $this->assertSame(['granted', 'revoked'], [$holder, $contender]);
        $this->assertSame([$hr->key, 'staff_self_service'], $this->activeRoles($m));
    }

    #[Test]
    public function an_acceptance_racing_the_revocation_of_the_issuers_authority_never_grants_on_stale_authority(): void
    {
        $this->fakeEmail();
        $school = $this->school();
        [$a, $ma] = $this->member($school, 'school_admin');
        [$b] = $this->member($school, 'school_admin');
        $email = $this->emails[] = 'sr2.race.stale@example.test';
        app(StaffInvitationService::class)->issue($school, $a, $email, ['teacher']);
        $this->assertSame(1, preg_match('#/staff/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $this->lastAcceptedEmail()->text, $link));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $b->id, $ma->id, 'school_admin'),
            $this->script('accept-new', $school->id, $link[1], $link[2]),
        );
        $this->assertSame(['revoked', 'invalid'], [$holder, $contender]);
        $this->assertSame(0, User::query()->where('email', $email)->count());
        $this->assertSame(['invitation_acceptance:not_role_manager'], $this->refusals($school));

        // Acceptance first: it grants under the issuer's then-current authority; the revocation waits.
        [$c, $mc] = $this->member($school, 'school_admin');
        $email2 = $this->emails[] = 'sr2.race.fresh@example.test';
        app(StaffInvitationService::class)->issue($school, $c, $email2, ['teacher']);
        $this->assertSame(1, preg_match('#/staff/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $this->lastAcceptedEmail()->text, $link2));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('accept-new', $school->id, $link2[1], $link2[2]),
            $this->script('revoke-role', $school->id, $b->id, $mc->id, 'school_admin'),
        );
        $this->assertSame(['accepted_new', 'revoked'], [$holder, $contender]);
        $accepted = SchoolMembership::query()->where('school_id', $school->id)->whereIn('user_id', User::query()->where('email', $email2)->select('id'))->sole();
        $this->assertSame(['teacher'], $this->activeRoles($accepted));
    }

    #[Test]
    public function invitation_issue_and_acceptance_wait_on_the_school_access_lock_itself(): void
    {
        // The holder touches NO row the invitation paths read (an unrelated
        // member's grant): only the School access lock can make them wait.
        $this->fakeEmail();
        $school = $this->school();
        [$a] = $this->member($school, 'school_admin');
        [$b] = $this->member($school, 'school_admin');
        [, $unrelated] = $this->member($school, 'staff_self_service');
        $email = $this->emails[] = 'sr2.race.lock@example.test';
        app(StaffInvitationService::class)->issue($school, $b, $email, ['teacher']);
        $this->assertSame(1, preg_match('#/staff/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $this->lastAcceptedEmail()->text, $link));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('grant', $school->id, $a->id, $unrelated->id, 'teacher'),
            $this->script('accept-new', $school->id, $link[1], $link[2]),
        );
        $this->assertSame(['granted', 'accepted_new'], [$holder, $contender]);

        $this->emails[] = 'race.invite@example.test';
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $a->id, $unrelated->id, 'teacher'),
            $this->script('invite', $school->id, $b->id, 'race.invite@example.test'),
        );
        $this->assertSame(['revoked', 'invited'], [$holder, $contender]);
    }

    #[Test]
    public function revoking_an_invitation_racing_the_loss_of_staff_administration_never_acts_on_stale_authority(): void
    {
        // SR.5 (ADR 0071 §27): invitation revoke (and resend) re-read
        // `school.members.manage` under the School access lock. The holder
        // removes A's administration while A's revoke waits on that lock.
        $this->fakeEmail();
        $school = $this->school();
        [$a, $ma] = $this->member($school, 'school_admin');
        [$b] = $this->member($school, 'school_admin');
        $email = $this->emails[] = 'sr5.race.revoke@example.test';
        $invitation = app(StaffInvitationService::class)->issue($school, $a, $email, ['teacher']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $b->id, $ma->id, 'school_admin'),
            $this->script('revoke-invitation', $school->id, $a->id, $invitation->id),
        );
        $this->assertSame(['revoked', 'rejected:not_authorized'], [$holder, $contender]);
        $this->assertSame('pending', app(TenantContext::class)->withSchool($school, fn () => $invitation->fresh()->status));
    }

    #[Test]
    public function a_single_role_revoke_serializes_with_reactivation_and_off_boarding_without_losing_history(): void
    {
        $school = $this->school();
        [$a] = $this->member($school, 'school_admin');
        [$b] = $this->member($school, 'school_admin');

        // Reactivation first, then the waiting revoke removes the role it re-granted.
        [, $m] = $this->member($school, 'staff_self_service', 'teacher');
        app(StaffAccessService::class)->suspend($school, $a, $m->id);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('reactivate', $school->id, $a->id, $m->id, 'teacher'),
            $this->script('revoke-role', $school->id, $b->id, $m->id, 'teacher'),
        );
        $this->assertSame(['reactivated', 'revoked'], [$holder, $contender]);
        $this->assertSame([], $this->activeRoles($m));
        $this->assertSame('active', $m->fresh()->status);
        $this->assertSame([
            ['staff_self_service', 'membership_suspended'],
            ['teacher', 'membership_suspended'],
            ['teacher', 'revoked'],
        ], $this->history($m), 'Every grant and revocation kept, in order.');

        // A revoke first, then the waiting off-boarding removes the rest.
        [, $m2] = $this->member($school, 'staff_self_service', 'teacher');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $b->id, $m2->id, 'teacher'),
            $this->script('suspend', $school->id, $a->id, $m2->id),
        );
        $this->assertSame(['revoked', 'suspended'], [$holder, $contender]);
        $this->assertSame('suspended', $m2->fresh()->status);
        $this->assertSame([
            ['staff_self_service', 'membership_suspended'],
            ['teacher', 'revoked'],
        ], $this->history($m2));
    }
}
