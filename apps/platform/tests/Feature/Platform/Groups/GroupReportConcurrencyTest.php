<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0N.11 (ADR 0048 sections 7, 11): a Group report racing Group
 * governance, as two real OS processes with forced, verified overlap
 * (ForcesConcurrentOverlap) -- never slept. Each School observation holds
 * the Group, the grant and that membership FOR SHARE, so a removal or
 * revocation either commits first (and the report sees it) or waits for
 * the observation.
 */
class GroupReportConcurrencyTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures, ForcesConcurrentOverlap, GroupTestHelpers;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $schoolIds = [];

    private ?string $groupId = null;

    private ?\DateTimeInterface $startedAt = null;

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $this->userIds)->orWhere('subject_id', $this->groupId)->delete();
        $admin->table('schools')->whereIn('id', $this->schoolIds)->delete();
        $admin->table('group_role_assignments')->where('school_group_id', $this->groupId)->delete();
        $admin->table('school_groups')->where('id', $this->groupId)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('user_mfa_recovery_codes')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('user_mfa_factors')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('school_memberships')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('users')->whereIn('id', $this->userIds)->delete();
        // createUserWithCapabilities() (the delivery fixtures) adds ad hoc roles.
        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();

        parent::tearDown();
    }

    /** @return array{0: School, 1: School, 2: SchoolGroup, 3: User, 4: GroupRoleAssignment, 5: User} */
    private function world(): array
    {
        $this->startedAt = now()->subSecond();
        $first = $this->deliveryWorld();
        $second = $this->deliveryWorld();
        [$a, $b] = [$first['school'], $second['school']];
        $group = $this->createGroup([$a, $b]);
        $reporter = $this->groupAdmin($group);
        $root = $this->platformAdmin();

        $this->groupId = $group->id;
        $this->schoolIds = [$a->id, $b->id];
        $this->userIds = [$reporter->id, $root->id, $first['actor']->id, $second['actor']->id,
            ...GroupRoleAssignment::query()->where('school_group_id', $group->id)->pluck('granted_by_user_id')->all()];

        // Report order is by School id: make $b the SECOND School read.
        if (strcmp($a->id, $b->id) > 0) {
            [$a, $b] = [$b, $a];
        }

        return [$a, $b, $group, $reporter, GroupRoleAssignment::query()->where('user_id', $reporter->id)->firstOrFail(), $root];
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/group-report-op.php', ...$args];
    }

    #[Test]
    public function a_removal_that_commits_first_leaves_the_school_out_of_the_report(): void
    {
        [$a, $b, $group, $reporter, , $root] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('remove', $root->id, $group->id, $b->id),
            $this->script('report', $reporter->id, $group->id),
        );

        $this->assertSame('removed', $holder);
        $this->assertSame('report:'.$a->id, $contender, 'School B was not read once its removal committed.');
    }

    #[Test]
    public function a_removal_waits_for_an_observation_already_holding_the_membership(): void
    {
        [$a, $b, $group, $reporter, , $root] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('report', $reporter->id, $group->id),
            $this->script('remove', $root->id, $group->id, $b->id),
        );

        $this->assertSame('report:'.$a->id.','.$b->id, $holder, 'B was observed under a still-valid membership.');
        $this->assertSame('removed', $contender, 'The removal waited, then committed.');
        $this->assertSame(0, DB::table('school_group_members')->where('school_group_id', $group->id)->where('school_id', $b->id)->count());
    }

    #[Test]
    public function a_revocation_that_commits_first_fails_the_report_closed(): void
    {
        [, , $group, $reporter, $grant, $root] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('revoke', $root->id, $grant->id),
            $this->script('report', $reporter->id, $group->id),
        );

        $this->assertSame('revoked', $holder);
        $this->assertSame('failed:authority_lost', $contender);
        $failed = DB::table('platform_audit_events')->where('event_type', GroupCurriculumCoverageReportService::FAILED)->where('subject_id', $group->id)->value('metadata');
        $this->assertSame('authority_lost', json_decode($failed, true)['outcome_code']);
        $this->assertSame(0, DB::table('platform_audit_events')->where('event_type', GroupCurriculumCoverageReportService::VIEWED)->where('subject_id', $group->id)->count());
    }

    #[Test]
    public function a_report_that_completes_first_was_authorized_and_the_revocation_waits(): void
    {
        [$a, $b, $group, $reporter, $grant, $root] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('report', $reporter->id, $group->id),
            $this->script('revoke', $root->id, $grant->id),
        );

        $this->assertSame('report:'.$a->id.','.$b->id, $holder);
        $this->assertSame('revoked', $contender);
        $this->assertNotNull(DB::table('group_role_assignments')->where('id', $grant->id)->value('revoked_at'));
        $this->assertSame(1, DB::table('platform_audit_events')->where('event_type', GroupCurriculumCoverageReportService::VIEWED)->where('subject_id', $group->id)->count());
    }
}
