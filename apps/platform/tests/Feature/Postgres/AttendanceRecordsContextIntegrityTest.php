<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2, mandatory per root CLAUDE.md rules 28 and 70. Every test
 * here writes RAW SQL, deliberately bypassing
 * AttendanceSubmissionService and all application validation, to prove
 * the DATABASE -- not the application -- rejects a wrong-context
 * attendance row.
 *
 * The design under test: `attendance_records` carries ONE physical copy
 * of (`academic_year_id`, `campus_id`, `grade_level_id`, `section_id`),
 * and BOTH composite foreign keys reference those same four columns --
 * one against `attendance_sessions`, one against `student_enrollments`.
 * A row therefore exists only if the Session's context and the
 * Enrollment's context are byte-identical. Each wrong-context case
 * below is asserted from BOTH directions (context copied from the
 * Session, and context copied from the Enrollment) because that is what
 * actually proves the pinning is two-sided rather than one FK doing all
 * the work.
 */
class AttendanceRecordsContextIntegrityTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * Inserts an AttendanceSession by raw SQL for a given context.
     *
     * @return string the new session id
     */
    private function rawSession(School $school, array $w, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('attendance_sessions')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'timetable_entry_id' => $w['entry']->id,
            'attendance_date' => self::MONDAY,
            'academic_year_id' => $w['entry']->academic_year_id,
            'campus_id' => $w['entry']->campus_id,
            'grade_level_id' => $w['entry']->grade_level_id,
            'section_id' => $w['entry']->section_id,
            'subject_offering_id' => $w['entry']->subject_offering_id,
            'teacher_id' => $w['entry']->teacher_id,
            'period_id' => $w['entry']->period_id,
            'period_start_time' => '09:00:00',
            'period_end_time' => '10:00:00',
            'submitted_by_user_id' => $w['actor']->id,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function insertRecord(School $school, string $sessionId, string $enrollmentId, array $context): void
    {
        DB::connection('pgsql')->table('attendance_records')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'attendance_session_id' => $sessionId,
            'student_enrollment_id' => $enrollmentId,
            'academic_year_id' => $context['academic_year_id'],
            'campus_id' => $context['campus_id'],
            'grade_level_id' => $context['grade_level_id'],
            'section_id' => $context['section_id'],
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: array<string,mixed>, 1: string, 2: StudentEnrollment, 3: StudentEnrollment}
     */
    private function scenario(callable $makeForeignEnrollment): array
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);

        $own = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $foreign = $makeForeignEnrollment($w);

        $this->setSchool($w['school']->id);
        $sessionId = $this->rawSession($w['school'], $w);

        return [$w, $sessionId, $own, $foreign];
    }

    private function sessionContext(array $w): array
    {
        return [
            'academic_year_id' => $w['entry']->academic_year_id,
            'campus_id' => $w['entry']->campus_id,
            'grade_level_id' => $w['entry']->grade_level_id,
            'section_id' => $w['entry']->section_id,
        ];
    }

    private function enrollmentContext(StudentEnrollment $enrollment): array
    {
        return [
            'academic_year_id' => $enrollment->academic_year_id,
            'campus_id' => $enrollment->campus_id,
            'grade_level_id' => $enrollment->grade_level_id,
            'section_id' => $enrollment->section_id,
        ];
    }

    /**
     * Runs $op and asserts PostgreSQL rejected it naming $constraint.
     *
     * Wrapped in a NESTED transaction so Laravel issues a SAVEPOINT:
     * a constraint violation aborts the current (sub)transaction, and
     * without a savepoint to roll back to, every later statement in
     * this test's enclosing DatabaseTransactions transaction would fail
     * with SQLSTATE 25P02 ("current transaction is aborted") instead of
     * its own real error. This is what lets one test assert several
     * independent rejections in sequence.
     */
    private function assertRejectedBy(string $constraint, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage(), $message);
        }
    }

    /**
     * @param  list<string>  $constraints
     */
    private function assertRejectedByAnyOf(array $constraints, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $matched = false;
            foreach ($constraints as $constraint) {
                if (str_contains($e->getMessage(), $constraint)) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, $message.' Reported: '.$e->getMessage());
        }
    }

    /**
     * Both directions: with the Session's own context the ENROLLMENT
     * FK must reject; with the foreign Enrollment's context the SESSION
     * FK must reject. Neither insert may be accepted.
     */
    private function assertBothDirectionsRejected(School $school, string $sessionId, array $w, StudentEnrollment $foreign, string $label): void
    {
        $this->assertRejectedBy(
            'attendance_records_enrollment_context_fk',
            fn () => $this->insertRecord($school, $sessionId, $foreign->id, $this->sessionContext($w)),
            "{$label}: a record using the SESSION's context must be rejected by the enrollment-context FK.",
        );

        $this->assertRejectedBy(
            'attendance_records_session_context_fk',
            fn () => $this->insertRecord($school, $sessionId, $foreign->id, $this->enrollmentContext($foreign)),
            "{$label}: a record using the ENROLLMENT's context must be rejected by the session-context FK.",
        );

        $this->assertSame(0, DB::connection('pgsql')->table('attendance_records')
            ->where('attendance_session_id', $sessionId)->count());
    }

    #[Test]
    public function both_tables_have_rls_enabled_and_forced(): void
    {
        foreach (['attendance_sessions', 'attendance_records'] as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} not found");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS ENABLED");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must have RLS FORCED");
        }
    }

    #[Test]
    public function a_record_referencing_an_enrollment_from_another_section_is_rejected(): void
    {
        [$w, $sessionId, , $foreign] = $this->scenario(function (array $w) {
            $other = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);

            return $this->enrollStudent($other, '9', '2026-06-01');
        });

        $this->assertNotSame($w['entry']->section_id, $foreign->section_id);
        $this->assertBothDirectionsRejected($w['school'], $sessionId, $w, $foreign, 'wrong Section');
    }

    #[Test]
    public function a_record_referencing_an_enrollment_from_another_academic_year_is_rejected(): void
    {
        [$w, $sessionId, , $foreign] = $this->scenario(function (array $w) {
            $otherYear = $this->createAcademicYear($w['school'], [
                'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31', 'code' => 'AY2',
            ]);
            $otherSection = $this->createSection($otherYear, $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'A']);

            return $this->enrollStudent($otherSection, '9', '2027-06-01');
        });

        $this->assertNotSame($w['entry']->academic_year_id, $foreign->academic_year_id);
        $this->assertBothDirectionsRejected($w['school'], $sessionId, $w, $foreign, 'wrong AcademicYear');
    }

    #[Test]
    public function a_record_referencing_an_enrollment_from_another_campus_is_rejected(): void
    {
        [$w, $sessionId, , $foreign] = $this->scenario(function (array $w) {
            $otherCampus = $this->createCampus($w['school'], ['code' => 'C2']);
            $otherSection = $this->createSection($w['year'], $otherCampus, $w['grade'], ['status' => 'active', 'code' => 'A']);

            return $this->enrollStudent($otherSection, '9', '2026-06-01');
        });

        $this->assertNotSame($w['entry']->campus_id, $foreign->campus_id);
        $this->assertBothDirectionsRejected($w['school'], $sessionId, $w, $foreign, 'wrong Campus');
    }

    #[Test]
    public function a_record_referencing_an_enrollment_from_another_grade_level_is_rejected(): void
    {
        [$w, $sessionId, , $foreign] = $this->scenario(function (array $w) {
            $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 99]);
            $otherSection = $this->createSection($w['year'], $w['campus'], $otherGrade, ['status' => 'active', 'code' => 'A']);

            return $this->enrollStudent($otherSection, '9', '2026-06-01');
        });

        $this->assertNotSame($w['entry']->grade_level_id, $foreign->grade_level_id);
        $this->assertBothDirectionsRejected($w['school'], $sessionId, $w, $foreign, 'wrong GradeLevel');
    }

    #[Test]
    public function a_correct_context_record_is_accepted(): void
    {
        // The negative tests above are only meaningful if the positive
        // case genuinely inserts.
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);
        $own = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->setSchool($w['school']->id);
        $sessionId = $this->rawSession($w['school'], $w);

        $this->insertRecord($w['school'], $sessionId, $own->id, $this->sessionContext($w));

        $this->assertSame(1, DB::connection('pgsql')->table('attendance_records')
            ->where('attendance_session_id', $sessionId)->count());
    }

    #[Test]
    public function a_session_whose_section_and_subject_offering_contexts_disagree_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);

        // A Section from a DIFFERENT GradeLevel than the Offering.
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 99]);
        $mismatched = $this->createSection($w['year'], $w['campus'], $otherGrade, ['status' => 'active', 'code' => 'X']);
        $this->setSchool($w['school']->id);

        // Using the Offering's context, the SECTION fk must reject.
        $this->assertRejectedBy(
            'attendance_sessions_section_fk',
            fn () => $this->rawSession($w['school'], $w, ['section_id' => $mismatched->id]),
            'A Session pairing a Section and a SubjectOffering from different contexts must be rejected.',
        );

        // Using the Section's context, the SUBJECT OFFERING fk must reject.
        $this->assertRejectedBy(
            'attendance_sessions_subject_offering_fk',
            fn () => $this->rawSession($w['school'], $w, [
                'section_id' => $mismatched->id,
                'grade_level_id' => $mismatched->grade_level_id,
            ]),
            'The subject-offering context FK must reject the mirrored attempt.',
        );
    }

    #[Test]
    public function a_duplicate_section_period_and_date_session_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);
        $this->rawSession($w['school'], $w);

        // A different TimetableEntry (so the entry/date key does not
        // fire) reusing the SAME Section + Period + date.
        $second = $this->createTimetableEntry($w['offering'], $w['section'], $w['teacher'], $w['period'], [
            'day_of_week' => 1, 'status' => 'inactive',
        ]);
        $this->setSchool($w['school']->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/attendance_sessions_section_slot_unique/');
        $this->rawSession($w['school'], $w, ['timetable_entry_id' => $second->id]);
    }

    #[Test]
    public function a_duplicate_timetable_entry_and_date_session_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);
        $this->rawSession($w['school'], $w);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/attendance_sessions_(entry_date|section_slot)_unique/');
        $this->rawSession($w['school'], $w);
    }

    #[Test]
    public function a_session_with_inverted_period_times_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/attendance_sessions_period_times_check/');
        $this->rawSession($w['school'], $w, ['period_start_time' => '11:00:00', 'period_end_time' => '10:00:00']);
    }

    #[Test]
    public function an_invalid_record_status_is_rejected(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);
        $own = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->setSchool($w['school']->id);
        $sessionId = $this->rawSession($w['school'], $w);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/attendance_records_status_check/');
        DB::connection('pgsql')->table('attendance_records')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $w['school']->id,
            'attendance_session_id' => $sessionId,
            'student_enrollment_id' => $own->id,
            'academic_year_id' => $w['entry']->academic_year_id,
            'campus_id' => $w['entry']->campus_id,
            'grade_level_id' => $w['entry']->grade_level_id,
            'section_id' => $w['entry']->section_id,
            'status' => 'sick',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function attendance_rows_are_tenant_isolated_at_the_raw_sql_layer(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);
        $own = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->setSchool($w['school']->id);
        $sessionId = $this->rawSession($w['school'], $w);
        $this->insertRecord($w['school'], $sessionId, $own->id, $this->sessionContext($w));

        $this->assertSame(1, DB::connection('pgsql')->table('attendance_sessions')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('attendance_records')->count());

        // Switch to a DIFFERENT School's context: School B must see none
        // of School A's rows, at the raw SQL layer, via RLS alone.
        $schoolB = $this->createSchool();
        $this->setSchool($schoolB->id);

        $this->assertSame(0, DB::connection('pgsql')->table('attendance_sessions')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('attendance_records')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('attendance_sessions')->where('id', $sessionId)->count());

        // Missing tenant context fails closed rather than exposing all.
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('attendance_sessions')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('attendance_records')->count());
    }

    #[Test]
    public function attendance_context_foreign_keys_exist_with_the_exact_designed_shape(): void
    {
        $rows = DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint '.
            'where conrelid = ?::regclass or conrelid = ?::regclass',
            ['attendance_records', 'attendance_sessions'],
        );
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->conname] = $row->def;
        }

        $expected = [
            'attendance_records_session_context_fk' => 'FOREIGN KEY (attendance_session_id, school_id, academic_year_id, campus_id, grade_level_id, section_id) REFERENCES attendance_sessions(id, school_id, academic_year_id, campus_id, grade_level_id, section_id) ON DELETE RESTRICT',
            'attendance_records_enrollment_context_fk' => 'FOREIGN KEY (student_enrollment_id, school_id, academic_year_id, campus_id, grade_level_id, section_id) REFERENCES student_enrollments(id, school_id, academic_year_id, campus_id, grade_level_id, section_id) ON DELETE RESTRICT',
            'attendance_sessions_section_fk' => 'FOREIGN KEY (section_id, school_id, academic_year_id, campus_id, grade_level_id) REFERENCES sections(id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT',
            'attendance_sessions_subject_offering_fk' => 'FOREIGN KEY (subject_offering_id, school_id, academic_year_id, campus_id, grade_level_id) REFERENCES subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT',
            'attendance_sessions_teacher_fk' => 'FOREIGN KEY (teacher_id, school_id) REFERENCES employees(id, school_id) ON DELETE RESTRICT',
            'attendance_sessions_period_fk' => 'FOREIGN KEY (period_id, school_id) REFERENCES timetable_periods(id, school_id) ON DELETE RESTRICT',
            'attendance_sessions_timetable_entry_fk' => 'FOREIGN KEY (timetable_entry_id, school_id) REFERENCES timetable_entries(id, school_id) ON DELETE RESTRICT',
            'attendance_sessions_submitted_by_fk' => 'FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
        ];

        foreach ($expected as $name => $definition) {
            $this->assertArrayHasKey($name, $byName, "Missing foreign key {$name}");
            $this->assertSame($definition, $byName[$name], "Unexpected definition for {$name}");
        }

        // Both record-level FKs must reference the SAME physical child
        // columns -- that identity is what makes context drift
        // unrepresentable rather than merely unlikely.
        $childColumns = fn (string $def) => substr($def, strpos($def, '(') + 1, strpos($def, ')') - strpos($def, '(') - 1);
        $this->assertSame(
            str_replace('attendance_session_id, ', '', $childColumns($byName['attendance_records_session_context_fk'])),
            str_replace('student_enrollment_id, ', '', $childColumns($byName['attendance_records_enrollment_context_fk'])),
            'Both record-level composite FKs must pin the same physical context columns.',
        );
    }

    #[Test]
    public function a_referenced_parent_cannot_be_hard_deleted(): void
    {
        $w = $this->attendanceWorld();
        $this->setSchool($w['school']->id);
        $own = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->setSchool($w['school']->id);
        $sessionId = $this->rawSession($w['school'], $w);
        $this->insertRecord($w['school'], $sessionId, $own->id, $this->sessionContext($w));

        // Attendance's OWN FK is the one that fires for a parent nothing
        // else references any more (the source TimetableEntry, the
        // referenced StudentEnrollment). For the parents Timetable/SIS
        // ALSO reference (Section, SubjectOffering, Employee,
        // TimetablePeriod), PostgreSQL reports whichever RESTRICT it
        // reaches first -- which is still a correct
        // history-preservation guarantee, just not attributable to
        // Attendance alone, so those accept either constraint. The
        // Attendance FKs' existence and exact shape are asserted
        // independently by `attendance_context_foreign_keys_exist()`.
        $cases = [
            ['timetable_entries', $w['entry']->id, ['attendance_sessions_timetable_entry_fk']],
            ['student_enrollments', $own->id, ['attendance_records_enrollment_context_fk']],
            ['sections', $w['section']->id, ['attendance_sessions_section_fk', 'student_enrollments_section_id_school_id_foreign', 'timetable_entries_section_fk']],
            ['subject_offerings', $w['offering']->id, ['attendance_sessions_subject_offering_fk', 'timetable_entries_subject_offering_fk']],
            ['employees', $w['teacher']->id, ['attendance_sessions_teacher_fk', 'timetable_entries_teacher_fk']],
            ['timetable_periods', $w['period']->id, ['attendance_sessions_period_fk', 'timetable_entries_period_fk']],
        ];

        foreach ($cases as [$table, $id, $constraints]) {
            $this->assertRejectedByAnyOf(
                $constraints,
                fn () => DB::connection('pgsql')->table($table)->where('id', $id)->delete(),
                "Deleting {$table} row {$id} must be rejected while attendance history references it.",
            );
        }

        // The Session itself is protected by its own records.
        $this->assertRejectedBy(
            'attendance_records_session_context_fk',
            fn () => DB::connection('pgsql')->table('attendance_sessions')->where('id', $sessionId)->delete(),
            'Deleting a Session that still has records must be rejected.',
        );

        // The submitting User is RESTRICT-protected too. `users` is not
        // tenant-scoped, so this one deletes through the admin role via
        // the same savepoint-protected helper connection.
        $this->assertRejectedBy(
            'attendance_sessions_submitted_by_fk',
            fn () => DB::connection('pgsql')->table('users')->where('id', $w['actor']->id)->delete(),
            'Deleting the submitting User must be rejected.',
        );
    }
}
