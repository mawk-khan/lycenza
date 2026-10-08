<?php

namespace Tests\Concerns;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Portal\GuardianPortalRoleGrants;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Domain\Students\Infrastructure\Student;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * POR.1 (ADR 0070): Guardian portal fixtures built through the real services
 * -- AccountLinkService for the identity link, GuardianPortalRoleGrants for
 * the `guardian`-scope grant (the path activation itself uses), and
 * AnnouncementService createDraft()+publish() for Guardian-addressed
 * announcements with their real audience snapshot and in-app delivery.
 */
trait CreatesGuardianPortalFixtures
{
    /**
     * A portal-ready Guardian: User + active membership + active link +
     * `guardian` grant + one legal-guardian relationship to an active Student.
     *
     * @return array{user: User, membership: SchoolMembership, guardian: Guardian, student: Student, link: StudentGuardianAccountLink}
     */
    protected function portalGuardian(School $school, ?User $user = null, ?SchoolMembership $membership = null, bool $legalGuardian = true, string $studentStatus = 'active'): array
    {
        $user ??= $this->createUser();
        $membership ??= $this->createMembership($user, $school);
        $guardian = $this->createGuardian($school);
        $student = $this->createStudent($school, ['status' => $studentStatus]);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => $legalGuardian]);

        $admin = $this->portalAdmin($school);
        $link = app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $admin);
        $this->grantGuardianPortal($school, $membership, $user);

        return ['user' => $user, 'membership' => $membership, 'guardian' => $guardian, 'student' => $student, 'link' => $link];
    }

    protected function grantGuardianPortal(School $school, SchoolMembership $membership, User $user): void
    {
        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(
            fn () => app(GuardianPortalRoleGrants::class)->grant($school, $membership, $user),
        ));
        app(CapabilityResolver::class)->forgetCache($user, $school);
    }

    protected function portalAdmin(School $school): User
    {
        return $this->portalAdmins[$school->id] ??= $this->createUserWithCapabilities($school, ['guardians.view', 'guardians.manage', 'school.members.view', 'school.members.manage', 'school.roles.manage']);
    }

    /** @var array<string, User> */
    private array $portalAdmins = [];

    protected function guardianAnnouncement(School $school, Guardian $guardian, string $title = 'Sports day', ?User $creator = null): CommunicationAnnouncement
    {
        $creator ??= $this->announcementCreator($school);
        $service = app(AnnouncementService::class);
        $draft = $service->createDraft(
            $school, $creator, $title, 'Body of '.$title, CommunicationPriority::Normal, CommunicationAudienceType::Guardian,
            domainAudienceMemberIds: [$guardian->id], channels: [CommunicationChannel::InApp],
        );

        return $service->publish($draft, $creator);
    }

    protected function announcementCreator(School $school): User
    {
        return $this->announcementCreators[$school->id] ??= (function () use ($school): User {
            $user = $this->createUser();
            $this->assignSchoolRole($this->createMembership($user, $school), 'school_admin');

            return $user;
        })();
    }

    /** @var array<string, User> */
    private array $announcementCreators = [];

    protected function signInTo(User $user, School $school): static
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $this;
    }

    /** @return list<string> active grant role keys on the membership */
    protected function activeRoleKeys(School $school, SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)->active()->with('role')->get()
            ->map(fn ($g) => $g->role->key)->sort()->values()->all());
    }
}
