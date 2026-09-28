<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Identity\Application\Staff\BootstrapAccountProvisioningService;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\SchoolAdministrators;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 section 16; owner amendment): staff account races
 * between real OS processes against real PostgreSQL, overlap forced and
 * verified (ForcesConcurrentOverlap), never slept. The invariants in every
 * order: one email never creates two Users; never two usable invitations or
 * two active grants of one role; a consumed/superseded/revoked credential
 * never works; and a School never ends with zero qualifying administrators.
 */
class StaffAccountConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, FakesEmail, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $emails = [];

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin = DB::connection('pgsql_admin');
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
    private function member(School $school, string $role): array
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role);

        return [$user, $membership];
    }

    private function activeRoleCount(SchoolMembership $membership, ?string $role = null): int
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => DB::table('membership_role_assignments')
            ->where('school_membership_id', $membership->id)->whereNull('revoked_at')
            ->when($role, fn ($q) => $q->whereIn('role_id', DB::table('roles')->where('key', $role)->select('id')))
            ->count());
    }

    private function assertAdministered(School $school): void
    {
        $this->assertNotEmpty(app(SchoolAdministrators::class)->qualifying($school), 'The School kept a qualifying administrator.');
    }

    #[Test]
    public function two_administrators_suspending_each_other_cannot_both_succeed(): void
    {
        $school = $this->school();
        [$a, $ma] = $this->member($school, 'school_admin');
        [$b, $mb] = $this->member($school, 'school_admin');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $school->id, $a->id, $mb->id),
            $this->script('suspend', $school->id, $b->id, $ma->id),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:not_authorized', $contender);
        $this->assertSame(['active', 'suspended'], [$ma->fresh()->status, $mb->fresh()->status]);
        $this->assertAdministered($school);
    }

    #[Test]
    public function two_administrators_revoking_each_others_admin_role_cannot_both_succeed(): void
    {
        $school = $this->school();
        [$a, $ma] = $this->member($school, 'school_admin');
        [$b, $mb] = $this->member($school, 'school_admin');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-role', $school->id, $a->id, $mb->id, 'school_admin'),
            $this->script('revoke-role', $school->id, $b->id, $ma->id, 'school_admin'),
        );

        $this->assertSame('revoked', $holder);
        $this->assertSame('rejected:not_authorized', $contender);
        $this->assertSame([1, 0], [$this->activeRoleCount($ma), $this->activeRoleCount($mb)]);
        $this->assertAdministered($school);
    }

    #[Test]
    public function a_suspension_racing_a_role_grant_or_a_role_revocation_wins_consistently(): void
    {
        $school = $this->school();
        [$a] = $this->member($school, 'school_admin');
        [$a2] = $this->member($school, 'school_admin');
        [, $m] = $this->member($school, 'principal');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $school->id, $a->id, $m->id),
            $this->script('grant', $school->id, $a2->id, $m->id, 'school_admin'),
        );
        $this->assertSame(['suspended', 'rejected:not_active'], [$holder, $contender]);
        $this->assertSame(0, $this->activeRoleCount($m), 'No grant survives on a suspended membership.');

        [, $m2] = $this->member($school, 'principal');
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $school->id, $a->id, $m2->id),
            $this->script('revoke-role', $school->id, $a2->id, $m2->id, 'principal'),
        );
        $this->assertSame(['suspended', 'rejected:role_not_granted'], [$holder, $contender]);
        $this->assertSame('suspended', $m2->fresh()->status);
    }

    #[Test]
    public function reactivations_and_grants_never_duplicate_and_a_suspension_after_reactivation_holds(): void
    {
        $school = $this->school();
        [$a] = $this->member($school, 'school_admin');
        [$a2] = $this->member($school, 'school_admin');
        [, $m] = $this->member($school, 'principal');
        app(StaffAccessService::class)->suspend($school, $a, $m->id);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('reactivate', $school->id, $a->id, $m->id, 'principal'),
            $this->script('reactivate', $school->id, $a2->id, $m->id, 'principal'),
        );
        $this->assertSame(['reactivated', 'rejected:not_suspended'], [$holder, $contender]);
        $this->assertSame(1, $this->activeRoleCount($m, 'principal'));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('grant', $school->id, $a->id, $m->id, 'school_admin'),
            $this->script('grant', $school->id, $a2->id, $m->id, 'school_admin'),
        );
        $this->assertSame(['granted', 'rejected:role_already_granted'], [$holder, $contender]);
        $this->assertSame(1, $this->activeRoleCount($m, 'school_admin'));

        [, $m3] = $this->member($school, 'principal');
        app(StaffAccessService::class)->suspend($school, $a, $m3->id);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('reactivate', $school->id, $a->id, $m3->id, 'principal'),
            $this->script('suspend', $school->id, $a2->id, $m3->id),
        );
        $this->assertSame(['reactivated', 'suspended'], [$holder, $contender], 'Serialized: reactivate, then suspend.');
        $this->assertSame(['suspended', 0], [$m3->fresh()->status, $this->activeRoleCount($m3)]);
        $this->assertAdministered($school);
    }

    #[Test]
    public function two_invitations_for_one_address_in_one_school_leave_one_pending(): void
    {
        $school = $this->school();
        [$a] = $this->member($school, 'school_admin');
        [$a2] = $this->member($school, 'school_admin');
        $this->emails[] = 'race.invite@example.test';

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('invite', $school->id, $a->id, 'race.invite@example.test'),
            $this->script('invite', $school->id, $a2->id, 'race.invite@example.test'),
        );

        $this->assertSame(['invited', 'rejected:already_invited'], [$holder, $contender]);
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->where('status', 'pending')->count()));
    }

    /** @return array{0: string, 1: string} */
    private function invitationLink(School $school, User $actor, string $email): array
    {
        $this->fakeEmail();
        app(StaffInvitationService::class)->issue($school, $actor, $email, ['principal']);
        $this->assertSame(1, preg_match('#/staff/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $this->lastAcceptedEmail()->text, $m));

        return [$m[1], $m[2]];
    }

    #[Test]
    public function two_schools_accepting_for_the_same_new_address_create_one_user(): void
    {
        $email = $this->emails[] = 'race.new@example.test';
        $one = $this->school();
        $two = $this->school();
        [$a1] = $this->member($one, 'school_admin');
        [$a2] = $this->member($two, 'school_admin');
        [$s1, $k1] = $this->invitationLink($one, $a1, $email);
        [$s2, $k2] = $this->invitationLink($two, $a2, $email);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('accept-new', $one->id, $s1, $k1),
            $this->script('accept-new', $two->id, $s2, $k2),
        );

        $this->assertSame(['accepted_new', 'sign_in_required'], [$holder, $contender]);
        $this->assertSame(1, User::query()->where('email', $email)->count(), 'One email never creates two Users.');
        $this->assertSame('pending', app(TenantContext::class)->withSchool($two, fn () => StaffAccountInvitation::query()->sole()->status), 'The second School\'s invitation is still usable after signing in.');
    }

    #[Test]
    public function a_revocation_racing_an_acceptance_leaves_no_membership(): void
    {
        $email = $this->emails[] = 'race.revoke@example.test';
        $school = $this->school();
        [$a] = $this->member($school, 'school_admin');
        [$selector, $secret] = $this->invitationLink($school, $a, $email);
        $invitationId = app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->sole()->id);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke-invitation', $school->id, $a->id, $invitationId),
            $this->script('accept-new', $school->id, $selector, $secret),
        );

        $this->assertSame(['revoked', 'invalid'], [$holder, $contender]);
        $this->assertSame(0, User::query()->where('email', $email)->count());
    }

    #[Test]
    public function activation_and_a_reissue_serialize_on_the_user(): void
    {
        $email = $this->emails[] = 'race.activate@example.test';
        $issued = app(BootstrapAccountProvisioningService::class)->provision($email, 'Race Admin', null);
        preg_match('#/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $issued->link, $m);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('reissue', $issued->user->id),
            $this->script('activate', $m[1], $m[2]),
        );
        $this->assertSame(['reissued', 'invalid'], [$holder, $contender], 'A superseded link never works.');
        $this->assertFalse($issued->user->fresh()->hasLocalCredential());

        $second = app(BootstrapAccountProvisioningService::class)->reissue($issued->user->fresh(), null);
        preg_match('#/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $second->link, $n);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('activate', $n[1], $n[2]),
            $this->script('reissue', $issued->user->id),
        );
        $this->assertSame(['activated', 'refused:account_has_credential'], [$holder, $contender]);
        $this->assertTrue($issued->user->fresh()->hasLocalCredential());
    }
}
