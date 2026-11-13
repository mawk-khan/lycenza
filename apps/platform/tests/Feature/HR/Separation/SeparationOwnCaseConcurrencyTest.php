<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-18 (C34-N2, C34-N3), on COMMITTED data with real second PostgreSQL sessions. Child processes
 * use the real clock, so every date here is in the past.
 *
 * - A relink of the separating Employee and a write on its case are ordered by the Employee row (the write
 *   holds it FOR SHARE; a link is an UPDATE of it): the link first -> the write is refused; the write first ->
 *   it stands.
 * - A link of ANOTHER Employee (making an account known-same) and a voiding correction take no lock in
 *   common with a separation write and need none: the write reads committed history only, so it is the
 *   serial one. Held open in another session, an uncommitted link or correction is invisible; once
 *   committed it governs the next write.
 */
class SeparationOwnCaseConcurrencyTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $hr;

    private User $linker;

    private User $old;

    private Employee $employee;

    private string $employmentId;

    private const KEYS = ['hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, self::KEYS);
        $this->linker = $this->fullHrActor($this->school);
        $this->old = $this->createUserWithCapabilities($this->school, self::KEYS);
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->employmentId = $this->inSchool(fn () => app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2022-01-01'], $this->hr)->id);
        // The separating Employee's old account: linked, then unlinked (an ended, uncorrected link).
        $this->link($this->employee, $this->old);
        $this->inSchool(fn () => app(EmployeeService::class)->unlinkUser(Employee::query()->findOrFail($this->employee->id), $this->linker));
    }

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->purgeSchoolsAsAdmin([$this->school->id]);
            } catch (\Throwable) {
                // Residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    private function inSchool(callable $work): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $work);
    }

    private function link(Employee $employee, User $user): void
    {
        $this->inSchool(fn () => app(EmployeeService::class)->linkUser(Employee::query()->findOrFail($employee->id), $user->id, $this->linker));
    }

    private function approvedCase(): EmploymentSeparationCase
    {
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);

        return $this->inSchool(function () use ($approver): EmploymentSeparationCase {
            $case = app(SeparationCaseService::class)->record($this->school, $this->employmentId, SeparationReason::RESIGNATION, '2026-01-10', '2026-01-31', $this->hr);

            return app(SeparationCaseService::class)->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        });
    }

    private function cancelInProcess(EmploymentSeparationCase $case, User $as): string
    {
        try {
            $fresh = $this->inSchool(fn () => EmploymentSeparationCase::query()->findOrFail($case->id));

            return $this->inSchool(fn () => app(SeparationCaseService::class)->cancel($this->school, $case->id, $fresh->version, SeparationCancelReason::RECORDED_IN_ERROR, $as, $this->freshMfaProofFor($as))->status);
        } catch (SeparationException $e) {
            return $e->errorCode();
        }
    }

    /** @return list<string> */
    private function cancelOp(EmploymentSeparationCase $case, User $as): array
    {
        return ['php', __DIR__.'/../../../Support/separation-race.php', 'cancel', $this->school->id, $as->id, $case->id, (string) $case->version];
    }

    /** @return list<string> */
    private function linkOp(Employee $employee, User $user): array
    {
        return ['php', __DIR__.'/../../../Support/payroll-reconciliation-op.php', 'service-link', $this->school->id, $employee->id, $user->id, $this->linker->id];
    }

    /** Runs $command held open in another process, calls $whileHeld, then releases it; returns its output. */
    private function holding(array $command, callable $whileHeld): string
    {
        $dir = sys_get_temp_dir().'/wo18_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $holder = new Process($command, null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        $holder->start();
        try {
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                if (! $holder->isRunning() || microtime(true) > $deadline) {
                    $this->fail("The holder never reached its held commit.\n".$holder->getOutput().$holder->getErrorOutput());
                }
                usleep(2_000);
            }
            $whileHeld();
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        return trim($holder->getOutput());
    }

    private function caseStatus(EmploymentSeparationCase $case): string
    {
        return (string) $this->inSchool(fn () => EmploymentSeparationCase::query()->whereKey($case->id)->value('status'));
    }

    #[Test]
    public function a_relink_of_the_separating_employee_held_open_is_seen_by_a_waiting_cancellation_which_is_refused(): void
    {
        $case = $this->approvedCase();
        $x = $this->createUserWithCapabilities($this->school, self::KEYS);

        [$holder, $contender] = $this->raceWithHeldHolder($this->linkOp($this->employee, $x), $this->cancelOp($case, $x));

        $this->assertSame('ok', $holder);
        // An ordering proof: X now holds the CURRENT link, so the existing current-link rule refuses it (not WO-18's clause).
        $this->assertSame('refused:HR_SELF_ADMINISTRATION', $contender, 'it waited on the Employee row, then saw its own link');
        $this->assertSame('approved', $this->caseStatus($case));
    }

    #[Test]
    public function a_cancellation_held_open_is_ordered_before_a_waiting_relink_and_stands(): void
    {
        $case = $this->approvedCase();
        $x = $this->createUserWithCapabilities($this->school, self::KEYS);

        [$holder, $contender] = $this->raceWithHeldHolder($this->cancelOp($case, $x), $this->linkOp($this->employee, $x));

        $this->assertSame(['ok:cancelled', 'ok'], [$holder, $contender]);
        $this->assertSame('cancelled', $this->caseStatus($case), 'never re-read');
    }

    #[Test]
    public function an_uncommitted_link_of_another_employee_is_invisible_and_once_committed_it_governs(): void
    {
        $case = $this->approvedCase();
        $alt = $this->createUserWithCapabilities($this->school, self::KEYS);
        $duplicate = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($duplicate, $this->old);
        $this->inSchool(fn () => app(EmployeeService::class)->unlinkUser(Employee::query()->findOrFail($duplicate->id), $this->linker));

        // Held open: ALT is not yet known to be OLD, so the write reads committed history and proceeds.
        $out = $this->holding($this->linkOp($duplicate, $alt), fn () => $this->assertSame('cancelled', $this->cancelInProcess($case, $alt)));
        $this->assertSame('ok', $out);

        // Committed: ALT is known to be the separating person, and the next write is refused.
        try {
            $this->inSchool(fn () => app(SeparationCaseService::class)->record($this->school, $this->employmentId, SeparationReason::RESIGNATION, '2026-02-10', '2026-02-28', $alt));
            $this->fail('expected SEPARATION_OWN_CASE');
        } catch (SeparationException $e) {
            $this->assertSame('SEPARATION_OWN_CASE', $e->errorCode());
        }
    }

    #[Test]
    public function a_voiding_correction_held_open_is_seen_by_a_waiting_cancellation_which_then_may_act(): void
    {
        $case = $this->approvedCase();
        $requester = $this->linkedHrStaff($this->school);
        $reviewer = $this->linkedHrStaff($this->school);
        $linkId = (string) $this->inSchool(fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->where('user_id', $this->old->id)->value('id'));
        $correction = $this->requestCorrection($this->inSchool(fn () => Employee::query()->findOrFail($this->employee->id)), $linkId, $requester);
        $version = $this->inSchool(fn () => app(EmployeeAccountLinkCorrectionService::class)->version($this->school->id, $linkId));

        // Before: OLD is the separating person.
        $this->assertSame('SEPARATION_OWN_CASE', $this->cancelInProcess($case, $this->old));

        // The correction holds the Employee row; the cancellation waits for it, then reads the committed void.
        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../../Support/payroll-reconciliation-op.php', 'approve-correction', $this->school->id, $correction->id, $version, $reviewer->id],
            $this->cancelOp($case, $this->old),
        );

        $this->assertSame(['ok', 'ok:cancelled'], [$holder, $contender]);
        $this->assertSame('cancelled', $this->caseStatus($case));
    }
}
