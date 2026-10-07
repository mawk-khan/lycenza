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
 *
 * RES.4 (ADR 0068 §25; owner-authorised DEVELOPMENT, RES-L2 / E37 and the
 * teacher RES-L0 re-review / E35 undetermined) amends them deliberately: one
 * owned-scope capability (`examinations.marks.teacher`, held by `teacher` and
 * by `school_admin` for grantability only), two teacher session routes behind
 * the development-only block, and the teacher path writing through
 * StudentMarkService with a guard -- still one writer, no new table.
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
        // RES.4 (ADR 0068 §25): the owned teacher surface.
        $files[] = app_path('Http/Controllers/App/Examinations/TeacherStudentMarkController.php');
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
            'app.examination-papers.marks.update', 'app.my-examination-papers.index', 'app.my-examination-papers.marks.index', 'app.my-examination-papers.marks.update',
            'app.student-mark-corrections.approve', 'app.student-mark-corrections.reject',
        ], $routes->map(fn (Route $route) => (string) $route->getName())->sort()->values()->all(), 'RES.3 adds lock, request, approve and reject; RES.4 the owned teacher read and write, RES.4A the owned paper discovery list -- never an unlock, reopen, bypass, Student/mark list or search route.');
        foreach ($routes as $route) {
            $this->assertStringStartsNotWith('api/', $route->uri(), 'No bearer-token marks route (ADR 0049; ADR 0068 §4.1).');
            $middleware = $route->gatherMiddleware();
            $this->assertContains('mfa', $middleware, $route->uri().' needs the MFA window.');
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'capability:examinations.marks.')), $route->uri().' needs an examinations.marks.* capability.');
            $this->assertContains('school-context', $middleware);
            $this->assertContains('marks-development-only', $middleware, $route->uri().' is development only until RES-L1 (ADR 0068 §27).');

            // RES.4: the teacher routes carry the teacher key and the development-only block; no administrative
            // route accepts the teacher key, and no teacher route accepts an administrative one.
            $capabilities = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'capability:')));
            if (str_starts_with((string) $route->getName(), 'app.my-examination-papers.')) {
                $this->assertSame(['capability:examinations.marks.teacher'], $capabilities, $route->uri());
                $this->assertContains('teacher-marks-development-only', $middleware, $route->uri().' is development only (ADR 0068 §25.3).');
                $this->assertSame('App\\Http\\Controllers\\App\\Examinations\\TeacherStudentMarkController', $route->getControllerClass());
            } else {
                $this->assertNotContains('capability:examinations.marks.teacher', $capabilities, $route->uri());
                $this->assertNotContains('teacher-marks-development-only', $middleware);
            }
        }
    }

    #[Test]
    public function only_administrative_roles_hold_administrative_marks_and_no_results_capability_exists(): void
    {
        $this->assertSame(0, DB::table('capabilities')->where('key', 'like', 'examinations.results.%')->count(), 'No results capability (RES-L4).');
        $holders = fn (string $key) => DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('role_capabilities.capability_key', $key)->distinct()->orderBy('roles.key')->pluck('roles.key')->all();
        $administrative = ['examinations.marks.correction.approve', 'examinations.marks.correction.request', 'examinations.marks.lock', 'examinations.marks.manage', 'examinations.marks.view'];
        foreach ($administrative as $key) {
            $this->assertSame(['principal', 'school_admin'], $holders($key), "{$key}: administrative roles only; never teacher (RES-L2, RES-L0 re-review).");
        }
        // RES.4 (ADR 0068 §25.2): the owned key -- teacher, and school_admin only so it can grant the teacher role
        // (StaffRoleCatalog: an issuer grants only capabilities it holds). principal does not hold it.
        $this->assertSame(['school_admin', 'teacher'], $holders('examinations.marks.teacher'));
        $this->assertSame(['examinations.marks.teacher'], DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', 'teacher')->where('role_capabilities.capability_key', 'like', 'examinations.%')->pluck('role_capabilities.capability_key')->all(),
            'teacher holds no administrative marks, lock, correction, paper, definition or results key');
        $this->assertSame([...array_slice($administrative, 0, 4), 'examinations.marks.teacher', 'examinations.marks.view'],
            DB::table('capabilities')->where('key', 'like', 'examinations.marks.%')->orderBy('key')->pluck('key')->all(), 'No unlock, reopen or bypass capability (RES.3, §21); one owned teacher key (RES.4).');
        $this->assertSame(5, DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', 'principal')->where('role_capabilities.capability_key', 'like', 'examinations.marks.%')->count(), 'principal unchanged');
        $this->assertSame(6, DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', 'school_admin')->where('role_capabilities.capability_key', 'like', 'examinations.marks.%')->count(), 'school_admin: its five plus the grantable teacher key');
    }

    /**
     * RES.4 (ADR 0068 §25): the teacher path is a narrowing of the one writer, never a second one; its read is
     * purpose-built (never the administrative grid filtered afterwards); the production block is code, not
     * configuration, and every teacher entry point passes through it.
     */
    #[Test]
    public function the_teacher_path_is_one_guarded_writer_a_purpose_built_read_and_development_only(): void
    {
        $marks = app_path('Domain/Examinations/Application/Marks/');
        $guards = array_values(array_filter($this->appFiles(), fn (string $f) => preg_match('/implements\s+StudentMarkWriteGuard\b/', $this->code($f)) === 1));
        $this->assertSame([$marks.'TeacherStudentMarkGuard.php'], $guards, 'exactly one write guard: the teacher one');

        $guard = $this->code($marks.'TeacherStudentMarkGuard.php');
        $this->assertMatchesRegularExpression('/function holdActor[^{]*\{\s*TeacherStudentMarkAvailability::assertAvailable\(\);\s*\$this->authorizeCapabilityFor\(\$this->actor, TeacherStudentMarkAccess::CAPABILITY/', $guard, 'block, then capability, first');
        $this->assertStringContainsString('$this->identities->hold($this->actor, $school)', $guard, 'ActingEmployee held inside the transaction');
        $this->assertStringContainsString('$this->ownership->holdOffering(', $guard, 'per-Student ownership under lock');
        $this->assertStringNotContainsString('->resolve(', $guard);

        $access = $this->code($marks.'TeacherStudentMarkAccess.php');
        $this->assertMatchesRegularExpression('/function scope[^{]*\{\s*TeacherStudentMarkAvailability::assertAvailable\(\);\s*StudentMarkAvailability::assertAvailable\(\);\s*\$this->authorizeCapabilityFor/', $access);

        $service = $this->code($marks.'StudentMarkService.php');
        $order = array_map(fn (string $needle) => strpos($service, $needle), ['$guard?->holdActor(', '->sharedLock()->first()', '$guard?->admitPaper(', 'STATUS_ACTIVE', 'lockEligibilityAsOf(', '$guard?->admitStudent(', 'lockQualifyingAuthorizationIdForStudentId(', '->lockForUpdate()->first()']);
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'canonical lock order (§25.7): identity, paper, visibility, state, P3, ownership, ADR 0038, mark');

        $read = $this->code($marks.'TeacherStudentMarkReadService.php');
        $this->assertDoesNotMatchRegularExpression('/\bStudentMarkReadService\b/', $read, 'never the administrative grid');
        $this->assertStringNotContainsString('StudentMarkCorrection', $read, 'no correction data on the teacher surface');
        $this->assertStringContainsString('$this->access->scope($actor, $school)', $read);

        $controller = $this->code(app_path('Http/Controllers/App/Examinations/TeacherStudentMarkController.php'));
        $this->assertStringContainsString('$access->guard($user)', $controller);
        $this->assertDoesNotMatchRegularExpression('/\bStudentMarkReadService\b/', $controller);
        $this->assertDoesNotMatchRegularExpression('/ExaminationPaper \$examinationPaper/', $controller, 'not route-model-bound: every paper miss is the same 404');

        $availability = $this->code($marks.'TeacherStudentMarkAvailability.php');
        $this->assertStringContainsString("public const array ENVIRONMENTS = ['local', 'testing'];", $availability);
        foreach (['config(', 'env(', 'getenv', '$_ENV', '$_SERVER'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $availability, 'the block is not configurable');
        }
        $this->assertStringContainsString('TeacherStudentMarkAvailability::isAvailable()', $this->code(app_path('Http/Middleware/EnsureTeacherStudentMarksDevelopmentOnly.php')));

        // RES.4A (ADR 0068 §26): discovery is the RES.4 access path over papers only -- no Student, mark, revision,
        // correction, P3, processing-authorization or results read, and never the administrative paper surface.
        $discovery = $this->code($marks.'TeacherExaminationPaperDiscoveryService.php');
        $this->assertMatchesRegularExpression('/function papers[^{]*\{\s*\$scope = \$this->access->scope\(\$actor, \$school\);/', $discovery, 'block, capability and ActingEmployee first');
        $this->assertStringContainsString('$scope->ownsOffering(', $discovery, 'the RES.4 paper-visibility rule, on each paper\'s date');
        foreach (['/\bStudentMark(Revision|Correction)?\b/', '/\bSubjectOfferingEligibility/', '/ProcessingAuthorization/', '/\bStudent\b/', '/examinations\.results/', '/\bStudentMarkReadService\b/', '/ExaminationPaperService/', "/'value'/"] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression($forbidden, $discovery, "discovery must not use {$forbidden}");
        }

        foreach (['TeacherStudentMarkGuard.php', 'TeacherStudentMarkAccess.php', 'TeacherStudentMarkReadService.php', 'TeacherStudentMarkScope.php', 'TeacherExaminationPaperDiscoveryService.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression("/'examinations\.marks\.(view|manage|lock|correction\.[a-z]+)'/", $this->code($marks.$file), "{$file} never consults an administrative marks key");
        }
    }

    /**
     * RES.5 (ADR 0068 §27): the marks Application layer has a CLOSED consumer set. Any other class reaching a
     * marks service, guard, scope or the availability block -- a job, command, AI tool, report, Communications or
     * Automation consumer, a differently named route -- is a new consumer that needs its own RES review.
     */
    #[Test]
    public function the_marks_application_layer_has_a_closed_consumer_set(): void
    {
        $classes = array_map(fn (string $f) => basename($f, '.php'), glob(app_path('Domain/Examinations/Application/Marks/*.php')) ?: []);
        $this->assertNotEmpty($classes);
        $pattern = '/Examinations\\\\Application\\\\Marks\\\\|\b('.implode('|', $classes).')\b/';
        $allowed = [
            'Domain/Examinations/Application/Marks/',
            'Http/Controllers/App/Examinations/StudentMarkController.php',
            'Http/Controllers/App/Examinations/StudentMarkCorrectionController.php',
            'Http/Controllers/App/Examinations/TeacherStudentMarkController.php',
            'Http/Middleware/EnsureTeacherStudentMarksDevelopmentOnly.php',
            'Http/Middleware/EnsureStudentMarksDevelopmentOnly.php',
        ];
        $consumers = [];
        foreach ($this->appFiles() as $file) {
            $relative = substr($file, strlen(app_path()) + 1);
            if (preg_match($pattern, $this->code($file)) === 1 && array_filter($allowed, fn (string $a) => str_starts_with($relative, $a)) === []) {
                $consumers[] = $relative;
            }
        }
        $this->assertSame([], $consumers, 'StudentMark application code is consumed only by its own controllers and middleware.');
    }

    /** RES.5 (ADR 0068 §25.3, §27): the production block's body and its entry points are pinned, not only its constant. */
    #[Test]
    public function the_teacher_production_block_cannot_be_hollowed_out(): void
    {
        $marks = app_path('Domain/Examinations/Application/Marks/');
        $this->assertMatchesRegularExpression('/function isAvailable\(\): bool\s*\{\s*return app\(\)->environment\(self::ENVIRONMENTS\);\s*\}/', $this->code($marks.'TeacherStudentMarkAvailability.php'));
        $this->assertMatchesRegularExpression('/function assertAvailable\(\): void\s*\{\s*if \(! self::isAvailable\(\)\) \{\s*throw new TeacherStudentMarksUnavailableException;/', $this->code($marks.'TeacherStudentMarkAvailability.php'));

        $callers = fn (string $needle) => array_values(array_map(fn (string $f) => basename($f), array_filter($this->appFiles(), fn (string $f) => str_contains($this->code($f), $needle))));
        $this->assertEqualsCanonicalizing(['TeacherStudentMarkAccess.php', 'TeacherStudentMarkGuard.php'], $callers('->scopeFor('), 'the block-free scopeFor() is reached only after the block');
        $guardBuilders = array_values(array_map(fn (string $f) => basename($f), array_filter($this->appFiles(), fn (string $f) => str_contains($this->code($f), 'TeacherStudentMarkAccess') && str_contains($this->code($f), '->guard('))));
        $this->assertSame(['TeacherStudentMarkController.php'], $guardBuilders, 'only the teacher controller builds a teacher write guard');
        $this->assertEqualsCanonicalizing(['TeacherExaminationPaperDiscoveryService.php', 'TeacherStudentMarkReadService.php'], $callers('$this->access->scope('), 'every teacher read starts at the blocked scope()');
    }

    /** RES.5 (ADR 0068 §27): every StudentMark entry point refuses outside local/testing first (RES-L1), not by process alone. */
    #[Test]
    public function every_marks_entry_point_is_development_only_first(): void
    {
        $marks = app_path('Domain/Examinations/Application/Marks/');
        $this->assertMatchesRegularExpression('/function isAvailable\(\): bool\s*\{\s*return app\(\)->environment\(self::ENVIRONMENTS\);\s*\}/', $this->code($marks.'StudentMarkAvailability.php'));
        $this->assertStringContainsString("public const array ENVIRONMENTS = ['local', 'testing'];", $this->code($marks.'StudentMarkAvailability.php'));
        foreach (['config(', 'env(', 'getenv', '$_ENV', '$_SERVER'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->code($marks.'StudentMarkAvailability.php'));
        }
        foreach ([
            'StudentMarkService.php' => 'record', 'StudentMarkReadService.php' => 'grid', 'StudentMarkLockService.php' => 'lock',
            'StudentMarkCorrectionService.php' => 'request|approve|reject',
        ] as $file => $methods) {
            foreach (explode('|', $methods) as $method) {
                $this->assertMatchesRegularExpression('/public function '.$method.'\([^{]*\{\s*StudentMarkAvailability::assertAvailable\(\);/', $this->code($marks.$file), "{$file}::{$method}() must refuse outside local/testing first");
            }
        }
    }

    /**
     * S6 (ADR 0068 §27.11): the database defence is installed as designed -- the paper FOR SHARE fires first on every
     * mark write, the lock guard covers INSERT and UPDATE, the approved-correction check is in place, and a marked
     * paper keeps its Examination, Offering, maximum and date. The application keeps the identity fixed and answers
     * 409 EXAMINATION_PAPER_HAS_MARKS for the maximum and date.
     */
    #[Test]
    public function the_database_defence_of_marks_is_installed(): void
    {
        $before = collect(DB::select("select t.tgname, pg_get_triggerdef(t.oid) as def from pg_trigger t where t.tgrelid = 'student_marks'::regclass
            and not t.tgisinternal and pg_get_triggerdef(t.oid) like '%BEFORE%' order by t.tgname"));
        $this->assertSame('student_marks_a_paper_share_trigger', $before->first()->tgname, 'the paper FOR SHARE fires before every other BEFORE trigger');
        $definitions = $before->pluck('def', 'tgname');
        foreach (['student_marks_a_paper_share_trigger', 'student_marks_lock_guard_trigger', 'student_marks_guard_trigger'] as $trigger) {
            $this->assertStringContainsString('BEFORE INSERT OR UPDATE ON public.student_marks', (string) $definitions[$trigger], $trigger);
        }
        $this->assertStringContainsString('FOR SHARE', (string) DB::selectOne("select pg_get_functiondef('student_marks_paper_share'::regproc) as d")->d);
        $this->assertSame(1, DB::table('pg_trigger')->where('tgname', 'student_marks_locked_change_approved')->where('tgdeferrable', true)->count(), 'RES.3: a locked change is approved by commit');

        $freeze = (string) DB::selectOne("select pg_get_triggerdef(oid) as d from pg_trigger where tgname = 'examination_papers_freeze_when_marked_trigger'")->d;
        $this->assertStringContainsString('BEFORE UPDATE OF max_marks, scheduled_on, examination_id, subject_offering_id ON public.examination_papers', $freeze);

        foreach (['student_marks_paper_share', 'examination_papers_freeze_when_marked', 'student_marks_lock_guard', 'student_marks_guard'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, has_function_privilege('public', p.oid, 'EXECUTE') as public_exec
                from pg_proc p where p.proname = ?", [$function]);
            $this->assertNotNull($fn, $function);
            $this->assertFalse($fn->prosecdef, "{$function}: SECURITY INVOKER");
            $this->assertStringContainsString('search_path=', $fn->config);
            $this->assertFalse($fn->public_exec);
        }

        $papers = $this->code(app_path('Domain/Examinations/Application/ExaminationPaperService.php'));
        preg_match('/public function update\(.*?\n    \}/s', $papers, $update);
        $this->assertStringNotContainsString("'examination_id' =>", $update[0] ?? '', 'the application never re-points a paper');
        $this->assertStringNotContainsString("'subject_offering_id' =>", $update[0] ?? '');
        $this->assertStringContainsString('ExaminationPaperMarksRecordedException', $papers, 'the documented 409 for a marked paper\'s maximum and date');
    }
}
