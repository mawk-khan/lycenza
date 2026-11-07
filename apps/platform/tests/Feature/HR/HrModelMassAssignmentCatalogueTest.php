<?php

namespace Tests\Feature\HR;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\LifecycleModels;
use Tests\TestCase;

/**
 * HR.R1-C13 (WO-2, GOV-6): which models may mass-assign an ownership, parent, state or
 * actor column. The inventory is DISCOVERED from the namespaces, so a new model cannot
 * be left out by forgetting to list it: it fails here until it is hardened or given a
 * disposition. What it cannot see is meaning: "protected" is a name pattern, so a column
 * called something else needs a person. A model here is a structural fact, and none of
 * these was reachable from a request (`HrServiceBoundModelTest` proves the writers).
 */
class HrModelMassAssignmentCatalogueTest extends TestCase
{
    private const ROOTS = ['HR', 'Leave', 'StaffAttendance', 'WorkforceTasks', 'Payroll'];

    private const PROTECTED = '/^(?:school_id|user_id|employee_id|employment_record_id|payroll_run_id|case_id|requirement_id|subordinate_employment_id|manager_employment_id|status|record_status|is_primary|verification_status|.*_by_user_id|author_user_id|actor_user_id|(?:verified|archived|retired|voided|ended|executed|captured|uploaded|reversed|started)_at|campus_id|department_id|position_id|staff_type|parent_department_id|employee_number|document_id|archive_reason)$/';

    /**
     * Exactly what each hardened model accepts. The pattern above cannot know every protected name, and a service
     * strip list hides a wrong `$fillable` from a behaviour test, so these are pinned outright.
     */
    private const EXACT = [
        'Department' => ['name', 'code', 'description'],
        'Employee' => ['full_name', 'work_email', 'work_phone'],
        'EmployeeAddress' => ['address_type', 'address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code'],
        'EmployeeAssignment' => [],
        'EmployeeCategory' => ['name', 'code'],
        'EmployeeCertification' => ['name', 'issuer', 'credential_number', 'issued_on', 'expires_on'],
        'EmployeeDocument' => [],
        'EmployeeEmergencyContact' => ['name', 'relationship', 'phone', 'alternate_phone', 'email'],
        'EmployeeExperience' => ['organization', 'job_title', 'starts_on', 'ends_on', 'description', 'location', 'country_code'],
        'EmployeeNote' => ['body', 'classification_tier'],
        'EmployeePersonalDetail' => ['date_of_birth', 'nationality', 'marital_status', 'preferred_language', 'personal_email', 'personal_phone', 'alternate_phone'],
        'EmployeeQualification' => ['qualification_type', 'qualification_name', 'specialization', 'institution', 'awarding_body', 'country_code', 'starts_on', 'completed_on', 'grade_or_result'],
        'LeavePolicyAssignment' => [],
        'LeaveType' => ['code', 'name', 'is_paid', 'tracks_balance', 'allows_half_day'],
        'Position' => ['name', 'code', 'description'],
        'ReportingRelationship' => [],
        'SeparationClearanceItem' => [],
        'SeparationClearanceRequirement' => [],
        'StaffAttendanceRecord' => [],
        // HR.R1-C11's nine.
        'EmployeeCompensationAssignment' => ['effective_from', 'effective_to'],
        'EmploymentRecord' => ['probation_ends_on'],
        'EmploymentSeparationCase' => ['reason_code', 'notified_on', 'planned_last_day'],
        'LeaveAccrualRun' => [],
        'LeavePolicy' => ['name', 'annual_allocation_units', 'carry_forward_allowed', 'carry_forward_cap_units', 'carry_forward_expiry_days', 'min_notice_days', 'max_backdate_days', 'max_units_per_request', 'eligibility_rule_count', 'entitlement_method', 'monthly_accrual_units', 'accrual_timing'],
        'LeaveRequest' => [],
        'PayrollPeriod' => ['period_month', 'starts_on', 'ends_on', 'payment_date'],
        'PayrollRun' => [],
        'WorkforceTask' => ['title', 'description', 'category_id', 'completion_mode', 'available_on', 'due_on', 'section_id', 'subject_offering_id'],
    ];

