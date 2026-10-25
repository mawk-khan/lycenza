<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Application\Exceptions\EmptyAudienceException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\HR\Application\CurrentStaffMembers;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9D-1 (ADR 0076 §73, PV1-10): the Staff audience is the School's CURRENT
 * staff -- an active membership, an enabled User, the linked active Employee
 * and exactly one current employment (HR's CurrentStaffMembers) -- never a
 * role, capability or persona. It is resolved at preview and again at
 * publish (a leaver is never snapshotted, a new hire is), deduplicated, and
 * the School-wide approval rule applies to it.
 */
class StaffAudienceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $creator;

    /** @var array<string, User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15T06:00:00Z'));
        [$this->creator, $this->school] = $this->createSchoolAdmin('school_admin');
        $this->school->forceFill(['timezone' => 'Asia/Kolkata'])->save();

        $this->u['teacher'] = $this->staff('2025-01-01', null);
        $this->u['notice'] = $this->staff('2025-01-01', null, 'notice_period');
        $this->u['leaver'] = $this->staff('2024-01-01', '2026-07-14', 'separated');
        $this->u['futureHire'] = $this->staff('2026-07-16', null);
        $this->u['archived'] = $this->staff('2025-01-01', null, 'active', 'archived');
        $this->u['disabled'] = $this->staff('2025-01-01', null);
        $this->u['disabled']->forceFill(['is_disabled' => true])->save();
        $this->u['suspended'] = $this->staff('2025-01-01', null);
        $this->inSchool(fn () => DB::table('school_memberships')->where('user_id', $this->u['suspended']->id)->update(['status' => 'suspended']));
        $this->u['twoRoles'] = $this->staff('2025-01-01', null, roles: ['teacher', 'librarian']);
        $this->u['guardianOnly'] = $this->member();
        $this->u['studentOnly'] = $this->member();
        $this->u['platformOnly'] = $this->createUser();
        // An Employee with no linked User is never a recipient (Communications delivers to Users).
        $unlinked = $this->createEmployee($this->school, ['record_status' => 'active', 'user_id' => null]);
        $this->createEmploymentRecord($unlinked, ['status' => 'active', 'starts_on' => '2025-01-01', 'ends_on' => null]);
    }

    private function inSchool(callable $fn, ?School $school = null): mixed
    {
        return app(TenantContext::class)->withSchool($school ?? $this->school, $fn);
    }

    private function member(?School $school = null): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school ?? $this->school);

        return $user;
    }

    /** @param  list<string>  $roles */
    private function staff(string $starts, ?string $ends, string $status = 'active', string $record = 'active', array $roles = ['teacher'], ?School $school = null, ?User $user = null): User
    {
        $school ??= $this->school;
        $user ??= $this->createUser();
        $membership = $this->createMembership($user, $school);
        foreach ($roles as $role) {
            $this->assignSchoolRole($membership, $role);
        }
        $employee = $this->createEmployee($school, ['user_id' => $user->id, 'record_status' => $record]);
        $this->createEmploymentRecord($employee, ['status' => $status, 'starts_on' => $starts, 'ends_on' => $ends]);

        return $user;
    }

    /** @return list<string> */
    private function staffIds(?School $school = null): array
    {
        $ids = app(CurrentStaffMembers::class)->userIds($school ?? $this->school);
        sort($ids);

        return $ids;
    }

    /** @param  list<string>  $keys */
    private function ids(array $keys): array
    {
        $ids = array_map(fn (string $k) => (string) $this->u[$k]->id, $keys);
        sort($ids);

        return $ids;
    }

    private function draft(CommunicationAudienceType $type = CommunicationAudienceType::Staff): CommunicationAnnouncement
    {
        return app(AnnouncementService::class)->createDraft($this->school, $this->creator, 'Staff meeting', 'Friday 3 pm in the hall.', CommunicationPriority::Normal, $type);
    }

    /** @return list<string> */
    private function recipients(CommunicationAnnouncement $announcement): array
    {
        $ids = $this->inSchool(fn () => CommunicationAnnouncementRecipient::query()->where('announcement_id', $announcement->id)->pluck('user_id')->map(fn ($id) => (string) $id)->all());
        sort($ids);

        return $ids;
    }

    #[Test]
    public function only_current_linked_active_employees_are_staff(): void
    {
        $this->assertSame($this->ids(['teacher', 'notice', 'twoRoles']), $this->staffIds(), 'never a leaver, a future hire, an archived Employee, a disabled User, a suspended member, a non-employee or an Employee without a User');

        // A role name is not staff: a Guardian/Student-only member holding no Employee stays out even with a staff role.
        $this->assignSchoolRole(SchoolMembership::query()->where('user_id', $this->u['guardianOnly']->id)->firstOrFail(), 'teacher');
        $this->assertNotContains((string) $this->u['guardianOnly']->id, $this->staffIds());

        // Today is School-local: on 07-16 (IST) the future hire joins and the notice-period Employee still counts.
        $this->travelTo(CarbonImmutable::parse('2026-07-15T18:31:00Z'));
        $this->assertContains((string) $this->u['futureHire']->id, $this->staffIds());
    }

    #[Test]
    public function staff_identity_is_per_school_and_two_eligible_employments_fail_closed(): void
    {
        $other = $this->createSchool();
        $both = $this->staff('2025-01-01', null, school: $other);
        $this->createMembership($both, $this->school); // a plain member here, staff only in the other School
        $this->assertNotContains((string) $both->id, $this->staffIds());
        $this->assertSame([(string) $both->id], $this->staffIds($other));

        $employee = $this->inSchool(fn () => Employee::query()->where('user_id', $this->u['teacher']->id)->firstOrFail());
        $this->inSchool(fn () => DB::table('employment_records')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id,
            'status' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => null, 'created_at' => now(), 'updated_at' => now(),
        ] + $this->employmentDefaults()));
        $this->assertNotContains((string) $this->u['teacher']->id, $this->staffIds(), 'ambiguous current employment: excluded, never a tie-break');
    }

    /** Required columns of an employment row beyond the ones under test. */
    private function employmentDefaults(): array
    {
        $sample = $this->inSchool(fn () => (array) DB::table('employment_records')->first());

        return array_diff_key($sample, array_flip(['id', 'school_id', 'employee_id', 'status', 'starts_on', 'ends_on', 'created_at', 'updated_at']));
    }

    #[Test]
    public function the_preview_counts_each_current_staff_member_once_and_excludes_the_creator(): void
    {
        $announcement = $this->draft();
        $preview = app(AnnouncementService::class)->previewAudience($announcement);

        $this->assertSame(['Staff' => 3], $preview->categoryBreakdown);
        $this->assertSame($this->ids(['teacher', 'notice', 'twoRoles']), collect($preview->userIds)->sort()->values()->all(), 'two roles = one recipient; Guardian/Student/platform-only Users never');
        $this->assertSame([], $preview->guardianIds);
        $this->assertSame([], $preview->studentIds);

        $creatorAsStaff = $this->staff('2025-01-01', null, user: $this->createUser());
        $mine = app(AnnouncementService::class)->createDraft($this->school, $creatorAsStaff, 'Mine', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Staff);
        $this->assertNotContains((string) $creatorAsStaff->id, app(AnnouncementService::class)->previewAudience($mine)->userIds);
    }

    #[Test]
    public function publishing_delivers_once_to_current_staff_only(): void
    {
        $published = app(AnnouncementService::class)->publish($this->draft(), $this->creator);

        $this->assertSame('published', $published->status);
        $this->assertSame($this->ids(['teacher', 'notice', 'twoRoles']), $this->recipients($published));
        $deliveries = $this->inSchool(fn () => CommunicationDelivery::query()->count());
        $this->assertSame(3, $deliveries, 'one in-app delivery per staff member, never per role');

        // A replayed publish adds nothing.
        app(AnnouncementService::class)->publish($published, $this->creator);
        $this->assertSame(3, $this->inSchool(fn () => CommunicationDelivery::query()->count()));

        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('subject_id', $published->id)->get());
        foreach ($audit as $event) {
            foreach ($this->ids(['teacher', 'notice', 'twoRoles']) as $id) {
                $this->assertStringNotContainsString($id, json_encode($event->metadata), "{$event->event_type} never lists the recipients");
            }
        }
    }

    #[Test]
    public function eligibility_is_read_again_at_publish_so_a_leaver_is_dropped_and_a_new_hire_added(): void
    {
        $announcement = $this->draft();
        $this->assertCount(3, app(AnnouncementService::class)->previewAudience($announcement)->userIds);

        // After the preview: the teacher leaves today (ends yesterday) and a new hire starts today.
        $this->inSchool(fn () => EmploymentRecord::query()->whereHas('employee', fn ($q) => $q->where('user_id', $this->u['teacher']->id))
            ->update(['ends_on' => '2026-07-14', 'status' => 'separated']));
        $hire = $this->staff('2026-07-15', null);

        $published = app(AnnouncementService::class)->publish($announcement, $this->creator);
        $ids = $this->recipients($published);
        $this->assertNotContains((string) $this->u['teacher']->id, $ids, 'the preview never reserves a recipient');
        $this->assertContains((string) $hire->id, $ids);
    }

    #[Test]
    public function the_school_wide_approval_rule_covers_staff_and_switching_audience_never_bypasses_it(): void
    {
        $this->createApprovalPolicy($this->school, ['require_school_wide_approval' => true]);
        $approvals = app(CommunicationApprovalService::class);
        $announcement = $this->draft();

        $this->assertSame(['school_wide'], $approvals->requirement($announcement)->reasons);
        $this->assertThrows(fn () => app(AnnouncementService::class)->publish($announcement, $this->creator), ApprovalRequiredException::class);

        // Approved as School-wide, then switched to Staff: the approval no longer matches.
        $wide = $this->draft(CommunicationAudienceType::SchoolWide);
        $request = $approvals->submit($wide, $this->creator);
        $approver = $this->createUserWithCapabilities($this->school, ['communications.approve', 'communications.view']);
        $approvals->approve($request, $approver);
        $this->inSchool(fn () => DB::table('communication_announcements')->where('id', $wide->id)->update(['audience_type' => 'staff']));
        $this->assertThrows(fn () => app(AnnouncementService::class)->publish($this->inSchool(fn () => $wide->fresh()), $this->creator), ApprovalRequiredException::class);

        // The normal path: submit -> another person approves -> publish -> staff only.
        $request = $approvals->submit($announcement, $this->creator);
        $approvals->approve($request, $approver);
        $published = app(AnnouncementService::class)->publish($this->inSchool(fn () => $announcement->fresh()), $this->creator);
        $this->assertSame($this->ids(['teacher', 'notice', 'twoRoles']), $this->recipients($published));
        $this->assertSame('approved', $this->inSchool(fn () => CommunicationApprovalRequest::query()->whereKey($request->id)->value('status')));
    }

    #[Test]
    public function a_rejected_staff_announcement_sends_nothing_and_an_empty_staff_audience_never_publishes(): void
    {
        $this->createApprovalPolicy($this->school, ['require_school_wide_approval' => true]);
        $approvals = app(CommunicationApprovalService::class);
        $announcement = $this->draft();
        $approvals->reject($approvals->submit($announcement, $this->creator), $this->createUserWithCapabilities($this->school, ['communications.approve', 'communications.view']), 'Not now');
        $this->assertSame([], $this->recipients($announcement));

        $empty = $this->createSchool();
        $admin = $this->createUserWithCapabilities($empty, ['communications.announce', 'communications.view', 'communications.manage']);
        $this->member($empty);
        $lonely = app(AnnouncementService::class)->createDraft($empty, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Staff);
        $this->assertThrows(fn () => app(AnnouncementService::class)->publish($lonely, $admin), EmptyAudienceException::class);
        $this->assertSame('draft', app(TenantContext::class)->withSchool($empty, fn () => $lonely->fresh()->status), 'refused as a whole, never half-published');
    }

    #[Test]
    public function the_composer_accepts_staff_and_authority_is_unchanged(): void
    {
        $this->actingAs($this->creator)->post("/app/schools/{$this->school->id}/activate");
        $this->post('/app/communications/announcements', [
            'title' => 'Staff only', 'body' => 'Hello staff', 'priority' => 'normal', 'audience_type' => 'staff',
        ])->assertRedirect();
        $this->assertSame(1, $this->inSchool(fn () => CommunicationAnnouncement::query()->where('audience_type', 'staff')->count()));

        $member = $this->member();
        $this->actingAs($member)->post("/app/schools/{$this->school->id}/activate");
        $this->post('/app/communications/announcements', ['title' => 'x', 'body' => 'y', 'priority' => 'normal', 'audience_type' => 'staff'])->assertForbidden();
    }

    #[Test]
    public function the_single_user_answer_agrees_with_the_staff_set(): void
    {
        // ORG.9D-3: CurrentStaffMembers::includes() is the same identity chain for ONE User.
        $staff = app(CurrentStaffMembers::class);
        $set = $staff->userIds($this->school);
        foreach ($this->u as $key => $user) {
            $this->assertSame(in_array((string) $user->id, $set, true), $staff->includes($this->school, $user), $key);
        }
    }
}
