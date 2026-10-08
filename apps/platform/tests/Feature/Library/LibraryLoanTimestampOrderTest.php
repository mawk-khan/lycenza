<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * A Loan's return never precedes its checkout -- the Library counterpart of
 * S3 (ADR 0068 §27.11; VisitorVisitTimestampOrderTest).
 *
 * The fixture defect: a call-site `checked_in_at => now()` is evaluated
 * BEFORE the factory's own `checked_out_at => now()`. Both are written at
 * whole-second precision, so the row broke
 * `library_loans_checkin_after_checkout_check` whenever a second boundary
 * fell between the two reads. Production check-in had the cousin: `now()`
 * against a stored checkout, refused if this node's clock reads earlier.
 *
 * The clock is driven per call (Carbon test-now sequences, reset after each
 * test by the framework); nothing sleeps or depends on real elapsed time.
 */
class LibraryLoanTimestampOrderTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** Each clock read returns the next instant (the last one repeats). */
    private function clockReads(string ...$instants): void
    {
        $read = 0;
        Carbon::setTestNow(function () use (&$read, $instants): Carbon {
            return Carbon::parse($instants[min($read++, count($instants) - 1)]);
        });
    }

    /** @return array{0: string, 1: string} the stored checkout / return, as PostgreSQL holds them */
    private function stored(LibraryLoan $loan): array
    {
        $row = app(TenantContext::class)->withSchool($loan->school, fn () => DB::table('library_loans')->where('id', $loan->id)->first(['checked_out_at', 'checked_in_at']));

        return [(string) $row->checked_out_at, (string) $row->checked_in_at];
    }

    #[Test]
    public function the_returned_fixture_is_ordered_whatever_the_clock_does(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);

        foreach ([
            'forward across a second boundary' => ['2026-10-08 10:00:00.999990', '2026-10-08 10:00:01.000010'],
            'backward step' => ['2026-10-08 10:00:05', '2026-10-08 10:00:00'],
            'frozen' => ['2026-10-08 10:00:00.5'],
        ] as $case => $reads) {
            $copy = $this->createLibraryCopy($this->createLibraryTitle($school));
            $this->clockReads(...$reads);
            $loan = $this->createReturnedLibraryLoan($copy, $student);
            Carbon::setTestNow();

            [$out, $in] = $this->stored($loan);
            $this->assertSame('returned', $loan->status, $case);
            $this->assertGreaterThan($out, $in, $case);
        }
    }

    #[Test]
    public function the_returned_fixture_derives_from_an_overridden_checkout(): void
    {
        $school = $this->createSchool();
        $loan = $this->createReturnedLibraryLoan($this->createLibraryCopy($this->createLibraryTitle($school)), $this->createStudent($school), [
            'checked_out_at' => '2026-07-01 09:00:00', 'due_at' => '2026-07-15 09:00:00',
        ]);

        $this->assertSame(['2026-07-01 09:00:00', '2026-07-08 09:00:00'], $this->stored($loan));
    }

    #[Test]
    public function a_return_on_a_clock_behind_the_checkout_is_recorded_at_the_checkout_and_is_not_overdue(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $student = $this->createStudent($school);
        $service = app(LibraryLoanService::class);
        $within = fn (callable $fn) => app(TenantContext::class)->withSchool($school, $fn);

        // A backward step (or a node whose clock is 5 s behind the one that checked out).
        $this->travelTo(Carbon::parse('2026-10-08 11:00:00'));
        $loan = $within(fn () => $service->checkout($this->createLibraryCopy($this->createLibraryTitle($school)), $student, now()->addDays(14), $admin));
        $this->travelTo(Carbon::parse('2026-10-08 10:59:55'));
        $returned = $within(fn () => $service->checkIn($loan, $admin));
        $this->assertSame(['returned', '2026-10-08 11:00:00', '2026-10-08 11:00:00'], [$returned->status, ...$this->stored($returned)]);
        $this->assertEquals($loan->checked_out_at, $returned->checked_out_at, 'the checkout is never rewritten');
        $audit = $within(fn () => SchoolAuditEvent::query()->where('event_type', 'library.loan.checked_in')->where('subject_id', $loan->id)->firstOrFail());
        $this->assertFalse($audit->metadata['wasOverdue']);
        $this->assertSame(0, $within(fn () => DB::table('library_fines')->where('library_loan_id', $loan->id)->count()));

        // An ordinary clock: the return is the current instant.
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00'));
        $loan = $within(fn () => $service->checkout($this->createLibraryCopy($this->createLibraryTitle($school)), $student, now()->addDays(14), $admin));
        $this->travelTo(Carbon::parse('2026-10-10 16:30:10'));
        $this->assertSame(['2026-10-08 12:00:00', '2026-10-10 16:30:10'], $this->stored($within(fn () => $service->checkIn($loan, $admin))));
    }

    #[Test]
    public function the_database_still_refuses_a_return_before_the_checkout(): void
    {
        $school = $this->createSchool();
        $copy = $this->createLibraryCopy($this->createLibraryTitle($school));
        $student = $this->createStudent($school);
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        $rejected = null;
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('library_loans')->insert([
                'id' => (string) new UuidV7, 'school_id' => $school->id, 'library_copy_id' => $copy->id, 'student_id' => $student->id,
                'status' => 'returned', 'checked_out_at' => '2026-10-08 10:00:01', 'due_at' => '2026-10-22 10:00:01',
                'checked_in_at' => '2026-10-08 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (QueryException $e) {
            $rejected = $e->getMessage();
        }

        $this->assertNotNull($rejected);
        $this->assertStringContainsString('library_loans_checkin_after_checkout_check', (string) $rejected);
    }
}