    /** Not hardened on purpose. Each states why its protected columns may stay fillable. */
    private const RETAINED = [
        // Append-only: the runtime role holds no UPDATE, and each row is built once from a fixed array.
        'EmployeePlacementChange' => 'append-only', 'EmploymentSeparationEvent' => 'append-only', 'EmploymentTerm' => 'append-only',
        'LeaveAccrualRunItem' => 'append-only', 'LeaveAllocationRun' => 'append-only', 'LeaveDecision' => 'append-only', 'LeaveEligibilityRule' => 'append-only',
        'LeaveEligibilityRun' => 'append-only', 'LeaveLedgerEntry' => 'append-only', 'LeaveRequestDay' => 'append-only', 'LeaveYear' => 'append-only',
        'LeaveYearClose' => 'append-only', 'LeaveYearCloseItem' => 'append-only', 'LeaveYearCloseReconciliation' => 'append-only', 'LeaveYearStartChange' => 'append-only',
        'StaffAttendanceCorrection' => 'append-only', 'TaskEvent' => 'append-only', 'TaskTemplateVersion' => 'append-only',
        'CompensationAssignmentValue' => 'append-only', 'PayrollAdjustment' => 'append-only', 'PayrollPostingAttestation' => 'append-only', 'PayrollReversalReconciliation' => 'append-only',
        'PayrollReversalReconciliationReview' => 'append-only', 'PayrollRunCalculation' => 'append-only', 'PayrollRunCalculationSource' => 'append-only',
        'PayrollRunHrxInput' => 'append-only', 'PayrollRunPosting' => 'append-only',
        // Configuration or operational: no caller array reaches them, and a person reviews them with their own domain.
        'HrEmployeeNumberCounter' => 'configuration', 'LeaveSetting' => 'configuration', 'StaffHoliday' => 'decision-gated: D-6', 'StaffWorkingWeekday' => 'decision-gated: D-6',
        'TaskCategory' => 'configuration', 'TaskGenerationRun' => 'operational', 'TaskTemplate' => 'configuration',
        // Payroll's own, outside WO-2's HR and Leave scope.
        'PayrollAccountingConfiguration' => 'other-domain: Payroll', 'PayrollRunResult' => 'other-domain: Payroll', 'PayrollRunResultLine' => 'other-domain: Payroll',
        'SalaryComponent' => 'other-domain: Payroll', 'SalaryStructure' => 'other-domain: Payroll', 'SalaryStructureComponent' => 'other-domain: Payroll',
        // Legally gated, unreachable without a statutory grant (ADR 0036).
        'EmployeeEsiCoverage' => 'decision-gated: ADR 0036', 'EmployeePfStatus' => 'decision-gated: ADR 0036', 'EmployeeStatutoryIdentifier' => 'decision-gated: ADR 0036',
        'EmployeeTaxProfile' => 'decision-gated: ADR 0036', 'PayrollEsiRuleVersion' => 'decision-gated: ADR 0036', 'PayrollIncomeTaxRuleSlab' => 'decision-gated: ADR 0036',
        'PayrollIncomeTaxRuleVersion' => 'decision-gated: ADR 0036', 'PayrollLwfAnnualCharge' => 'decision-gated: ADR 0036', 'PayrollLwfRuleVersion' => 'decision-gated: ADR 0036',
        'PayrollPfRuleVersion' => 'decision-gated: ADR 0036', 'PayrollProfessionalTaxRuleSlab' => 'decision-gated: ADR 0036', 'PayrollProfessionalTaxRuleVersion' => 'decision-gated: ADR 0036',
        'PayrollSalaryComponentStatutoryClassification' => 'decision-gated: ADR 0036', 'PayrollStatutoryAccountingConfiguration' => 'decision-gated: ADR 0036',
        'PayrollStatutoryCalculationResult' => 'decision-gated: ADR 0036', 'PayrollStatutoryRunPosting' => 'decision-gated: ADR 0036',
    ];

    /** Accept nothing by mass assignment by construction: `$guarded = ['*']`, or a writer of their own. */
    private const CLOSED = ['EmployeeAccountLink', 'EmployeeAccountLinkCorrection', 'EmployeeReferenceCode'];

