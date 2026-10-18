<?php

namespace Tests\Feature\Authorization\Coverage;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsNavigation;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeacherLearningContentFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.2 (ADR 0076 §28): one representative route per enforcement style,
 * proved at runtime -- the authorized actor keeps its existing behaviour,
 * the unauthorized actor is refused, the other School's actor fails closed.
 * The full-route sweep (SchoolRouteRefusalSweepTest) covers the refusal
 * half for every route; this covers the "nothing valid was lost" half.
 */
class RepresentativeRouteAuthorizationTest extends TestCase
{
    use AssertsNavigation, CreatesGuardianPortalFixtures, CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures, CreatesTeacherLearningContentFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function web(User $user, School $school): static
    {
        return $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
    }

    #[Test]
    public function c1_route_capability_api(): void
    {
        $school = $this->createSchool();
        $holder = $this->createUserWithCapabilities($school, ['hr.employees.view']);
        $member = $this->createUser();
        $this->createMembership($member, $school);
        [$outsider] = $this->createSchoolAdmin();

        $this->actingAs($holder)->getJson("/api/v1/schools/{$school->id}/employees")->assertOk();

        // auth:sanctum + school-membership alone is not authorization.
        $this->actingAs($member)->getJson("/api/v1/schools/{$school->id}/employees")->assertForbidden();

        // Another School's administrator: the School itself is not disclosed.
        $this->actingAs($outsider)->getJson("/api/v1/schools/{$school->id}/employees")->assertNotFound();
    }

    #[Test]
    public function c2_controller_gate_web(): void
    {
        $school = $this->createSchool();
        $holder = $this->createUserWithCapabilities($school, ['hr.leave.view']);
        $member = $this->createUser();
        $this->createMembership($member, $school);
        [$outsider] = $this->createSchoolAdmin();

        $this->web($holder, $school)->get('/app/leave/configuration')->assertOk();
        $this->web($member, $school)->get('/app/leave/configuration')->assertForbidden();
        // A forged session School the actor is not a member of never becomes context.
        $this->web($outsider, $school)->get('/app/leave/configuration')->assertRedirect('/app');
    }

    #[Test]
    public function c3_domain_service_authority(): void
    {
        $school = $this->createSchool();
        $holder = $this->createUserWithCapabilities($school, ['payroll.structures.view']);
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $this->web($holder, $school)->get('/app/payroll/components')->assertOk();
        $this->web($member, $school)->get('/app/payroll/components')->assertForbidden();
    }

    #[Test]
    public function c4_teacher_ownership_is_kept_beneath_the_capability(): void
    {
        $w = $this->contentWorld();
        [$teacher] = $this->contentTeacher($w);
        [$colleague] = $this->contentTeacher($w);
        $mine = $this->teacherContent($w, $teacher);
        $theirs = $this->teacherContent($w, $colleague);

        $as = fn (User $user) => $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);

