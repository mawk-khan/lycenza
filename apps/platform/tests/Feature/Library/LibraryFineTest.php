<?php

namespace Tests\Feature\Library;

use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\Exceptions\ChargeIsSourceChargeException;
use App\Domain\Fees\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Library\Application\Exceptions\InvalidLibraryFinePolicyException;
use App\Domain\Library\Application\Exceptions\LibraryFineAlreadyVoidedException;
use App\Domain\Library\Application\Exceptions\LibraryFineNotVoidableException;
use App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException;
use App\Domain\Library\Application\LibraryFinePolicyService;
use App\Domain\Library\Application\LibraryFineService;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Domain\Library\Infrastructure\LibraryFineVoid;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * OPF.4 (ADR 0067 §17, §30, D3, D4, D6): an overdue Library loan is fined
 * once, at check-in, under the School's governing immutable policy version,
 * as one EVENT charge through FEE's trusted seam; waivers are FEE
 * concessions; an erroneous unpaid fine is voided at its source (never a
 * refund). World: the concession world (active 2026-27, receivable/revenue
 * accounts, maker/checker, payment recorder) plus a LIBFINE fee head, a
 * Library circulation operator, a Library fines officer and a Finance
 * charges officer.
 */
class LibraryFineTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $w['fineHead'] = $this->makeFeeHead($w, ['code' => 'LIBFINE', 'name' => 'Library fines']);
        $w['librarian'] = $this->createUserWithCapabilities($w['school'], ['library.circulation.view', 'library.circulation.manage', 'library.catalogue.view']);
        $w['finesOfficer'] = $this->createUserWithCapabilities($w['school'], ['library.fines.view', 'library.fines.manage', 'library.fines.void']);
        $w['financeOfficer'] = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.charges.manage', 'finance.fee_structures.view']);
        $w['title'] = $this->createLibraryTitle($w['school']);

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function publish(array $w, string $rate = '5.00', int $grace = 2, ?string $cap = '50.00', ?string $headId = null): LibraryFinePolicy
    {
        return app(LibraryFinePolicyService::class)->publish($w['school'], [
            'status' => 'active', 'fee_head_id' => $headId ?? $w['fineHead']->id, 'daily_rate' => $rate, 'grace_days' => $grace, 'max_amount' => $cap,
        ], $w['finesOfficer']);
    }

    /** A loan checked out on 2026-07-01 10:00 UTC, due at $due. @param  array<string, mixed>  $w */
    private function loan(array $w, string $due = '2026-07-08 10:00:00'): LibraryLoan
    {
        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
        $copy = $this->createLibraryCopy($w['title']);

        return $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkout($copy, $w['student'], Carbon::parse($due, 'UTC'), $w['librarian']));
    }

    /** @param  array<string, mixed>  $w */
    private function returnAt(array $w, LibraryLoan $loan, string $at): LibraryLoan
    {
        $this->travelTo(Carbon::parse($at, 'UTC'));

        return $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkIn($loan, $w['librarian']));
    }

    /** @param  array<string, mixed>  $w */
    private function fines(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => LibraryFine::query()->orderBy('created_at')->orderBy('id')->get());
    }

    /** Charges other than the concession world's own fixture charge. @param  array<string, mixed>  $w */
    private function fineCharges(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->whereKeyNot($w['charge']->id)->get());
    }

    /** @param  array<string, mixed>  $w */
    private function audits(array $w, string $type): Collection
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->get());
    }

    private function policyModel(string $rate, int $grace, ?string $cap): LibraryFinePolicy
    {
        return new LibraryFinePolicy(['status' => 'active', 'daily_rate' => $rate, 'grace_days' => $grace, 'max_amount' => $cap]);
    }

    #[Test]
    public function the_formula_counts_started_days_after_due_minus_grace_capped(): void
    {
        $due = Carbon::parse('2026-07-08 10:00:00', 'UTC');
        $at = fn (string $s) => Carbon::parse($s, 'UTC');
        $calc = fn (string $returned, string $rate = '5.00', int $grace = 2, ?string $cap = '50.00') => LibraryFineService::calculate($due, $at($returned), $this->policyModel($rate, $grace, $cap));

        $this->assertSame(['overdue_days' => 0, 'chargeable_days' => 0, 'amount' => '0.00'], $calc('2026-07-08 10:00:00'), 'returned exactly at due: not overdue');
        $this->assertSame(['overdue_days' => 0, 'chargeable_days' => 0, 'amount' => '0.00'], $calc('2026-07-07 09:00:00'), 'early');
        $this->assertSame(['overdue_days' => 1, 'chargeable_days' => 0, 'amount' => '0.00'], $calc('2026-07-08 10:00:01'), 'one second late starts day 1, inside grace');
        $this->assertSame(['overdue_days' => 2, 'chargeable_days' => 0, 'amount' => '0.00'], $calc('2026-07-10 10:00:00'), 'exactly 48 h: two days, the last grace day');
        $this->assertSame(['overdue_days' => 3, 'chargeable_days' => 1, 'amount' => '5.00'], $calc('2026-07-10 10:00:01'), 'first chargeable day');
        $this->assertSame(['overdue_days' => 7, 'chargeable_days' => 5, 'amount' => '25.00'], $calc('2026-07-15 09:00:00'), 'several days');
        $this->assertSame(['overdue_days' => 12, 'chargeable_days' => 10, 'amount' => '50.00'], $calc('2026-07-20 10:00:00'), 'exactly the cap');
        $this->assertSame(['overdue_days' => 13, 'chargeable_days' => 11, 'amount' => '50.00'], $calc('2026-07-21 10:00:00'), 'capped');
        $this->assertSame(['overdue_days' => 13, 'chargeable_days' => 13, 'amount' => '32.50'], $calc('2026-07-21 10:00:00', '2.50', 0, null), 'no grace, no cap, exact decimals');
    }

    #[Test]
    public function policy_versions_are_immutable_numbered_and_validated(): void
    {
        $w = $this->world();
        $v1 = $this->publish($w);
        $v2 = $this->publish($w, '3.00', 0, null);
        $off = app(LibraryFinePolicyService::class)->publish($w['school'], ['status' => 'disabled'], $w['finesOfficer']);

        $this->assertSame([1, 2, 3], [$v1->version, $v2->version, $off->version]);
        $this->assertSame($off->id, app(LibraryFinePolicyService::class)->current($w['school'])->id);
        $this->assertNull($off->fee_head_id);
        $this->assertSame(3, $this->audits($w, 'library.fine_policy.published')->count());
        $this->assertStringNotContainsString('late_fee', implode(',', DB::getSchemaBuilder()->getColumnListing('library_fine_policies')));

        // Insert-only: a version a fine may have used can never be rewritten or removed.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('library_fine_policies')->where('id', $v1->id)->update(['daily_rate' => '9.00']))), QueryException::class, 'permission denied');
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('library_fine_policies')->where('id', $v1->id)->delete())), QueryException::class, 'permission denied');

        // Only an active fee head of this School; positive rate; sane grace and cap.
        $foreign = $this->makeFeeHead(['school' => $other = $this->createSchool(), 'actor' => $this->createUserWithCapabilities($other, self::FEE_SETUP_CAPABILITIES),
            'receivable' => $this->createLedgerAccount($other, ['code' => 'AR-X', 'type' => 'asset']), 'revenue' => $this->createLedgerAccount($other, ['code' => 'INC-X', 'type' => 'income'])]);
        foreach ([['headId' => $foreign->id], ['rate' => '0'], ['rate' => '1.234'], ['grace' => -1], ['cap' => '0.00']] as $bad) {
            $this->assertThrows(fn () => $this->publish($w, $bad['rate'] ?? '5.00', $bad['grace'] ?? 2, array_key_exists('cap', $bad) ? $bad['cap'] : '50.00', $bad['headId'] ?? null), InvalidLibraryFinePolicyException::class);
        }
        app(FeeHeadService::class)->deactivate($w['school'], $w['fineHead']->id, $w['actor']);
        $this->assertThrows(fn () => $this->publish($w), InvalidLibraryFinePolicyException::class);
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('library_fine_policies')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'version' => 99, 'status' => 'active', 'fee_head_id' => $foreign->id,
            'daily_rate' => '1.00', 'grace_days' => 0, 'currency' => 'INR', 'created_at' => now(),
        ]))), QueryException::class, 'library_fine_policies_fee_head_fk');
    }

    #[Test]
    public function an_on_time_return_has_no_fine_and_an_overdue_return_one_event_charge(): void
    {
        $w = $this->world();
        $policy = $this->publish($w);

        $onTime = $this->returnAt($w, $this->loan($w), '2026-07-08 10:00:00');
        $this->assertSame('returned', $onTime->status);
        $this->assertCount(0, $this->fines($w));
        $this->assertCount(0, $this->fineCharges($w));
        $this->assertCount(0, $this->audits($w, 'library.fine.not_applicable'), 'an on-time return says nothing');

        $loan = $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00'); // 7 days overdue, 2 grace: 5 x 5.00
        $this->assertSame('returned', $loan->status);
        $fine = $this->fines($w)->sole();
        $charge = $this->fineCharges($w)->sole();
        $this->assertSame([$loan->id, $w['student']->id, 'overdue', $policy->id, $w['fineHead']->id, $w['year']->id, 7, 5, '25.00', $charge->id],
            [$fine->library_loan_id, $fine->student_id, $fine->kind, $fine->library_fine_policy_id, $fine->fee_head_id, $fine->academic_year_id, $fine->overdue_days, $fine->chargeable_days, $fine->amount, $fine->charge_id]);
        $this->assertSame([$w['student']->id, $w['year']->id, '25.00', 'INR', $w['receivable']->id, $w['revenue']->id, null],
            [$charge->student_id, $charge->academic_year_id, $charge->amount, $charge->currency, $charge->receivable_ledger_account_id, $charge->revenue_ledger_account_id, $charge->cancelled_at],
            'a Student charge on the fee head\'s accounts, through FEE');

        $source = $this->audits($w, 'charge.source_assessed')->sole();
        $this->assertSame(['library', $fine->id, $charge->id], [$source->metadata['sourceKind'], $source->metadata['sourceId'], $source->metadata['chargeId']], 'FEE audits the source');
        $assessed = $this->audits($w, 'library.fine.assessed')->sole();
        $this->assertSame([$fine->id, $charge->id, 1, $w['librarian']->id], [$assessed->metadata['libraryFineId'], $assessed->metadata['chargeId'], $assessed->metadata['policyVersion'], $assessed->actor_user_id]);

        // Replays are safe: the fine path returns the existing fine; a second check-in is refused; one charge.
        $this->assertSame($fine->id, $this->inSchool($w['school'], fn () => DB::transaction(fn () => app(LibraryFineService::class)->assessOnCheckIn($loan->refresh(), $w['librarian'])))->id);
        $this->assertThrows(fn () => $this->returnAt($w, $loan, '2026-07-16 09:00:00'), LoanAlreadyReturnedException::class);
        $this->assertCount(1, $this->fines($w));
        $this->assertCount(1, $this->fineCharges($w));

        // The copy circulates again.
        $copy = $this->inSchool($w['school'], fn () => $loan->refresh()->copy);
        $this->assertSame('active', $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkout($copy, $w['student'], Carbon::parse('2026-08-01 10:00:00', 'UTC'), $w['librarian']))->status);
    }

    #[Test]
    public function not_applicable_overdue_returns_are_audited_and_never_fail_the_return(): void
    {
        $w = $this->world();

        $cases = [];
        $cases['no_policy'] = $this->returnAt($w, $this->loan($w), '2026-07-20 10:00:00');
        $this->publish($w);
        $cases['within_grace'] = $this->returnAt($w, $this->loan($w), '2026-07-09 10:00:00');
        app(LibraryFinePolicyService::class)->publish($w['school'], ['status' => 'disabled'], $w['finesOfficer']);
        $disabled = $this->returnAt($w, $this->loan($w), '2026-07-20 10:00:00');
        $this->publish($w);
        app(FeeHeadService::class)->deactivate($w['school'], $w['fineHead']->id, $w['actor']);
        $cases['fee_head_unavailable'] = $this->returnAt($w, $this->loan($w), '2026-07-20 10:00:00');
        app(FeeHeadService::class)->reactivate($w['school'], $w['fineHead']->id, $w['actor']);
        $this->inSchool($w['school'], fn () => DB::table('academic_years')->where('id', $w['year']->id)->update(['status' => 'closed']));
        $cases['no_active_academic_year'] = $this->returnAt($w, $this->loan($w), '2026-07-20 10:00:00');

        foreach ([...$cases, 'disabled' => $disabled] as $loan) {
            $this->assertSame('returned', $loan->status);
        }
        $this->assertCount(0, $this->fines($w));
        $this->assertCount(0, $this->fineCharges($w));
        $expected = [$cases['no_policy']->id => 'no_policy', $cases['within_grace']->id => 'within_grace', $disabled->id => 'no_policy',
            $cases['fee_head_unavailable']->id => 'fee_head_unavailable', $cases['no_active_academic_year']->id => 'no_active_academic_year'];
        $actual = $this->audits($w, 'library.fine.not_applicable')->mapWithKeys(fn ($a) => [$a->metadata['libraryLoanId'] => $a->metadata['reason']])->all();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function a_new_policy_version_never_rewrites_an_assessed_fine(): void
    {
        $w = $this->world();
        $v1 = $this->publish($w);
        $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00');
        $v2 = $this->publish($w, '1.00', 0, null);
        $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00');

        [$first, $second] = $this->fines($w)->all();
        $this->assertSame([$v1->id, '25.00'], [$first->library_fine_policy_id, $first->amount], 'still v1\'s 5 x 5.00');
        $this->assertSame([$v2->id, '7.00'], [$second->library_fine_policy_id, $second->amount], 'v2: 7 x 1.00, no grace');
        $this->assertSame('5.00', $this->inSchool($w['school'], fn () => LibraryFinePolicy::query()->findOrFail($v1->id))->daily_rate);
    }

    #[Test]
    public function a_waiver_is_a_fee_concession_under_maker_checker_and_blocks_the_void(): void
    {
        $w = $this->world();
        $this->publish($w);
        $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00');
        $charge = $this->fineCharges($w)->sole();
        $fine = $this->fines($w)->sole();

        // The requester can never approve their own waiver, even holding both capabilities.
        $both = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.request', 'finance.fee_concessions.approve']);
        $waiver = $this->requestTargeted($w, '25.00', 'waiver', $charge, $both);
        $this->assertThrows(fn () => $this->concessions()->approve($w['school'], $waiver->id, $both), SelfApprovalNotAllowedException::class);
        $this->assertCount(0, $this->adjustmentsOf($w, $charge->id), 'nothing changes until a different checker approves');
        $this->concessions()->approve($w['school'], $waiver->id, $w['checker']);
        $this->assertSame('25.00', $this->adjustmentsOf($w, $charge->id)->sole()->amount);
        $this->assertSame('25.00', $this->inSchool($w['school'], fn () => $charge->refresh()->amount), 'the charge itself is never rewritten');

        // A waived fine is not erroneous-and-unpaid: the void path refuses and records nothing.
        $this->assertThrows(fn () => app(LibraryFineService::class)->void($w['school'], $fine->id, 'mistake', $w['finesOfficer']), LibraryFineNotVoidableException::class);
        $this->assertSame(0, $this->inSchool($w['school'], fn () => LibraryFineVoid::query()->count()));
    }

    #[Test]
    public function an_unpaid_fine_is_voided_at_its_source_and_a_paid_one_is_refused_without_refund(): void
    {
        $w = $this->world();
        $this->publish($w);
        $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00');
        $this->returnAt($w, $this->loan($w), '2026-07-16 09:00:00');
        [$unpaid, $paid] = $this->fines($w)->all();

        // Finance's generic cancel cannot cancel a fine's charge: only the source void can.
        $this->assertThrows(fn () => app(ChargeAdministrationService::class)->cancel($w['school'], $unpaid->charge_id, $w['financeOfficer']), ChargeIsSourceChargeException::class);

        app(LibraryFineService::class)->void($w['school'], $unpaid->id, 'Returned through the drop box before due', $w['finesOfficer']);
        $this->assertNotNull($this->inSchool($w['school'], fn () => Charge::query()->findOrFail($unpaid->charge_id))->cancelled_at);
        $void = $this->inSchool($w['school'], fn () => LibraryFineVoid::query()->where('library_fine_id', $unpaid->id)->sole());
        $this->assertSame([$w['finesOfficer']->id, 'Returned through the drop box before due'], [$void->voided_by_user_id, $void->reason]);
        $this->assertNotNull($this->inSchool($w['school'], fn () => LibraryFine::query()->find($unpaid->id)), 'the fine evidence is kept');
        $this->assertSame(1, $this->audits($w, 'library.fine.voided')->count());
        $this->assertSame(1, $this->audits($w, 'charge.source_cancelled')->count());
        $this->assertThrows(fn () => app(LibraryFineService::class)->void($w['school'], $unpaid->id, 'again', $w['finesOfficer']), LibraryFineAlreadyVoidedException::class);

        // A paid fine is refused; nothing is recorded, cancelled or refunded.
        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$this->inSchool($w['school'], fn () => Charge::query()->findOrFail($paid->charge_id)), '30.00']], '30.00');
        $this->assertThrows(fn () => app(LibraryFineService::class)->void($w['school'], $paid->id, 'mistake', $w['finesOfficer']), LibraryFineNotVoidableException::class);
        $this->assertNull($this->inSchool($w['school'], fn () => Charge::query()->findOrFail($paid->charge_id))->cancelled_at);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => LibraryFineVoid::query()->count()));
        $this->assertSame(0, (int) DB::connection('pgsql_admin')->table('payments')->where('school_id', $w['school']->id)->where('amount', '<', 0)->count(), 'no refund');
    }

    #[Test]
    public function the_database_derives_checks_and_freezes_fine_evidence(): void
    {
        $w = $this->world();
        $this->publish($w);
        $loan = $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00');
        $fine = $this->fines($w)->sole();
        $row = fn (array $over) => array_merge($this->inSchool($w['school'], fn () => (array) DB::table('library_fines')->where('id', $fine->id)->first()), ['id' => (string) Str::uuid7()], $over);
        $insert = fn (array $over) => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('library_fines')->insert(collect($row($over))->except('retention_recorded_at')->all())));

        $this->assertThrows(fn () => $insert([]), QueryException::class, 'library_fines_one_per_loan_kind');
        $other = $this->returnAt($w, $this->loan($w), '2026-07-15 09:00:00');
        $otherCharge = $this->fines($w)->firstWhere('library_loan_id', $other->id)->charge_id;
        $this->assertThrows(fn () => $insert(['library_loan_id' => $other->id, 'amount' => '99.00', 'charge_id' => $otherCharge]), QueryException::class, "not the policy version's amount");
        $this->assertThrows(fn () => $insert(['library_loan_id' => $other->id, 'due_at' => '2026-07-01 10:00:00']), QueryException::class, 'does not match its returned loan');
        $this->assertThrows(fn () => $insert(['amount' => '0.00']), QueryException::class, "not the policy version's amount");

        // Insert-only: neither the fine nor its void can be rewritten or removed by the runtime role.
        foreach (['update' => fn () => DB::table('library_fines')->where('id', $fine->id)->update(['amount' => '1.00']), 'delete' => fn () => DB::table('library_fines')->where('id', $fine->id)->delete()] as $op) {
            $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction($op)), QueryException::class, 'permission denied');
        }

        // A void without cancelling its charge cannot commit.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(function () use ($w, $fine): void {
            DB::table('library_fine_voids')->insert(['id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'library_fine_id' => $fine->id, 'reason' => 'x', 'created_at' => now()]);
            DB::statement('SET CONSTRAINTS library_fine_voids_require_cancelled_charge IMMEDIATE');
        })), QueryException::class, 'was not cancelled in the same transaction');
        $this->assertSame('returned', $loan->status);
    }

    #[Test]
    public function library_staff_run_fines_without_finance_powers_and_finance_without_library_powers(): void
    {
        $w = $this->world();
        $base = "/api/v1/schools/{$w['school']->id}";
        $as = fn (User $u) => $this->actingAs($u)->withHeader('X-School-Id', $w['school']->id);
        $policy = ['status' => 'active', 'fee_head_id' => $w['fineHead']->id, 'daily_rate' => '5.00', 'grace_days' => 2, 'max_amount' => '50.00'];

        // Publishing and reading the policy: library.fines.* only.
        foreach ([$w['librarian'], $w['financeOfficer']] as $denied) {
            $as($denied)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/library-fine-policy/versions", $policy)->assertForbidden();
            $as($denied)->getJson("{$base}/library-fine-policy")->assertForbidden();
        }
        $as($w['finesOfficer'])->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/library-fine-policy/versions", $policy)
            ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.dailyRate', '5.00');
        $as($w['finesOfficer'])->getJson("{$base}/library-fine-policy/versions")->assertOk()->assertJsonCount(1, 'data');

        // An ordinary check-in (circulation) assesses the fine; reading and voiding it is library.fines.*.
        $loan = $this->loan($w);
        $this->travelTo(Carbon::parse('2026-07-15 09:00:00', 'UTC'));
        $as($w['librarian'])->postJson("{$base}/library-loans/{$loan->id}/check-in")->assertOk();
        $fine = $this->fines($w)->sole();
        $as($w['librarian'])->getJson("{$base}/library-loans/{$loan->id}/fine")->assertForbidden();
        $as($w['finesOfficer'])->getJson("{$base}/library-loans/{$loan->id}/fine")->assertOk()->assertJsonPath('data.amount', '25.00')->assertJsonPath('data.status', 'assessed');
        foreach ([$w['librarian'], $w['financeOfficer']] as $denied) {
            $as($denied)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/library-fines/{$fine->id}/void", ['reason' => 'x'])->assertForbidden();
        }

        // Library staff get no Finance power: no generic charge cancellation, no assessment run, no concession approval.
        $this->assertThrows(fn () => app(ChargeAdministrationService::class)->cancel($w['school'], $fine->charge_id, $w['finesOfficer']), AuthorizationException::class);
        $this->assertThrows(fn () => $this->runs()->create($w['school'], (string) Str::uuid7(), 'T1', $w['finesOfficer']), AuthorizationException::class);
        $this->assertThrows(fn () => $this->concessions()->approve($w['school'], $this->requestTargeted($w, '5.00', 'waiver', $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($fine->charge_id)))->id, $w['finesOfficer']), AuthorizationException::class);

        // A merely pending waiver changes nothing yet, so the fines officer may still void the erroneous fine.
        $as($w['finesOfficer'])->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/library-fines/{$fine->id}/void", ['reason' => 'Returned before due'])
            ->assertOk()->assertJsonPath('data.status', 'voided');

        // Another School reads nothing.
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)->getJson("/api/v1/schools/{$other->id}/library-loans/{$loan->id}/fine")->assertNotFound();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)->getJson("/api/v1/schools/{$other->id}/library-fine-policy")->assertOk()->assertJsonPath('data', null);
        $this->assertSame(0, $this->inSchool($other, fn () => LibraryFine::query()->count()), 'RLS');
    }
}
