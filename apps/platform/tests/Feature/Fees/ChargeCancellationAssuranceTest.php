<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeCancellationConflictException;
use App\Domain\Fees\Application\Exceptions\ChargeCancellationReasonException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Identity\Application\AccountLinkService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantRls;
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
 * ORG.8G (ADR 0076 §65, owner decision OD-8.3): ending a Charge -- cancel,
 * fee-assessment void, late-fee void, a source's own void -- is a direct
 * action with assurance: a closed reason (the note for `other`), the actor's
 * fresh MFA proof, their CURRENT whole-School authority under the Charge lock,
 * and never a Guardian of the Charge's Student (any account link, active or
 * revoked, to a Guardian currently related to the Student: the ORG.8A-R1
 * rule). The database requires the reason and the actor, refuses a linked
 * Guardian and keeps both final. No approval workflow.
 */
class ChargeCancellationAssuranceTest extends TestCase
{
    use CreatesFeeConcessionFixtures, ProvidesSensitiveActionMfa;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $w['canceller'] = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.charges.manage']);

        return $w;
    }

    private function proof(User $user): FreshMfaProof
    {
        return new FreshMfaProof($user->id, CarbonImmutable::now());
    }

    private function cancel(array $w, ?User $actor = null, string $code = 'charged_in_error', ?string $note = null): mixed
    {
        $actor ??= $w['canceller'];

        return app(ChargeAdministrationService::class)->cancel($w['school'], $w['charge']->id, $actor, $code, $note, $this->proof($actor));
    }

    private function charge(array $w): Charge
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($w['charge']->id));
    }

    private function linkAccount(School $school, User $user, $guardian, User $by): void
    {
        $membership = $this->inSchool($school, fn () => SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->firstOrFail());
        $this->inSchool($school, fn () => app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $by));
    }

    #[Test]
    public function a_cancellation_names_a_listed_reason_and_records_why_and_who(): void
    {
        $w = $this->world();

        foreach (['', 'waived', 'assessment_error', 'source_voided'] as $bad) {
            $this->assertThrows(fn () => $this->cancel($w, code: $bad), ChargeCancellationReasonException::class);
        }
        $this->assertThrows(fn () => $this->cancel($w, code: 'other'), ChargeCancellationReasonException::class); // other needs its note
        $this->assertNull($this->charge($w)->cancelled_at);

        $this->cancel($w, code: 'other', note: 'billed for a trip that was called off');
        $charge = $this->charge($w);
        $this->assertNotNull($charge->cancelled_at);
        $this->assertSame(['other', $w['canceller']->id], [$charge->cancellation_reason_code, $charge->cancelled_by_user_id]);

        $audit = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'charge.cancelled')->sole());
        $this->assertSame([$w['student']->id, ChargeCancellationReason::CANCEL, 'other', 'live', 'cancelled', true],
            [$audit->metadata['studentId'], $audit->metadata['action'], $audit->metadata['reasonCode'], $audit->metadata['previousState'], $audit->metadata['newState'], $audit->metadata['freshMfa']]);
        $this->assertStringNotContainsString('trip', json_encode($audit->metadata), 'the note never enters audit metadata');

        $this->assertThrows(fn () => $this->cancel($w), ChargeAlreadyCancelledException::class);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('journal_entries')->where('reversal_of_journal_entry_id', $charge->journal_entry_id)->count()), 'one reversal, ever');
    }

    #[Test]
    public function the_proof_must_be_the_actors_and_authority_is_read_fresh_under_the_lock(): void
    {
        $w = $this->world();
        $this->assertThrows(fn () => app(ChargeService::class)->cancel($w['school'], $w['charge']->id, $w['canceller'], ChargeCancellationReason::CANCEL, 'charged_in_error', null, $this->proof($w['checker'])), LogicException::class);

        $this->assertTrue(Gate::forUser($w['canceller'])->allows('capability', ['finance.charges.manage', $w['school']]), 'the cache still says yes');
        $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $w['canceller']->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertThrows(fn () => app(ChargeService::class)->cancel($w['school'], $w['charge']->id, $w['canceller'], ChargeCancellationReason::CANCEL, 'charged_in_error', null, $this->proof($w['canceller'])), AuthorizationException::class);
        $this->assertNull($this->charge($w)->cancelled_at);
    }

    #[Test]
    public function a_linked_guardian_of_the_student_never_ends_the_charge_and_an_unrelated_actor_does(): void
    {
        $w = $this->world();
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $guardian);
        $this->linkAccount($w['school'], $w['canceller'], $guardian, $w['recorder']);

        $this->assertThrows(fn () => $this->cancel($w), ChargeCancellationConflictException::class);
        // A revoked link still counts.
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->unlinkGuardian($w['school'], $guardian, $w['recorder']));
        $this->assertThrows(fn () => $this->cancel($w), ChargeCancellationConflictException::class);
        $this->assertNull($this->charge($w)->cancelled_at);

        // Their link to ANOTHER Student's Guardian is irrelevant here; an unrelated colleague cancels.
        $other = $this->createUserWithCapabilities($w['school'], ['finance.charges.manage']);
        $this->cancel($w, $other);
        $this->assertSame($other->id, $this->charge($w)->cancelled_by_user_id);
    }

    #[Test]
    public function the_database_requires_the_reason_and_a_member_actor_refuses_a_guardian_and_keeps_both_final(): void
    {
        $w = $this->world();
        $guardianActor = $this->createUserWithCapabilities($w['school'], ['finance.charges.manage']);
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $guardian);
        $this->linkAccount($w['school'], $guardianActor, $guardian, $w['recorder']);
        $outsider = $this->createUser();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');

        $charge = $this->charge($w);
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
        // Each attempt carries the TRUE reversal (the existing pair/immutability rules), so only the ORG.8G rules decide.
        $attempt = function (array $columns) use ($db, $charge, $w): string {
            try {
                $db->transaction(function () use ($db, $charge, $columns, $w) {
                    $reversal = $this->inSchool($w['school'], fn () => app(LedgerService::class)->reverseById($w['school'], $charge->journal_entry_id))->journalEntryId;
                    $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
                    $db->table('charges')->where('id', $charge->id)->update(['cancelled_at' => now(), 'cancellation_journal_entry_id' => $reversal] + $columns);
                    throw new \RuntimeException('applied');
                });
            } catch (QueryException $e) {
                return $e->getMessage();
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }

            return '';
        };

        $this->assertStringContainsString('without its reason and actor', $attempt([]));
        $this->assertStringContainsString('without its reason and actor', $attempt(['cancelled_by_user_id' => $w['canceller']->id]));
        $this->assertStringContainsString('charges_cancellation_reason_code_check', $attempt(['cancellation_reason_code' => 'waived', 'cancelled_by_user_id' => $w['canceller']->id]));
        $this->assertStringContainsString('not an active member of its School', $attempt(['cancellation_reason_code' => 'charged_in_error', 'cancelled_by_user_id' => $outsider->id]));
        $this->assertStringContainsString('charges_cancellation_beneficiary_guardian_guard', $attempt(['cancellation_reason_code' => 'charged_in_error', 'cancelled_by_user_id' => $guardianActor->id]));
        $this->assertStringContainsString('charges_cancellation_shape_check', (function () use ($db, $charge) {
            try {
                $db->transaction(fn () => $db->table('charges')->where('id', $charge->id)->update(['cancellation_reason_code' => 'charged_in_error']));
            } catch (QueryException $e) {
                return $e->getMessage();
            }

            return '';
        })(), 'a live Charge carries no cancellation facts');

        $this->cancel($w);
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
        foreach ([['cancellation_reason_code' => 'duplicate_charge'], ['cancelled_by_user_id' => $w['checker']->id]] as $change) {
            try {
                $db->transaction(fn () => $db->table('charges')->where('id', $charge->id)->update($change));
                $this->fail('a recorded cancellation is final');
            } catch (QueryException $e) {
                $this->assertStringContainsString('charges_cancellation_evidence', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_web_and_api_need_a_listed_reason_and_a_fresh_code(): void
    {
        $w = $this->world();
        $this->actingAs($w['canceller'])->withHeader('X-School-Id', $w['school']->id);
        $url = "/app/finance/charges/{$w['charge']->id}/cancel";

        $this->get("/app/finance/charges/{$w['charge']->id}")->assertInertia(fn ($page) => $page
            ->where('cancelReasons', ChargeCancellationReason::CODES[ChargeCancellationReason::CANCEL]));
        $this->post($url, ['reason' => 'x'])->assertSessionHasErrors('reason_code');
        $this->post($url, ['reason_code' => 'waived', 'mfa_code' => $this->freshMfaCode($w['canceller'])])->assertSessionHasErrors('reason_code');
        $this->post($url, ['reason_code' => 'other', 'mfa_code' => $this->freshMfaCode($w['canceller'])])->assertSessionHasErrors('reason');
        $this->post($url, ['reason_code' => 'duplicate_charge'])->assertSessionHasErrors('mfa_code');
        $this->assertNull($this->charge($w)->cancelled_at);

        $this->post($url, ['reason_code' => 'duplicate_charge', 'mfa_code' => $this->freshMfaCode($w['canceller'])])->assertSessionHasNoErrors();
        $this->assertSame('duplicate_charge', $this->charge($w)->cancellation_reason_code);
    }
}
