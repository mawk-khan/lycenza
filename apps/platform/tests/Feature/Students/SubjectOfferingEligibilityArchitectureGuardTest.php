<?php

namespace Tests\Feature\Students;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RES.1 (ADR 0068 §5, §11, §15) as amended by RES.2 (§19): P3 is a
 * Students-owned read seam. RES-L0 was determined CURRENT WITH CHANGES
 * (2026-10-07), so StudentMark (RES.2) became its first -- and only --
 * consumer. These fail on the shape of a change that would move P3 out of
 * Students, give it any other consumer, or start RES.3+ (lock, corrections,
 * results, report cards, transcripts, results capabilities or routes).
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

    /**
     * RES.2 (ADR 0068 §6.2, §19): the only P3 consumers -- the StudentMark write and grid read. RES.3 (§21) adds
     * the correction service, which re-checks P3 under lock on every request and approval (no new derivation).
     */
    private const SANCTIONED_CALLERS = [
        'Domain/Examinations/Application/Marks/StudentMarkCorrectionService.php',
        'Domain/Examinations/Application/Marks/StudentMarkReadService.php',
        'Domain/Examinations/Application/Marks/StudentMarkService.php',
    ];

    #[Test]
    public function the_seam_has_exactly_the_student_mark_consumers_and_no_route(): void
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
        sort($callers);
        $this->assertSame(self::SANCTIONED_CALLERS, $callers, 'P3 consumers are exactly StudentMark entry, read and correction (RES.2/RES.3, development only, ADR 0068 §19, §21); a new consumer needs its own review.');

        foreach (glob(base_path('routes/*.php')) ?: [] as $file) {
            $this->assertStringNotContainsString('SubjectOfferingEligibility', (string) file_get_contents($file), 'P3 is internal: no route.');
        }
    }

    #[Test]
    public function no_res4_or_later_artifact_exists(): void
    {
        // RES.2 built StudentMark and its value history; RES.3 (ADR 0068 §21) the per-paper lock and the
        // correction requests -- deliberately admitted below. Nothing from RES.4 onward (teacher entry), results,
        // report cards or transcripts exists, and no results capability.
        foreach ($this->files(app_path()) as $file) {
            $this->assertDoesNotMatchRegularExpression('/^(MarkCorrection|ReportCard|Transcript|ExaminationResult|StudentResult|TeacherMark|MyMark)\w*\.php$/', basename($file), "RES.4+ is not started: {$file}");
            if (str_starts_with(basename($file), 'StudentMarkCorrection')) {
                $this->assertMatchesRegularExpression('/^StudentMarkCorrection(Service|Controller|(AlreadyDecided|ContextChanged|Invalid|PaperNotLocked|PendingExists|SelfDecision)Exception)?\.php$/', basename($file), "Only the RES.3 correction files: {$file}");
            }
        }

        foreach ($this->files(database_path('migrations')) as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/Schema::create\(\s*'(mark_corrections?|marks_states?|student_results?|examination_results?|report_cards?|transcripts?)'/",
                (string) file_get_contents($file),
                "RES.4+ tables are not created: {$file}",
            );
        }

        $seeder = $this->code(database_path('seeders/CapabilityAndRoleSeeder.php'));
        $this->assertDoesNotMatchRegularExpression("/'key'\s*=>\s*'examinations\.results\./", $seeder, 'No examinations.results.* capability before a results contract.');
        preg_match_all("/'key'\s*=>\s*'(examinations\.marks\.[a-z_.]+)'/", $seeder, $keys);
        $this->assertSame([
            'examinations.marks.view', 'examinations.marks.manage',
            'examinations.marks.lock', 'examinations.marks.correction.request', 'examinations.marks.correction.approve',
        ], $keys[1], 'RES.2 adds marks view and manage, RES.3 lock, correction request and approve; no teacher, unlock or results key.');

        $marksRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => preg_match('#(^|[/-])(marks?|report-cards?|transcripts?|student-results?|examination-results?)([/-]|$)#', $route->uri()) === 1)
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())->sort()->values()->all();
        $this->assertSame([
            'GET|HEAD app/examination-papers/{examinationPaper}/marks',
            'POST app/examination-papers/{examinationPaper}/marks/lock',
            'POST app/examination-papers/{examinationPaper}/marks/{studentMark}/corrections',
            'POST app/student-mark-corrections/{studentMarkCorrection}/approve',
            'POST app/student-mark-corrections/{studentMarkCorrection}/reject',
            'PUT app/examination-papers/{examinationPaper}/marks',
        ], $marksRoutes, 'Exactly the RES.2 grid and batch write plus the RES.3 lock and correction routes; no unlock, results, report-card or transcript route.');
    }
}
