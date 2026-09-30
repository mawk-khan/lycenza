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
 * - no LMS teacher path exists yet: no `lms.*.teacher` capability, no
 *   identity/ownership read in LMS, no route or page that can create a
 *   teacher-owned row.
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
        foreach (['app/Domain/HR', 'app/Domain/TeachingAssignments', 'app/Domain/Timetable', 'app/Domain/AcademicStructure', 'app/Support/Authorization'] as $path) {
            $this->assertSame([], $this->codeMatches('App\\Domain\\LMS', $path), "{$path} must not depend on LMS.");
        }
    }

    #[Test]
    public function the_foundation_is_dormant(): void
    {
        // No identity, ownership or timetable read inside LMS until TCH.5C.
        foreach (['ActingEmployeeResolver', 'TeachingOwnership', 'App\\Domain\\TeachingAssignments', 'App\\Domain\\Timetable', "'lms.content.teacher'", "'lms.assignments.teacher'"] as $forbidden) {
            $this->assertSame([], $this->codeMatches($forbidden, 'app/Domain/LMS'), "LMS must not use {$forbidden} yet.");
        }

        // Only the LMS services may hand over a SectionAudience; no transport can.
        foreach (['app/Http', 'app/Domain/LMS/Http', 'routes', 'resources/js'] as $path) {
            $this->assertSame([], $this->codeMatches('SectionAudience', $path), "{$path} must not create teacher-owned rows.");
        }

        $this->assertSame(0, DB::table('capabilities')->whereIn('key', ['lms.content.teacher', 'lms.assignments.teacher'])->count());
        $this->assertSame([], array_values(array_filter(
            array_map(fn ($route) => $route->uri(), iterator_to_array(Route::getRoutes())),
            fn (string $uri) => preg_match('#/my/(learning|assignment)|my-(learning|assignment|lms)#', $uri) === 1,
        )), 'No teacher LMS route exists.');
    }
}
