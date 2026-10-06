<?php

namespace Tests\Feature\Examinations;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RES.2 (ADR 0068 §6, §9, §13, §15, §19; RES-L0 2026-10-07): structural
 * guards on StudentMark. They fail on the shape of a change that would widen
 * the column set, add a writer, expose marks beyond the per-paper session
 * surface (bearer API, outbox, webhook, Analytics, Group reports, AI), grant
 * a teacher or create a results capability.
 *
 * RES.3 (ADR 0068 §7, §21) amends them deliberately: two more tables (the
 * per-paper lock, the correction requests), each with exactly one writer;
 * four more session routes (lock, request, approve, reject -- never an
 * unlock or reopen); three more capabilities, still administrative only.
 */
class StudentMarkArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function markFiles(): array
    {
        $files = glob(app_path('Domain/Examinations/Application/Marks/*.php')) ?: [];
        $files[] = app_path('Domain/Examinations/Infrastructure/StudentMark.php');
        $files[] = app_path('Domain/Examinations/Infrastructure/StudentMarkRevision.php');
        $files[] = app_path('Http/Controllers/App/Examinations/StudentMarkController.php');
        // RES.3 (ADR 0068 §21): the marks lock and the correction workflow.
        $files[] = app_path('Domain/Examinations/Infrastructure/ExaminationPaperMarkState.php');
        $files[] = app_path('Domain/Examinations/Infrastructure/StudentMarkCorrection.php');
        $files[] = app_path('Http/Controllers/App/Examinations/StudentMarkCorrectionController.php');
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function appFiles(string $relative = ''): array
    {
        $out = [];
        $dir = app_path($relative);
        if (! is_dir($dir)) {
            return [];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ? order by column_name', ['public', $table],
        ))->pluck('column_name')->all();
    }

    #[Test]
    public function the_column_sets_stay_narrow(): void
    {
        $this->assertSame([
            'academic_year_id', 'created_at', 'eligibility_source', 'examination_paper_id', 'id', 'processing_authorization_id',
            'processing_purpose', 'recorded_by_user_id', 'retention_recorded_at', 'school_id', 'status', 'student_enrollment_id',
            'student_id', 'student_subject_enrollment_id', 'updated_at', 'value', 'version',
        ], $this->columns('student_marks'), 'No remark, grade, percentage, pass/fail, GPA, rank, publication or visibility column (ADR 0068 §6.1, §8).');
        $this->assertSame([
            'eligibility_source', 'id', 'new_status', 'new_value', 'previous_status', 'previous_value', 'processing_authorization_id',
            'recorded_at', 'recorded_by_user_id', 'retention_recorded_at', 'revision', 'school_id', 'student_enrollment_id',
            'student_mark_id', 'student_subject_enrollment_id',
        ], $this->columns('student_mark_revisions'), 'The value history records exactly the write, its provenance and its actor (§19.3 a).');
        $this->assertSame([
            'created_at', 'examination_paper_id', 'id', 'locked_at', 'locked_by_user_id', 'retention_recorded_at', 'school_id', 'state', 'updated_at',
        ], $this->columns('examination_paper_mark_states'), 'The lock records the state, who and when -- no unlock, reopen or override column (RES.3, §21).');
        $this->assertSame([
            'base_version', 'created_at', 'decided_at', 'decided_by_user_id', 'decision_processing_authorization_id', 'examination_paper_id', 'id',
            'previous_status', 'previous_value', 'processing_purpose', 'proposed_status', 'proposed_value', 'reason_code', 'request_processing_authorization_id',
            'requested_at', 'requested_by_user_id', 'retention_recorded_at', 'school_id', 'status', 'student_id', 'student_mark_id', 'updated_at',
        ], $this->columns('student_mark_corrections'), 'A correction records the change, a closed reason code (no free text), maker, checker and both bases (RES.3, §21).');
    }

    #[Test]
    public function only_the_mark_service_writes_and_no_lock_or_event_machinery_leaks(): void
    {
        // RES.3: one writer per table -- StudentMarkLockService the lock, StudentMarkCorrectionService the requests
        // (it changes a mark only through StudentMarkService::applyCorrection()).
        $writers = [
            'Application/Marks/StudentMarkService.php' => '$mark',
            'Application/Marks/StudentMarkLockService.php' => '$state',
            'Application/Marks/StudentMarkCorrectionService.php' => '$correction',
        ];
        foreach ($writers as $suffix => $variable) {
            $code = $this->code(app_path('Domain/Examinations/'.$suffix));
            preg_match_all('/(\$(?!this\b)\w+)->(forceFill|save)\(/', $code, $calls);
            $targets = array_values(array_unique($calls[1]));
            $this->assertNotEmpty($targets);
            $this->assertSame([], array_values(array_diff($targets, [$variable, '$lockedMark'])), $suffix.' writes only its own table.');
            if ($variable !== '$mark') {
                $this->assertStringNotContainsString('$lockedMark', $code);
            }
        }
        $this->assertStringContainsString('$this->marks->applyCorrection(', $this->code(app_path('Domain/Examinations/Application/Marks/StudentMarkCorrectionService.php')));

        foreach ($this->appFiles() as $file) {
            if (array_filter(array_keys($writers), fn (string $suffix) => str_ends_with($file, $suffix)) !== []) {
                continue;
            }
            $code = $this->code($file);
            if (preg_match('/\bStudentMark(Revision|Correction)?\b|\bExaminationPaperMarkState\b/', $code) || preg_match("/'(student_marks|student_mark_revisions|student_mark_corrections|examination_paper_mark_states)'/", $code)) {
                $this->assertDoesNotMatchRegularExpression('/->(forceFill|save|update|delete|create)\(|::create\(|->insert\(/', in_array($file, $this->markFiles(), true) ? $code : '',
                    basename($file).' must not write marks, the lock or corrections; each has exactly one writer.');
                $this->assertContains($file, [...$this->markFiles(), app_path('Support/Retention/RetentionAnchors.php'), app_path('Support/Retention/TenantRetentionCatalog.php'), app_path('Support/Retention/Erasure/UserReferenceCatalog.php'), app_path('Support/Operations/DatabaseRoleVerifier.php')],
                    basename($file).' references StudentMark outside its own files and the retention/verifier registries (no reuse, ADR 0068 §19.2 #8-9).');
            }
        }

        foreach ($this->markFiles() as $file) {
            $code = $this->code($file);
            foreach (['TenantLock', 'pg_advisory', 'event(', 'Event::dispatch', '::dispatch(', 'DomainEvent', 'domain_event_outbox', 'WebhookEventRegistry', 'Log::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file).' must not use '.$forbidden.' (no outbox, webhook, lock machinery or logging of marks).');
            }
        }
    }

    #[Test]
    public function marks_never_reach_analytics_group_reports_or_ai(): void
    {
        foreach (['Domain/Analytics', 'Support/Ai'] as $relative) {
            foreach ($this->appFiles($relative) as $file) {
                $code = (string) file_get_contents($file);
                $this->assertDoesNotMatchRegularExpression('/StudentMark|student_marks|student_mark_revisions/', $code, "{$file}: marks are never an Analytics, Group-report or AI input (RES-L0 §5 re-review trigger).");
            }
        }
    }

    #[Test]
    public function every_marks_route_is_session_only_and_composes_capability_with_mfa(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_contains((string) $route->getActionName(), 'StudentMark') || preg_match('#(^|/)marks(/|$)#', $route->uri()) === 1);

        $this->assertSame([
            'app.examination-papers.marks.corrections.store', 'app.examination-papers.marks.index', 'app.examination-papers.marks.lock',
            'app.examination-papers.marks.update', 'app.student-mark-corrections.approve', 'app.student-mark-corrections.reject',
        ], $routes->map(fn (Route $route) => (string) $route->getName())->sort()->values()->all(), 'RES.3 adds lock, request, approve and reject -- never an unlock, reopen, bypass, list or search route.');
        foreach ($routes as $route) {
            $this->assertStringStartsNotWith('api/', $route->uri(), 'No bearer-token marks route (ADR 0049; ADR 0068 §4.1).');
            $middleware = $route->gatherMiddleware();
            $this->assertContains('mfa', $middleware, $route->uri().' needs the MFA window.');
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'capability:examinations.marks.')), $route->uri().' needs an examinations.marks.* capability.');
            $this->assertContains('school-context', $middleware);
        }
    }

    #[Test]
    public function only_administrative_roles_hold_marks_and_no_results_capability_exists(): void
    {
        $this->assertSame(0, DB::table('capabilities')->where('key', 'like', 'examinations.results.%')->count(), 'No results capability (RES-L4).');
        $holders = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('role_capabilities.capability_key', 'like', 'examinations.marks.%')->where('roles.is_system', true)
            ->distinct()->orderBy('roles.key')->pluck('roles.key')->all();
        $this->assertSame(['principal', 'school_admin'], $holders, 'Administrative roles only; never teacher (RES-L2, E33, RES-L0 re-review).');
        $this->assertSame(0, DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', 'teacher')->where('role_capabilities.capability_key', 'like', 'examinations.marks.%')->count());
        $this->assertSame([
            'examinations.marks.correction.approve', 'examinations.marks.correction.request', 'examinations.marks.lock', 'examinations.marks.manage', 'examinations.marks.view',
        ], DB::table('capabilities')->where('key', 'like', 'examinations.marks.%')->orderBy('key')->pluck('key')->all(), 'No unlock, reopen or bypass capability (RES.3, §21).');
        foreach (['principal', 'school_admin'] as $role) {
            $this->assertSame(5, DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
                ->where('roles.key', $role)->where('role_capabilities.capability_key', 'like', 'examinations.marks.%')->count());
        }
    }
}
