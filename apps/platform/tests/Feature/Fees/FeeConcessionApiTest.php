<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * FEE.3: the /api/v1 concession surface -- allow AND deny for every
 * operation (route middleware plus the Application service), Principal
 * denied by default, maker/checker, replay 200 vs. create 201, typed 409s,
 * validation 422s and cross-School 404s.
 */
class FeeConcessionApiTest extends TestCase
{
    use CreatesFeeConcessionFixtures, ProvidesSensitiveActionMfa;

    private ?User $actor = null;

    private function as(array $w, User $actor): static
    {
        $this->actor = $actor;

        return $this->actingAs($actor)->withHeader('X-School-Id', $w['school']->id);
    }

    /** SR.4 (ADR 0071 §26.7): approve, revoke and adjustment cancellation send a fresh code. */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($method === 'POST' && $this->actor !== null && preg_match('#/(fee-concessions/[^/]+/(approve|revoke)|fee-adjustments/[^/]+/cancel)$#', $uri) === 1) {
            $data += ['mfa_code' => $this->freshMfaCode($this->actor)];
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function targetedBody(array $w, string $amount = '150.00', ?string $key = null): array
    {
        return ['idempotency_key' => $key ?? (string) Str::uuid(), 'scope' => 'targeted', 'category' => 'concession', 'kind' => 'fixed', 'fixed_amount' => $amount, 'charge_id' => $w['charge']->id];
    }

    #[Test]
    public function every_operation_is_denied_without_its_capability_and_allowed_with_it(): void
    {
        $w = $this->concessionWorld();
        $b = $this->base($w);
        $pendingA = $this->requestTargeted($w, '10.00');
        $pendingB = $this->requestTargeted($w, '10.00');
        $approved = $this->requestTargeted($w, '10.00');
        $this->concessions()->approve($w['school'], $approved->id, $w['checker']);
        $adjustment = $this->adjustmentsOf($w)->sole();
        $enrollment = $this->enroll($w);
        $standing = $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_FIXED, '10.00');

        $unrelated = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.charges.manage']);
        $operations = [
            ['GET', "{$b}/fee-concessions", 'finance.fee_concessions.view', []],
            ['GET', "{$b}/fee-concessions/{$approved->id}", 'finance.fee_concessions.view', []],
            ['GET', "{$b}/fee-adjustments?charge_id={$w['charge']->id}", 'finance.fee_concessions.view', []],
            ['GET', "{$b}/fee-settings", 'finance.fee_structures.view', []],
            ['PUT', "{$b}/fee-settings/concession-account", 'finance.fee_structures.manage', ['concession_ledger_account_id' => $w['expense']->id]],
            ['POST', "{$b}/fee-concessions", 'finance.fee_concessions.request', $this->targetedBody($w)],
            ['POST', "{$b}/fee-concessions/{$pendingA->id}/approve", 'finance.fee_concessions.approve', []],
            ['POST', "{$b}/fee-concessions/{$pendingB->id}/reject", 'finance.fee_concessions.approve', []],
            ['POST', "{$b}/fee-concessions/{$standing->id}/revoke", 'finance.fee_concessions.approve', []],
            ['POST', "{$b}/fee-adjustments/{$adjustment->id}/cancel", 'finance.fee_concessions.approve', ['reason' => 'test']],
        ];

        foreach ($operations as [$method, $url, , $body]) {
            $this->as($w, $unrelated)->json($method, $url, $body)->assertForbidden();
        }

        foreach ($operations as [$method, $url, $capability, $body]) {
            $actor = $this->createUserWithCapabilities($w['school'], [$capability]);
            $response = $this->as($w, $actor)->json($method, $url, $body);
            $this->assertContains($response->status(), [200, 201], "{$method} {$url} with {$capability} must succeed, got {$response->status()}: {$response->getContent()}");
        }

        // Withdraw: .request, and only by the requester.
        $mine = $this->requestTargeted($w, '5.00');
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.approve']))->postJson("{$b}/fee-concessions/{$mine->id}/withdraw")->assertForbidden();
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.request']))->postJson("{$b}/fee-concessions/{$mine->id}/withdraw")
            ->assertForbidden()->assertJsonPath('error.code', 'FEE_CONCESSION_NOT_REQUESTER');
        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions/{$mine->id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    #[Test]
    public function principal_holds_no_concession_capability(): void
    {
        $w = $this->concessionWorld();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');

        $this->as($w, $principal)->getJson($this->base($w).'/fee-concessions')->assertForbidden();
        $this->as($w, $principal)->postJson($this->base($w).'/fee-concessions', $this->targetedBody($w))->assertForbidden();
    }

    #[Test]
    public function maker_checker_over_http_with_replay_and_typed_errors(): void
    {
        $w = $this->concessionWorld();
        $b = $this->base($w);
        $key = (string) Str::uuid();

        $created = $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", $this->targetedBody($w, '150.00', $key))->assertCreated();
        $id = $created->json('data.id');
        $created->assertJsonPath('data.status', 'pending')->assertJsonPath('data.fixedAmount', '150.00')->assertJsonMissingPath('data.schoolId');

        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", $this->targetedBody($w, '150.00', $key))->assertOk()->assertJsonPath('data.id', $id);
        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", $this->targetedBody($w, '151.00', $key))->assertStatus(409)->assertJsonPath('error.code', 'FEE_CONCESSION_IDEMPOTENCY_CONFLICT');

        $both = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.request', 'finance.fee_concessions.approve']);
        $own = $this->as($w, $both)->postJson("{$b}/fee-concessions", $this->targetedBody($w, '1.00'))->assertCreated()->json('data.id');
        $this->as($w, $both)->postJson("{$b}/fee-concessions/{$own}/approve")->assertForbidden()->assertJsonPath('error.code', 'FEE_CONCESSION_SELF_APPROVAL');

        $this->as($w, $w['checker'])->postJson("{$b}/fee-concessions/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($w, $w['checker'])->postJson("{$b}/fee-concessions/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'FEE_CONCESSION_ILLEGAL_TRANSITION');

        $detail = $this->as($w, $w['checker'])->getJson("{$b}/fee-concessions/{$id}")->assertOk();
        $detail->assertJsonPath('data.adjustments.0.amount', '150.00')->assertJsonPath('data.adjustments.0.debitLedgerAccountId', $w['expense']->id);

        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '850.00']], '850.00');
        $late = $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", $this->targetedBody($w, '10.00'))->json('data.id');
        $this->as($w, $w['checker'])->postJson("{$b}/fee-concessions/{$late}/approve")->assertStatus(409)->assertJsonPath('error.code', 'CHARGE_FULLY_PAID');
    }

    #[Test]
    public function validation_errors_are_422_on_the_field(): void
    {
        $w = $this->concessionWorld();
        $b = $this->base($w);

        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", [...$this->targetedBody($w), 'category' => 'sibling'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['category']]]);
        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", [...$this->targetedBody($w), 'fixed_amount' => '12.345'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['fixed_amount']]]);
        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", [...$this->targetedBody($w), 'idempotency_key' => 'nope'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['idempotency_key']]]);
        $this->as($w, $w['maker'])->postJson("{$b}/fee-concessions", [
            'idempotency_key' => (string) Str::uuid(), 'scope' => 'standing', 'category' => 'waiver', 'kind' => 'percentage', 'percentage' => '10',
            'student_id' => $w['student']->id, 'academic_year_id' => $w['year']->id, 'valid_from' => '2026-06-01', 'valid_to' => '2027-07-01',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['valid_to']]]);
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.manage']))
            ->putJson("{$b}/fee-settings/concession-account", ['concession_ledger_account_id' => $w['revenue']->id])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['concession_ledger_account_id']]]);
    }

    #[Test]
    public function another_schools_concession_or_adjustment_is_a_404(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $foreign = $this->requestTargeted($b, '10.00');
        $this->concessions()->approve($b['school'], $foreign->id, $b['checker']);
        $adjustment = $this->adjustmentsOf($b)->sole();
        $base = $this->base($a);

        $this->as($a, $a['checker'])->getJson("{$base}/fee-concessions/{$foreign->id}")->assertNotFound();
        $this->as($a, $a['checker'])->postJson("{$base}/fee-concessions/{$foreign->id}/reject")->assertNotFound();
        $this->as($a, $a['checker'])->postJson("{$base}/fee-adjustments/{$adjustment->id}/cancel")->assertNotFound();
        $this->as($a, $a['checker'])->getJson("{$base}/fee-adjustments?charge_id={$b['charge']->id}")->assertOk()->assertJsonCount(0, 'data');
    }
}
