<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\Exceptions\CredentialRevisionChangedException;
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
 * HR.R1-C29 (C14-N5, C29-A/B): the other half. A verification named no version, so one landing
 * after an edit attested content nobody may have read: `5be4f9ad` ended that race `verified`.
 * A decision now names the revision its decision-maker saw and is refused when the row moved on;
 * when the verification commits first, the edit resets the status and the decision on the earlier
 * revision stays recorded.
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

    /** @return array{0: int, 1: ?int} the revision, and the revision the status names */
    private function revisions(string $table, string $id): array
    {
        $row = DB::connection('pgsql_admin')->table($table)->where('id', $id)->first();

        return [(int) $row->revision, $row->verification_revision === null ? null : (int) $row->verification_revision];
    }

    /** @return list<array{0: int, 1: string}> every recorded decision on the credential: revision, decision */
    private function decisions(string $column, string $id): array
    {
        return DB::connection('pgsql_admin')->table('employee_credential_verifications')->where($column, $id)->orderBy('sequence')
            ->get(['revision', 'decision'])->map(fn ($d) => [(int) $d->revision, (string) $d->decision])->all();
    }

    #[Test]
    public function a_qualification_edit_waiting_on_a_verification_resets_it(): void
    {
        $row = app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('qualification-verify', $row->id), $this->op('qualification-edit', $row->id));

        $this->assertSame('ok:verified', $holder);
        $this->assertSame('ok:unverified', $contender);
        $this->assertSame(['Edited in a race', 'unverified'], $this->row('employee_qualifications', 'qualification_name', $row->id));
        // HR.R1-C29: the edit moved the revision, and the decision on the revision that was verified stays recorded.
        $this->assertSame([2, null], $this->revisions('employee_qualifications', $row->id));
        $this->assertSame([[1, 'verified']], $this->decisions('qualification_id', $row->id));
    }

    /** @return array<string, array{0: string}> */
    public static function decisionsBehindAnEdit(): array
    {
        return ['qualification verify' => ['qualification-verify'], 'qualification reject' => ['qualification-reject'],
            'certification verify' => ['certification-verify'], 'certification reject' => ['certification-reject']];
    }

    /**
     * HR.R1-C29: on `5be4f9ad` the contender ended `ok:verified` and the row read
     * ['Edited in a race', 'verified']: content nobody had read, attested.
     */
    #[DataProvider('decisionsBehindAnEdit')]
    #[Test]
    public function a_decision_waiting_on_an_edit_is_refused_as_stale(string $decision): void
    {
        [$row, $table, $column, $key] = str_starts_with($decision, 'qualification')
            ? [app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'degree', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr), 'employee_qualifications', 'qualification_name', 'qualification_id']
            : [app(EmployeeCertificationService::class)->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board'], $this->hr), 'employee_certifications', 'name', 'certification_id'];
        $edit = str_starts_with($decision, 'qualification') ? 'qualification-edit' : 'certification-edit';

        // The decision-maker saw revision 1; the edit commits revision 2 while the decision waits on the row.
        [$holder, $contender] = $this->raceWithHeldHolder($this->op($edit, $row->id), [...$this->op($decision, $row->id), '1']);

        $this->assertSame('ok:unverified', $holder);
        $this->assertSame('rejected:'.CredentialRevisionChangedException::class, $contender);
        $this->assertSame(['Edited in a race', 'unverified'], $this->row($table, $column, $row->id));
        $this->assertSame([2, null], $this->revisions($table, $row->id));
        $this->assertSame([], $this->decisions($key, $row->id), 'nothing was decided');
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