    /** @return array<string, class-string<Model>> short name => class */
    private static function discovered(): array
    {
        $models = [];
        foreach (self::ROOTS as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/../../../app/Domain/{$root}", RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = (string) $file->getPathname();
                if ($file->getExtension() !== 'php' || ! str_contains($path, '/Infrastructure/')) {
                    continue;
                }
                $class = 'App\\Domain\\'.str_replace('/', '\\', substr($path, (int) strpos($path, 'app/Domain/') + 11, -4));
                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    $models[$file->getBasename('.php')] = $class;
                }
            }
        }
        ksort($models);

        return $models;
    }

    /** @return array<string, array{0: class-string<Model>}> */
    public static function hardened(): array
    {
        $cases = [];
        foreach (LifecycleModels::HARDENED as $class) {
            $cases[class_basename($class)] = [$class];
        }

        return $cases;
    }

    #[Test]
    public function the_inventory_is_the_whole_namespace(): void
    {
        $this->assertCount(87, self::discovered(), 'A model was added or removed: give it a disposition here and in the C13 record.');
    }

    #[Test]
    public function each_hardened_model_accepts_exactly_its_own_fields(): void
    {
        $models = self::discovered();
        $hardened = array_map(class_basename(...), LifecycleModels::HARDENED);
        sort($hardened);
        $exact = array_keys(self::EXACT);
        sort($exact);
        $this->assertSame($hardened, $exact, 'every hardened model has an exact list');
        foreach (self::EXACT as $name => $fields) {
            $this->assertSame($fields, (new $models[$name])->getFillable(), $name);
        }
    }

    #[Test]
    public function every_model_has_exactly_one_disposition(): void
    {
        // The strict list is kept by hand, so it is checked against what exists: a model cannot fall off it unnoticed.
        $hardened = array_map(class_basename(...), LifecycleModels::HARDENED);
        $declared = [...$hardened, ...self::CLOSED, ...array_keys(self::RETAINED)];
        sort($declared);

        $this->assertSame(array_keys(self::discovered()), $declared);
    }

    #[Test]
    public function no_model_mass_assigns_a_protected_column_without_a_disposition(): void
    {
        $unanswered = [];
        foreach (self::discovered() as $name => $class) {
            $exposed = array_values(preg_grep(self::PROTECTED, (new $class)->getFillable()) ?: []);
            if ($exposed !== [] && ! isset(self::RETAINED[$name])) {
                $unanswered[$name] = $exposed;
            }
        }

        $this->assertSame([], $unanswered, 'These accept a protected column by mass assignment. Harden the model, or state why not in RETAINED.');
    }

    #[Test]
    public function a_retained_disposition_is_not_left_behind_by_a_hardened_model(): void
    {
        $stale = array_values(array_intersect(array_map(class_basename(...), LifecycleModels::HARDENED), array_keys(self::RETAINED)));

        $this->assertSame([], $stale);
        $this->assertSame([], array_values(array_diff(array_keys(self::RETAINED), array_keys(self::discovered()))), 'A disposition outlived its model.');
    }

    #[Test]
    #[DataProvider('hardened')]
    public function strict_mode_refuses_a_protected_column_and_a_refused_key_never_reaches_the_row(string $class): void
    {
        $column = 'school_id';
        $model = new $class;

        // In tests a dropped key is an exception, so a missed writer fails loudly here and in CI.
        try {
            $model->fill([$column => 'forged']);
            $this->fail("{$class} took {$column} by mass assignment.");
        } catch (MassAssignmentException) {
            $this->assertNull($model->getAttribute($column));
        }
    }

    #[Test]
    #[DataProvider('hardened')]
    public function outside_strict_mode_a_protected_column_is_dropped_never_stored(string $class): void
    {
        // Production has no strict mode and no callback. A model with some fillable columns drops the key silently, and
        // one with none refuses outright, so both shapes are checked against what the attribute ends up holding.
        // TestCase::setUp() restores both for the next test.
        Model::preventSilentlyDiscardingAttributes(false);
        Model::handleDiscardedAttributeViolationUsing(null);
        $model = new $class;
        try {
            $model->fill(['school_id' => 'forged']);
        } catch (MassAssignmentException) {
            $this->assertTrue($model->totallyGuarded(), 'only a model with no fillable column refuses outside strict mode');
        }
        $this->assertNull($model->getAttribute('school_id'));
    }
}
