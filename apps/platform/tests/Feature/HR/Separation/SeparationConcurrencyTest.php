<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.12: real two-process races against PostgreSQL (each child is its own
 * PHP process and connection). The serialized outcome is asserted, never a
 * timing: whichever transaction wins, exactly one effect commits and every
 * loser is a clean domain refusal. Child processes use the real clock, so
 * every date here is in the past.
 */
class SeparationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school);
        }
        parent::tearDown();
    }

    /** @return array{User, EmploymentRecord} */
    private function world(): array
    {
        $this->school = $this->createSchool();
        $hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure']);
        $employment = app(EmploymentService::class)->create($this->createEmployee($this->school), ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $hr);

        return [$hr, $employment];
    }

    private function recorded(User $hr, EmploymentRecord $employment): EmploymentSeparationCase
    {
        return app(SeparationCaseService::class)->record($this->school, $employment->id, SeparationReason::RESIGNATION, '2026-01-10', '2026-01-31', $hr);
    }

    /**
     * @param  list<array{string, User, ?string}>  $runs  [op, actor, itemId]
     * @return list<string>
     */
    private function race(EmploymentSeparationCase $case, int $version, array $runs): array
    {
        $script = __DIR__.'/../../../Support/separation-race.php';
        $processes = array_map(function (array $run) use ($script, $case, $version): Process {
            $process = new Process(['php', $script, $run[0], $this->school->id, $run[1]->id, $case->id, (string) $version, $run[2] ?? '']);
            $process->start();

            return $process;
        }, $runs);
        foreach ($processes as $process) {
            $process->wait();
        }

        return array_map(fn (Process $p) => trim($p->getOutput()), $processes);
    }

    private function inSchool(callable $read): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $read);
    }

    #[Test]
    public function four_concurrent_approvals_approve_once_and_take_the_checklist_once(): void
    {
        [$hr, $employment] = $this->world();
        app(SeparationClearanceRequirementService::class)->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $hr);
        $case = $this->recorded($hr, $employment);
        $approvers = array_map(fn () => $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']), range(1, 4));

        $outputs = $this->race($case, $case->version, array_map(fn (User $u) => ['approve', $u, null], $approvers));

        $this->assertSame(1, count(array_filter($outputs, fn ($o) => $o === 'ok:approved')), implode(' | ', $outputs));
        foreach ($outputs as $o) {
            $this->assertMatchesRegularExpression('/^(ok:approved|refused:SEPARATION_(STALE|INVALID_STATE))$/', $o);
        }
        $this->assertSame('notice_period', $this->inSchool(fn () => EmploymentRecord::query()->whereKey($employment->id)->value('status')));
        $this->assertSame(1, $this->inSchool(fn () => DB::table('separation_clearance_items')->where('case_id', $case->id)->count()));
        $this->assertSame(1, $this->inSchool(fn () => DB::table('employment_separation_events')->where('case_id', $case->id)->where('kind', 'approved')->count()));
    }

    #[Test]
    public function finalize_racing_cancel_commits_exactly_one_and_the_employment_agrees(): void
    {
        [$hr, $employment] = $this->world();
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $case = $this->recorded($hr, $employment);
        $case = app(SeparationCaseService::class)->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        $otherHr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage']);

        $outputs = $this->race($case, $case->version, [['finalize', $hr, null], ['cancel', $otherHr, null]]);

        $wins = array_values(array_filter($outputs, fn ($o) => str_starts_with($o, 'ok:')));
        $this->assertCount(1, $wins, implode(' | ', $outputs));
        $status = $this->inSchool(fn () => EmploymentSeparationCase::query()->whereKey($case->id)->value('status'));
        $employmentStatus = $this->inSchool(fn () => EmploymentRecord::query()->whereKey($employment->id)->value('status'));
        $this->assertSame($status === 'finalized' ? 'separated' : 'active', $employmentStatus, "case {$status}, employment {$employmentStatus}");
    }

    #[Test]
    public function two_concurrent_clears_of_one_item_record_it_once(): void
    {
        [$hr, $employment] = $this->world();
        app(SeparationClearanceRequirementService::class)->create($this->school, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $hr);
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $case = $this->recorded($hr, $employment);
        $case = app(SeparationCaseService::class)->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        $item = $this->inSchool(fn () => DB::table('separation_clearance_items')->where('case_id', $case->id)->value('id'));
        $otherHr = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.manage']);

        $outputs = $this->race($case, $case->version, [['clear', $hr, $item], ['clear', $otherHr, $item]]);

        sort($outputs);
        $this->assertSame(['ok:cleared', 'refused:SEPARATION_CLEARANCE_ALREADY_CLEARED'], $outputs);
        $this->assertSame(1, $this->inSchool(fn () => DB::table('employment_separation_events')->where('case_id', $case->id)->where('kind', 'clearance_cleared')->count()));
    }
}
