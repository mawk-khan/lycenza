<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\ConcessionDecisionConflictException;
use App\Domain\Fees\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Guardians\Application\Exceptions\GuardianSelfAdministrationException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Identity\Application\AccountLinkService;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * ORG.8A-R1 (ADR 0076 §59), Rule A, closing ORG.8A G-7: nobody linked as a
 * Guardian of a concession's Student -- through any Guardian identity their
 * account has ever been linked to, active or revoked -- approves or rejects
 * that concession, whoever requested it, by service, API or browser, even
 * with a fresh MFA code. Per Student: the same person decides another
 * Student's concession, and an unrelated approver decides this one. Before
 * R1 a Guardian approver approved their own child's concession, and could
 * unlink, decide and relink (now refused by Rule B).
 */
class FeeConcessionBeneficiaryConflictTest extends TestCase
{
    use CreatesFeeConcessionFixtures, ProvidesSensitiveActionMfa;

    /** Links $user's account (their membership) to Guardian identity $guardian. */
    private function linkAccount(array $w, User $user, Guardian $guardian): void
    {
        $membership = $this->inSchool($w['school'], fn () => SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $w['school']->id)->firstOrFail());
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->linkGuardian($w['school'], $guardian, $membership, $w['recorder']));
    }

    /** @return array{0: User, 1: Guardian} an approver who is a Guardian of the world's Student */
    private function guardianApprover(array $w): array
    {
        $approver = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.approve', 'students.manage', 'guardians.manage']);
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $guardian);
        $this->linkAccount($w, $approver, $guardian);

        return [$approver, $guardian];
    }

    private function assertConflicted(array $w, User $decider, FeeConcession $concession): void
    {
        $journals = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
        foreach (['approve', 'reject'] as $decision) {
            try {
                $this->concessions()->{$decision}($w['school'], $concession->id, $decider);
                $this->fail("A Guardian of the Student must not {$decision}.");
            } catch (ConcessionDecisionConflictException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertStringNotContainsStringIgnoringCase('guardian', $e->getMessage(), 'no relationship oracle');
            }
        }
        $this->assertSame(FeeConcession::STATUS_PENDING, $this->inSchool($w['school'], fn () => $concession->refresh()->status));
        $this->assertCount(0, $this->adjustmentsOf($w), 'no adjustment');
        $this->assertSame($journals, $this->inSchool($w['school'], fn () => JournalEntry::query()->count()), 'no journal');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->whereIn('event_type', ['fee_concession.approved', 'fee_concession.rejected'])->count()));
    }

    #[Test]
    public function a_guardian_approver_cannot_decide_a_colleagues_concession_for_their_student(): void
    {
        $w = $this->concessionWorld();
        [$approver] = $this->guardianApprover($w);

        $this->assertConflicted($w, $approver, $this->requestTargeted($w, '100.00'));
    }

    #[Test]
    public function the_guardian_cannot_unlink_decide_and_relink(): void
    {
        $w = $this->concessionWorld();
        [$approver, $guardian] = $this->guardianApprover($w);
        $concession = $this->requestTargeted($w, '100.00');

        $this->expectException(GuardianSelfAdministrationException::class);
        try {
            app(StudentGuardianRelationshipService::class)->unlink(
                $this->inSchool($w['school'], fn () => StudentGuardianRelationship::query()->where('guardian_id', $guardian->id)->firstOrFail()),
                $approver,
            );
        } finally {
            $this->assertConflicted($w, $approver, $concession);
        }
    }

    #[Test]
    public function a_revoked_account_link_and_any_of_several_guardians_still_conflict(): void
    {
        $w = $this->concessionWorld();
        [$approver, $guardian] = $this->guardianApprover($w);
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->unlinkGuardian($w['school'], $guardian, $w['recorder']));
        $unrelated = $this->createGuardian($w['school']);
        $this->linkAccount($w, $approver, $unrelated);

        // Revoked link to the Student's Guardian + an active link to an unrelated one: still conflicted.
        $this->assertConflicted($w, $approver, $this->requestTargeted($w, '100.00'));

        // A Student with two Guardians: the approver linked to the second one only is conflicted too.
        $other = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.approve']);
        $second = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $second);
        $this->linkAccount($w, $other, $second);
        $this->assertConflicted($w, $other, $this->requestTargeted($w, '90.00'));
    }

    #[Test]
    public function the_guardian_may_request_and_an_unrelated_approver_decides(): void
    {
        $w = $this->concessionWorld();
        $requester = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.request', 'finance.fee_concessions.approve']);
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $guardian);
        $this->linkAccount($w, $requester, $guardian);

        $concession = $this->requestTargeted($w, '100.00', actor: $requester);
        $this->assertSame(FeeConcession::STATUS_PENDING, $concession->status, 'requesting is not a decision');

        try {
            $this->concessions()->approve($w['school'], $concession->id, $requester);
            $this->fail('The requester never decides.');
        } catch (SelfApprovalNotAllowedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(FeeConcession::STATUS_APPROVED, $this->concessions()->approve($w['school'], $concession->id, $w['checker'])->status);
        $this->assertCount(1, $this->adjustmentsOf($w), 'the canonical adjustment and journal path');
    }

    #[Test]
    public function the_conflict_is_per_student(): void
    {
        $w = $this->concessionWorld();
        [$approver] = $this->guardianApprover($w);
        $otherStudent = $this->createStudent($w['school']);
        $otherCharge = $this->assessCharge($w['school'], $otherStudent, $w['year'], $w['receivable'], $w['revenue'], '400.00');

        $concession = $this->requestTargeted($w, '100.00', charge: $otherCharge);
        $this->assertSame(FeeConcession::STATUS_APPROVED, $this->concessions()->approve($w['school'], $concession->id, $approver)->status);
    }

    #[Test]
    public function after_an_independent_administrator_ends_the_relationship_the_current_contract_applies(): void
    {
        $w = $this->concessionWorld();
        [$approver, $guardian] = $this->guardianApprover($w);
        $concession = $this->requestTargeted($w, '100.00');
        $admin = $this->createUserWithCapabilities($w['school'], ['students.manage', 'guardians.manage']);

        app(StudentGuardianRelationshipService::class)->unlink(
            $this->inSchool($w['school'], fn () => StudentGuardianRelationship::query()->where('guardian_id', $guardian->id)->firstOrFail()),
            $admin,
        );

        $this->assertSame(FeeConcession::STATUS_REJECTED, $this->concessions()->reject($w['school'], $concession->id, $approver)->status);
    }

    #[Test]
    public function the_json_api_refuses_after_a_valid_fresh_mfa_code_without_naming_the_relationship(): void
    {
        $w = $this->concessionWorld();
        [$approver, $guardian] = $this->guardianApprover($w);
        $concession = $this->requestTargeted($w, '100.00');

        $token = $this->mfaToken($approver);
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/schools/{$w['school']->id}/fee-concessions/{$concession->id}/approve", $this->mfaBody($token))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FEE_CONCESSION_DECISION_CONFLICT');
        foreach (['guardian', $guardian->id, $w['student']->id] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $response->getContent());
        }
        $this->assertCount(0, $this->adjustmentsOf($w));
    }
}
