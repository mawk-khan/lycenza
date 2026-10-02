<?php

namespace Tests\Feature\Retention;

use App\Support\Retention\ReferencingRows;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3B: the Student-linked residual expiries stay narrow.
 * - Each newly expired table is deleted from in exactly one retention
 *   service of its owning module.
 * - The append-only Student-core evidence leaves only through
 *   RetentionExpiry::studentCoreEvidence(), from its two owners.
 * - Those services are reached only through the one orchestration
 *   (StudentRetention) or their own command; never from a request, job or
 *   listener.
 * - A new table referencing one of these rows is picked up from the FK
 *   catalog, so it keeps the row until it is classified.
 */
class StudentLinkedRetentionArchitectureGuardTest extends TestCase
{
    /** table => the one file that may delete from it */
    private const DELETERS = [
        'library_loans' => 'Domain/Library/Application/Retention/LibraryLoanRetentionService.php',
        'transport_student_assignments' => 'Domain/Transport/Application/Retention/TransportAssignmentRetentionService.php',
        'hostel_residency_assignments' => 'Domain/Hostel/Application/Retention/HostelResidencyRetentionService.php',
        'admission_applications' => 'Domain/Admissions/Application/Retention/ConvertedApplicationRetentionService.php',
        'applicants' => 'Domain/Admissions/Application/Retention/ConvertedApplicationRetentionService.php',
        'communication_domain_preferences' => 'Domain/Communications/Application/Retention/StudentConsentRetentionService.php',
        'identity_account_invitations' => 'Domain/Identity/Application/Retention/PortalInvitationRetentionService.php',
    ];

    /** Eloquent models of those tables. */
    private const MODELS = ['LibraryLoan', 'TransportStudentAssignment', 'HostelResidencyAssignment', 'AdmissionApplication', 'Applicant', 'CommunicationDomainPreference', 'GuardianAccountInvitation'];

    /** service => the only files (besides itself) that may reference it */
    private const CALLERS = [
        'LibraryLoanRetentionService' => ['Support/Retention/StudentRetention.php'],
        'TransportAssignmentRetentionService' => ['Support/Retention/StudentRetention.php'],
        'HostelResidencyRetentionService' => ['Support/Retention/StudentRetention.php'],
        'ConvertedApplicationRetentionService' => ['Support/Retention/StudentRetention.php'],
        'StudentConsentRetentionService' => ['Support/Retention/StudentRetention.php'],
        'PortalInvitationRetentionService' => ['Console/Commands/PrunePortalInvitations.php'],
        'StudentRetention;' => ['Console/Commands/PruneStudentRecords.php', 'Support/Retention/Erasure/Subjects/StudentErasureAdapter.php'],
    ];

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        sort($files);

        return $files;
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    #[Test]
    public function each_newly_expired_table_is_deleted_from_only_by_its_retention_service(): void
    {
        foreach ($this->phpFiles() as $file) {
            $code = $this->code($file);
            $relative = substr($file, strlen(app_path()) + 1);

            foreach (self::DELETERS as $table => $owner) {
                $names = str_contains($code, "table('{$table}") || str_contains($code, "= '{$table}';");
                if ($names && str_contains($code, '->delete(')) {
                    $this->assertSame($owner, $relative, "{$relative} names {$table} and deletes: only {$owner} may delete those rows");
                }
            }
            foreach (self::MODELS as $model) {
                $this->assertDoesNotMatchRegularExpression('/\b'.$model.'::[^;]*->(delete|forceDelete)\(/s', $code, "{$relative} deletes {$model} rows outside retention");
            }
        }
    }

    #[Test]
    public function append_only_student_core_evidence_leaves_only_through_its_database_function(): void
    {
        $callers = [];
        foreach ($this->phpFiles() as $file) {
            if (str_contains($this->code($file), 'studentCoreEvidence(')) {
                $callers[] = substr($file, strlen(app_path()) + 1);
            }
        }

        $this->assertSame([
            'Domain/Communications/Application/Retention/StudentConsentRetentionService.php',
            'Domain/Students/Application/Retention/StudentRecordRetentionService.php',
            'Support/Retention/RetentionExpiry.php',
        ], $callers);

        foreach (['communication_domain_consent_events', 'student_processing_authorizations'] as $table) {
            foreach ($this->phpFiles() as $file) {
                $this->assertDoesNotMatchRegularExpression("/table\\('{$table}'\\)[^;]*->delete\\(/s", $this->code($file), "{$file} must not delete {$table} directly");
            }
        }
    }

    #[Test]
    public function the_retention_services_are_reached_only_through_their_orchestration_or_command(): void
    {
        foreach (self::CALLERS as $needle => $allowed) {
            foreach ($this->phpFiles() as $file) {
                $relative = substr($file, strlen(app_path()) + 1);
                if (! str_contains($this->code($file), $needle) || str_ends_with($relative, '/'.rtrim($needle, ';').'.php')) {
                    continue;
                }

                $this->assertContains($relative, $allowed, "{$relative} must not reach {$needle}");
                $this->assertDoesNotMatchRegularExpression('#^(Http|Jobs|Listeners)/#', $relative);
            }
        }

        $schedule = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString("Schedule::command('platform:portal-invitations-prune')", $schedule);
        $this->assertStringContainsString("Schedule::command('platform:student-retention-prune')", $schedule);
    }

    #[Test]
    public function a_new_table_referencing_a_newly_expired_row_is_picked_up_and_keeps_it(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->statement('CREATE TABLE retention_e21_3b_probe (id uuid PRIMARY KEY, school_id uuid, library_loan_id uuid REFERENCES library_loans (id), applicant_id uuid REFERENCES applicants (id))');

        try {
            $references = new ReferencingRows;
            $this->assertContains('retention_e21_3b_probe', array_column($references->to('library_loans'), 'table'));
            $this->assertContains('retention_e21_3b_probe', array_column($references->to('applicants'), 'table'));
        } finally {
            $admin->statement('DROP TABLE IF EXISTS retention_e21_3b_probe');
        }
    }
}
