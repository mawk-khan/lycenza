<?php

namespace Tests\Feature\Tenancy;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextPoisonedConnectionException;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Phase 1B.4A: deterministic reproduction and regression coverage for
 * the cross-cutting TenantContext defect flagged as a P1 in the Phase
 * 1B.4 report -- an already-aborted PostgreSQL transaction causing
 * TenantContext's cleanup RESET/set_config to itself fail with
 * SQLSTATE 25P02, potentially masking the real original exception.
 * See docs/architecture/TENANCY.md ("Root cause: aborted-transaction
 * cleanup") for the full explanation this test suite proves.
 *
 * Two deliberately distinct groups:
 *
 * - "Unwrapped" (Group 1): the failing write is NOT inside its own
 *   DB::transaction() -- this proves ONLY that the original exception
 *   survives and PHP-side context is restored; it CANNOT prove the
 *   database connection becomes reusable within the same test method,
 *   because nothing has rolled back the aborted transaction yet (that
 *   is the caller's responsibility, never TenantContext's -- see
 *   restoreAfterFailure()'s docblock). This is a real, if unusual,
 *   caller shape (a raw query outside any service).
 * - "Wrapped" (Group 2): the failing write IS inside its own
 *   DB::transaction(), exactly like every sanctioned Application
 *   service in this codebase (StudentEnrollmentService, GuardianService,
 *   ...) already does. Laravel's transaction wrapper performs the real
 *   ROLLBACK TO SAVEPOINT automatically, so this group proves FULL
 *   recovery: exception preserved, PHP context correct, database GUC
 *   correctly reverted, and a genuinely subsequent operation succeeds.
 */
class TenantContextAbortedTransactionTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function currentSchoolIdSetting(): ?string
    {
        $row = DB::connection('pgsql')->selectOne('select current_setting(?, true) as value', [TenantRls::SESSION_VAR]);

        return $row->value === '' ? null : $row->value;
    }

    /**
     * @return array{student: Student, section: Section}
     */
    private function buildContext(School $school): array
    {
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-'.random_int(1000, 9999)]);

        return compact('student', 'section');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enrollmentAttributes(School $school, Student $student, Section $section, array $overrides = []): array
    {
        return array_merge([
            'school_id' => $school->id, 'student_id' => $student->id,
            'academic_year_id' => $section->academic_year_id, 'campus_id' => $section->campus_id,
            'grade_level_id' => $section->grade_level_id, 'section_id' => $section->id,
            'roll_number' => '01', 'status' => 'active', 'starts_on' => '2026-06-01',
        ], $overrides);
    }

    // ==================================================================
    // Group 1: unwrapped raw failure -- exception preservation only
    // ==================================================================

    #[Test]
    public function unwrapped_an_unique_constraint_violation_preserves_the_original_exception_not_a_masking_reset_failure(): void
    {
        $school = $this->createSchool();
        ['student' => $studentA, 'section' => $section] = $this->buildContext($school);
        $studentB = $this->createStudent($school, ['student_number' => 'S-9002']);

        app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->create(
            $this->enrollmentAttributes($school, $studentA, $section),
        ));

        $thrown = null;
        try {
            // Deliberately NOT wrapped in its own DB::transaction() --
            // the exact shape that reproduces the defect.
            app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->create(
                $this->enrollmentAttributes($school, $studentB, $section),
            ));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            UniqueConstraintViolationException::class,
            $thrown,
            'The ORIGINAL UniqueConstraintViolationException must propagate -- it must never be replaced by a secondary '.
            "RESET-statement QueryException (SQLSTATE 25P02) from TenantContext's own cleanup.",
        );
        $this->assertSame('23505', $thrown->getCode());

        // PHP-side context must be restored even though the database
        // connection itself remains aborted until something (a real
        // DB::transaction() wrapper, or this test's own tearDown) rolls
        // it back -- proven separately in Group 2 and section "PHP
        // context restored independently of DB state" below.
        $this->assertNull(app(TenantContext::class)->school(), 'PHP-side TenantContext must be restored regardless of the still-aborted database connection.');
    }

    #[Test]
    public function unwrapped_a_check_constraint_violation_also_preserves_the_original_exception(): void
    {
        $school = $this->createSchool();
        ['student' => $student, 'section' => $section] = $this->buildContext($school);

        $thrown = null;
        try {
            app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->create(
                $this->enrollmentAttributes($school, $student, $section, ['starts_on' => '2027-01-01', 'ends_on' => '2026-01-01']),
            ));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(QueryException::class, $thrown);
        $this->assertNotSame('25P02', $thrown->getCode(), 'Must not be the secondary aborted-transaction RESET failure.');
        $this->assertNull(app(TenantContext::class)->school());
    }

    #[Test]
    public function unwrapped_php_side_context_is_restored_independently_of_the_still_aborted_database_connection(): void
    {
        $school = $this->createSchool();
        ['student' => $student, 'section' => $section] = $this->buildContext($school);

        try {
            app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->create(
                $this->enrollmentAttributes($school, $student, $section, ['starts_on' => '2027-01-01', 'ends_on' => '2026-01-01']),
            ));
        } catch (Throwable) {
            // expected
        }

        // The PHP-side fields are plain assignments, never dependent on
        // a database round trip succeeding -- restored unconditionally
        // by restoreAfterFailure() before it even attempts the DB-side
        // GUC restore.
        $this->assertNull(app(TenantContext::class)->school());
        $this->assertNull(app(TenantContext::class)->campus());
    }

    // ==================================================================
    // Group 2: wrapped in the sanctioned DB::transaction() pattern --
    // full recovery, matching every real Application service
    // ==================================================================

    #[Test]
    public function wrapped_the_original_exception_survives_and_the_connection_is_fully_usable_immediately_after(): void
    {
        $school = $this->createSchool();
        ['student' => $studentA, 'section' => $section] = $this->buildContext($school);
        $studentB = $this->createStudent($school, ['student_number' => 'S-9002']);

        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => StudentEnrollment::query()->create(
            $this->enrollmentAttributes($school, $studentA, $section),
        )));

        $thrown = null;
        try {
            app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => StudentEnrollment::query()->create(
                $this->enrollmentAttributes($school, $studentB, $section),
            )));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        $this->assertNull(app(TenantContext::class)->school());
        $this->assertNull($this->currentSchoolIdSetting(), 'The GUC must be fully reverted once DB::transaction() has rolled back.');

        // A genuinely subsequent operation, in a fresh withSchool()
        // call, on the SAME connection, succeeds immediately.
        $studentC = $this->createStudent($school, ['student_number' => 'S-9003']);
        $result = app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => StudentEnrollment::query()->create(
            $this->enrollmentAttributes($school, $studentC, $section, ['roll_number' => '02']),
        )));
        $this->assertNotNull($result->id);
    }

    #[Test]
    public function wrapped_the_database_guc_does_not_leak_school_a_and_school_b_sees_only_its_own_context(): void
    {
        $schoolA = $this->createSchool();
        ['student' => $studentA1, 'section' => $sectionA] = $this->buildContext($schoolA);
        $studentA2 = $this->createStudent($schoolA, ['student_number' => 'A-9002']);

        app(TenantContext::class)->withSchool($schoolA, fn () => DB::transaction(fn () => StudentEnrollment::query()->create(
            $this->enrollmentAttributes($schoolA, $studentA1, $sectionA),
        )));

        try {
            app(TenantContext::class)->withSchool($schoolA, fn () => DB::transaction(fn () => StudentEnrollment::query()->create(
                $this->enrollmentAttributes($schoolA, $studentA2, $sectionA),
            )));
        } catch (Throwable) {
            // expected
        }

        $this->assertNull(app(TenantContext::class)->school(), 'PHP-side TenantContext must not remain bound to School A after the failure.');
        $this->assertNull($this->currentSchoolIdSetting(), 'The PostgreSQL GUC must not remain bound to School A after the failure.');

        $schoolB = $this->createSchool();
        ['student' => $studentB, 'section' => $sectionB] = $this->buildContext($schoolB);

        app(TenantContext::class)->withSchool($schoolB, function () use ($schoolB, $sectionB, $studentB): void {
            $this->assertSame($schoolB->id, $this->currentSchoolIdSetting(), "School B's own context must be exactly School B, not leaked School A.");

            $visible = StudentEnrollment::query()->count();
            $this->assertSame(0, $visible, "School B must see zero rows -- School A's Enrollment (created above) must not be visible.");

            DB::transaction(fn () => StudentEnrollment::query()->create(
                $this->enrollmentAttributes($schoolB, $studentB, $sectionB),
            ));

            $this->assertSame(1, StudentEnrollment::query()->count(), 'School B must see exactly its own one row.');
        });
    }

    #[Test]
    public function wrapped_nested_with_school_restores_the_outer_school_after_an_inner_failure(): void
    {
        $outer = $this->createSchool();
        $inner = $this->createSchool();
        ['student' => $innerStudent, 'section' => $innerSection] = $this->buildContext($inner);

        app(TenantContext::class)->withSchool($outer, function () use ($outer, $inner, $innerSection, $innerStudent): void {
            $this->assertSame($outer->id, $this->currentSchoolIdSetting());

            try {
                app(TenantContext::class)->withSchool($inner, fn () => DB::transaction(fn () => StudentEnrollment::query()->create(
                    $this->enrollmentAttributes($inner, $innerStudent, $innerSection, ['starts_on' => '2027-01-01', 'ends_on' => '2026-01-01']),
                )));
                $this->fail('Expected a CHECK constraint violation.');
            } catch (QueryException) {
                // expected
            }

            // Back in the OUTER School's context -- not null, not the
            // inner School, and the DB GUC genuinely reverted (not just
            // the PHP-side field) because DB::transaction() rolled the
            // inner failure back before withSchool(inner, ...)'s own
            // restore ran.
            $this->assertNotNull(app(TenantContext::class)->school());
            $this->assertSame($outer->id, app(TenantContext::class)->school()->id);
            $this->assertSame($outer->id, $this->currentSchoolIdSetting());
        });
    }

    // ==================================================================
    // Callback catches its own DB exception (Case B) -- poisoned
    // connection is reported explicitly, never silently ignored
    // ==================================================================

    #[Test]
    public function a_callback_that_swallows_its_own_db_exception_and_leaves_the_connection_poisoned_raises_a_clear_exception(): void
    {
        $school = $this->createSchool();
        ['student' => $studentA, 'section' => $section] = $this->buildContext($school);
        $studentB = $this->createStudent($school, ['student_number' => 'S-9002']);

        app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->create(
            $this->enrollmentAttributes($school, $studentA, $section),
        ));

        $this->expectException(TenantContextPoisonedConnectionException::class);

        // The callback catches its OWN UniqueConstraintViolationException
        // and returns normally, WITHOUT rolling back or rethrowing --
        // exactly the "leaves the connection poisoned while reporting
        // success" scenario. TenantContext must never silently pretend
        // this succeeded.
        app(TenantContext::class)->withSchool($school, function () use ($school, $studentB, $section) {
            try {
                return StudentEnrollment::query()->create(
                    $this->enrollmentAttributes($school, $studentB, $section),
                );
            } catch (UniqueConstraintViolationException) {
                return null; // swallowed -- the bug this scenario represents
            }
        });
    }

    // ==================================================================
    // Successful path must remain completely unchanged
    // ==================================================================

    #[Test]
    public function the_successful_path_is_completely_unaffected(): void
    {
        $school = $this->createSchool();
        ['student' => $student, 'section' => $section] = $this->buildContext($school);

        $result = app(TenantContext::class)->withSchool($school, function () use ($school, $student, $section) {
            $this->assertSame($school->id, $this->currentSchoolIdSetting());

            return StudentEnrollment::query()->create(
                $this->enrollmentAttributes($school, $student, $section),
            );
        });

        $this->assertNotNull($result->id);
        $this->assertNull(app(TenantContext::class)->school());
        $this->assertNull($this->currentSchoolIdSetting());
    }
}
