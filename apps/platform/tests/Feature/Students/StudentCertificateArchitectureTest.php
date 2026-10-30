<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Certificates\StudentCertificateAvailability;
use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Domain\Students\Application\Certificates\StudentCertificateVoidReason;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SIS.EXP-4: certificates stay a private, code-templated, immutable School
 * record -- no public or API surface, no tenant template, no hard delete, no
 * Finance prerequisite, no cross-School effect, no statutory identifier.
 */
class StudentCertificateArchitectureTest extends TestCase
{
    private function source(string $path): string
    {
        $code = '';
        $full = app_path($path);
        $files = is_dir($full) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full)) : [new \SplFileInfo($full)];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $code .= file_get_contents($file->getPathname());
            }
        }

        return $code;
    }

    /** @return list<string> */
    private function checkValues(string $constraint): array
    {
        $definition = (string) DB::selectOne('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ?', [$constraint])->d;
        preg_match_all("/'([a-z_]+)'/", $definition, $m);

        return $m[1];
    }

    #[Test]
    public function the_catalogues_are_code_owned_and_mirror_the_database(): void
    {
        $this->assertSame(['leaving', 'transfer'], StudentCertificateType::values());
        $this->assertSame(StudentCertificateType::values(), $this->checkValues('student_certificates_type_check'));
        $this->assertSame(array_map(fn ($r) => $r->value, StudentCertificateVoidReason::cases()), $this->checkValues('student_certificates_void_reason_check'));
        $this->assertSame([], DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '%certificate_template%'"), 'no tenant template table');
    }

    #[Test]
    public function routes_are_staff_web_only_private_and_never_delete_verify_or_share(): void
    {
        $routes = array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r) => str_contains($r->uri(), 'certificates')));
        $this->assertSame(
            ['app.students.certificates.index', 'app.students.certificates.reissue', 'app.students.certificates.store', 'app.students.certificates.void'],
            collect($routes)->map(fn (Route $r) => (string) $r->getName())->sort()->values()->all(),
        );
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertStringStartsWith('app/students/{student}/certificates', $route->uri(), 'no API or public route');
            $this->assertContains('auth', $middleware);
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'capability:students.certificates.issue')));
            $this->assertNotContains('DELETE', $route->methods());
            $this->assertDoesNotMatchRegularExpression('/verify|qr|share|public|token|export|sign/i', $route->uri());
        }
    }

    #[Test]
    public function the_template_is_one_fixed_escaped_view_rendered_with_a_locked_down_dompdf(): void
    {
        $views = glob(resource_path('views/certificates/*')) ?: [];
        $this->assertSame([resource_path('views/certificates/student-certificate.blade.php')], $views);
        $template = (string) file_get_contents($views[0]);
        $this->assertStringNotContainsString('{!!', $template, 'every value is escaped');
        $this->assertDoesNotMatchRegularExpression('/<script|<img|<link|url\(|@include|@php/i', $template);
        $this->assertStringContainsString('not a government-, board- or statute-prescribed form', $template, 'generic wording, no statutory claim');

        $renderer = $this->source('Domain/Students/Application/Certificates/StudentCertificateRenderer.php');
        foreach (['setIsRemoteEnabled(false)', 'setIsPhpEnabled(false)', 'setIsJavascriptEnabled(false)', "view('certificates.student-certificate'"] as $required) {
            $this->assertStringContainsString($required, $renderer);
        }
        $this->assertSame(['local', 'testing'], StudentCertificateAvailability::ENVIRONMENTS, 'the legal production gate is closed in production');
    }

    #[Test]
    public function certificates_touch_no_finance_no_other_school_and_no_storage_directly(): void
    {
        $code = $this->source('Domain/Students/Application/Certificates');
        foreach (['App\\Domain\\Payments', 'App\\Domain\\Fees', 'App\\Domain\\Finance', 'charges', 'Storage::', 'temporaryUrl', 'Mail::', 'AiGateway', 'School::query', 'Student::create', "table('documents')"] as $absent) {
            $this->assertStringNotContainsString($absent, $code, "the certificate domain never uses {$absent}");
        }
        $this->assertStringContainsString('createCertificateForStudent', $code, 'output goes through the private Documents pipeline');
    }

    #[Test]
    public function the_schema_carries_no_statutory_identifier_and_the_runtime_role_cannot_delete(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_name', 'student_certificates')->pluck('column_name')->all();
        foreach (['aadhaar', 'pan', 'passport', 'caste', 'religion', 'medical', 'birth', 'guardian', 'fee', 'balance', 'amount'] as $absent) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($absent, $column, "no {$absent} on a certificate");
            }
        }
        foreach (['student_certificates', 'student_certificate_sequences'] as $table) {
            $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege('school_os_app', ?, 'DELETE') AS p", [$table])->p, "{$table}: no runtime DELETE");
            $this->assertTrue((bool) DB::selectOne('SELECT relforcerowsecurity AS f FROM pg_class WHERE relname = ?', [$table])->f, "{$table}: forced RLS");
        }
        $this->assertSame([], (new StudentCertificate)->getFillable(), 'nothing is mass assignable');
    }

    #[Test]
    public function authority_is_one_school_key_held_by_admin_and_principal_whole_school_only(): void
    {
        $this->assertSame('school', DB::table('capabilities')->where('key', 'students.certificates.issue')->value('namespace'));
        $this->assertSame(['principal', 'school_admin'], DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('capability_key', 'students.certificates.issue')->where('roles.is_system', true)->orderBy('roles.key')->pluck('roles.key')->all());
        $this->assertFalse(CampusScopeCatalogue::isAdopted('students.certificates.issue'));
    }
}
