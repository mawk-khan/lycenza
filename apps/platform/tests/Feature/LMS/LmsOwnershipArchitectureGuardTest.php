<?php

namespace Tests\Feature\LMS;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.5B (ADR 0063 section 35) static guards: the ownership foundation is
 * dormant and the Documents seam points one way.
 *
 * - Documents decides LMS attachment access only through LMS's
 *   LmsParentResourceAuthorization -- it names no LMS capability, owner
 *   column, audience or TeachingAssignment, and LMS never depends on it;
 * - HR, TeachingAssignments, Timetable and capability resolution never
 *   depend on LMS;
 * - since TCH.5C, only Learning Content has a teacher path, built from the
 *   published HR/TeachingAssignments primitives; the owner is never client
 *   input; Assignment teacher adoption (TCH.5D) is absent.
 */
class LmsOwnershipArchitectureGuardTest extends TestCase
{
    /** @return list<string> code lines (comments stripped) matching $needle under $path */
    private function codeMatches(string $needle, string $path): array
    {
        $root = base_path($path);
        $files = is_dir($root)
            ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)))
            : [new \SplFileInfo($root)];
        $hits = [];

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php', 'vue', 'ts'], true)) {
                continue;
            }
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file->getPathname()));
            if (str_contains($code, $needle)) {
                $hits[] = $file->getPathname();
            }
        }

        return $hits;
    }

    #[Test]
    public function documents_asks_lms_and_knows_nothing_of_ownership(): void
    {
        foreach (["'lms.", 'owner_employee_id', 'SectionAudience', 'section_audiences', 'TeachingOwnership', 'TeachingAssignments', 'ActingEmployee'] as $forbidden) {
            $this->assertSame([], $this->codeMatches($forbidden, 'app/Domain/Documents'), "Documents must not use {$forbidden}.");
        }

        foreach (['DocumentService.php', 'DocumentReadService.php', 'DocumentListingService.php'] as $service) {
            $this->assertNotSame([], $this->codeMatches('LmsParentResourceAuthorization', "app/Domain/Documents/Application/{$service}"), "{$service} asks LMS.");
        }

        $this->assertSame([], $this->codeMatches('App\\Domain\\Documents', 'app/Domain/LMS'), 'LMS never depends on Documents.');
    }

    #[Test]
    public function nothing_below_lms_depends_on_it(): void
    {
        foreach (['app/Domain/HR', 'app/Domain/TeachingAssignments', 'app/Domain/Timetable', 'app/Domain/AcademicStructure', 'app/Support/Authorization', 'app/Support/Tenancy'] as $path) {
            $this->assertSame([], $this->codeMatches('App\\Domain\\LMS', $path), "{$path} must not depend on LMS.");
        }
    }

    #[Test]
    public function the_learning_content_teacher_path_uses_only_the_published_primitives(): void
    {
        // TCH.5C: identity through HR's ActingEmployeeResolver, ownership through
        // TeachingAssignments' TeachingOwnership/OwnedTeachingPeriod -- never their
        // models, never the Timetable, never a teacher column, never a role key.
        foreach (['App\\Domain\\TeachingAssignments\\Infrastructure', 'App\\Domain\\HR\\Infrastructure', 'App\\Domain\\Timetable', 'teacher_id', "'teacher'", 'hasRole('] as $forbidden) {
            $this->assertSame([], $this->codeMatches($forbidden, 'app/Domain/LMS'), "LMS must not use {$forbidden}.");
        }
        $this->assertNotSame([], $this->codeMatches('ActingEmployeeResolver', 'app/Domain/LMS/Application/TeacherLearningContentAccess.php'));
        $this->assertNotSame([], $this->codeMatches('TeachingOwnership', 'app/Domain/LMS/Application/TeacherLearningContentGuard.php'));

        // The owner is always derived from the ActingEmployee: no transport
        // builds a SectionAudience or accepts an owner field.
        foreach (['app/Http', 'app/Domain/LMS/Http', 'routes', 'resources/js'] as $path) {
            $this->assertSame([], $this->codeMatches('SectionAudience', $path), "{$path} must not construct ownership.");
            $this->assertSame([], $this->codeMatches('owner_employee_id', $path), "{$path} must not accept an owner.");
        }
    }

    #[Test]
    public function assignment_teacher_adoption_is_absent(): void
    {
        $this->assertSame(0, DB::table('capabilities')->where('key', 'lms.assignments.teacher')->count());
        $this->assertSame([], $this->codeMatches('lms.assignments.teacher', 'app'), 'No Assignment teacher capability in code (TCH.5D).');
        $this->assertSame([], $this->codeMatches('WriteGuard', 'app/Domain/LMS/Application/AssignmentService.php'), 'AssignmentService has no teacher path.');
        $this->assertSame([], array_values(array_filter(
            array_map(fn ($route) => $route->uri(), iterator_to_array(Route::getRoutes())),
            fn (string $uri) => preg_match('#/my/assignment|my-assignment|submission#', $uri) === 1,
        )), 'No teacher Assignment or Submission route exists.');

        // Every owned Learning Content route is gated by the owned capability
        // and documented.
        $live = 0;
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), '/my/learning-content')) {
                $this->assertContains('capability:lms.content.teacher', $route->gatherMiddleware(), $route->uri());
                $this->assertContains('private-no-store', $route->gatherMiddleware(), $route->uri());
                $this->assertNotContains('idempotent', $route->gatherMiddleware(), $route->uri());
                $live += count(array_diff($route->methods(), ['HEAD']));
            }
        }
        $this->assertSame(7, $live, 'Seven owned Learning Content operations.');

        $yaml = (string) file_get_contents(base_path('../../packages/contracts/openapi/school-os-api.yaml'));
        foreach (['listMyLearningContentContexts', 'listMyLearningContent', 'createMyLearningContent', 'getMyLearningContent', 'updateMyLearningContent', 'publishMyLearningContent', 'archiveMyLearningContent'] as $operationId) {
            $this->assertStringContainsString("operationId: {$operationId}\n", $yaml);
        }
        $this->assertStringNotContainsString('operationId: listMyAssignments', $yaml);
    }
}
