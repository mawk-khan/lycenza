<?php

namespace Tests\Feature\Portal;

use App\Domain\Attendance\Application\Portal\GuardianAttendanceReadService;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Portal\GuardianAnnouncementReadService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\GuardianAccountActivationService;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Portal\GuardianOffboardingException;
use App\Domain\Identity\Application\Portal\GuardianOffboardingService;
use App\Domain\Identity\Application\Portal\GuardianPortalAccessDeniedException;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountDirectory;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Payments\Application\Portal\GuardianFeeReadService;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * POR.1 (ADR 0070 §5, §8, §9): capability delivery, ActingGuardian, the
 * live relationship rule and the Guardian/staff lifecycle split.
 */
class GuardianPortalAuthorizationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures, FakesEmail;

    private function resolver(): ActingGuardianResolver
    {
        return app(ActingGuardianResolver::class);
    }

    /** @return list<string> */
    private function caps($user, $school): array
    {
        app(CapabilityResolver::class)->forgetCache($user, $school);

        return app(CapabilityResolver::class)->schoolCapabilities($user, $school);
    }

    #[Test]
    public function activation_grants_only_the_portal_capability_and_is_idempotent(): void
    {
        $this->fakeEmail();
        $school = $this->createSchool();
        $admin = $this->portalAdmin($school);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($this->createStudent($school), $guardian, ['is_legal_guardian' => true]);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');
        $invitation = app(AccountInvitationService::class)->invite($school, $guardian, $admin);

        $link = app(GuardianAccountActivationService::class)->accept($school, $invitation, null, 'a-strong-password-1');
        $user = $link->membership->user;

        $this->assertEqualsCanonicalizing(['portal.attendance.view', 'portal.communications.reply', 'portal.communications.view', 'portal.fees.view'], $this->caps($user, $school), 'Guardian activation delivers the portal capabilities and nothing else.');
        $this->assertSame([Role::GUARDIAN], $this->activeRoleKeys($school, $link->membership));
        $this->assertNotNull($this->resolver()->resolve($user, $school));

        // Re-granting through the activation path never duplicates the grant.
        $this->grantGuardianPortal($school, $link->membership, $user);
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $link->membership->id)->guardianPortal()->count()));
    }

    #[Test]
    public function no_staff_role_holds_a_portal_capability_and_the_guardian_role_holds_nothing_else(): void
    {
        $this->assertSame(['guardian'], DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')
            ->where('rc.capability_key', 'like', 'portal.%')->distinct()->orderBy('r.key')->pluck('r.key')->all());
        $this->assertSame(['portal.attendance.view', 'portal.communications.reply', 'portal.communications.view', 'portal.fees.view'], DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')
            ->where('r.key', Role::GUARDIAN)->orderBy('rc.capability_key')->pluck('rc.capability_key')->all());
        $this->assertSame('guardian', DB::table('roles')->where('key', Role::GUARDIAN)->value('scope'));
        $this->assertSame('guardian', DB::table('capabilities')->where('key', 'portal.communications.view')->value('namespace'));
    }

    #[Test]
    public function acting_guardian_resolves_only_with_every_part_present(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->assertNotNull($this->resolver()->resolve($p['user'], $school));

        // No eligible relationship: a non-legal-guardian relationship only.
        $q = $this->portalGuardian($school, legalGuardian: false);
        $this->assertNull($this->resolver()->resolve($q['user'], $school), 'is_legal_guardian is the fail-closed default (POR-L1 pending).');

        // Only an inactive Student.
        $r = $this->portalGuardian($school, studentStatus: 'withdrawn');
        $this->assertNull($this->resolver()->resolve($r['user'], $school), 'Only active Students qualify until POR-L1.');

        // Inactive persona.
        $s = $this->portalGuardian($school);
        app(TenantContext::class)->withSchool($school, fn () => $s['guardian']->forceFill(['status' => 'inactive'])->save());
        $this->assertNull($this->resolver()->resolve($s['user'], $school));

        // Suspended membership.
        $t = $this->portalGuardian($school);
        app(TenantContext::class)->withSchool($school, fn () => $t['membership']->update(['status' => SchoolMembership::STATUS_SUSPENDED]));
        $this->assertNull($this->resolver()->resolve($t['user'], $school));

        // No link at all.
        $u = $this->createUser();
        $this->createMembership($u, $school);
        $this->assertNull($this->resolver()->resolve($u, $school));

        // Wrong School: a Guardian here is nothing in another School.
        $other = $this->createSchool();
        $this->createMembership($p['user'], $other);
        $this->assertNull($this->resolver()->resolve($p['user'], $other));
    }

    #[Test]
    public function multi_school_guardians_resolve_independently(): void
    {
        [$a, $b] = [$this->createSchool(), $this->createSchool()];
        $pa = $this->portalGuardian($a);
        $pb = $this->portalGuardian($b, user: $pa['user']);

        $this->assertSame($pa['guardian']->id, $this->resolver()->resolve($pa['user'], $a)?->guardianId);
        $this->assertSame($pb['guardian']->id, $this->resolver()->resolve($pa['user'], $b)?->guardianId);

        app(TenantContext::class)->withSchool($b, fn () => DB::transaction(fn () => app(AccountLinkService::class)->unlinkGuardian($b, $pb['guardian'], $this->portalAdmin($b))));
        $this->assertNull($this->resolver()->resolve($pa['user'], $b));
        $this->assertNotNull($this->resolver()->resolve($pa['user'], $a), 'Losing School B never touches School A.');
    }

    #[Test]
    public function losing_the_last_eligible_relationship_denies_at_once_and_one_of_several_does_not(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $second = $this->createStudent($school);
        $this->createStudentGuardianRelationship($second, $p['guardian'], ['is_legal_guardian' => true]);

        app(TenantContext::class)->withSchool($school, fn () => DB::table('student_guardian_relationships')->where('student_id', $p['student']->id)->where('guardian_id', $p['guardian']->id)->delete());
        $this->assertNotNull($this->resolver()->resolve($p['user'], $school), 'Another eligible Student keeps the portal.');

        app(TenantContext::class)->withSchool($school, fn () => DB::table('student_guardian_relationships')
            ->where('student_id', $second->id)->where('guardian_id', $p['guardian']->id)->update(['is_legal_guardian' => false]));
        $this->assertNull($this->resolver()->resolve($p['user'], $school), 'No eligible relationship left: denied on the very next check.');
        $this->assertSame([Role::GUARDIAN], $this->activeRoleKeys($school, $p['membership']), 'The grant still exists -- denial comes from live state, not cleanup.');
        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertForbidden();
    }

    #[Test]
    public function unlinking_revokes_the_portal_grant_and_access_immediately(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertOk();

        app(AccountLinkService::class)->unlinkGuardian($school, $p['guardian'], $this->portalAdmin($school));

        $this->assertSame([], $this->activeRoleKeys($school, $p['membership']));
        $this->assertSame([], $this->caps($p['user'], $school));
        $this->get('/app/portal/communications')->assertForbidden();
        $reason = app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $p['membership']->id)->guardianPortal()->value('revocation_reason'));
        $this->assertSame(MembershipRoleAssignment::REASON_GUARDIAN_LINK_REVOKED, $reason);
    }

    #[Test]
    public function guardian_offboarding_suspends_a_guardian_only_membership_and_keeps_history(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);

        app(GuardianOffboardingService::class)->offboard($school, $this->portalAdmin($school), $p['guardian']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $p['membership']->fresh());
        $this->assertSame(SchoolMembership::STATUS_SUSPENDED, $fresh->status);
        $this->assertSame('revoked', app(TenantContext::class)->withSchool($school, fn () => $p['link']->fresh()->status));
        $this->assertSame([], $this->activeRoleKeys($school, $p['membership']));
        $this->assertNull($this->resolver()->resolve($p['user'], $school));
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $p['membership']->id)->guardianPortal()->exists()), 'History kept.');
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')
            ->where('event_type', GuardianOffboardingService::OFFBOARDED)->where('subject_id', $p['membership']->id)->exists()));
    }

    #[Test]
    public function guardian_offboarding_needs_both_capabilities_and_never_the_actors_own_membership(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $guardiansOnly = $this->createUserWithCapabilities($school, ['guardians.view', 'guardians.manage']);

        try {
            app(GuardianOffboardingService::class)->offboard($school, $guardiansOnly, $p['guardian']);
            $this->fail('guardians.manage alone must not off-board.');
        } catch (GuardianOffboardingException $e) {
            $this->assertSame('not_authorized', $e->outcome);
        }

        $self = $this->portalAdmin($school);
        $mine = $this->portalGuardian($school, user: $self, membership: SchoolMembership::query()->where('user_id', $self->id)->where('school_id', $school->id)->firstOrFail());
        try {
            app(GuardianOffboardingService::class)->offboard($school, $self, $mine['guardian']);
            $this->fail('Self off-boarding must be refused.');
        } catch (GuardianOffboardingException $e) {
            $this->assertSame('self_administration', $e->outcome);
        }
        $this->assertNotNull($this->resolver()->resolve($p['user'], $school));
    }

    #[Test]
    public function dual_persona_lifecycles_are_independent(): void
    {
        $school = $this->createSchool();
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');

        // A teacher who is also a Guardian: one membership, staff role + Guardian link + portal grant.
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $p = $this->portalGuardian($school, user: $user, membership: $membership);
        $this->assertContains('portal.communications.view', $this->caps($user, $school));
        $this->assertContains('students.view', $this->caps($user, $school));

        // The staff directory lists them as staff (school role), never because of the Guardian grant.
        $directory = app(StaffAccountDirectory::class)->for($school)['staff'];
        $this->assertContains($membership->id, array_column($directory, 'membershipId'));

        // Staff off-boarding: staff authority ends, the Guardian identity and portal stay.
        app(StaffAccessService::class)->suspend($school, $admin, $membership->id);
        $this->assertSame(SchoolMembership::STATUS_ACTIVE, app(TenantContext::class)->withSchool($school, fn () => $membership->fresh()->status));
        $this->assertEqualsCanonicalizing(['portal.attendance.view', 'portal.communications.reply', 'portal.communications.view', 'portal.fees.view'], $this->caps($user, $school));
        $this->assertNotNull($this->resolver()->resolve($user, $school));

        // Staff reactivation of the staff-offboarded dual membership restores staff roles only.
        app(StaffAccessService::class)->reactivate($school, $admin, $membership->id, ['principal']);
        $this->assertEqualsCanonicalizing([Role::GUARDIAN, 'principal'], $this->activeRoleKeys($school, $membership));

        // Guardian off-boarding: Guardian authority ends, staff stays, membership stays active.
        app(GuardianOffboardingService::class)->offboard($school, $this->portalAdmin($school), $p['guardian']);
        $this->assertSame(['principal'], $this->activeRoleKeys($school, $membership));
        $this->assertSame(SchoolMembership::STATUS_ACTIVE, app(TenantContext::class)->withSchool($school, fn () => $membership->fresh()->status));
        $this->assertNotContains('portal.communications.view', $this->caps($user, $school));
        $this->assertContains('students.view', $this->caps($user, $school));

        // Membership suspension denies both (now via ordinary staff off-boarding: no Guardian identity left).
        app(StaffAccessService::class)->suspend($school, $admin, $membership->id);
        $this->assertSame([], $this->caps($user, $school));
        $this->assertNull($this->resolver()->resolve($user, $school));
    }

    #[Test]
    public function a_guardian_only_membership_is_not_staff(): void
    {
        $school = $this->createSchool();
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');
        $p = $this->portalGuardian($school);

        $this->assertNotContains($p['membership']->id, array_column(app(StaffAccountDirectory::class)->for($school)['staff'], 'membershipId'));

        try {
            app(StaffAccessService::class)->suspend($school, $admin, $p['membership']->id);
            $this->fail('Staff lifecycle code must not operate on a Guardian-only membership.');
        } catch (StaffAccountException $e) {
            $this->assertSame('not_staff', $e->outcome);
        }
        $this->assertNotNull($this->resolver()->resolve($p['user'], $school));
    }

    #[Test]
    public function membership_suspension_denies_a_guardian(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertOk();

        app(TenantContext::class)->withSchool($school, fn () => $p['membership']->update(['status' => SchoolMembership::STATUS_SUSPENDED]));
        app(CapabilityResolver::class)->forgetCache($p['user'], $school);

        $this->get('/app/portal/communications')->assertRedirect('/app');
    }

    #[Test]
    public function school_setup_needs_the_profile_view_capability(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school)->get('/app/school-setup')->assertForbidden();

        $bare = $this->createUser();
        $this->createMembership($bare, $school);
        $this->signInTo($bare, $school)->get('/app/school-setup')->assertForbidden();

        $viewer = $this->createUserWithCapabilities($school, ['school.profile.view']);
        $this->signInTo($viewer, $school)->get('/app/school-setup')->assertOk();
    }

    #[Test]
    public function the_dashboard_offers_the_portal_only_to_a_live_guardian(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school)->get('/app')->assertOk()
            ->assertInertia(fn ($page) => $page->where('nav.canViewGuardianPortal', true)->where('nav.canViewSchoolSetup', false)->where('nav.canViewCommunications', false));

        $staff = $this->createUserWithCapabilities($school, ['communications.view', 'school.profile.view']);
        $this->signInTo($staff, $school)->get('/app')->assertOk()
            ->assertInertia(fn ($page) => $page->where('nav.canViewGuardianPortal', false)->where('nav.canViewSchoolSetup', true));
    }

    #[Test]
    public function a_bare_account_link_never_shields_a_staff_membership_from_off_boarding(): void
    {
        $school = $this->createSchool();
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');

        // A principal links their own membership to a Guardian record (no activation, no portal grant).
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($this->createStudent($school), $guardian, ['is_legal_guardian' => true]);
        app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $this->portalAdmin($school));

        app(StaffAccessService::class)->suspend($school, $admin, $membership->id);

        $this->assertSame(SchoolMembership::STATUS_SUSPENDED, app(TenantContext::class)->withSchool($school, fn () => $membership->fresh()->status), 'Only a live, activated Guardian keeps the membership active.');
    }

    #[Test]
    public function an_off_boarded_dual_persona_cannot_reach_staff_attachments(): void
    {
        $school = $this->createSchool();
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->portalGuardian($school, user: $user, membership: $membership);
        $thread = app(CommunicationThreadService::class)->createThread($school, $admin, 'direct', null, [$user->id]);
        Storage::fake('local');
        $attachment = app(CommunicationAttachmentService::class)
            ->uploadForThread($thread, $admin, UploadedFile::fake()->create('staff.pdf', 1, 'application/pdf'));
        app(CommunicationMessageService::class)->send($thread, $admin, 'Staff only', attachmentIds: [$attachment->id]);
        $this->signInTo($user, $school)->get("/app/communications/attachments/{$attachment->id}/download")->assertOk();

        app(StaffAccessService::class)->suspend($school, $admin, $membership->id);

        $this->get("/app/communications/attachments/{$attachment->id}/download")->assertForbidden();
        $this->get('/app/portal/communications')->assertOk();
    }

    #[Test]
    public function guardian_off_boarding_revokes_a_pending_invitation(): void
    {
        $this->fakeEmail();
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->createGuardianContact($p['guardian'], ContactType::Email, 'later@example.com');
        // An invitation issued while the account is already linked (an administrator linked it manually).
        app(TenantContext::class)->withSchool($school, fn () => DB::table('identity_account_invitations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $p['guardian']->id,
            'token_hash' => hash('sha256', 'pending-token'), 'destination_email_hash' => hash('sha256', 'later@example.com'),
            'status' => 'pending', 'expires_at' => now()->addDay(), 'invited_by_user_id' => $this->portalAdmin($school)->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        app(GuardianOffboardingService::class)->offboard($school, $this->portalAdmin($school), $p['guardian']);

        $this->assertSame('revoked', app(TenantContext::class)->withSchool($school, fn () => DB::table('identity_account_invitations')
            ->where('guardian_id', $p['guardian']->id)->value('status')));
    }

    #[Test]
    public function every_read_service_rechecks_its_capability_and_school_without_the_route(): void
    {
        // POR.5 (ADR 0070 §28.4, rule 6): a future non-route caller cannot skip the capability.
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $guardian = app(ActingGuardianResolver::class)->require($p['user'], $school);
        $calls = [
            fn () => app(GuardianAnnouncementReadService::class)->inbox($school, $guardian, $p['user']),
            fn () => app(GuardianAttendanceReadService::class)->students($school, $guardian, $p['user']),
            fn () => app(GuardianFeeReadService::class)->students($school, $guardian, $p['user']),
        ];
        foreach ($calls as $call) {
            $call();
        }

        LocalCatalogueFixtures::setRoleCapabilities(Role::query()->where('key', Role::GUARDIAN)->firstOrFail(), []);
        app(CapabilityResolver::class)->forgetCache($p['user'], $school);
        foreach ($calls as $call) {
            $this->assertThrows($call, GuardianPortalAccessDeniedException::class);
        }

        // An ActingGuardian of another School is never accepted.
        $foreign = new ActingGuardian($this->createSchool()->id, $guardian->userId, $guardian->membershipId, $guardian->accountLinkId, $guardian->guardianId);
        $this->assertThrows(fn () => app(GuardianAnnouncementReadService::class)->inbox($school, $foreign, $p['user']), ModelNotFoundException::class);
        $this->assertThrows(fn () => app(GuardianAttendanceReadService::class)->students($school, $foreign, $p['user']), ModelNotFoundException::class);
    }
}
