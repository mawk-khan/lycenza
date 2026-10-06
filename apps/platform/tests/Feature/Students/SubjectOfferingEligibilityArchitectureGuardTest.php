<?php

namespace Tests\Feature\Students;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RES.1 (ADR 0068 §5, §11, §15): P3 is a Students-owned read seam, and RES.1
 * builds nothing beyond it. These fail on the shape of a change that would
 * move P3 out of Students, give it a caller before RES.2, or start RES.2+
 * (StudentMark, marks or results capabilities, routes or tables, report
 * cards, transcripts) while legal item RES-L0 is unanswered.
 */
class SubjectOfferingEligibilityArchitectureGuardTest extends TestCase
{
    private const SEAM = 'Domain/Students/Application/SubjectOfferingEligibilityReadService.php';

    private const RESULT = 'Domain/Students/Application/SubjectOfferingEligibility.php';

    /** @return list<string> absolute paths of every PHP file under $directory */
    private function files(string $directory): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    #[Test]
    public function the_seam_is_owned_by_students_and_depends_on_no_examinations_code(): void
    {
        $this->assertFileExists(app_path(self::SEAM));
        $this->assertFileExists(app_path(self::RESULT));
        foreach ([self::SEAM, self::RESULT] as $file) {
            $code = $this->code(app_path($file));
            $this->assertDoesNotMatchRegularExpression('/Domain\\\\(Examinations|Syllabus|Attendance|TeachingAssignments)\\\\/', $code, "{$file} is a Students seam: its input is a SubjectOffering, never an Examination or ExaminationPaper.");
            $this->assertDoesNotMatchRegularExpression('/\bExamination(Paper)?\b|\bStudentMark\b/', $code, $file);
        }
        foreach ($this->files(app_path('Domain/Students')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/use App\\\\Domain\\\\Examinations\\\\/', (string) file_get_contents($file), "Students never depends on Examinations: {$file}");
        }
    }

    #[Test]
    public function the_seam_has_no_caller_route_or_capability_until_res2(): void
    {
        $callers = [];
        foreach ($this->files(app_path()) as $file) {
            if (str_ends_with($file, self::SEAM) || str_ends_with($file, self::RESULT)) {
                continue;
            }
            if (preg_match('/\bSubjectOfferingEligibility(ReadService)?\b/', $this->code($file))) {
                $callers[] = substr($file, strlen(app_path()) + 1);
            }
        }
        $this->assertSame([], $callers, 'P3 has no consumer in RES.1; StudentMark (RES.2) is its first, after RES-L0 (ADR 0068 §11).');

        foreach (glob(base_path('routes/*.php')) ?: [] as $file) {
            $this->assertStringNotContainsString('SubjectOfferingEligibility', (string) file_get_contents($file), 'P3 is internal: no route.');
        }
    }

    #[Test]
    public function no_res2_or_later_artifact_exists(): void
    {
        foreach ($this->files(app_path()) as $file) {
            $this->assertDoesNotMatchRegularExpression('/^(StudentMark|MarkCorrection|ReportCard|Transcript|ExaminationResult|StudentResult)\w*\.php$/', basename($file), "RES.2+ is not started: {$file}");
        }

        foreach ($this->files(database_path('migrations')) as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/Schema::create\(\s*'(student_marks?|mark_corrections?|examination_paper_marks?|marks_states?|student_results?|examination_results?|report_cards?|transcripts?)'/",
                (string) file_get_contents($file),
                "RES.2+ tables are not created in RES.1: {$file}",
            );
        }

        $seeder = $this->code(database_path('seeders/CapabilityAndRoleSeeder.php'));
        $this->assertDoesNotMatchRegularExpression("/'key'\s*=>\s*'examinations\.(marks|results)\./", $seeder, 'No examinations.marks.* or examinations.results.* capability before RES.2 (marks) or a results contract (results).');

        $uris = collect(RouteFacade::getRoutes()->getRoutes())->map(fn (Route $route) => $route->uri())->all();
        foreach ($uris as $uri) {
            $this->assertDoesNotMatchRegularExpression('#(^|[/-])(marks?|report-cards?|transcripts?|student-results?|examination-results?)([/-]|$)#', $uri, "No marks, results, report-card or transcript route exists: {$uri}");
        }
    }
}
