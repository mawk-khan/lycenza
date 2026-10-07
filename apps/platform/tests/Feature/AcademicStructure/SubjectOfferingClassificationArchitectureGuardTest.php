<?php

namespace Tests\Feature\AcademicStructure;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0069 (S1): structural pins for the SubjectOffering classification
 * freeze -- one update path, which defers to the database rule; the rule
 * installed on exactly the evidence tables; nothing else writes
 * `subject_offerings`; and the downstream fail-closed protections (RES
 * snapshots, TCH ownership dispatch) left intact.
 */
class SubjectOfferingClassificationArchitectureGuardTest extends TestCase
{
    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    #[Test]
    public function the_classification_is_written_only_at_creation_and_through_the_service(): void
    {
        $writers = [];
        foreach ($this->appFiles() as $file) {
            $code = $this->code($file);
            if (preg_match("/'is_required'\\s*=>\\s*\\\$/", $code) === 1 || str_contains($code, "table('subject_offerings')") || preg_match('/SubjectOffering::query\(\)[^;]*->update\(/s', $code) === 1) {
                $writers[] = substr($file, strlen(app_path()) + 1);
            }
        }
        $this->assertSame(['Domain/AcademicStructure/Http/Controllers/SubjectOfferingController.php'], $writers, 'creation (store) is the only other place a classification is written');

        $controller = $this->code(app_path('Domain/AcademicStructure/Http/Controllers/SubjectOfferingController.php'));
        preg_match('/public function update\(.*?\n    \}/s', $controller, $update);
        $this->assertStringContainsString('app(SubjectOfferingService::class)->update(', $update[0] ?? '');
        $this->assertStringNotContainsString('->update($validated)', $update[0] ?? '', 'the controller no longer writes the Offering itself');

        $service = $this->code(app_path('Domain/AcademicStructure/Application/SubjectOfferingService.php'));
        $this->assertStringContainsString('->lockForUpdate()->findOrFail($subjectOfferingId)', $service);
        $this->assertStringContainsString("str_contains(\$e->getMessage(), 'subject_offering_classification_locked')", $service);
        $this->assertStringContainsString('throw new SubjectOfferingClassificationLockedException', $service);
        foreach (['StudentSubjectEnrollment', 'TeachingAssignment', 'ExaminationPaper', 'TimetableEntry', 'CurriculumDelivery', 'AttendanceSession'] as $dependent) {
            $this->assertStringNotContainsString($dependent, $service, 'Academic Structure never reads the modules that depend on it (rule 4); the database owns the evidence check');
        }
    }

    #[Test]
    public function the_database_rule_is_installed_and_cannot_be_disabled_quietly(): void
    {
        $triggers = collect(DB::select("select c.relname, t.tgname, t.tgenabled from pg_trigger t join pg_class c on c.oid = t.tgrelid
            where t.tgname in ('trg_subject_offering_classification_freeze', 'trg_subject_offering_evidence') order by c.relname"));
        $this->assertSame([
            'attendance_sessions', 'curriculum_deliveries', 'elective_teaching_assignments', 'examination_papers',
            'student_subject_enrollments', 'subject_offerings', 'teaching_assignments', 'timetable_entries',
        ], $triggers->pluck('relname')->all());
        $this->assertSame(['O'], $triggers->pluck('tgenabled')->unique()->values()->all(), 'every trigger is enabled (origin)');

        foreach (['subject_offering_classification_freeze', 'subject_offering_evidence_guard'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                has_function_privilege('public', p.oid, 'EXECUTE') as public_exec from pg_proc p where p.proname = ?", [$function]);
            $this->assertNotNull($fn, $function);
            $this->assertFalse($fn->prosecdef, "{$function} runs as the caller (SECURITY INVOKER)");
            $this->assertStringContainsString('search_path=', $fn->config);
            $this->assertFalse($fn->public_exec);
        }
        $this->assertStringContainsString('FOR SHARE', (string) DB::selectOne("select pg_get_functiondef('subject_offering_evidence_guard'::regproc) as d")->d,
            'the evidence guard serializes with a classification change');
    }

    #[Test]
    public function the_downstream_fail_closed_protections_stay(): void
    {
        // RES (ADR 0068 §20.1, §21.2, §27): marks keep their recorded context even if it ever changed.
        $this->assertStringContainsString('throw new StudentMarkContextChangedException', $this->code(app_path('Domain/Examinations/Application/Marks/StudentMarkService.php')));
        $this->assertStringContainsString('assertContextUnchanged(', $this->code(app_path('Domain/Examinations/Application/Marks/StudentMarkCorrectionService.php')));

        // TCH: each ownership fact keeps refusing the other classification, and holdOffering() still dispatches on it.
        $this->assertStringContainsString('throw new RequiredOfferingOnlyException', $this->code(app_path('Domain/TeachingAssignments/Application/TeachingAssignmentService.php')));
        $this->assertStringContainsString('throw new ElectiveOfferingOnlyException', $this->code(app_path('Domain/TeachingAssignments/Application/ElectiveTeachingAssignmentService.php')));
        $ownership = $this->code(app_path('Domain/TeachingAssignments/Application/TeachingOwnership.php'));
        $this->assertMatchesRegularExpression('/\(bool\) \$isRequired => \$sectionId !== null && \$this->hold\(/', $ownership);
        $this->assertMatchesRegularExpression('/default => \$sectionId === null && \$this->holdElective\(/', $ownership);
    }
}
