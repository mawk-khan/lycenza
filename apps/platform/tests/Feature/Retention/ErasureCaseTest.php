<?php

namespace Tests\Feature\Retention;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\Student;
use App\Models\ErasureCase;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Support\Retention\Erasure\ErasureCaseException;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2F (E21-D10, project-adopted, pending legal ratification): a reviewed
 * erasure case never shortens retention, never bypasses a hold, never
 * crosses Schools and never cascades. Execution removes only categories
 * whose adopted period has already passed and that nothing retained needs.
 */
class ErasureCaseTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'retention.student_operational_years' => 7,
            'retention.student_core_years' => 25,
            'retention.employee_ancillary_years' => 2,
            'retention.employee_evidence_years' => 8,
            'retention.authority_history_years' => 7,
            'retention.hold_school_ids' => [],
        ]);
    }

    private function cases(): ErasureCaseService
    {
        return app(ErasureCaseService::class);
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** @return array{0: School, 1: Student, 2: AcademicYear} */
    private function leaver(): array
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $section = $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school), ['code' => 'A']);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $section, ['status' => 'withdrawn', 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);
        $this->createStudentGuardianRelationship($student, $this->createGuardian($school));

        return [$school, $student, $year];
    }

    /** @return array<string, ErasureCategory> */
    private function outcomes(array $plan): array
    {
        return collect($plan)->keyBy(fn (ErasureCategory $c) => $c->category)->all();
    }

    private function approved(?School $school, string $type, string $id): ErasureCase
    {
        $case = $this->cases()->open($school, $type, $id, 'written');

        return $this->cases()->decide($case->id, 'approve', 'request_valid');
    }

    #[Test]
    public function an_unapproved_or_denied_case_cannot_execute(): void
    {
        [$school, $student] = $this->leaver();
        $case = $this->cases()->open($school, 'student', $student->id, 'email');

        foreach ([fn () => $this->cases()->execute($case->id, false), fn () => $this->cases()->execute($case->id, true)] as $attempt) {
            try {
                $attempt();
                $this->fail('A requested case must not execute.');
            } catch (ErasureCaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->cases()->decide($case->id, 'deny', 'identity_not_verified');
        $this->expectException(ErasureCaseException::class);
        $this->cases()->execute($case->id, false);
    }

    #[Test]
    public function approval_sets_a_thirty_day_target_and_never_shortens_retention(): void
    {
        $this->travelTo(Carbon::parse('2027-01-10 12:00:00', 'UTC'));
        [$school, $student] = $this->leaver();
        $case = $this->approved($school, 'student', $student->id);

        $this->assertSame('approved', $case->status);
        $this->assertSame('2027-02-09', $case->target_on->toDateString());

        $plan = $this->outcomes($this->cases()->execute($case->id, false));
        $this->assertSame(ErasureCategory::RETAINED_UNTIL, $plan['student_operational']->outcome);
        $this->assertSame('2033-10-01', $plan['student_operational']->notBefore);
        $this->assertSame('2051-10-01', $plan['student_core']->notBefore);
        $this->assertSame(ErasureCategory::RETAINED_UNTIL, $plan['student_identity_minimization']->outcome);
        $this->assertSame('2051-10-01', $plan['student_identity_minimization']->notBefore, 'identity goes with the core record (E21.2G)');
        $this->assertTrue($this->inSchool($school, fn () => DB::table('student_guardian_relationships')->where('student_id', $student->id)->exists()));
        $this->assertSame('completed', $case->fresh()->status);
    }

    #[Test]
    public function a_dry_run_changes_nothing_and_execution_removes_only_what_is_past_its_period(): void
    {
        [$school, $student] = $this->leaver();
        $this->travelTo(Carbon::parse('2034-01-01 12:00:00', 'UTC'));
        $case = $this->approved($school, 'student', $student->id);

        $plan = $this->outcomes($this->cases()->execute($case->id, true));
        $this->assertSame(ErasureCategory::ELIGIBLE, $plan['student_operational']->outcome);
        $this->assertSame(ErasureCategory::RETAINED_UNTIL, $plan['student_core']->outcome);
        $this->assertTrue($this->inSchool($school, fn () => DB::table('student_guardian_relationships')->where('student_id', $student->id)->exists()));
        $this->assertSame('approved', $case->fresh()->status, 'a dry run changes no state');

        $done = $this->outcomes($this->cases()->execute($case->id, false));
        $this->assertSame(ErasureCategory::COMPLETED, $done['student_operational']->outcome);
        $this->assertFalse($this->inSchool($school, fn () => DB::table('student_guardian_relationships')->where('student_id', $student->id)->exists()));
        $this->assertTrue($this->inSchool($school, fn () => DB::table('students')->where('id', $student->id)->exists()), 'the 25-year core record stays');

        // Rerun is safe.
        $this->outcomes($this->cases()->execute($case->id, false));
        $this->assertTrue($this->inSchool($school, fn () => DB::table('students')->where('id', $student->id)->exists()));

        // Once the core period has passed, the record goes too.
        $this->travelTo(Carbon::parse('2052-01-01 12:00:00', 'UTC'));
        $final = $this->outcomes($this->cases()->execute($case->id, false));
        $this->assertSame(ErasureCategory::COMPLETED, $final['student_record']->outcome);
        $this->assertFalse($this->inSchool($school, fn () => DB::table('students')->where('id', $student->id)->exists()));
    }

    #[Test]
    public function finance_and_a_legal_hold_keep_everything(): void
    {
        [$school, $student, $year] = $this->leaver();
        $this->assessCharge($school, $student, $year, $this->createLedgerAccount($school, ['type' => 'asset']), $this->createLedgerAccount($school, ['type' => 'income']), '100.00');
        $this->travelTo(Carbon::parse('2060-01-01 12:00:00', 'UTC'));
        $case = $this->approved($school, 'student', $student->id);

        $plan = $this->outcomes($this->cases()->execute($case->id, false));
        $this->assertSame(ErasureCategory::DEPENDENCY_BLOCKED, $plan['student_core']->outcome);
        $this->assertSame('charges', $plan['student_core']->reason);
        $this->assertTrue($this->inSchool($school, fn () => DB::table('students')->where('id', $student->id)->exists()));
        $this->assertSame(1, $this->inSchool($school, fn () => DB::table('charges')->where('student_id', $student->id)->count()));

        [$heldSchool, $heldStudent] = $this->leaver();
        config(['retention.hold_school_ids' => [$heldSchool->id]]);
        $held = $this->approved($heldSchool, 'student', $heldStudent->id);
        foreach ($this->cases()->execute($held->id, false) as $category) {
            $this->assertSame(ErasureCategory::LEGAL_HOLD, $category->outcome);
        }
        $this->assertTrue($this->inSchool($heldSchool, fn () => DB::table('student_guardian_relationships')->where('student_id', $heldStudent->id)->exists()));
    }

    #[Test]
    public function an_active_or_rehired_employee_is_kept_and_a_long_separated_one_is_removed(): void
    {
        $school = $this->createSchool();
        $active = $this->createEmployee($school);
        $this->createEmploymentRecord($active, ['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => null]);
        $rehired = $this->createEmployee($school);
        $this->createEmploymentRecord($rehired, ['status' => 'separated', 'starts_on' => '2015-01-01', 'ends_on' => '2016-01-31']);
        $this->createEmploymentRecord($rehired, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $gone = $this->createEmployee($school);
        $this->createEmploymentRecord($gone, ['status' => 'separated', 'starts_on' => '2015-01-01', 'ends_on' => '2016-01-31']);
        $this->createEmployeeAddress($gone);
        $this->travelTo(Carbon::parse('2030-01-01 12:00:00', 'UTC'));

        foreach ([$active, $rehired] as $employee) {
            $plan = $this->outcomes($this->cases()->execute($this->approved($school, 'employee', $employee->id)->id, false));
            $this->assertSame('subject_current', $plan['employee_evidence']->reason);
            $this->assertTrue($this->inSchool($school, fn () => DB::table('employees')->where('id', $employee->id)->exists()));
        }

        $this->cases()->execute($this->approved($school, 'employee', $gone->id)->id, false);
        $this->assertFalse($this->inSchool($school, fn () => DB::table('employees')->where('id', $gone->id)->exists()));
    }

    #[Test]
    public function a_case_never_crosses_schools_and_guardian_and_user_cases_execute_nothing(): void
    {
        [$schoolA] = $this->leaver();
        [$schoolB, $studentB] = $this->leaver();

        try {
            $this->cases()->open($schoolA, 'student', $studentB->id, 'written');
            $this->fail('School A cannot open a case for School B\'s Student.');
        } catch (ErasureCaseException) {
            $this->addToAssertionCount(1);
        }

        $guardianId = $this->inSchool($schoolB, fn () => DB::table('student_guardian_relationships')->where('student_id', $studentB->id)->value('guardian_id'));
        $guardianPlan = $this->outcomes($this->cases()->execute($this->approved($schoolB, 'guardian', $guardianId)->id, false));
        $this->assertSame(ErasureCategory::RETAINED_UNTIL, $guardianPlan['guardian_personal_data']->outcome);
        $this->assertSame('mechanism_pending', $guardianPlan['guardian_personal_data']->reason);
        $this->assertTrue($this->inSchool($schoolB, fn () => DB::table('guardians')->where('id', $guardianId)->exists()));

        $user = $this->createUser();
        $this->createMembership($user, $schoolA);
        $userPlan = $this->outcomes($this->cases()->execute($this->approved(null, 'user', $user->id)->id, false));
        $this->assertSame(ErasureCategory::DEPENDENCY_BLOCKED, $userPlan['user_identity']->outcome);
        $this->assertSame(ErasureCategory::OUTSIDE_SCOPE, $userPlan['school_records']->outcome);
        $this->assertTrue(DB::table('users')->where('id', $user->id)->exists(), 'a User is never hard-deleted by a case');
        $this->assertSame(1, DB::table('school_memberships')->where('user_id', $user->id)->count(), 'nothing is unlinked');

        foreach ([fn () => $this->cases()->open($schoolA, 'applicant', $studentB->id, 'written'), fn () => $this->cases()->open($schoolA, 'user', $user->id, 'written')] as $attempt) {
            try {
                $attempt();
                $this->fail('Unknown subject types and mis-scoped cases fail closed.');
            } catch (ErasureCaseException|\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_case_and_its_audit_hold_codes_only(): void
    {
        [$school, $student] = $this->leaver();
        $this->travelTo(Carbon::parse('2034-01-01 12:00:00', 'UTC'));
        $case = $this->approved($school, 'student', $student->id);
        $this->cases()->execute($case->id, false);

        $row = (array) DB::table('erasure_cases')->where('id', $case->id)->first();
        $this->assertEqualsCanonicalizing(['id', 'scope', 'school_id', 'subject_type', 'subject_id', 'request_channel', 'status', 'requested_at', 'decided_at',
            'decision_reason', 'target_on', 'execution_started_at', 'completed_at', 'outcome', 'created_at', 'updated_at'], array_keys($row));
        foreach (json_decode((string) $row['outcome'], true) as $category) {
            $this->assertEqualsCanonicalizing(['category', 'outcome', 'reason', 'not_before'], array_keys($category));
        }

        $metadata = PlatformAuditEvent::query()->where('subject_id', $case->id)->pluck('metadata')->all();
        $this->assertCount(3, $metadata);
        $this->assertStringNotContainsString($student->first_name, json_encode($metadata));
        $this->assertStringNotContainsString($student->student_number, json_encode($metadata));
    }

    #[Test]
    public function the_operator_commands_run_the_lifecycle(): void
    {
        [$school, $student] = $this->leaver();
        $this->travelTo(Carbon::parse('2034-01-01 12:00:00', 'UTC'));

        $this->artisan('platform:erasure-case-open', ['--subject-type' => 'student', '--subject' => $student->id, '--school' => $school->id, '--channel' => 'written'])
            ->expectsOutputToContain('status: requested')->assertSuccessful();
        $case = ErasureCase::query()->where('subject_id', $student->id)->firstOrFail();

        $this->artisan('platform:erasure-case-execute', ['case' => $case->id, '--force' => true])->expectsOutputToContain('Only an approved case')->assertFailed();
        $this->artisan('platform:erasure-case-decide', ['case' => $case->id, '--decision' => 'approve', '--reason' => 'request_valid', '--force' => true])
            ->expectsOutputToContain('approved; target 2034-01-31')->assertSuccessful();
        $this->artisan('platform:erasure-case-execute', ['case' => $case->id, '--dry-run' => true])->expectsOutputToContain('Plan (nothing changed)')->assertSuccessful();
        $this->artisan('platform:erasure-case-execute', ['case' => $case->id, '--force' => true])->expectsOutputToContain('Executed; outcome')->assertSuccessful();
        $this->artisan('platform:erasure-case-status')->expectsOutputToContain('completed 1')->assertSuccessful();
    }
}
