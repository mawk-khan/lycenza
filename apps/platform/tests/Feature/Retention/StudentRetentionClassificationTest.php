<?php

namespace Tests\Feature\Retention;

use App\Domain\Students\Application\Retention\StudentRecordRetentionService;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\StudentRetention;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.2D (E21-D7): every table that references a Student-rooted parent is
 * classified here, read against the live FK catalog. The purge already
 * fails closed on an unclassified table (any referencing row blocks it).
 * This test makes the classification explicit and forces a conscious
 * decision when a new table appears.
 *
 * E21.3B: Library, Transport and Hostel rows are D7 operational (terminal
 * rows only); processing authorizations, converted admission applications,
 * consent events and domain preferences go with the core record. The
 * tables those rows can be referenced from are classified too, so a new
 * referencing table keeps blocking until it is decided.
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
            'student_processing_authorizations' => 'D7 core (25 y): legal-basis evidence, removed with the Student through its core-floored function (E21.3B)',
            'admission_applications' => 'D7 core (25 y): a converted application goes with its Student (Admissions participant, E21.3B)',
            'charges' => 'retained, blocks: Finance D8 (its own expiry, E21.3A2)',
            'fee_assessments' => 'retained, blocks: Finance D8',
            'fee_assessment_run_items' => 'retained, blocks: Finance D8',
            'fee_concessions' => 'retained, blocks: Finance D8',
            'fee_optional_selections' => 'retained, blocks: Finance D8',
            'canteen_orders' => 'retained, blocks: Canteen, billed through Fees (D8)',
            'hostel_residency_assignments' => 'D7 operational (7 y): ended residencies (E21.3B); an active one stays and blocks',
            'library_loans' => 'D7 operational (7 y): returned loans (E21.3B); an unreturned one stays and blocks; never cascaded',
            'transport_student_assignments' => 'D7 operational (7 y): ended assignments (E21.3B); an active one stays and blocks; never cascaded',
            'communication_announcement_recipients' => 'retained, blocks: Communications D3 removes it with its announcement first',
            'communication_announcement_domain_audience_members' => 'retained, blocks: Communications D3 removes it with its announcement first',
            'communication_delivery_policy_decisions' => 'retained, blocks: Communications D3 (1 y)',
            'communication_thread_participants' => 'retained, blocks: Communications D3 removes it with its thread first',
            'communication_domain_consent_events' => 'D7 core (25 y): consent evidence follows its Student subject (Communications participant, core-floored function, E21.3B)',
            'communication_domain_preferences' => 'D7 core (25 y): follows its Student subject (Communications participant, E21.3B)',
            'identity_account_invitations' => 'retained, blocks: an ended one expires 7 d after it ended (platform:portal-invitations-prune, E21.3B); a usable one keeps the Student',
        ],
        'student_enrollments' => [
            'student_subject_enrollments' => 'D7 core: purged first, with the Student',
            'attendance_records' => 'D7 operational (7 y): Attendance',
            'enrollment_rollover_items' => 'D7 operational (7 y)',
            'admission_applications' => 'D7 core: the converted application of the same Student (another Student\'s blocks)',
            'fee_assessments' => 'retained, blocks: Finance D8',
            'fee_assessment_run_items' => 'retained, blocks: Finance D8',
        ],
        'student_subject_enrollments' => [],
        'student_guardian_relationships' => [
            'student_processing_authorizations' => 'D7 core: a relationship an authorization names is its evidence and goes with the core record (E21.3B); the operational phase leaves it',
        ],
        'attendance_records' => [],
        'enrollment_rollover_items' => [],
        'documents' => [],
        // E21.3B: the rows that go with the Student, and what may reference them.
        'student_processing_authorizations' => [
            'student_processing_authorizations' => 'D7 core: terminal events point at their grant; removed together, leaves first',
        ],
        'admission_applications' => [],
        'applicants' => [
            'admission_applications' => 'D7 core: an applicant goes with its converted application only once no other application remains',
        ],
        'communication_domain_consent_events' => [],
        'communication_domain_preferences' => [],
        'library_loans' => [],
        'transport_student_assignments' => [
            // OPF.1 (ADR 0067 §21): the provenance of the fee selection the assignment recorded; it keeps the assignment
            // as the selection keeps its Student.
            'transport_fee_selections' => 'retained, blocks: Finance D8 (OPF fee-selection provenance)',
        ],
        'hostel_residency_assignments' => [],
        'identity_account_invitations' => [],
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

        // The one orchestration clears exactly those tables in its counting run.
        $cleared = array_keys(app(StudentRetention::class)->cleared());
        sort($cleared);
        $this->assertSame($declared, $cleared);
    }

    #[Test]
    public function the_core_participants_remove_exactly_the_core_classified_tables_of_other_modules(): void
    {
        $core = [];
        foreach (self::CLASSIFICATION['students'] as $table => $treatment) {
            if (str_starts_with($treatment, 'D7 core (25 y)') && ! in_array($table, ['student_enrollments', 'student_subject_enrollments', 'student_processing_authorizations'], true)) {
                $core[] = $table;
            }
        }
        $participants = array_merge(...array_map(fn ($p) => $p->tables(), app(StudentRetention::class)->participants()));
        $participants = array_values(array_diff($participants, ['student_guardian_relationships']));
        sort($core);
        sort($participants);

        $this->assertSame($core, $participants);
    }
}
