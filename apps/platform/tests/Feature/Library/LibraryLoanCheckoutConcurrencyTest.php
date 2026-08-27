<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (checkpoint brief section 11/23):
 * two GENUINELY separate OS processes -- not two sequential calls in
 * one PHP process -- both attempt to check out the SAME Library Copy
 * to two DIFFERENT Students at the same time, against real PostgreSQL.
 * Mirrors AcademicYearActivationConcurrencyTest's exact pattern.
 *
 * Unlike AcademicYear's activation race (two DIFFERENT draft rows
 * competing for one "active" slot -- no shared row for PostgreSQL to
 * serialize on its own, so the database's partial unique index is the
 * ONLY thing that can catch the race), LibraryLoanService::checkout()
 * first takes `lockForUpdate()` on the SAME Copy row both processes
 * target -- PostgreSQL itself serializes the two transactions on that
 * row lock before either reaches the INSERT. Observed and asserted
 * here, not assumed: the losing process's own post-lock
 * "does this Copy already have an active Loan" check catches the
 * conflict and throws the ordinary CopyNotAvailableException -- it
 * never actually reaches `library_loans_one_active_per_copy`'s raw
 * constraint in this exact race shape. That index still exists and is
 * proven directly by
 * tests/Feature/Postgres/LibraryLoansRlsIsolationTest::the_database_rejects_a_second_active_loan_for_the_same_copy
 * (a single-process, DB-authoritative-bypass-of-the-lock proof) --
 * this test's job is to prove the END STATE under real concurrency
 * (exactly one active Loan survives), not to force a specific
 * exception class through a specific internal code path.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's uncommitted rows.
 */
class LibraryLoanCheckoutConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades library_titles/copies/loans/students
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_checking_out_the_same_copy_leave_exactly_one_active_loan(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $title = $context->withSchool($this->school, fn () => LibraryTitle::factory()->for($this->school, 'school')->create());
        $copy = $context->withSchool($this->school, fn () => LibraryCopy::factory()->create([
            'school_id' => $this->school->id,
            'library_title_id' => $title->id,
        ]));
        $studentA = $context->withSchool($this->school, fn () => Student::factory()->for($this->school, 'school')->create(['status' => 'active']));
        $studentB = $context->withSchool($this->school, fn () => Student::factory()->for($this->school, 'school')->create(['status' => 'active']));

        $script = __DIR__.'/../../Support/checkout-library-copy.php';
        $processA = new Process(['php', $script, $this->school->id, $copy->id, $studentA->id]);
        $processB = new Process(['php', $script, $this->school->id, $copy->id, $studentB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $checkedOutCount = count(array_filter($outputs, fn ($o) => $o === 'checked_out'));

        $this->assertSame(1, $checkedOutCount, 'Exactly one of the two concurrent checkouts must succeed.');
        $rejectedOutputs = array_filter($outputs, fn ($o) => $o !== 'checked_out');
        $this->assertCount(1, $rejectedOutputs);
        // See class docblock: the loser is caught by its own post-lock
        // availability check, not the raw unique-constraint path.
        $this->assertStringContainsString('CopyNotAvailableException', reset($rejectedOutputs));

        $activeLoans = $context->withSchool(
            $this->school,
            fn () => LibraryLoan::query()->where('library_copy_id', $copy->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeLoans, 'The database must contain exactly one active Loan for this Copy.');
    }
}
