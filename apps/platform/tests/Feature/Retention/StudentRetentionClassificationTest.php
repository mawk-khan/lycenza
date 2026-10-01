<?php

namespace Tests\Feature\Retention;

use App\Domain\Students\Application\Retention\StudentRecordRetentionService;
use App\Support\Retention\ReferencingRows;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.2D (E21-D7): every table that references a Student-rooted parent is
 * classified here, read against the live FK catalog. The purge already
 * fails closed on an unclassified table (any referencing row blocks it).
 * This test makes the classification explicit and forces a conscious
 * decision when a new table appears.
 */
class StudentRetentionClassificationTest extends TestCase
{
    /** parent => referencing table => how D7 treats it */
    public const CLASSIFICATION = [
        'students' => [
            'student_enrollments' => 'D7 core (25 y): placements, purged with the Student',
            'student_subject_enrollments' => 'D7 core (25 y): subject history, purged with the Student',
            'documents' => 'D5 inherits the Student: purged with it (DocumentParentRetention)',
            'student_guardian_account_links' => 'D6 authority: removed with the Student only when revoked and past AUTHORITY_HISTORY_RETENTION_YEARS',
            'enrollment_rollover_items' => 'D7 operational (7 y): workflow lines',
            'student_guardian_relationships' => 'D7 operational (7 y)',
            'student_processing_authorizations' => 'retained, blocks: undeletable legal-basis evidence, no adopted period (E21.2G)',
            'admission_applications' => 'retained, blocks: Admissions has no adopted trigger (E21.2G)',
            'charges' => 'retained, blocks: Finance (E21.2E)',
            'fee_assessments' => 'retained, blocks: Finance (E21.2E)',
            'fee_assessment_run_items' => 'retained, blocks: Finance (E21.2E)',
            'fee_concessions' => 'retained, blocks: Finance (E21.2E)',
            'fee_optional_selections' => 'retained, blocks: Finance (E21.2E)',
            'canteen_orders' => 'retained, blocks: Canteen, billed through Fees (E21.2E/G)',
            'hostel_residency_assignments' => 'retained, blocks: Hostel, no adopted period (E21.2G)',
            'library_loans' => 'retained, blocks: Library, no adopted period (E21.2G); never cascaded',
            'transport_student_assignments' => 'retained, blocks: Transport, no adopted period (E21.2G); never cascaded',
            'communication_announcement_recipients' => 'retained, blocks: Communications D3 removes it with its announcement first',
            'communication_announcement_domain_audience_members' => 'retained, blocks: Communications D3 removes it with its announcement first',
            'communication_delivery_policy_decisions' => 'retained, blocks: Communications D3 (1 y)',
            'communication_thread_participants' => 'retained, blocks: Communications D3 removes it with its thread first',
            'communication_domain_consent_events' => 'retained, blocks: consent evidence, no adopted period (E21.2G)',
            'communication_domain_preferences' => 'retained, blocks: no adopted period (E21.2G)',
            'identity_account_invitations' => 'retained, blocks: Identity invitations (D13, E21.2G)',
        ],
        'student_enrollments' => [
            'student_subject_enrollments' => 'D7 core: purged first, with the Student',
            'attendance_records' => 'D7 operational (7 y): Attendance',
            'enrollment_rollover_items' => 'D7 operational (7 y)',
            'admission_applications' => 'retained, blocks (E21.2G)',
            'fee_assessments' => 'retained, blocks: Finance (E21.2E)',
            'fee_assessment_run_items' => 'retained, blocks: Finance (E21.2E)',
        ],
        'student_subject_enrollments' => [],
        'student_guardian_relationships' => [
            'student_processing_authorizations' => 'retained, blocks the relationship and its Student',
        ],
        'attendance_records' => [],
        'enrollment_rollover_items' => [],
        'documents' => [],
    ];

    #[Test]
    public function every_reference_to_a_student_rooted_parent_is_classified(): void
    {
        $references = app(ReferencingRows::class);

        foreach (array_keys(self::CLASSIFICATION) as $parent) {
            $live = array_values(array_unique(array_column($references->to($parent), 'table')));
            sort($live);
            $classified = array_keys(self::CLASSIFICATION[$parent]);
            sort($classified);

            $this->assertSame($classified, $live, "references to {$parent} changed: classify them for E21-D7");
        }
    }

    #[Test]
    public function the_operational_phase_tables_are_exactly_the_d7_operational_rows(): void
    {
        $operational = [];
        foreach (self::CLASSIFICATION as $tables) {
            foreach ($tables as $table => $treatment) {
                if (str_starts_with($treatment, 'D7 operational')) {
                    $operational[$table] = true;
                }
            }
        }
        $operational = array_keys($operational);
        sort($operational);
        $declared = StudentRecordRetentionService::OPERATIONAL_TABLES;
        sort($declared);

        $this->assertSame($declared, $operational);
    }
}
