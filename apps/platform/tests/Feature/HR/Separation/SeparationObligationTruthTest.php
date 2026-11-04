<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationObligationSource;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.15E/F: the separation case tells the truth about other owners' answers.
 * Kept School access is never shown as clear or revoked; a revocation in
 * Identity is reflected; access granted after the page loaded is caught by
 * completion's re-read; and an owner that cannot be asked is UNAVAILABLE and
 * blocks completion -- unknown is never clear -- without poisoning the
 * completion transaction.
 */
class SeparationObligationTruthTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private User $subjectUser;

    private EmploymentSeparationCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->subjectUser = $this->createUser();
        $employee = $this->createEmployee($this->school, ['user_id' => $this->subjectUser->id]);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);

        $cases = app(SeparationCaseService::class);
        $case = $cases->record($this->school, $employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-06-15', $this->hr);
        $case = $cases->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        $this->case = $cases->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function access(): SeparationObligation
    {
        return collect(app(SeparationObligations::class)->forCase($this->school, $this->case->refresh()))->firstWhere('code', SeparationObligations::SCHOOL_ACCESS);
    }

    private function grantStaffRole(): string
    {
        $membership = SchoolMembership::query()->where('school_id', $this->school->id)->where('user_id', $this->subjectUser->id)->first()
            ?? $this->createMembership($this->subjectUser, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['hr.employees.view']));

        return $membership->id;
    }

    #[Test]
    public function kept_access_is_never_shown_as_clear_and_never_as_revoked(): void
    {
        $this->grantStaffRole();
        $this->assertSame(SeparationObligation::OPEN, $this->access()->state);

        $members = $this->createUserWithCapabilities($this->school, ['school.members.manage']);
        $this->case = app(SeparationCaseService::class)->recordAccessRetained($this->school, $this->case->id, $this->case->version, $members);

        $access = $this->access();
        $this->assertSame(SeparationObligation::RETAINED, $access->state, 'kept on purpose, not clear');
        $this->assertSame(1, $access->count, 'the roles are still active');
        $this->assertNotContains($access, SeparationObligations::blocking([$access]), 'kept access does not block completion (HR.12 decision)');
    }

    #[Test]
    public function a_revocation_in_identity_is_reflected_and_a_later_grant_is_caught_at_completion(): void
    {
        $membershipId = $this->grantStaffRole();
        // Identity's own off-boarding effect on the grant (revoked, never deleted).
        DB::table('membership_role_assignments')->where('school_membership_id', $membershipId)
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->hr->id, 'revocation_reason' => 'membership_suspended']);
        $this->assertSame(SeparationObligation::CLEAR, $this->access()->state, 'no active School role: clear, from Identity\'s own rows');

        // The page said clear; access is granted again before HR completes.
        $this->grantStaffRole();
        try {
            app(SeparationCaseService::class)->complete($this->school, $this->case->id, $this->case->refresh()->version, $this->hr);
            $this->fail('completion trusted a stale answer');
        } catch (SeparationException $e) {
            $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $e->errorCode());
        }
        $this->assertSame('finalized', $this->case->refresh()->status);
        $this->assertNotNull(User::query()->find($this->subjectUser->id), 'no User is deleted');
        $this->assertNotNull(Employee::query()->where('user_id', $this->subjectUser->id)->first(), 'no Employee is deleted');
    }

    #[Test]
    public function an_owner_that_cannot_be_asked_is_unavailable_and_blocks_completion_without_poisoning_it(): void
    {
        $this->app->instance('test.failing_source', new class implements SeparationObligationSource
        {
            public function owner(): string
            {
                return 'Broken';
            }

            public function obligations(School $school, SeparationSubject $subject): array
            {
                DB::select('select * from a_table_that_does_not_exist');

                return [];
            }
        });
        $this->app->tag(['test.failing_source'], SeparationObligationSource::TAG);

        $all = collect(app(SeparationObligations::class)->forCase($this->school, $this->case));
        $broken = $all->firstWhere('owner', 'Broken');
        $this->assertSame(SeparationObligation::UNAVAILABLE, $broken->state);
        $this->assertNull($broken->count, 'never a silent zero');
        $this->assertSame(SeparationObligation::CLEAR, $all->firstWhere('code', 'leave_pending_requests')->state, 'the other owners still answered');

        try {
            app(SeparationCaseService::class)->complete($this->school, $this->case->id, $this->case->version, $this->hr);
            $this->fail('completion treated an unknown answer as clear');
        } catch (SeparationException $e) {
            $this->assertSame('SEPARATION_OBLIGATIONS_OPEN', $e->errorCode());
            $this->assertStringContainsString('Broken', $e->getMessage());
        }
        $this->assertSame('finalized', $this->case->refresh()->status);
        $this->assertSame('separated', EmploymentRecord::query()->whereKey($this->case->employment_record_id)->value('status'), 'the employment end is never rolled back by another owner');
    }
}
