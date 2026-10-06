<?php

namespace Tests\Feature\Library;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Library\Application\LibraryFinePolicyService;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryFineVoid;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * OPF.4 (ADR 0067 §10, §30): a Library fine is assessed, and voided, exactly
 * once under real concurrency -- two genuinely separate OS processes against
 * real PostgreSQL, the holder's writes held uncommitted until the contender
 * is observed blocked on them (ForcesConcurrentOverlap). The loan row lock
 * (the check-in's conditional UPDATE; the void's FOR UPDATE) serializes both
 * races. Committed fixtures, so the retention identity's own session also
 * sees the fine: the Finance D8 unit refuses its charge, and the fine keeps
 * its loan.
 */
class LibraryFineConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesFeeAssessmentFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/library-fine-op.php', ...$args];
    }

    /** @return array<string, mixed> a published policy and an active loan that fell due 9 days 23 hours ago (10 started days) */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['fineHead'] = $this->makeFeeHead($w, ['code' => 'LIBFINE', 'name' => 'Library fines']);
        app(LibraryFinePolicyService::class)->publish($w['school'], ['status' => 'active', 'fee_head_id' => $w['fineHead']->id, 'daily_rate' => '5.00', 'grace_days' => 2, 'max_amount' => '50.00'], null);
        $w['student'] = $this->createStudent($w['school']);
        $copy = $this->createLibraryCopy($this->createLibraryTitle($w['school']));
        $w['loan'] = $this->createLibraryLoan($copy, $w['student'], ['status' => 'active', 'checked_out_at' => now()->subDays(24), 'due_at' => now()->subDays(10)->addHour(), 'checked_in_at' => null]);

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function rows(array $w, string $model, array $where = []): int
    {
        return $this->inSchool($w['school'], fn () => $model::query()->where($where)->count());
    }

    /** @param  array<string, mixed>  $w */
    private function returned(array $w): LibraryFine
    {
        $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkIn($w['loan']));

        return $this->inSchool($w['school'], fn () => LibraryFine::query()->where('library_loan_id', $w['loan']->id)->sole());
    }

    #[Test]
    public function two_concurrent_check_ins_return_once_and_assess_one_fine_and_one_charge(): void
    {
        $w = $this->world();
        $args = ['check-in', $w['school']->id, $w['loan']->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        $this->assertSame('returned:1', $holder);
        $this->assertSame('error:App\\Domain\\Library\\Application\\Exceptions\\LoanAlreadyReturnedException', $contender,
            'the contender waited on the loan row, then found it returned -- no second fine, no uniqueness error');
        $this->assertSame(1, $this->rows($w, LibraryLoan::class, ['id' => $w['loan']->id, 'status' => 'returned']));
        $this->assertSame(1, $this->rows($w, LibraryFine::class, ['library_loan_id' => $w['loan']->id]));
        $this->assertSame(1, $this->rows($w, Charge::class, ['student_id' => $w['student']->id]));
        $this->assertSame('40.00', $this->inSchool($w['school'], fn () => LibraryFine::query()->sole()->amount), '10 started days, 2 grace: 8 x 5.00');
    }

    #[Test]
    public function two_concurrent_voids_record_one_void_and_cancel_the_charge_once(): void
    {
        $w = $this->world();
        $fine = $this->returned($w);
        $args = ['void', $w['school']->id, $fine->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        $this->assertSame('voided', $holder);
        $this->assertSame('error:App\\Domain\\Library\\Application\\Exceptions\\LibraryFineAlreadyVoidedException', $contender,
            'the contender waited on the loan row, then found the committed void');
        $this->assertSame(1, $this->rows($w, LibraryFineVoid::class, ['library_fine_id' => $fine->id]));
        $this->assertNotNull($this->inSchool($w['school'], fn () => Charge::query()->findOrFail($fine->charge_id))->cancelled_at);
        $this->assertSame(1, (int) DB::connection('pgsql_admin')->table('journal_entries')->where('school_id', $w['school']->id)->whereNotNull('reversal_of_journal_entry_id')->count(), 'one reversal');
    }

    #[Test]
    public function the_finance_unit_refuses_a_fined_charge_and_the_fine_keeps_its_loan(): void
    {
        $w = $this->world();
        $fine = $this->returned($w);

        foreach (['pgsql', RetentionExpiry::PRIVILEGED_CONNECTION] as $connection) {
            DB::connection($connection)->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
        }
        $retention = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION);
        try {
            $retention->transaction(fn () => $retention->selectOne(
                'SELECT retention_expire_finance_unit(?, ?::uuid[], ?::uuid[], ?, ?) AS n',
                [$w['school']->id, '{'.$fine->charge_id.'}', '{}', (string) new UuidV7, 'true'],
            ));
            $this->fail('the Finance D8 unit must refuse a charge a Library fine references');
        } catch (QueryException $e) {
            $this->assertStringContainsString('retention_finance_dependency', $e->getMessage(), 'refused cleanly (dependency_blocked), never a foreign-key failure');
        }

        // The returned loan is kept by its fine: the Library loan purge sees it as dependency_blocked.
        $this->assertSame('library_fines', $this->inSchool($w['school'], fn () => app(ReferencingRows::class)->first('library_loans', $w['school']->id, [$w['loan']->id], [])));
        $this->assertSame('library_fines', $this->inSchool($w['school'], fn () => app(ReferencingRows::class)->first('students', $w['school']->id, [$w['student']->id], ['charges', 'library_loans'])));
    }
}
