<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A (mandatory per root CLAUDE.md rule 28).
 */
class LibraryLoansRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['library_loans', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school);
        $this->createLibraryLoan($copy, $student);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from library_loans')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_loan(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);
        $copyB = $this->createLibraryCopy($titleB);
        $studentB = $this->createStudent($schoolB);
        $loanB = $this->createLibraryLoan($copyB, $studentB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from library_loans where id = ?', [$loanB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_loan(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);
        $copyB = $this->createLibraryCopy($titleB);
        $studentB = $this->createStudent($schoolB);
        $loanB = $this->createLibraryLoan($copyB, $studentB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update library_loans set status = 'returned' where id = ?",
            [$loanB->id],
        );

        $this->assertSame(0, $updated);
    }

    /**
     * Checkpoint brief section 8's explicit example: `library_loan.
     * school_id == student.school_id` must be a database-proven fact,
     * not just an application assumption. Raw privileged
     * (`pgsql_admin`) INSERT, bypassing RLS entirely -- PostgreSQL's
     * own composite FK is what rejects this.
     */
    #[Test]
    public function a_loan_cannot_reference_a_student_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $titleA = $this->createLibraryTitle($schoolA);
        $copyA = $this->createLibraryCopy($titleA);

        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('library_loans')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'library_copy_id' => $copyA->id,
                'student_id' => $studentB->id,
                'status' => 'active',
                'checked_out_at' => now(),
                'due_at' => now()->addDays(14),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (student_id, school_id) -> students(id, school_id) must reject a cross-School Student reference at the database level.');
    }

    /**
     * The mirror case: a Copy from a different School than the Loan's
     * own school_id.
     */
    #[Test]
    public function a_loan_cannot_reference_a_copy_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA);

        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);
        $copyB = $this->createLibraryCopy($titleB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('library_loans')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'library_copy_id' => $copyB->id,
                'student_id' => $studentA->id,
                'status' => 'active',
                'checked_out_at' => now(),
                'due_at' => now()->addDays(14),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (library_copy_id, school_id) -> library_copies(id, school_id) must reject a cross-School Copy reference at the database level.');
    }

    /**
     * Raw-SQL proof that the partial unique index itself exists and is
     * enforced -- complements (does not replace)
     * tests/Feature/Library/LibraryLoanCheckoutConcurrencyTest.php's
     * real two-process proof of the same invariant under genuine
     * concurrency.
     */
    #[Test]
    public function the_database_rejects_a_second_active_loan_for_the_same_copy(): void
    {
        $school = $this->createSchool();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $studentOne = $this->createStudent($school);
        $studentTwo = $this->createStudent($school);
        $this->createLibraryLoan($copy, $studentOne);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('library_loans')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'library_copy_id' => $copy->id,
                'student_id' => $studentTwo->id,
                'status' => 'active',
                'checked_out_at' => now(),
                'due_at' => now()->addDays(14),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'library_loans_one_active_per_copy must reject a second simultaneous active loan for the same Copy.');
    }
}
