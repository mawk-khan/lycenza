<?php

namespace Tests\Feature\CurriculumDelivery;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.3 (ADR 0063 sections 8, 11-13) static guards for the first owned
 * adopter: the dependency direction (Curriculum Delivery -> HR
 * ActingEmployee and TeachingAssignments ownership, never the reverse),
 * no role-name authorization anywhere, and no timetable in the Tier 2 path.
 */
class TeacherDeliveryArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function grep(string $pattern, string $path, bool $regex = false): array
    {
        $output = [];
        exec('grep -rn '.($regex ? '-E ' : '-F ').'--include=*.php --include=*.vue --include=*.ts '.escapeshellarg($pattern).' '.escapeshellarg(base_path($path)).' 2>/dev/null', $output);

        return array_values(array_filter($output, fn (string $line) => ! preg_match('#^[^:]+:\d+:\s*(\*|//)#', $line)));
    }

    #[Test]
    public function hr_teaching_assignments_and_capability_resolution_never_depend_on_curriculum_delivery(): void
    {
        foreach (['app/Domain/HR', 'app/Domain/TeachingAssignments', 'app/Support/Authorization'] as $path) {
            $this->assertSame([], $this->grep('App\\Domain\\CurriculumDelivery', $path), "{$path} must not depend on Curriculum Delivery.");
        }
    }

    #[Test]
    public function the_owned_path_uses_the_published_identity_and_ownership_primitives_only(): void
    {
        // Identity comes from ActingEmployeeResolver, ownership from
        // TeachingOwnership: no Employee lookup or teaching table here.
        foreach (['Employee::query', "'user_id'", 'teaching_assignments', 'TimetableEntry', 'timetable_entries', 'teacher_id'] as $forbidden) {
            $this->assertSame([], $this->grep($forbidden, 'app/Domain/CurriculumDelivery'), "Curriculum Delivery must not use {$forbidden}.");
            $this->assertSame([], $this->grep($forbidden, 'app/Http/Controllers/App/CurriculumDelivery'), "The Curriculum Delivery pages must not use {$forbidden}.");
        }

        $this->assertNotSame([], $this->grep('ActingEmployeeResolver', 'app/Domain/CurriculumDelivery'), 'Guard sanity: the owned path resolves identity through HR.');
        $this->assertNotSame([], $this->grep('TeachingOwnership', 'app/Domain/CurriculumDelivery'), 'Guard sanity: the owned path reads ownership through TeachingAssignments.');
    }

    #[Test]
    public function no_code_authorizes_by_the_teacher_role_key(): void
    {
        $patterns = [
            "=== 'teacher'", "== 'teacher'", "!== 'teacher'", 'hasRole(', 'isTeacher',
            "where('key', 'teacher')", "role === 'teacher'", 'role == "teacher"',
        ];

        foreach ($patterns as $pattern) {
            $this->assertSame([], $this->grep($pattern, 'app'), "Role-name authorization is forbidden (CLAUDE.md rule 24): {$pattern}");
            $this->assertSame([], $this->grep($pattern, 'resources/js'), "Role-name authorization is forbidden in the UI: {$pattern}");
        }
    }

    #[Test]
    public function every_owned_route_is_documented_and_gated_by_the_owned_capability(): void
    {
        $yaml = (string) file_get_contents(base_path('../../packages/contracts/openapi/school-os-api.yaml'));
        $live = [];

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'api/v1/schools/{school}/my/curriculum-deliver')) {
                $this->assertContains('capability:curriculum.delivery.teacher', $route->gatherMiddleware(), $route->uri());
                $this->assertNotContains('capability:curriculum.delivery.view', $route->gatherMiddleware());
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $live[] = $method;
                }
            }
        }

        $this->assertCount(6, $live, 'Six owned operations.');
        foreach (['listMyCurriculumDeliveryContexts', 'listMyCurriculumDeliveries', 'startMyCurriculumDelivery', 'getMyCurriculumDelivery', 'correctMyCurriculumDelivery', 'transitionMyCurriculumDelivery'] as $operationId) {
            $this->assertStringContainsString("operationId: {$operationId}\n", $yaml);
        }
    }
}
