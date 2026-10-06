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
    }

    #[Test]
    public function only_the_mark_service_writes_and_no_lock_or_event_machinery_leaks(): void
    {
        foreach ($this->appFiles() as $file) {
            if (str_ends_with($file, 'Application/Marks/StudentMarkService.php')) {
                continue;
            }
            $code = $this->code($file);
            if (preg_match('/\bStudentMark(Revision)?\b/', $code) || str_contains($code, "'student_marks'") || str_contains($code, "'student_mark_revisions'")) {
                $this->assertDoesNotMatchRegularExpression('/->(forceFill|save|update|delete|create)\(|::create\(|->insert\(/', in_array($file, $this->markFiles(), true) ? $code : '',
                    basename($file).' must not write StudentMark; StudentMarkService is its only writer.');
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

        $this->assertCount(2, $routes);
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
    }
}