        $as($teacher)->patch("/app/my-learning-content/{$mine->id}", ['title' => 'Mine, revised'])->assertRedirect()->assertSessionHasNoErrors();
        // Same capability, someone else's row: refused -- lms.content.teacher is not "every content row".
        $this->assertContains($as($teacher)->patch("/app/my-learning-content/{$theirs->id}", ['title' => 'Not mine'])->getStatusCode(), [403, 404]);
        $this->assertNotSame('Not mine', $this->withinSchool($w['school'], fn () => $theirs->fresh()->title));
    }

    #[Test]
    public function c4_guardian_scope_and_staff_authority_never_cross(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        [$admin] = [$this->announcementCreator($school)];

        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertOk();
        // A School Administrator holds every staff capability and no Guardian authority.
        $this->signInTo($admin, $school)->get('/app/portal/communications')->assertForbidden();
        // A Guardian holds no staff authority.
        $this->signInTo($p['user'], $school)->get('/app/students')->assertForbidden();
    }

    #[Test]
    public function c4_staff_self_service_needs_the_self_capability_and_an_acting_employee(): void
    {
        $school = $this->createSchool();
        $self = $this->createUserWithCapabilities($school, ['payroll.payslips.self']);
        $member = $this->createUser();
        $this->createMembership($member, $school);

        // Holder without a linked Employee: the page opens and shows nothing of anyone.
        $this->web($self, $school)->get('/app/my-payslips')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
        $this->web($member, $school)->get('/app/my-payslips')->assertForbidden();
    }

    #[Test]
    public function hidden_navigation_is_not_security(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['students.view']);

        $this->web($user, $school)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('navigation', self::nav(shown: ['students'], hidden: ['finance', 'domains'])));
        // The direct URL still reaches the backend check.
        $this->web($user, $school)->get('/app/finance')->assertForbidden();
        $this->web($user, $school)->getJson('/app/settings/domains')->assertForbidden()->assertJsonPath('message', "You cannot view this School's domains.");
    }

    #[Test]
    public function a_cross_school_record_is_concealed_not_disclosed(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $other = $this->createSchool();
        $foreign = app(TenantContext::class)->withSchool($other, fn () => $this->createStudent($other));

        $this->actingAs($admin)->getJson("/api/v1/schools/{$school->id}/students/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/schools/{$school->id}/students/".Str::uuid())->assertNotFound();
    }

    #[Test]
    public function a_legal_gated_surface_refuses_whatever_the_grants(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school)->get('/app/portal/communications')->assertOk();

        $this->app['env'] = 'production';
        try {
            $this->get('/app/portal/communications')->assertForbidden();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /**
     * ORG.2 finding: announcement attachments needed only authorship, while
     * editing the announcement needs `communications.announce`. A creator
     * who lost that capability (an off-boarded staff member who stays a
     * Guardian member keeps an active membership) is now refused.
     */
    #[Test]
    public function attachments_need_the_announce_capability_not_authorship_alone(): void
    {
        Storage::fake('local');
        $school = $this->createSchool();
        $author = $this->createUserWithCapabilities($school, ['communications.view', 'communications.announce']);
        $former = $this->createUser();
        $this->createMembership($former, $school);

        $draft = fn (User $creator) => app(TenantContext::class)->withSchool($school, function () use ($school, $author, $creator): CommunicationAnnouncement {
            $announcement = app(AnnouncementService::class)->createDraft(
                $school, $author, 'Trip', 'Details', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide, channels: [CommunicationChannel::InApp],
            );
            $announcement->forceFill(['created_by_user_id' => $creator->id])->save();

            return $announcement;
        });

        $own = $draft($author);
        $this->web($author, $school)
            ->post("/app/communications/announcements/{$own->id}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertRedirect("/app/communications/announcements/{$own->id}")->assertSessionHasNoErrors();

        $orphaned = $draft($former);
        $this->web($former, $school)
            ->post("/app/communications/announcements/{$orphaned->id}/attachments", ['file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')])
            ->assertForbidden();
    }

    /**
     * ORG.2 finding (rule 32): the two `idempotent` HR document-record routes
     * had no route-level capability, so a stored success could be replayed to
     * an actor who had since lost the capability. The any-of floor now runs
     * before the idempotency guard.
     */
    #[Test]
    public function an_idempotent_replay_is_re_authorized_first(): void
    {
        $school = $this->createSchool();
        $holder = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $employee = $this->createEmployee($school);
        $url = "/api/v1/schools/{$school->id}/employees/{$employee->id}/hr-document-records";
        $record = ['category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 's3', 'storage_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10];

        $this->actingAs($holder)->withHeader('Idempotency-Key', 'org2-replay-1')->postJson($url, $record)->assertCreated();
        $this->actingAs($holder)->withHeader('Idempotency-Key', 'org2-replay-1')->postJson($url, $record)->assertCreated();

        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->whereHas('membership', fn ($q) => $q->where('user_id', $holder->id))
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $holder->id, 'revocation_reason' => MembershipRoleAssignment::REASON_REVOKED]));
        app(CapabilityResolver::class)->forgetCache($holder, $school);

        $this->actingAs($holder)->withHeader('Idempotency-Key', 'org2-replay-1')->postJson($url, $record)->assertForbidden();
        // A member holding only the sensitive tier still passes the floor and
        // reaches the tier's own step-up (a fresh MFA code), not a 403.
        $sensitive = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $this->actingAs($sensitive)->withHeader('Idempotency-Key', 'org2-replay-2')->postJson($url, ['classification_tier' => 'highly_sensitive'] + $record)
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['mfa_code']]]);
    }
}
