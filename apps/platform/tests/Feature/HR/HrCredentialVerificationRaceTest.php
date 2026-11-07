<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\Exceptions\ProfileRecordArchivedException;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C14 (C14-N5): an edit landing after a verification resets it. The writers decided
 * from a model loaded before their transaction, so that edit wrote only its own columns
 * and the new content stayed verified. Two real sessions, the verification held open and
 * the edit observed blocked. Reproduced on the unlocked writers.
 *
 * It does NOT prove a verifier saw what they verified. A verification names no version, so
 * one landing after an edit attests content nobody may have read. That half of C14-N5 is open.
 */
class HrCredentialVerificationRaceTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.qualifications.view', 'hr.employees.qualifications.manage']);
        $this->employee = $this->createEmployee($this->school);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        parent::tearDown();
    }

    /** @return list<string> */
    private function op(string $kind, string $rowId): array
    {
        return ['php', __DIR__.'/../../Support/hr-lifecycle-op.php', $this->school->id, $kind, $this->employee->id, $rowId, $this->hr->id];
    }

    /** @return array{0: string, 1: ?string} */
    private function row(string $table, string $column, string $id): array
    {
        $row = DB::connection('pgsql_admin')->table($table)->where('id', $id)->first();

        return [$row->{$column}, $row->verification_status];
    }

    #[Test]
    public function a_qualification_edit_waiting_on_a_verification_resets_it(): void
    {
        $row = app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('qualification-verify', $row->id), $this->op('qualification-edit', $row->id));

        $this->assertSame('ok:verified', $holder);
        $this->assertSame('ok:unverified', $contender);
        $this->assertSame(['Edited in a race', 'unverified'], $this->row('employee_qualifications', 'qualification_name', $row->id));
    }

    #[Test]
    public function a_verification_landing_after_an_edit_attests_the_row_as_it_stands(): void
    {
        $row = app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('qualification-edit', $row->id), $this->op('qualification-verify', $row->id));

        $this->assertSame('ok:unverified', $holder);
        $this->assertSame('ok:verified', $contender);
        $this->assertSame(['Edited in a race', 'verified'], $this->row('employee_qualifications', 'qualification_name', $row->id));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function writersBehindAnArchive(): array
    {
        return [
            'qualification verify' => ['qualification', 'verify'],
            'qualification reject' => ['qualification', 'reject'],
            'qualification edit' => ['qualification', 'edit'],
            'certification verify' => ['certification', 'verify'],
            'certification reject' => ['certification', 'reject'],
            'certification edit' => ['certification', 'edit'],
        ];
    }

    /** Without the check on the locked row the database refuses instead, and the caller gets a raw 500. */
    #[DataProvider('writersBehindAnArchive')]
    #[Test]
    public function a_writer_waiting_on_an_archive_gets_the_controlled_refusal(string $kind, string $writer): void
    {
        [$row, $table, $column, $name] = $kind === 'qualification'
            ? [app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr), 'employee_qualifications', 'qualification_name', 'BEd']
            : [app(EmployeeCertificationService::class)->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board'], $this->hr), 'employee_certifications', 'name', 'First aid'];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op("{$kind}-archive", $row->id), $this->op("{$kind}-{$writer}", $row->id));

        $this->assertSame('ok:archived', $holder);
        $this->assertSame('rejected:'.ProfileRecordArchivedException::class, $contender);
        $this->assertSame([$name, 'unverified'], $this->row($table, $column, $row->id));
    }

    #[Test]
    public function a_certification_edit_waiting_on_a_verification_resets_it(): void
    {
        $row = app(EmployeeCertificationService::class)->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board'], $this->hr);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('certification-verify', $row->id), $this->op('certification-edit', $row->id));

        $this->assertSame('ok:verified', $holder);
        $this->assertSame('ok:unverified', $contender);
        $this->assertSame(['Edited in a race', 'unverified'], $this->row('employee_certifications', 'name', $row->id));
    }
}
