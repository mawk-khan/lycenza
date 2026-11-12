<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C29 (C14-N5, C29-A/B), as the real runtime role, with positive controls: raw SQL cannot make a credential
 * verified or rejected without a recorded decision on its current revision, cannot move a revision or keep a
 * status across an edit, cannot record a decision on another revision, Employee or School, and cannot change or
 * delete a decision. Each refusal is matched on the words of the guard that owns that property, so an unrelated
 * constraint failing does not count as proof.
 */
class CredentialVerificationRawRoleTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> committed, so each statement is judged by the database alone */
    protected $connectionsToTransact = [];

    private School $school;

    private School $other;

    private User $hr;

    private Employee $employee;

    private string $qualificationId;

    private string $certificationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->other = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school);
        $this->qualificationId = app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'bachelors', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr)->id;
        $this->certificationId = app(EmployeeCertificationService::class)->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board'], $this->hr)->id;
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id, $this->other->id]);
        parent::tearDown();
    }

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function refused(string $needle, callable $statement): void
    {
        try {
            $this->in($this->school, $statement);
            $this->fail("not refused: {$needle}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertDecision(string $kind, int $revision, array $overrides = []): void
    {
        $row = array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $this->employee->id, 'credential_kind' => $kind,
            'qualification_id' => $kind === 'qualification' ? $this->qualificationId : null,
            'certification_id' => $kind === 'certification' ? $this->certificationId : null,
            'revision' => $revision, 'decision' => 'verified', 'decided_by_user_id' => $this->hr->id, 'decided_at' => now(),
        ], $overrides);
        DB::table('employee_credential_verifications')->insert($row);
    }

    /** @return array{0: string, 1: int, 2: ?int} */
    private function state(string $table, string $id): array
    {
        $row = DB::connection('pgsql_admin')->table($table)->where('id', $id)->first();

        return [$row->verification_status, (int) $row->revision, $row->verification_revision === null ? null : (int) $row->verification_revision];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function credentials(): array
    {
        return ['qualification' => ['qualification', 'employee_qualifications'], 'certification' => ['certification', 'employee_certifications']];
    }

    #[DataProvider('credentials')]
    #[Test]
    public function a_raw_decision_on_the_current_revision_is_the_only_way_to_verify(string $kind, string $table): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $id = $kind === 'qualification' ? $this->qualificationId : $this->certificationId;

        // The projection alone, with or without naming the revision, is refused: no decision says so.
        $this->refused('only the latest recorded decision on this revision', fn () => DB::statement("UPDATE {$table} SET verification_status = 'verified', verified_at = now() WHERE id = ?", [$id]));
        $this->refused('only the latest recorded decision on this revision', fn () => DB::statement("UPDATE {$table} SET verification_status = 'verified', verification_revision = 1, verified_at = now() WHERE id = ?", [$id]));
        // A revision is the database's.
        $this->refused('is the database', fn () => DB::statement("UPDATE {$table} SET revision = 7 WHERE id = ?", [$id]));
        // A decision on a revision the row does not have.
        $this->refused('a decision binds the current revision', fn () => $this->insertDecision($kind, 2));
        // Born decided.
        $this->refused('is recorded unverified at revision 1', fn () => DB::statement(
            $kind === 'qualification'
                ? "INSERT INTO employee_qualifications (id, school_id, employee_id, qualification_type, qualification_name, institution, verification_status, verified_at, created_at, updated_at) VALUES (?, ?, ?, 'bachelors', 'Forged', 'X', 'verified', now(), now(), now())"
                : "INSERT INTO employee_certifications (id, school_id, employee_id, name, issuer, verification_status, verified_at, created_at, updated_at) VALUES (?, ?, ?, 'Forged', 'X', 'verified', now(), now(), now())",
            [(string) Str::uuid7(), $this->school->id, $this->employee->id],
        ));
        $this->assertSame(['unverified', 1, null], $this->state($table, $id));

        // Positive control: the decision itself, raw, is accepted and sets the projection from its own values.
        $this->in($this->school, fn () => $this->insertDecision($kind, 1));
        $this->assertSame(['verified', 1, 1], $this->state($table, $id));

        // An edit that keeps the status is refused; an edit that resets it moves the revision.
        $column = $kind === 'qualification' ? 'qualification_name' : 'name';
        $this->refused('resets its verification', fn () => DB::statement("UPDATE {$table} SET {$column} = 'Swapped' WHERE id = ?", [$id]));
        $this->in($this->school, fn () => DB::statement("UPDATE {$table} SET {$column} = 'Swapped', verification_status = 'unverified', verified_at = NULL, verification_revision = NULL WHERE id = ?", [$id]));
        $this->assertSame(['unverified', 2, null], $this->state($table, $id));

        // The decision on revision 1 cannot be replayed onto revision 2 by hand.
        $this->refused('only the latest recorded decision on this revision', fn () => DB::statement("UPDATE {$table} SET verification_status = 'verified', verification_revision = 2, verified_at = now() WHERE id = ?", [$id]));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('employee_credential_verifications')->where("{$kind}_id", $id)->count());
    }

    #[Test]
    public function a_later_decision_cannot_be_overridden_by_restoring_an_earlier_one(): void
    {
        $this->in($this->school, fn () => $this->insertDecision('qualification', 1));
        $this->in($this->school, fn () => $this->insertDecision('qualification', 1, ['decision' => 'rejected']));
        $this->assertSame(['rejected', 1, 1], $this->state('employee_qualifications', $this->qualificationId));

        $this->refused('only the latest recorded decision on this revision', fn () => DB::statement(
            "UPDATE employee_qualifications SET verification_status = 'verified', verified_at = (SELECT (decided_at AT TIME ZONE 'UTC')::timestamp(0) FROM employee_credential_verifications WHERE qualification_id = ? AND decision = 'verified') WHERE id = ?",
            [$this->qualificationId, $this->qualificationId],
        ));
        $this->refused('this revision already carries this decision', fn () => $this->insertDecision('qualification', 1, ['decision' => 'rejected']));
    }

    /** Review MEDIUM-1: a supplied order was trusted as "latest" and could pin a credential, or collide with others. */
    #[Test]
    public function a_writer_cannot_choose_the_order_of_a_decision(): void
    {
        $this->in($this->school, fn () => DB::statement(
            "INSERT INTO employee_credential_verifications (id, school_id, employee_id, credential_kind, qualification_id, revision, decision, decided_by_user_id, decided_at, sequence)
             OVERRIDING SYSTEM VALUE VALUES (?, ?, ?, 'qualification', ?, 1, 'verified', ?, now(), 9223372036854775807)",
            [(string) Str::uuid7(), $this->school->id, $this->employee->id, $this->qualificationId, $this->hr->id],
        ));
        $sequence = (int) DB::connection('pgsql_admin')->table('employee_credential_verifications')->where('qualification_id', $this->qualificationId)->value('sequence');
        $this->assertLessThan(9223372036854775807, $sequence, 'the database assigned the order');

        // The credential is not pinned: an edit, then a decision on the new revision, both go through.
        app(EmployeeQualificationService::class)->update($this->employee, $this->in($this->school, fn () => EmployeeQualification::query()->findOrFail($this->qualificationId)), ['qualification_name' => 'MEd'], $this->hr);
        $this->in($this->school, fn () => $this->insertDecision('qualification', 2, ['decision' => 'rejected']));
        $this->assertSame(['rejected', 2, 2], $this->state('employee_qualifications', $this->qualificationId));
    }

    /** Review LOW-1: the projection is applied at the end of the statement, so the guard also reads the latest decision. */
    #[Test]
    public function one_statement_cannot_record_the_same_decision_twice(): void
    {
        $row = fn () => [(string) Str::uuid7(), $this->school->id, $this->employee->id, $this->certificationId, $this->hr->id];
        $this->refused('this revision already carries this decision', fn () => DB::statement(
            "INSERT INTO employee_credential_verifications (id, school_id, employee_id, credential_kind, certification_id, revision, decision, decided_by_user_id, decided_at)
             VALUES (?, ?, ?, 'certification', ?, 1, 'verified', ?, now()), (?, ?, ?, 'certification', ?, 1, 'verified', ?, now())",
            [...$row(), ...$row()],
        ));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('employee_credential_verifications')->where('certification_id', $this->certificationId)->count());
    }

    /** Review LOW-2: only an edit resets a decided revision, so the projection always states the latest decision on it. */
    #[Test]
    public function a_decided_revision_is_not_reset_without_an_edit(): void
    {
        $this->in($this->school, fn () => $this->insertDecision('qualification', 1));

        $this->refused('keeps its decision until an edit', fn () => DB::statement(
            "UPDATE employee_qualifications SET verification_status = 'unverified', verified_at = NULL, verification_revision = NULL WHERE id = ?", [$this->qualificationId],
        ));
        // Through the service, re-saving the same values is no edit and decides nothing.
        $service = app(EmployeeQualificationService::class);
        $model = $this->in($this->school, fn () => EmployeeQualification::query()->findOrFail($this->qualificationId));
        $this->assertSame('verified', $service->update($this->employee, $model, ['qualification_name' => 'BEd'], $this->hr)->verification_status);
        $this->assertSame(['verified', 1, 1], $this->state('employee_qualifications', $this->qualificationId));
    }

    #[Test]
    public function a_decision_is_never_changed_or_deleted_at_runtime(): void
    {
        $this->in($this->school, fn () => $this->insertDecision('certification', 1));

        $this->refused('permission denied', fn () => DB::statement("UPDATE employee_credential_verifications SET decision = 'rejected' WHERE certification_id = ?", [$this->certificationId]));
        $this->refused('permission denied', fn () => DB::statement('UPDATE employee_credential_verifications SET revision = 2 WHERE certification_id = ?', [$this->certificationId]));
        $this->refused('permission denied', fn () => DB::statement('DELETE FROM employee_credential_verifications WHERE certification_id = ?', [$this->certificationId]));
        $this->refused('permission denied', fn () => DB::statement('TRUNCATE employee_credential_verifications'));
        // Not even the owner rewrites one.
        try {
            DB::connection('pgsql_admin')->statement("UPDATE employee_credential_verifications SET decision = 'rejected' WHERE certification_id = ?", [$this->certificationId]);
            $this->fail('the owner rewrote a decision');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only history (hr_history_row_immutable)', $e->getMessage());
        }
        $this->assertSame(['verified', 1, 1], $this->state('employee_certifications', $this->certificationId));
    }

    #[Test]
    public function a_decision_names_its_own_employee_school_and_credential(): void
    {
        $colleague = $this->createEmployee($this->school);
        $foreignEmployee = $this->createEmployee($this->other);
        $foreign = app(EmployeeQualificationService::class)->add($foreignEmployee, ['qualification_type' => 'bachelors', 'qualification_name' => 'Foreign', 'institution' => 'B College'], $this->fullHrActor($this->other))->id;

        // Another Employee's name on this credential: the guard sees it is not theirs.
        $this->refused('a decision names a credential of its Employee', fn () => $this->insertDecision('qualification', 1, ['employee_id' => $colleague->id]));
        // Another School's credential under this School's context: invisible, so not found.
        $this->refused('a decision names a credential of its Employee', fn () => $this->insertDecision('qualification', 1, ['qualification_id' => $foreign, 'employee_id' => $foreignEmployee->id]));
        // Written as another School: the guard reads under this School's RLS, sees no such credential and refuses
        // before the policy's own check would.
        $this->refused('a decision names a credential of its Employee', fn () => $this->insertDecision('qualification', 1, ['school_id' => $this->other->id, 'qualification_id' => $foreign, 'employee_id' => $foreignEmployee->id]));
        // A kind that names the other column finds no credential; one naming both is refused by the shape check.
        $this->refused('a decision names a credential of its Employee', fn () => $this->insertDecision('qualification', 1, ['qualification_id' => null, 'certification_id' => $this->certificationId]));
        $this->refused('employee_credential_verifications_kind_check', fn () => $this->insertDecision('qualification', 1, ['certification_id' => $this->certificationId]));

        $this->assertSame(0, DB::connection('pgsql_admin')->table('employee_credential_verifications')->whereIn('school_id', [$this->school->id, $this->other->id])->count());

        // Reading: a decision of this School is invisible from the other, and nothing is visible without a School.
        $this->in($this->school, fn () => $this->insertDecision('certification', 1));
        $this->assertSame(1, $this->in($this->school, fn () => DB::table('employee_credential_verifications')->count()));
        $this->assertSame(0, $this->in($this->other, fn () => DB::table('employee_credential_verifications')->count()));
        $this->assertSame(0, DB::table('employee_credential_verifications')->count(), 'missing tenant context fails closed');
        $this->assertSame(['unverified', 1, null], $this->state('employee_qualifications', $foreign));
    }

    #[Test]
    public function a_decision_leaves_with_its_credential_and_never_holds_it(): void
    {
        $this->in($this->school, fn () => $this->insertDecision('qualification', 1));

        // The ancillary purge removes the credential as the owner does; its decisions follow (cascade).
        DB::connection('pgsql_admin')->table('employee_qualifications')->where('id', $this->qualificationId)->delete();
        $this->assertSame(0, DB::connection('pgsql_admin')->table('employee_credential_verifications')->where('qualification_id', $this->qualificationId)->count());
    }
}
