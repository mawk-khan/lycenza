<?php

namespace Tests\Feature\Retention;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21-RH.6 (ADR 0066 §14): the final retention boundary, proven with the
 * real runtime and retention logins.
 * - No retention function is runtime-executable; the runtime role holds no
 *   DELETE that only retention used.
 * - Every delete the retention identity makes from PHP passes the
 *   database hold guard (School and platform), whatever PHP decided.
 * - The eligibility sources (employment and enrollment ends, erasure
 *   cases, lifecycle markers) cannot be forged by the runtime role, and
 *   the database floors count from when an end was RECORDED.
 * - User erasure (minimization) honours holds in the database.
 * - The verifier detects a regression of each.
 *
 * COMMITTED fixtures (the retention and maintenance connections are their
 * own sessions).
 */
class RetentionDeleteBoundaryTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    /** These proofs set the database recording themselves (backdateEndRecording() where a row is old). */
    protected bool $alignRetentionAnchors = false;

    private const RH6_FUNCTIONS = [
        'retention_expire_finance_unit', 'retention_expire_student_processing_authorizations',
        'retention_expire_student_consent_events', 'retention_expire_guardian_consent_events',
    ];

    /** $statement's refusal message on $connection in $school's context ('' when it succeeded). */
    private function refusal(string $connection, ?School $school, callable $statement): string
    {
        try {
            DB::usingConnection($connection, fn () => DB::transaction(function () use ($school, $statement): void {
                if ($school !== null) {
                    DB::select("SELECT set_config('app.current_school_id', ?, true)", [$school->id]);
                }
                if (DB::connection()->getName() === RetentionExpiry::PRIVILEGED_CONNECTION) {
                    // As a retention unit does (E21-RH.7): it deletes only rows recorded before its declared cutoff, now.
                    DB::select("SELECT set_config('app.retention_anchor_cutoff', (now() AT TIME ZONE 'UTC')::text, true)");
                }
                $statement();
            }));
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    #[Test]
    public function the_runtime_role_executes_no_retention_function_and_deletes_nothing_only_retention_deletes(): void
    {
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname LIKE 'retention\\_%' AND has_function_privilege('school_os_app', p.oid, 'EXECUTE')")->n, 'H1 closed');
        foreach (self::RH6_FUNCTIONS as $function) {
            $this->assertFalse((bool) DB::selectOne("SELECT has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS x FROM pg_proc p WHERE p.proname = ?", [$function])->x, $function);
            $this->assertTrue((bool) DB::selectOne("SELECT has_function_privilege('school_os_retention', p.oid, 'EXECUTE') AS x FROM pg_proc p WHERE p.proname = ?", [$function])->x, $function);
        }

        $school = $this->createSchool();
        foreach (['students', 'student_enrollments', 'guardians', 'employees', 'employment_records', 'documents', 'learning_content', 'assignments',
            'student_processing_authorizations', 'visitors', 'communication_messages', 'domain_event_outbox', 'email_events', 'webhook_deliveries'] as $table) {
            $this->assertStringContainsString('permission denied for table', $this->refusal('pgsql', $school, fn () => DB::table($table)->where('id', (string) Str::uuid7())->delete()), "{$table}: runtime DELETE revoked");
        }
        // The product keeps the deletes it needs (a profile entry, a Guardian unlink, the failed-job store).
        foreach (DatabaseRoleVerifier::RUNTIME_PRODUCT_DELETES as $table) {
            $this->assertTrue((bool) DB::selectOne("SELECT has_table_privilege('school_os_app', ?, 'DELETE') AS x", ['public.'.$table])->x, $table);
        }
        $this->assertStringContainsString('permission denied to set role', $this->refusal('pgsql', null, fn () => DB::statement('SET LOCAL ROLE school_os_retention')));
    }

    #[Test]
    public function every_retention_session_delete_is_held_back_by_an_active_hold_in_postgresql(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $visitorA = $this->createVisitor($a)->id;
        $visitorB = $this->createVisitor($b)->id;
        $this->backdateEndRecording(); // recorded long ago: only the holds decide here
        $exists = fn (School $s, string $id): bool => $this->inSchool($s, fn () => DB::table('visitors')->where('id', $id)->exists());
        $holds = app(RetentionHolds::class);
        $retention = RetentionExpiry::PRIVILEGED_CONNECTION;

        // A School hold that exists only in the database: the retention identity's direct DELETE is refused.
        $holds->place($a->id, 'litigation', 'RH6-HOLD-1');
        $this->assertStringContainsString('retention_hold', $this->refusal($retention, $a, fn () => DB::table('visitors')->where('id', $visitorA)->delete()));
        $this->assertTrue($exists($a, $visitorA));

        // The platform hold refuses every School and the School-less records too.
        $holds->place(null, 'regulatory_inquiry', 'RH6-HOLD-2');
        $this->assertStringContainsString('retention_hold', $this->refusal($retention, $b, fn () => DB::table('visitors')->where('id', $visitorB)->delete()));
        $this->assertStringContainsString('retention_hold', $this->refusal($retention, null, fn () => DB::table('email_events')->where('received_at', '<', '1990-01-01')->delete()));
        $holds->release(null, 'inquiry_closed', 'RH6-HOLD-2');

        // School B is independent; released, School A goes too.
        $this->assertSame('', $this->refusal($retention, $b, fn () => DB::table('visitors')->where('id', $visitorB)->delete()));
        $this->assertFalse($exists($b, $visitorB));
        $holds->release($a->id, 'matter_concluded', 'RH6-HOLD-1');
        $this->assertSame('', $this->refusal($retention, $a, fn () => DB::table('visitors')->where('id', $visitorA)->delete()));
        $this->assertFalse($exists($a, $visitorA));

        // An ordinary product delete by the runtime role is not retention: a hold does not freeze the product.
        $address = $this->createEmployeeAddress($this->createEmployee($a))->id;
        $holds->place($a->id, 'litigation', 'RH6-HOLD-3');
        $this->assertSame('', $this->refusal('pgsql', $a, fn () => DB::table('employee_addresses')->where('id', $address)->delete()));
    }

    #[Test]
    public function the_lock_and_probe_helpers_are_the_retention_identitys_and_closed(): void
    {
        $school = $this->createSchool();
        $studentModel = $this->createStudent($school);
        $student = $studentModel->id;
        $lock = fn (string $table) => DB::select('SELECT id FROM retention_lock_rows(?, ?::uuid[], false) AS id', [$table, '{'.$student.'}']);

        $this->assertStringContainsString('permission denied for function retention_lock_rows', $this->refusal('pgsql', $school, fn () => $lock('students')));
        $this->assertStringContainsString('retention_lock', $this->refusal(RetentionExpiry::PRIVILEGED_CONNECTION, $school, fn () => $lock('schools')), 'a closed table list');
        $this->assertSame('', $this->refusal(RetentionExpiry::PRIVILEGED_CONNECTION, $school, fn () => $this->assertCount(1, $lock('students'))));
        $this->assertStringContainsString('retention_privilege', $this->refusal(RetentionHolds::MAINTENANCE_CONNECTION, $school, fn () => $lock('students')));

        $probe = fn () => DB::selectOne("SELECT retention_first_reference('students', ?, ?::uuid[], '{}') AS t", [$school->id, '{'.$student.'}'])->t;
        $this->assertStringContainsString('permission denied for function retention_first_reference', $this->refusal('pgsql', $school, $probe));
        $this->createStudentEnrollment($studentModel, $this->createSection($this->createAcademicYear($school), $this->createCampus($school), $this->createGradeLevel($school)));
        $found = null;
        DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, function () use ($school, $probe, &$found): void {
            $found = $this->inSchool($school, $probe);
        });
        $this->assertSame('student_enrollments', $found, 'a bare table name, as ReferencingRows names it');
    }

    #[Test]
    public function the_runtime_role_cannot_forge_an_end_and_the_floors_count_from_when_it_was_recorded(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $record = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2005-01-01', 'ends_on' => null]);
        $runtime = fn (array $values) => $this->refusal('pgsql', $school, fn () => DB::table('employment_records')->where('id', $record->id)->update($values));

        // A legitimate end (any past date, once, terminal) is recorded NOW by the database.
        $this->assertStringContainsString('ends only with a terminal status', $runtime(['ends_on' => '2010-01-01']));
        $this->assertSame('', $runtime(['status' => 'separated', 'ends_on' => '2010-01-01', 'ended_recorded_at' => '2009-01-01 00:00:00']));
        $recorded = (string) $this->inSchool($school, fn () => DB::table('employment_records')->where('id', $record->id)->value('ended_recorded_at'));
        $this->assertStringStartsWith(now('UTC')->toDateString(), $recorded, 'the database clock, never the caller');
        // ... and never changes again: no re-dating, no other terminal status, no reopening, no new identity.
        $this->assertStringContainsString('keeps its end and status', $runtime(['ends_on' => '2005-06-01']));
        $this->assertStringContainsString('keeps its end and status', $runtime(['status' => 'retired']));
        $this->assertStringContainsString('keeps its end and status', $runtime(['status' => 'active', 'ends_on' => null]));
        $this->assertStringContainsString('identity and start', $runtime(['starts_on' => '2004-01-01']));
        $this->assertSame('', $runtime(['probation_ends_on' => '2005-03-01']), 'ordinary edits still work');

        // The D9 floor counts from the LATER of the end and its recording: recorded today, not separated long enough.
        $floor = fn () => $this->refusal(RetentionExpiry::PRIVILEGED_CONNECTION, $school, fn () => DB::select('SELECT retention_expire_payroll_employee_evidence(?, ?, ?, true)', [$school->id, $employee->id, '2015-01-01']));
        $this->assertStringContainsString('retention_payroll_employee', $floor());
        // Recorded back then (the schema owner, e.g. an operator repair or a migration backfill): eligible.
        $this->backdateEndRecording();
        $this->assertSame('', $floor());
    }

    #[Test]
    public function a_student_exit_cannot_be_forged_and_the_core_floor_counts_from_its_recording(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $section = $this->createSection($this->createAcademicYear($school, ['starts_on' => '1990-04-01', 'ends_on' => '1991-03-31', 'status' => 'closed']), $this->createCampus($school), $this->createGradeLevel($school));
        $enrollment = $this->createStudentEnrollment($student, $section, ['status' => 'active', 'starts_on' => '1990-06-01', 'ends_on' => null]);
        $runtime = fn (array $values) => $this->refusal('pgsql', $school, fn () => DB::table('student_enrollments')->where('id', $enrollment->id)->update($values));

        $this->assertStringContainsString('ends only with a terminal status', $runtime(['ends_on' => '1991-01-01']));
        $this->assertSame('', $runtime(['status' => 'withdrawn', 'ends_on' => '1991-01-01']));
        $this->assertStringContainsString('keeps its end and status', $runtime(['ends_on' => '1990-07-01']));
        $this->assertStringContainsString('keeps its end and status', $runtime(['status' => 'active', 'ends_on' => null]));

        $floor = fn () => $this->refusal(RetentionExpiry::PRIVILEGED_CONNECTION, $school, fn () => DB::select('SELECT retention_expire_student_consent_events(?, ?, ?, true)', [$school->id, $student->id, '1995-01-01']));
        $this->assertStringContainsString('retention_student_core', $floor(), 'withdrawn in 1991, but recorded today');
        $this->backdateEndRecording();
        $this->assertSame('', $floor());
    }

    #[Test]
    public function erasure_cases_and_lifecycle_markers_cannot_be_backdated_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $case = (string) Str::uuid7();
        $insert = fn (array $values) => $this->refusal('pgsql', null, fn () => DB::table('erasure_cases')->insert(array_merge([
            'id' => $case, 'scope' => 'school', 'school_id' => $school->id, 'subject_type' => 'student', 'subject_id' => (string) Str::uuid7(),
            'request_channel' => 'written', 'status' => 'requested', 'requested_at' => '2001-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ], $values)));
        $update = fn (array $values) => $this->refusal('pgsql', null, fn () => DB::table('erasure_cases')->where('id', $case)->update($values));

        $this->assertStringContainsString('opened as requested', $insert(['status' => 'denied', 'decided_at' => '2001-01-01 00:00:00', 'decision_reason' => 'not_applicable']));
        $this->assertSame('', $insert([]));
        $this->assertStringStartsWith(now('UTC')->toDateString(), (string) DB::table('erasure_cases')->where('id', $case)->value('requested_at'), 'requested_at is the database clock');
        // A decision is stamped by the database, whatever the caller sends, then fixed.
        $this->assertSame('', $update(['status' => 'denied', 'decided_at' => '2001-01-01 00:00:00', 'decision_reason' => 'not_applicable']));
        $this->assertStringStartsWith(now('UTC')->toDateString(), (string) DB::table('erasure_cases')->where('id', $case)->value('decided_at'));
        $this->assertStringContainsString('decision is fixed', $update(['decided_at' => '2001-01-01 00:00:00']));
        $this->assertStringContainsString('decision is fixed', $update(['status' => 'requested', 'decided_at' => null, 'decision_reason' => null]));
        $this->assertStringContainsString('not a case transition', $update(['status' => 'requested']));
        $this->assertStringContainsString('not a case transition', $update(['status' => 'completed', 'completed_at' => '2001-01-01 00:00:00']));

        // Lifecycle markers: the runtime role cannot age a Guardian or a legacy terminal application into eligibility.
        $guardian = $this->inSchool($school, fn () => Guardian::factory()->create(['school_id' => $school->id, 'no_relationship_since' => null]));
        $this->assertStringContainsString('maintained by the relationship trigger', $this->refusal('pgsql', $school, fn () => DB::table('guardians')->where('id', $guardian->id)->update(['no_relationship_since' => '2000-01-01 00:00:00'])));
    }

    #[Test]
    public function minimizing_a_user_honours_the_holds_of_every_school_it_belongs_to(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $minimize = fn () => $this->refusal('pgsql', null, fn () => DB::table('users')->where('id', $user->id)->update([
            'name' => 'Former user', 'email' => 'minimized-'.$user->id.'@users.invalid', 'email_verified_at' => null, 'remember_token' => null,
            'is_disabled' => true, 'disabled_at' => now(), 'minimized_at' => now(),
        ]));

        app(RetentionHolds::class)->place($school->id, 'litigation', 'RH6-USER');
        $this->assertStringContainsString('retention_hold', $minimize(), 'a hold of one of its Schools');
        app(RetentionHolds::class)->release($school->id, 'matter_concluded', 'RH6-USER');
        app(RetentionHolds::class)->place(null, 'regulatory_inquiry', 'RH6-USER-P');
        $this->assertStringContainsString('retention_hold', $minimize(), 'the platform hold');
        app(RetentionHolds::class)->release(null, 'inquiry_closed', 'RH6-USER-P');
        $this->assertSame('', $minimize());
    }

    #[Test]
    public function the_verifier_proves_the_final_boundary_and_detects_each_regression(): void
    {
        $checks = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        foreach (['retention_functions_narrow', 'privileged_retention_functions_closed', 'retention_read_helpers_closed', 'retention_deletes_guarded',
            'runtime_retention_deletes_revoked', 'retention_eligibility_guards', 'retention_role_writes_exact', 'retention_role_selects_exact',
            'retention_role_functions_exact', 'retention_holds_authoritative'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }

        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
        $regressions = [
            'retention_functions_narrow' => ['GRANT EXECUTE ON FUNCTION retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean) TO school_os_app', 'REVOKE EXECUTE ON FUNCTION retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean) FROM school_os_app'],
            'runtime_retention_deletes_revoked' => ['GRANT DELETE ON students TO school_os_app', 'REVOKE DELETE ON students FROM school_os_app'],
            'retention_deletes_guarded' => ['ALTER TABLE visitors DISABLE TRIGGER trg_retention_guard_visitors', 'ALTER TABLE visitors ENABLE TRIGGER trg_retention_guard_visitors'],
            'retention_eligibility_guards' => ['ALTER TABLE employment_records DISABLE TRIGGER trg_employment_records_eligibility_guard', 'ALTER TABLE employment_records ENABLE TRIGGER trg_employment_records_eligibility_guard'],
            'retention_role_writes_exact' => ['GRANT UPDATE ON students TO school_os_retention', 'REVOKE UPDATE ON students FROM school_os_retention'],
        ];
        foreach ($regressions as $code => [$break, $restore]) {
            $admin->statement($break);
            try {
                $this->assertSame(CheckResult::FAIL, $checks()[$code]->status, "{$code} detects: {$break}");
            } finally {
                $admin->statement($restore);
            }
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }
    }
}
