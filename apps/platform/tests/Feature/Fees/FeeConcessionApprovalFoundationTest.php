<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Fees\Application\FeeConcessionService;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Models\SchoolAuditEvent;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Tenancy\SchoolNotOperationalException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * ORG.8A (ADR 0076 §58): the fee-concession pilot of the shared approval
 * foundation. Approve and reject decide under the concession lock with the
 * decider's CURRENT authority (read fresh, so a revocation or a disabled
 * module the 60 s caches have not seen still refuses), the shared
 * maker/checker over the concession's durable participants, and approval
 * provenance on the audit event (ids and closed codes only). Nothing here
 * changes who may request or approve.
 */
class FeeConcessionApprovalFoundationTest extends TestCase
{
    use CreatesFeeConcessionFixtures, ProvidesSensitiveActionMfa;

    /** @return array<string, mixed> */
    private function lastAudit(array $w, string $event): array
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $event)->latest('id')->firstOrFail()->metadata);
    }

    /** Warms the 60 s grant and entitlement caches the way an earlier request would. */
    private function warmCaches(array $w): void
    {
        $this->assertTrue(Gate::forUser($w['checker'])->allows('capability', [FeeConcessionService::APPROVE, $w['school']]));
    }

    private function assertNothingDecided(array $w, FeeConcession $concession): void
    {
        $this->assertSame(FeeConcession::STATUS_PENDING, $this->inSchool($w['school'], fn () => $concession->refresh()->status));
        $this->assertCount(0, $this->adjustmentsOf($w));
    }

    #[Test]
    public function the_participants_are_exactly_the_requester_from_the_concession_row(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        $participants = FeeConcessionService::participants($concession);
        $this->assertSame('fee_concessions', $participants->workflow);
        $this->assertSame($concession->id, $participants->itemId);
        $this->assertSame(['requester' => [$w['maker']->id]], $participants->all());
    }

    #[Test]
    public function the_requester_never_decides_even_holding_both_capabilities_by_a_direct_service_call(): void
    {
        $w = $this->concessionWorld();
        $both = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.request', 'finance.fee_concessions.approve']);
        $concession = $this->requestTargeted($w, actor: $both);

        foreach (['approve', 'reject'] as $decision) {
            try {
                $this->concessions()->{$decision}($w['school'], $concession->id, $both);
                $this->fail("The requester must not {$decision} their own concession.");
            } catch (SelfApprovalNotAllowedException) {
                $this->assertNothingDecided($w, $concession);
            }
        }

        // An item the dual holder did NOT request is theirs to decide (per item, never per role).
        $other = $this->requestTargeted($w, '50.00');
        $this->assertSame(FeeConcession::STATUS_APPROVED, $this->concessions()->approve($w['school'], $other->id, $both)->status);
    }

    #[Test]
    public function a_grant_revoked_behind_the_cache_refuses_the_decision_at_decision_time(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);
        $this->warmCaches($w);

        $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $w['checker']->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertTrue(Gate::forUser($w['checker'])->allows('capability', [FeeConcessionService::APPROVE, $w['school']]), 'the cached floor still says yes');

        $this->expectException(AuthorizationException::class);
        try {
            $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        } finally {
            $this->assertNothingDecided($w, $concession);
        }
    }

    #[Test]
    public function a_module_disabled_behind_the_cache_refuses_the_decision(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);
        $this->warmCaches($w);

        $this->inSchool($w['school'], fn () => DB::table('school_module_entitlements')->where('school_id', $w['school']->id)->where('module_key', 'fees')->update(['enabled' => false]));

        $this->expectException(AuthorizationException::class);
        try {
            $this->concessions()->reject($w['school'], $concession->id, $w['checker']);
        } finally {
            $this->assertNothingDecided($w, $concession);
        }
    }

    #[Test]
    public function a_suspended_school_or_a_disabled_user_cannot_decide(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        DB::table('users')->where('id', $w['checker']->id)->update(['is_disabled' => true]);
        try {
            // The stale model passes the cached floor; the decision-time read sees the disabled User.
            $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
            $this->fail('A disabled User has no authority.');
        } catch (AuthorizationException) {
            $this->assertNothingDecided($w, $concession);
        }
        DB::table('users')->where('id', $w['checker']->id)->update(['is_disabled' => false]);

        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'suspended']);
        $this->expectException(SchoolNotOperationalException::class);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']->fresh());
    }

    #[Test]
    public function a_decision_without_the_approve_capability_is_refused_below_the_controller(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        $this->expectException(AuthorizationException::class);
        $this->concessions()->approve($w['school'], $concession->id, $w['maker']);
    }

    #[Test]
    public function the_audit_events_carry_approval_provenance_and_nothing_sensitive(): void
    {
        $w = $this->concessionWorld();
        $approved = $this->requestTargeted($w, '120.00');
        $proof = new FreshMfaProof($w['checker']->id, CarbonImmutable::now());
        $this->concessions()->approve($w['school'], $approved->id, $w['checker'], $proof);
        $rejected = $this->requestTargeted($w, '30.00');
        $this->concessions()->reject($w['school'], $rejected->id, $w['checker']);

        $requested = $this->lastAudit($w, 'fee_concession.requested');
        $this->assertSame(['workflow' => 'fee_concessions', 'decision' => 'requested', 'participants' => ['requester' => [$w['maker']->id]]],
            ['workflow' => $requested['approvalWorkflow'], 'decision' => $requested['approvalDecision'], 'participants' => $requested['participants']]);
        $this->assertArrayNotHasKey('decidedByUserId', $requested);

        $approval = $this->lastAudit($w, 'fee_concession.approved');
        $this->assertSame([$approved->id, 'approved', $w['checker']->id, 'school', true],
            [$approval['feeConcessionId'], $approval['approvalDecision'], $approval['decidedByUserId'], $approval['decisionScope'], $approval['freshMfa']]);

        $rejection = $this->lastAudit($w, 'fee_concession.rejected');
        $this->assertSame(['rejected', false], [$rejection['approvalDecision'], $rejection['freshMfa']]);

        foreach ([$requested, $approval, $rejection] as $metadata) {
            $encoded = json_encode($metadata);
            $this->assertStringNotContainsString('120.00', $encoded, 'no amount');
            $this->assertStringNotContainsString($w['student']->id, $encoded, 'no Student');
        }
    }

    #[Test]
    public function a_fresh_mfa_proof_is_recorded_only_for_the_decider(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        $this->expectException(LogicException::class);
        try {
            $this->concessions()->approve($w['school'], $concession->id, $w['checker'], new FreshMfaProof($w['maker']->id, CarbonImmutable::now()));
        } finally {
            $this->assertNothingDecided($w, $concession);
        }
    }

    #[Test]
    public function the_json_api_approval_records_the_verified_fresh_mfa(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        $token = $this->mfaToken($w['checker']);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/schools/{$w['school']->id}/fee-concessions/{$concession->id}/approve", $this->mfaBody($token))
            ->assertOk();

        $this->assertTrue($this->lastAudit($w, 'fee_concession.approved')['freshMfa']);
    }

    #[Test]
    public function the_requested_terms_and_requester_cannot_change_after_submission(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        foreach (['fixed_amount' => '1.00', 'requested_by_user_id' => $w['checker']->id, 'charge_id' => $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '10.00')->id] as $column => $value) {
            try {
                $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('fee_concessions')->where('id', $concession->id)->update([$column => $value])));
                $this->fail("{$column} must be immutable after submission.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('request is immutable', $e->getMessage(), $column);
            }
        }
    }

    #[Test]
    public function the_pilot_keys_stay_whole_school_and_campus_incompatible(): void
    {
        foreach ([FeeConcessionService::REQUEST, FeeConcessionService::APPROVE] as $key) {
            $this->assertFalse(CampusScopeCatalogue::isAdopted($key), "{$key} is not Campus-adopted");
            $this->assertTrue(CampusScopeCatalogue::isCampusIncompatible($key), "{$key} never rides a Campus grant");
        }
    }
}
