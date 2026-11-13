<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;

/**
 * HR.R1-C34 (WO-16; owner decision HR.R1-C33): a separation is never approved or rejected by an
 * account known to be the same person as its recorder, or as the separating Employee through an
 * uncorrected link, current or ended -- in the service and as the raw runtime role. The case and the
 * employment stay exactly as they were; another person still decides.
 */
class SeparationKnownSamePersonTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private User $approver;

    private Employee $employee;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
            'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure',
        ]);
        $this->approver = $this->decider();
        app(TenantContext::class)->set($this->school);
        $this->employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->employment = app(EmploymentService::class)->create($this->employee, ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2022-01-01'], $this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function decider(): User
    {
        return $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
    }

    private function cases(): SeparationCaseService
    {
        return app(SeparationCaseService::class);
    }

    private function record(): EmploymentSeparationCase
    {
        return $this->cases()->record($this->school, $this->employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $this->hr);
    }

    /** Links $employee to each of $userIds in turn (null unlinks): every step leaves history. */
    private function relink(Employee $employee, ?string ...$userIds): void
    {
        foreach ($userIds as $userId) {
            Employee::query()->whereKey($employee->id)->update(['user_id' => $userId]);
        }
    }

    private function code(callable $work): string
    {
        try {
            DB::transaction($work);

            return 'ok';
        } catch (SeparationException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 300);
        }
    }

    /** @return array{0: string, 1: string} the case and employment status */
    private function state(EmploymentSeparationCase $case): array
    {
        return [(string) $case->fresh()->status, (string) EmploymentRecord::query()->whereKey($this->employment->id)->value('status')];
    }

    #[Test]
    public function a_known_same_account_of_the_recorder_never_decides(): void
    {
        $alt = $this->decider();
        $this->relink($this->createEmployee($this->school, ['user_id' => null]), $this->hr->id, null, $alt->id);
        $case = $this->record();

        $this->assertSame('SEPARATION_SAME_PERSON_DECISION', $this->code(fn () => $this->cases()->approve($this->school, $case->id, $case->version, $alt, $this->freshMfaProofFor($alt))));
        $this->assertSame('SEPARATION_SAME_PERSON_DECISION', $this->code(fn () => $this->cases()->reject($this->school, $case->id, $case->version, $alt)));
        $this->assertSame(['requested', 'active'], $this->state($case), 'nothing moved');

        $this->assertSame('approved', $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver))->status);
    }

    #[Test]
    public function a_replaced_account_of_the_separating_person_never_decides_their_separation(): void
    {
        $old = $this->decider();
        $new = $this->createUserWithCapabilities($this->school, []);
        $this->relink($this->employee, $old->id, null, $new->id);
        $case = $this->record();

        // The old account has no current link, so "never the subject" (current link) alone let it through before WO-16.
        $this->assertSame('SEPARATION_SAME_PERSON_DECISION', $this->code(fn () => $this->cases()->approve($this->school, $case->id, $case->version, $old, $this->freshMfaProofFor($old))));
        $this->assertSame(['requested', 'active'], $this->state($case));
    }

    #[Test]
    public function a_corrected_link_is_no_evidence_and_unknown_is_never_refused(): void
    {
        $alt = $this->decider();
        $own = $this->createEmployee($this->school, ['user_id' => null]);
        $this->relink($own, $this->hr->id, null, $alt->id);
        $case = $this->record();

        // C34-N5 (WO-18): refused BEFORE the correction, on both decisions, and nothing moves.
        $this->assertSame('SEPARATION_SAME_PERSON_DECISION', $this->code(fn () => $this->cases()->approve($this->school, $case->id, $case->version, $alt, $this->freshMfaProofFor($alt))));
        $this->assertSame('SEPARATION_SAME_PERSON_DECISION', $this->code(fn () => $this->cases()->reject($this->school, $case->id, $case->version, $alt)));
        $this->assertSame(['requested', 'active'], $this->state($case));

        // An approved two-person correction voids the recorder's old link: the two accounts are no longer known to be one person.
        $link = EmployeeAccountLink::query()->where('employee_id', $own->id)->where('user_id', $this->hr->id)->firstOrFail();
        $this->correctLink(Employee::query()->findOrFail($own->id), $link->id);

        // Allowed AFTER it, on both decisions (reject this case; approve a second one).
        $this->assertSame('ok', $this->code(fn () => $this->cases()->reject($this->school, $case->id, $case->version, $alt)));
        $after = $this->record();
        $this->assertSame('approved', $this->cases()->approve($this->school, $after->id, $after->version, $alt, $this->freshMfaProofFor($alt))->status);

    }

    #[Test]
    public function unknown_is_never_refused(): void
    {
        // Unknown: an account sharing no Employee with anyone on the case decides.
        $case = $this->record();
        $unknown = $this->decider();
        $this->assertSame('approved', $this->cases()->approve($this->school, $case->id, $case->version, $unknown, $this->freshMfaProofFor($unknown))->status);
    }

    #[Test]
    public function the_runtime_role_cannot_record_a_decision_by_a_known_same_account(): void
    {
        $alt = $this->decider();
        $old = $this->decider();
        $this->relink($this->createEmployee($this->school, ['user_id' => null]), $this->hr->id, null, $alt->id);
        $this->relink($this->employee, $old->id, null);
        $case = $this->record();
        $decide = fn (User $as, string $status = 'approved') => $this->code(fn () => DB::update(
            'UPDATE employment_separation_cases SET status = ?, decided_by_user_id = ?, decided_at = now(), version = version + 1 WHERE id = ?',
            [$status, $as->id, $case->id],
        ));

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $this->assertStringContainsString('hr_employment_separation_same_person', $decide($alt));
        $this->assertStringContainsString('hr_employment_separation_same_person', $decide($alt, 'rejected'));
        $this->assertStringContainsString('hr_employment_separation_same_person', $decide($old), 'an ended, uncorrected link to the separating Employee');
        $this->assertSame('requested', $case->fresh()->status);
        $this->assertSame('ok', $decide($this->approver, 'rejected'), 'the positive control');
    }
}
