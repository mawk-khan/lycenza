<?php

namespace Tests\Feature\Students;

use App\Domain\Documents\Application\DocumentService;
use App\Domain\Students\Application\Documents\StudentDocumentArchiveReason;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SIS.EXP-3 (OD-SIS-1): Student documents stay Student-record attachments
 * over the one Documents repository -- never a file manager, a public link,
 * an API surface, a tenant-defined type, an OCR/AI pipeline, a certificate
 * generator or a hard delete; whole-School School authority only.
 */
class StudentDocumentArchitectureTest extends TestCase
{
    private const KEYS = ['students.documents.view', 'students.documents.manage'];

    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r) => str_contains($r->uri(), 'documents') && str_contains($r->uri(), 'students')));
    }

    /** @return list<string> */
    private function checkValues(string $constraint): array
    {
        $definition = (string) DB::selectOne('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ?', [$constraint])->d;
        preg_match_all("/'([a-z_]+)'/", $definition, $m);

        return $m[1];
    }

    private function sources(string $directory): string
    {
        $code = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path($directory))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $code .= file_get_contents($file->getPathname());
            }
        }

        return $code;
    }

    #[Test]
    public function the_catalogues_are_code_owned_and_mirror_the_database(): void
    {
        $this->assertSame(StudentDocumentCategory::values(), $this->checkValues('student_documents_category_check'));
        $this->assertSame(StudentDocumentArchiveReason::values(), $this->checkValues('student_documents_archive_reason_check'));
        $this->assertSame(['enrollment_support', 'prior_school_record', 'consent_form', 'correspondence'], StudentDocumentCategory::values(), 'changing the catalogue is a reviewed release');
        foreach (['aadhaar', 'pan', 'passport', 'visa', 'caste', 'religion', 'medical', 'immunis', 'disability', 'biometric', 'photo', 'certificate', 'contract', 'report_card', 'marks', 'other'] as $gated) {
            foreach (StudentDocumentCategory::values() as $value) {
                $this->assertStringNotContainsString($gated, $value, "no {$gated} category without its legal/owner decision");
            }
        }
        // No tenant-defined type: no type table, no free category column.
        $this->assertSame([], DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '%document_type%'"));
    }

    #[Test]
    public function routes_are_staff_web_only_authenticated_and_never_delete_share_or_export(): void
    {
        $routes = $this->routes();
        $this->assertSame(
            ['app.students.documents.archive', 'app.students.documents.download', 'app.students.documents.index', 'app.students.documents.store', 'app.students.documents.update'],
            collect($routes)->map(fn (Route $r) => (string) $r->getName())->sort()->values()->all(),
        );
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertStringStartsWith('app/students/', $route->uri(), 'no API or public route');
            $this->assertContains('auth', $middleware);
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'capability:students.documents.')), "{$route->getName()} carries the document capability floor");
            $this->assertNotContains('DELETE', $route->methods(), 'no hard delete');
            $this->assertDoesNotMatchRegularExpression('/share|token|public|export|zip|bulk|certificate/i', $route->uri());
            if (in_array('GET', $route->methods(), true)) {
                $this->assertContains('mfa-page', $middleware, 'a Highly Sensitive read needs current MFA');
            }
        }
    }

    #[Test]
    public function authority_is_two_school_keys_held_by_admin_and_principal_whole_school_only(): void
    {
        $this->assertSame(
            array_map(fn ($k) => ['key' => $k, 'namespace' => 'school'], self::KEYS),
            DB::table('capabilities')->whereIn('key', self::KEYS)->orderByDesc('key')->get(['key', 'namespace'])->map(fn ($r) => (array) $r)->all(),
        );
        $holders = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->whereIn('capability_key', self::KEYS)->where('roles.is_system', true)->distinct()->orderBy('roles.key')->pluck('roles.key')->all();
        $this->assertSame(['principal', 'school_admin'], $holders);
        foreach (self::KEYS as $key) {
            $this->assertFalse(CampusScopeCatalogue::isAdopted($key), "{$key} is never Campus-adopted");
        }
        $this->assertSame(DocumentService::STUDENT_DOCUMENTS_VIEW, self::KEYS[0]);
        $this->assertSame(DocumentService::STUDENT_DOCUMENTS_MANAGE, self::KEYS[1]);
        $this->assertSame('highly_sensitive', DocumentService::STUDENT_CLASSIFICATION_TIER);
        $this->assertSame(['application/pdf', 'image/jpeg', 'image/png'], DocumentService::STUDENT_ALLOWED_MIME_TYPES);
    }

    #[Test]
    public function the_students_side_never_reads_the_documents_table_or_sends_files_anywhere(): void
    {
        $students = $this->sources('Domain/Students/Application/Documents').file_get_contents(app_path('Http/Controllers/App/StudentDocumentController.php'));
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z])Document::query/', $students, 'Students never queries the Documents model');
        foreach (["table('documents')", 'Storage::', 'AiGateway', 'ocr', 'tesseract', 'Dompdf', 'Snappy', 'share_token', 'temporaryUrl', 'Mail::', 'OutboundEmailGateway'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $students, "Student documents never use {$absent}");
        }
        $this->assertStringNotContainsString('student_documents', (string) shell_exec('grep -rl "student_documents\|students/documents" '.escapeshellarg(base_path('../../services/ai')).' 2>/dev/null'), 'the AI Gateway never sees Student documents');
        $this->assertStringNotContainsString('temporaryUrl', file_get_contents(app_path('Domain/Documents/Application/DocumentReadService.php')), 'no signed or public URL');
    }

    #[Test]
    public function only_the_students_reader_and_writer_call_the_student_document_entry_points(): void
    {
        // Documents' Student methods decide the document capability, not
        // Student visibility: every caller must come through the Students side.
        $callers = [
            'createForStudent' => ['app/Domain/Students/Application/Documents/StudentDocumentService.php', 'database/seeders/Demo/DemoSisData.php'],
            'archiveForStudent' => ['app/Domain/Students/Application/Documents/StudentDocumentService.php'],
            'studentFacts' => ['app/Domain/Students/Application/Documents/StudentDocumentReader.php'],
            'studentContent' => ['app/Domain/Students/Application/Documents/StudentDocumentReader.php'],
        ];
        foreach ($callers as $method => $allowed) {
            $found = [];
            foreach (['app', 'database'] as $root) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root))) as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), "->{$method}(")) {
                        $found[] = substr($file->getPathname(), strlen(base_path()) + 1);
                    }
                }
            }
            sort($found);
            $this->assertSame($allowed, $found, "{$method}() is called only from the Students side");
        }
    }

    #[Test]
    public function structural_fields_are_never_mass_assignable_and_the_runtime_role_cannot_delete(): void
    {
        $this->assertSame(['category', 'title', 'document_date'], (new StudentDocument)->getFillable());
        $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege('school_os_app', 'student_documents', 'DELETE') AS p")->p);
        $this->assertTrue((bool) DB::selectOne("SELECT relforcerowsecurity AS f FROM pg_class WHERE relname = 'student_documents'")->f, 'forced RLS');
    }
}
