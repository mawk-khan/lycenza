<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeOnboardingData;
use App\Domain\HR\Application\EmployeeOnboardingService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCapabilities;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsChangeReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C11 (HRC-11, rule 86): every HR writer of employment, terms, placement,
 * reporting and separation re-checks the School when it executes, the way Leave and
 * Identity do. The request-time `school-context` check is not enough: a School
 * suspended after that check, or a direct service call, reached the write. With the
 * guard lines removed, every case here fails except the positive control. Two of them,
 * 'separation complete' and 'link correction request', then refuse for another reason:
 * those prove the School is checked first, not that a write is stopped.
 */
class HrExecutionTimeSchoolCheckTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private Employee $employee;

    private EmploymentRecord $employment;

    private EmployeeAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school);
        $this->employment = $this->createEmploymentRecord($this->employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->assignment = $this->createEmployeeAssignment($this->employment, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function suspend(): void
    {
        DB::table('schools')->where('id', $this->school->id)->update(['status' => 'suspended']);
    }

    /** @return array<string, array{0: string}> */
    public static function writers(): array
    {
        return array_combine($names = [
            'employment create', 'employment update', 'employment end', 'rehire', 'terms change', 'assignment create', 'assignment end', 'set primary',
            'placement change', 'reporting assign', 'reporting remove', 'reporting correct', 'separation record', 'employee create', 'employee update', 'employee archive', 'employee restore',
            'link user', 'unlink user', 'onboarding', 'separation complete', 'clearance requirement create', 'clearance requirement retire', 'link correction request',
        ], array_map(fn (string $n) => [$n], $names));
    }

    private function run_(string $writer): void
    {
        $other = fn () => $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $manager = str_starts_with($writer, 'reporting') ? $other() : null;
        $position = $this->createPosition($this->school);
        $campus = $this->createCampus($this->school);
        $second = $writer === 'set primary' ? $this->createEmployeeAssignment($this->employment, $position, ['starts_on' => '2025-01-01', 'ends_on' => null, 'is_primary' => false]) : null;
        $leaver = $this->createEmployee($this->school);
        $this->createEmploymentRecord($leaver, ['status' => 'separated', 'starts_on' => '2020-01-01', 'ends_on' => '2020-12-31']);
        $line = $writer === 'reporting remove' || $writer === 'reporting correct'
            ? app(ReportingRelationshipService::class)->assign($this->employment, $manager, '2026-01-01', ReportingChangeReason::ORGANISATIONAL_CHANGE, $this->hr)
            : null;
        $account = $this->createUser();
        $linked = $writer === 'unlink user' ? app(EmployeeService::class)->linkUser($this->employee, $this->createMembership($account, $this->school)->user_id, $this->hr) : null;
        $separations = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.manage', SeparationCapabilities::CONFIGURE]);
        $requirement = $writer === 'clearance requirement retire'
            ? app(SeparationClearanceRequirementService::class)->create($this->school, 'KEYS', 'Keys', ClearanceResponsibility::cases()[0], true, null, $separations)
            : null;
        if ($writer === 'employee restore') {
            app(EmployeeService::class)->archive($this->employee, $this->hr);
        }
        $link = null;
        if ($writer === 'link correction request') {
            app(EmployeeService::class)->linkUser($this->employee, $this->createMembership($account, $this->school)->user_id, $this->hr);
            $link = app(TenantContext::class)->withSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->firstOrFail());
        }
        $this->suspend();

        match ($writer) {
            'employment create' => app(EmploymentService::class)->create($leaver, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $this->hr),
            'employment update' => app(EmploymentService::class)->update($this->employment, ['probation_ends_on' => '2026-12-31'], $this->hr),
            'employment end' => app(EmploymentService::class)->end($this->employment, '2026-06-30', $this->hr),
            'rehire' => app(EmployeeLifecycleService::class)->rehire($leaver, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $this->hr),
            'terms change' => app(EmploymentTermsService::class)->change($this->employment, '2026-06-15', EmploymentType::cases()[1], StaffType::from('teaching'), null, EmploymentTermsChangeReason::cases()[0], $this->hr),
            'assignment create' => app(EmployeeAssignmentService::class)->create($this->employment, ['starts_on' => '2026-06-20'], $position, $this->hr),
            'assignment end' => app(EmployeeAssignmentService::class)->end($this->assignment, '2026-06-30', $this->hr),
            'set primary' => app(EmployeeAssignmentService::class)->setPrimary($second, $this->hr),
            'placement change' => app(PlacementChangeService::class)->change($this->assignment, PlacementChangeKind::TRANSFER, '2026-07-01', PlacementChangeReason::CAREER_PROGRESSION, $position, $this->hr, campus: $campus),
            'reporting assign' => app(ReportingRelationshipService::class)->assign($this->employment, $manager, '2026-01-01', ReportingChangeReason::ORGANISATIONAL_CHANGE, $this->hr),
            'reporting remove' => app(ReportingRelationshipService::class)->remove($this->employment, '2026-06-20', ReportingChangeReason::ORGANISATIONAL_CHANGE, $this->hr),
            'reporting correct' => app(ReportingRelationshipService::class)->correct($line, null, ReportingChangeReason::ORGANISATIONAL_CHANGE, $this->hr),
            'separation record' => app(SeparationCaseService::class)->record($this->school, $this->employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $separations),
            'employee create' => app(EmployeeService::class)->create($this->school, ['full_name' => 'Never Written'], $this->hr),
            'employee update' => app(EmployeeService::class)->update($this->employee, ['full_name' => 'Never Written'], $this->hr),
            'employee archive' => app(EmployeeService::class)->archive($this->employee, $this->hr),
            'employee restore' => app(EmployeeService::class)->restore($this->employee, $this->hr),
            'link user' => app(EmployeeService::class)->linkUser($this->employee, $this->createMembership($account, $this->school)->user_id, $this->hr),
            'unlink user' => app(EmployeeService::class)->unlinkUser($linked, $this->hr),
            // Refused before the case is looked up: the School comes before any row, after Identity's lock (rule 141).
            'separation complete' => app(SeparationCaseService::class)->complete($this->school, (string) Str::uuid7(), 1, $separations),
            'clearance requirement create' => app(SeparationClearanceRequirementService::class)->create($this->school, 'KEYS', 'Keys', ClearanceResponsibility::cases()[0], true, null, $separations),
            'clearance requirement retire' => app(SeparationClearanceRequirementService::class)->retire($this->school, $requirement->id, $separations),
            'link correction request' => app(EmployeeAccountLinkCorrectionService::class)->request($this->employee, $link->id, EmployeeAccountLinkVoidReason::cases()[0], $this->hr),
            'onboarding' => app(EmployeeOnboardingService::class)->onboard($this->school, new EmployeeOnboardingData('Never Written', null, null, 'permanent', 'teaching', '2026-06-15', $campus->id, $position->id, null), $this->hr),
        };
    }

    #[Test]
    #[DataProvider('writers')]
    public function a_suspended_school_refuses_the_write_when_it_executes(string $writer): void
    {
        $this->expectException(SchoolNotOperationalException::class);

        $this->run_($writer);
    }

    #[Test]
    public function a_campus_actor_is_refused_the_same_way(): void
    {
        $campus = $this->createCampus($this->school);
        $position = $this->createPosition($this->school);
        $this->createEmployeeAssignment($this->employment, $position, ['starts_on' => '2026-01-01', 'ends_on' => null, 'is_primary' => false, 'campus_id' => $campus->id]);
        $actor = $this->createUser();
        $this->assignSchoolRole($this->createMembership($actor, $this->school), 'hr_officer', $campus->id);
        $this->suspend();

        $this->expectException(SchoolNotOperationalException::class);

        app(EmployeeOnboardingService::class)->onboard($this->school, new EmployeeOnboardingData('Never Written', null, null, 'permanent', 'teaching', '2026-06-15', $campus->id, $position->id, null), $actor);
    }

    #[Test]
    public function an_import_row_reports_the_refusal_and_leaves_nothing_behind(): void
    {
        // Import turns every failure into a row result, so a suspended School must fail the row, not skip the guard.
        $this->suspend();
        $before = app(TenantContext::class)->withSchool($this->school, fn () => Employee::query()->count());

        $result = app(EmployeeImportService::class)->import($this->school, $this->hr, [['full_name' => 'Never Written']]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('unavailable', $result->rows[0]->errors[0]['code']);
        $this->assertSame($before, app(TenantContext::class)->withSchool($this->school, fn () => Employee::query()->count()));
    }

    #[Test]
    public function an_operational_school_keeps_every_kind_of_write(): void
    {
        $employee = app(EmployeeService::class)->create($this->school, ['full_name' => 'Written'], $this->hr);
        $first = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $this->hr);
        app(EmploymentService::class)->update($first, ['probation_ends_on' => '2020-06-30'], $this->hr);
        $this->assertSame('separated', app(EmploymentService::class)->end($first, '2020-12-31', $this->hr)->status);

        $this->assertSame('active', app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $this->hr)->status);
    }
}
