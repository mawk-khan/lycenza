<?php

namespace Tests\Feature\Retention;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3C: the Admissions and Guardian expiries stay narrow.
 * - Their clocks are the two database-owned markers, never `updated_at`.
 * - Application code writes a marker only in the evidence backfill; the
 *   database refuses any other direct write.
 * - A Guardian's consent events leave only through their Guardian-floored
 *   function, from their one owner.
 * - The Guardian root is never deleted by a raw cascade: the database
 *   trigger keeps the marker for every relationship writer, and the purge
 *   deletes the root last.
 */
class AdmissionsGuardianRetentionArchitectureGuardTest extends TestCase
{
    private const RETENTION_FILES = [
        'Domain/Admissions/Application/Retention/TerminalApplicationRetentionService.php',
        'Domain/Admissions/Application/Retention/AdmissionDecisionBackfill.php',
        'Domain/Guardians/Application/Retention/GuardianRetentionEligibility.php',
        'Domain/Guardians/Application/Retention/GuardianRecordRetentionService.php',
        'Domain/Guardians/Application/Retention/GuardianMarkerBackfill.php',
        'Domain/Communications/Application/Retention/GuardianConsentRetentionService.php',
        'Support/Retention/GuardianRetention.php',
        'Console/Commands/PruneAdmissionApplications.php',
        'Console/Commands/PruneGuardianRecords.php',
    ];

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> app-relative files whose code contains $needle */
    private function filesContaining(string $needle): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        $hits = [];
        foreach ($files as $file) {
            if (str_contains($this->code($file), $needle)) {
                $hits[] = substr($file, strlen(app_path()) + 1);
            }
        }
        sort($hits);

        return $hits;
    }

    #[Test]
    public function the_clocks_are_the_lifecycle_markers_never_updated_or_created_at(): void
    {
        foreach (self::RETENTION_FILES as $file) {
            $code = $this->code(app_path($file));
            $this->assertStringNotContainsString('updated_at', $code, "{$file} must never use updated_at as a retention trigger");
            $this->assertStringNotContainsString('created_at', $code, "{$file} must never use created_at as a retention trigger");
        }
    }

    #[Test]
    public function application_code_writes_a_marker_only_in_the_evidence_backfill(): void
    {
        $this->assertSame(['Domain/Admissions/Application/Retention/AdmissionDecisionBackfill.php'], $this->filesContaining("'terminal_at' => $"));
        $this->assertSame(['Domain/Guardians/Application/Retention/GuardianMarkerBackfill.php'], $this->filesContaining("'no_relationship_since' => $"));
    }

    #[Test]
    public function guardian_consent_evidence_leaves_only_through_its_function(): void
    {
        $this->assertSame([
            'Domain/Communications/Application/Retention/GuardianConsentRetentionService.php',
            'Support/Retention/RetentionExpiry.php',
        ], $this->filesContaining('guardianConsentEvents('));
    }

    #[Test]
    public function the_database_maintains_the_marker_for_every_relationship_writer(): void
    {
        $triggers = collect(DB::select("SELECT tgname, tgenabled FROM pg_trigger WHERE NOT tgisinternal AND tgname IN ('student_guardian_relationships_track_guardian', 'guardians_guard_no_relationship_since', 'admission_applications_guard_terminal_at')"))
            ->pluck('tgenabled', 'tgname')->all();
        ksort($triggers);

        $this->assertSame([
            'admission_applications_guard_terminal_at' => 'O',
            'guardians_guard_no_relationship_since' => 'O',
            'student_guardian_relationships_track_guardian' => 'O',
        ], $triggers);
    }
}
