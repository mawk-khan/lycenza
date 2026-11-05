<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.16B (R-HR15-1): separation completion CERTIFIES the subject's School
 * access, so it holds Identity's School access lock (the one every grant,
 * revocation, reactivation, invitation acceptance and off-boarding takes
 * first) before reading it. Two real OS processes, with the contender
 * OBSERVED blocked on the holder's uncommitted change (ForcesConcurrentOverlap):
 *
 * - a grant racing completion -- even onto a membership holding NO role --
 *   commits first and completion then refuses (access OPEN), never certifies
 *   a state that was already stale;
 * - a revocation racing completion commits first and completion then sees
 *   the access clear;
 * - a change after completion is a later fact: the completed case stays
 *   completed and its current view shows today's access (no automatic
 *   revocation, owner decision 2026-11-04).
 *
 * Fixtures are committed (child processes must see them) and removed through
 * deleteSchoolAsAdmin(). Child processes use the real clock: dates are past.
 */
class SeparationAccessCertificationTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school);
        }
        parent::tearDown();
    }

    /** @return array{School, User, User, SchoolMembership, EmploymentSeparationCase} [school, school admin, HR actor, subject membership, finalized case] */
    private function finalizedCase(): array
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $this->schools[] = $school;
        $hr = $this->createUserWithCapabilities($school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);
        $approver = $this->createUserWithCapabilities($school, ['hr.separations.view', 'hr.separations.approve']);
        $subject = $this->createUser();
        $membership = $this->createMembership($subject, $school);
        // A former staff account (Identity's definition: it has held a School role) that holds NONE now.
        $this->assignSchoolRole($membership, 'librarian');
        app(StaffAccessService::class)->revokeRole($school, $admin, $membership->id, 'librarian');
        $employment = app(EmploymentService::class)->create($this->createEmployee($school, ['user_id' => $subject->id]), ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $hr);
        $cases = app(SeparationCaseService::class);
        $case = $cases->record($school, $employment->id, SeparationReason::RESIGNATION, '2026-01-10', '2026-01-31', $hr);
        $case = $cases->approve($school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        $case = $cases->finalize($school, $case->id, $case->version, $hr, $this->freshMfaProofFor($hr));

        return [$school, $admin, $hr, $membership, $case];
    }

    /** @return list<string> */
    private function op(string $op, School $school, User $actor, string $arg1, string $arg2): array
    {
        return ['php', __DIR__.'/../../../Support/staff-access-certification-op.php', $op, $school->id, $actor->id, $arg1, $arg2];
    }

    private function caseStatus(School $school, EmploymentSeparationCase $case): string
    {
        return app(TenantContext::class)->withSchool($school, fn () => (string) EmploymentSeparationCase::query()->whereKey($case->id)->value('status'));
    }

    #[Test]
    public function a_grant_onto_a_role_less_membership_racing_completion_is_seen_and_completion_refuses(): void
    {
        [$school, $admin, $hr, $membership, $case] = $this->finalizedCase();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('grant', $school, $admin, $membership->id, 'teacher'),
            $this->op('complete', $school, $hr, $case->id, (string) $case->version),
        );

        $this->assertSame('ok:granted', $holder);
        $this->assertSame('refused:SEPARATION_OBLIGATIONS_OPEN', $contender, 'completion waited for the grant and never certified a stale "no access"');
        $this->assertSame('finalized', $this->caseStatus($school, $case));
    }

    #[Test]
    public function a_revocation_racing_completion_is_seen_and_completion_certifies_clear_access(): void
    {
        [$school, $admin, $hr, $membership, $case] = $this->finalizedCase();
        $this->assignSchoolRole($membership, 'teacher');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('revoke', $school, $admin, $membership->id, 'teacher'),
            $this->op('complete', $school, $hr, $case->id, (string) $case->version),
        );

        $this->assertSame('ok:revoked', $holder);
        $this->assertSame('ok:completed', $contender);
        $this->assertSame('completed', $this->caseStatus($school, $case));
    }

    #[Test]
    public function a_grant_after_completion_is_a_later_fact_and_is_shown_never_revoked(): void
    {
        [$school, $admin, $hr, $membership, $case] = $this->finalizedCase();
        $completed = app(SeparationCaseService::class)->complete($school, $case->id, $case->version, $hr);
        $this->assertSame('completed', $completed->status);

        app(StaffAccessService::class)->grantRole($school, $admin, $membership->id, 'teacher');

        $this->assertSame('completed', $this->caseStatus($school, $case), 'the completion record is history');
        $fresh = app(TenantContext::class)->withSchool($school, fn () => EmploymentSeparationCase::query()->findOrFail($case->id));
        $access = collect(app(SeparationObligations::class)->forCase($school, $fresh))->firstWhere('code', SeparationObligations::SCHOOL_ACCESS);
        $this->assertSame([SeparationObligation::OPEN, 1], [$access->state, $access->count], 'the current view tells the truth about access granted since');
    }

    #[Test]
    public function another_schools_grant_never_blocks_or_changes_this_completion_and_authority_is_still_required(): void
    {
        [$school, , $hr, $membership, $case] = $this->finalizedCase();
        [, $other] = $this->createSchoolAdmin();
        $this->schools[] = $other;
        $this->assignSchoolRole($this->createMembership(User::query()->findOrFail($membership->user_id), $other), 'teacher');

        $outsider = $this->createUserWithCapabilities($school, ['hr.separations.view']);
        try {
            app(SeparationCaseService::class)->complete($school, $case->id, $case->version, $outsider);
            $this->fail('completion needs hr.separations.manage');
        } catch (SeparationException|AuthorizationException) {
        }

        $this->assertSame('completed', app(SeparationCaseService::class)->complete($school, $case->id, $case->version, $hr)->status, 'a role in another School is not access here');
    }
}
