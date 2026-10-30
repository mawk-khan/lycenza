<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Import\StudentGuardianImport;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * SIS.EXP-5: the import stays an add-only onboarding adapter over the
 * canonical services -- never an update or merge engine, a history or
 * module-data importer, a mapping/schema engine, a notifier or a Portal
 * provisioner; whole-School `students.import` (School Admin only).
 */
class StudentGuardianImportArchitectureTest extends TestCase
{
    private function source(): string
    {
        $tokens = token_get_all((string) file_get_contents((string) (new ReflectionClass(StudentGuardianImport::class))->getFileName()));

        return implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, $tokens));
    }

    #[Test]
    public function it_only_adds_through_the_canonical_services(): void
    {
        $code = $this->source();
        foreach (['$this->students->create(', '$this->placements->enroll(', '$this->guardians->create(', '$this->contacts->create(', '$this->relationships->link(', '$this->relationships->setPrimary('] as $call) {
            $this->assertStringContainsString($call, $code, "the canonical path {$call}");
        }
        foreach (['->update(', '->delete(', 'changeStatus', 'transferPlacement', 'withdraw', 'complete(', 'cancel', 'unlink', 'deactivate', '->insert(', 'forceDelete', 'upsert', 'updateOrCreate', 'firstOrCreate'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "the import never uses {$forbidden}");
        }
        $this->assertDoesNotMatchRegularExpression('/\\bmerge/i', $code, 'no merge');
        // Raw table access is read-only (rolls, contact hashes).
        preg_match_all("/DB::table\\('([a-z_]+)'\\)/", $code, $tables);
        $this->assertEqualsCanonicalizing(['student_enrollments', 'guardian_contacts'], array_values(array_unique($tables[1])));
    }

    #[Test]
    public function it_touches_no_other_module_no_history_and_no_external_effect(): void
    {
        $code = $this->source();
        foreach (['App\\Domain\\Documents', 'App\\Domain\\Payments', 'App\\Domain\\Fees', 'App\\Domain\\Finance', 'App\\Domain\\Attendance', 'App\\Domain\\Transport',
            'App\\Domain\\Library', 'App\\Domain\\Hostel', 'App\\Domain\\Communications', 'App\\Domain\\Identity', 'Certificate', 'Lifecycle', 'Rollover',
            'Mail::', 'Notification', 'OutboundEmailGateway', 'AccountLink', 'Invitation', 'User::create', 'Http::', 'dispatch('] as $absent) {
            $this->assertStringNotContainsString($absent, $code, "the import never touches {$absent}");
        }
    }

    #[Test]
    public function the_format_is_one_fixed_code_owned_contract_with_no_mapping_or_tenant_schema(): void
    {
        $this->assertSame('student_guardian_import_v1', StudentGuardianImport::FORMAT);
        $this->assertSame([
            'first_name', 'middle_name', 'last_name', 'date_of_birth', 'academic_year_code', 'campus_code', 'grade_code', 'section_code', 'roll_number', 'start_date',
            'guardian1_key', 'guardian1_first_name', 'guardian1_last_name', 'guardian1_relationship', 'guardian1_email', 'guardian1_mobile', 'guardian1_legal_guardian', 'guardian1_emergency_contact', 'guardian1_authorized_pickup',
            'guardian2_key', 'guardian2_first_name', 'guardian2_last_name', 'guardian2_relationship', 'guardian2_email', 'guardian2_mobile', 'guardian2_legal_guardian', 'guardian2_emergency_contact', 'guardian2_authorized_pickup',
        ], StudentGuardianImport::columns(), 'changing the format is a new version, never configuration');
        foreach (StudentGuardianImport::columns() as $column) {
            $this->assertDoesNotMatchRegularExpression('/school|status|student_number|aadhaar|pan|passport|caste|religion|medical|disab|biometric|gender|sex/', $column);
        }
        $this->assertSame([], DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND (tablename LIKE '%import_mapping%' OR tablename LIKE '%import_template%' OR tablename LIKE '%import_row%')"));
        $this->assertSame(['id', 'school_id', 'format_version', 'content_digest', 'row_count', 'students_created', 'guardians_created', 'relationships_created', 'placements_created', 'campus_count', 'committed_by_user_id', 'committed_at'],
            DB::table('information_schema.columns')->where('table_name', 'student_import_batches')->orderBy('ordinal_position')->pluck('column_name')->all(), 'the batch keeps counts and a digest, never a row or PII');
    }

    #[Test]
    public function routes_are_staff_web_only_behind_the_import_capability(): void
    {
        $routes = array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r) => str_contains($r->uri(), 'import') && str_contains($r->uri(), 'students')));
        $this->assertSame(['app.students.import.commit', 'app.students.import.create', 'app.students.import.preview', 'app.students.import.template'],
            collect($routes)->map(fn (Route $r) => (string) $r->getName())->sort()->values()->all());
        foreach ($routes as $route) {
            $this->assertStringStartsWith('app/students/import', $route->uri(), 'no API route');
            $this->assertContains('capability:students.import', $route->gatherMiddleware());
            $this->assertNotContains('DELETE', $route->methods());
        }
    }

    #[Test]
    public function authority_is_one_school_key_held_by_school_admin_only_and_the_batch_is_append_only(): void
    {
        $this->assertSame('school', DB::table('capabilities')->where('key', 'students.import')->value('namespace'));
        $this->assertSame(['school_admin'], DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('capability_key', 'students.import')->where('roles.is_system', true)->pluck('roles.key')->all());
        $this->assertFalse(CampusScopeCatalogue::isAdopted('students.import'));
        $this->assertSame(['students.import', 'students.manage', 'guardians.manage', 'enrollments.manage'], StudentGuardianImport::AUTHORITY);
        foreach (['UPDATE', 'DELETE'] as $privilege) {
            $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege('school_os_app', 'student_import_batches', ?) AS p", [$privilege])->p, "no runtime {$privilege}");
        }
        $this->assertTrue((bool) DB::selectOne("SELECT relforcerowsecurity AS f FROM pg_class WHERE relname = 'student_import_batches'")->f);
    }
}
