<?php

namespace Tests\Feature\Retention;

use App\Domain\HR\Application\Retention\EmployeeRecordRetentionService;
use App\Domain\Payroll\Application\Retention\PayrollEmployeeRetentionService;
use App\Support\Retention\ReferencingRows;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.2E (E21-D9): every table that references an Employee-rooted parent is
 * classified here, read against the live FK catalog. The purge already
 * fails closed on an unclassified table (any referencing row blocks it).
 * This test makes the classification explicit and forces a conscious
 * decision when a new table appears.
 */
class EmployeeRetentionClassificationTest extends TestCase
{
    /** parent => referencing table => how D9 treats it */
    public const CLASSIFICATION = [
        'employees' => [
            'employment_records' => 'D9 evidence (8 y): purged with the Employee',
            'employee_personal_details' => 'D9 evidence (8 y): not in the ancillary list (date of birth, nationality)',
            'employee_documents' => 'D9 evidence (8 y): HR documents, bytes after commit',
            'documents' => 'D5 inherits the Employee: purged with it (DocumentParentRetention)',
            'employee_addresses' => 'D9 ancillary (2 y)',
            'employee_emergency_contacts' => 'D9 ancillary (2 y)',
            'employee_notes' => 'D9 ancillary (2 y)',
            'employee_qualifications' => 'D9 ancillary (2 y)',
            'employee_experience_records' => 'D9 ancillary (2 y)',
            'employee_certifications' => 'D9 ancillary (2 y)',
            'payroll_run_results' => 'retained, blocks: posted payroll evidence until Payroll\'s own D9 expiry removes it (E21.3F: 8 y after final separation, once every posting is that old)',
            'teaching_assignments' => 'retained, blocks: D6 authority history (E21.2B, 7 y after it ends)',
            'attendance_sessions' => 'retained, blocks: register provenance until the header itself expires (A1, E21.3D: 7 y after its Academic Year, once empty)',
            'timetable_entries' => 'retained, blocks: until the entry itself expires (A1, E21.3D: 7 y after its Academic Year, once no header references it)',
            'learning_content' => 'retained, blocks: LMS owner until the resource expires (A1 + D6 minimum, E21.3D)',
            'assignments' => 'retained, blocks: LMS owner until the resource expires (A1 + D6 minimum, E21.3D)',
            'transport_route_assignments' => 'retained, blocks: until the driver assignment itself expires (O2, E21.3E: 7 y after ends_on)',
            'visitor_visits' => 'retained, blocks: as host until the visit itself expires (O3, E21.3E: 1 y after checkout)',
        ],
        'employment_records' => [
            'employee_assignments' => 'D9 evidence (8 y): employment history, purged with the Employee',
            'employee_compensation_assignments' => 'D9 evidence (8 y): Payroll, purged by Payroll first',
            'employee_statutory_identifiers' => 'D9 evidence (8 y): Payroll, purged by Payroll first',
            'employee_tax_profile' => 'D9 evidence (8 y): Payroll, purged by Payroll first',
            'employee_pf_status' => 'D9 evidence (8 y): Payroll, purged by Payroll first',
            'employee_esi_coverage' => 'D9 evidence (8 y): Payroll, purged by Payroll first',
            'payroll_run_results' => 'retained, blocks: posted payroll evidence until its D9 expiry (E21.3F)',
            'payroll_adjustments' => 'retained, blocks: posted payroll evidence until its D9 expiry (E21.3F)',
            'payroll_lwf_annual_charges' => 'retained, blocks: posted payroll evidence until its D9 expiry (E21.3F)',
            'leave_policy_assignments' => 'retained, blocks: D9 leave evidence until HRX.6 adds its purge participant (ADR 0065 §18)',
            'leave_ledger_entries' => 'retained, blocks: D9 leave evidence until HRX.6 adds its purge participant (ADR 0065 §18)',
        ],
        'employee_assignments' => [
            'employee_assignments' => 'retained, blocks: another Employee names it as manager (ON DELETE SET NULL would rewrite their history)',
        ],
        'employee_compensation_assignments' => [
            'compensation_assignment_values' => 'D9 evidence: append-only values follow their assignment by FK cascade',
        ],
        'employee_personal_details' => [], 'employee_documents' => [],
        'employee_addresses' => [], 'employee_emergency_contacts' => [], 'employee_notes' => [],
        'employee_qualifications' => [], 'employee_experience_records' => [], 'employee_certifications' => [],
        'employee_statutory_identifiers' => [], 'employee_tax_profile' => [], 'employee_pf_status' => [], 'employee_esi_coverage' => [],
    ];

    #[Test]
    public function every_reference_to_an_employee_rooted_parent_is_classified(): void
    {
        $references = app(ReferencingRows::class);

        foreach (self::CLASSIFICATION as $parent => $tables) {
            $live = array_values(array_unique(array_column($references->to($parent), 'table')));
            sort($live);
            $classified = array_keys($tables);
            sort($classified);

            $this->assertSame($classified, $live, "references to {$parent} changed: classify them for E21-D9");
        }
    }

    #[Test]
    public function every_retention_parent_is_classified_by_exactly_one_checkpoint(): void
    {
        $parents = [...array_keys(StudentRetentionClassificationTest::CLASSIFICATION), ...array_keys(self::CLASSIFICATION), ...array_keys(GuardianRetentionClassificationTest::CLASSIFICATION), ...array_keys(AcademicRetentionClassificationTest::CLASSIFICATION), ...array_keys(ResidualRetentionClassificationTest::CLASSIFICATION)];
        sort($parents);
        $declared = ReferencingRows::PARENTS;
        sort($declared);

        $this->assertSame($declared, $parents);
    }

    #[Test]
    public function the_phase_table_lists_match_the_classification(): void
    {
        $ancillary = array_keys(array_filter(self::CLASSIFICATION['employees'], fn (string $t) => str_starts_with($t, 'D9 ancillary')));
        sort($ancillary);
        $declared = EmployeeRecordRetentionService::ANCILLARY_TABLES;
        sort($declared);
        $this->assertSame($declared, $ancillary);

        $payroll = array_keys(array_filter(self::CLASSIFICATION['employment_records'], fn (string $t) => str_contains($t, 'purged by Payroll first')));
        sort($payroll);
        $tables = PayrollEmployeeRetentionService::TABLES;
        sort($tables);
        $this->assertSame($tables, $payroll);
    }
}
